<?php

declare(strict_types=1);

namespace Wbpms\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * No SQL string in app/ may use the same named placeholder twice.
 *
 * The app uses native prepares (PDO::ATTR_EMULATE_PREPARES => false), so a
 * repeated ":name" fails with SQLSTATE[HY093] "Invalid parameter number"
 * (c309d33; Manual Payroll Adjustment reused :amount). [PAY-024]
 */
final class SqlPlaceholderReuseTest extends TestCase
{
    #[Test]
    public function noSqlStringReusesANamedPlaceholder(): void
    {
        $root = dirname(__DIR__, 2) . '/app';
        $scanned = 0;
        $problems = [];

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            foreach (self::stringLiterals((string) file_get_contents($file->getPathname())) as [$text, $line]) {
                if (preg_match('/\b(SELECT|INSERT|UPDATE|DELETE)\b/i', $text) !== 1) {
                    continue;
                }
                $scanned++;
                $duplicates = self::reusedPlaceholders($text);
                if ($duplicates !== []) {
                    $problems[] = sprintf('%s near line %d reuses :%s', $file->getFilename(), $line, implode(', :', $duplicates));
                }
            }
        }

        self::assertGreaterThan(100, $scanned, 'Expected to find the SQL strings in app/.');
        self::assertSame([], $problems);
    }

    #[Test]
    public function theCheckCatchesTheManualAdjustmentBug(): void
    {
        $old = "INSERT INTO deduction (payroll_id, deduction_type, description, quantity, unit_rate, amount)
                VALUES (:payroll_id, 'ManualAdjustment', :description, 1, :amount, :amount)";
        self::assertSame(['amount'], self::reusedPlaceholders($old));

        $fixed = "INSERT INTO deduction (payroll_id, deduction_type, description, quantity, unit_rate, amount)
                  VALUES (:payroll_id, 'ManualAdjustment', :description, 1, :unit_rate, :amount)";
        self::assertSame([], self::reusedPlaceholders($fixed));

        // Times, casts and date formats are not placeholders.
        self::assertSame([], self::reusedPlaceholders("SELECT '08:00:00', '17:00:00', DATE_FORMAT(x, '%H:%i') FROM t WHERE a = :a"));
    }

    /** @return list<string> */
    private static function reusedPlaceholders(string $sql): array
    {
        preg_match_all('/(?<![:\w]):([A-Za-z_]\w*)/', $sql, $matches);

        return array_values(array_map('strval', array_keys(array_filter(
            array_count_values($matches[1]),
            static fn (int $count): bool => $count > 1
        ))));
    }

    /**
     * Every string literal in a PHP file, with interpolated "..." and heredoc
     * strings joined back together (variables become a space).
     *
     * @return list<array{0:string,1:int}>
     */
    private static function stringLiterals(string $source): array
    {
        $strings = [];
        $buffer = null;
        $line = 1;
        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                $line = $token[2];
            }
            $opens = $token === '"' || (is_array($token) && $token[0] === T_START_HEREDOC);
            $closes = $token === '"' || (is_array($token) && $token[0] === T_END_HEREDOC);
            if ($buffer === null && $opens) {
                $buffer = '';
                continue;
            }
            if ($buffer !== null && $closes) {
                $strings[] = [$buffer, $line];
                $buffer = null;
                continue;
            }
            if ($buffer !== null) {
                $buffer .= is_array($token) && $token[0] === T_ENCAPSED_AND_WHITESPACE ? $token[1] : ' ';
                continue;
            }
            if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
                $strings[] = [substr($token[1], 1, -1), $line];
            }
        }

        return $strings;
    }
}

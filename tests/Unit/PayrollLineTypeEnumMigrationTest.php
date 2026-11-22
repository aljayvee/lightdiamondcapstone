<?php

declare(strict_types=1);

namespace Wbpms\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The newest migration that redefines a payroll line-type ENUM must still list
 * every type the code writes.
 *
 * 20261018000024 (add 'Absence') rebuilt deduction.deduction_type from an old
 * list and dropped 'ManualAdjustment', so Manual Payroll Adjustment deductions
 * failed with "Data truncated for column 'deduction_type'" until
 * 20261121000032. [PAY-024]
 */
final class PayrollLineTypeEnumMigrationTest extends TestCase
{
    /** @return array<string, array{0:string, 1:list<string>}> */
    public static function columns(): array
    {
        return [
            'deduction.deduction_type' => ['deduction_type', [
                'Late', 'Undertime', 'Absence', 'CashAdvance', 'SSS', 'PhilHealth', 'PagIBIG', 'IncomeTax', 'ManualAdjustment',
            ]],
            'payroll_earnings.earning_type' => ['earning_type', [
                'Basic', 'Overtime', 'RegularHoliday', 'SpecialHoliday', 'SpecialNonWorkingHoliday', 'ThirteenthMonth', 'ManualAdjustment',
            ]],
        ];
    }

    /** @param list<string> $required */
    #[Test]
    #[DataProvider('columns')]
    public function theLatestEnumKeepsEveryTypeTheCodeWrites(string $column, array $required): void
    {
        $files = glob(dirname(__DIR__, 2) . '/database/migrations/*.php') ?: [];
        sort($files);

        $latest = null;
        $latestFile = '';
        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            $up = explode('function down', $source, 2)[0];
            $pattern = '/MODIFY\s+(?:COLUMN\s+)?`?' . $column . '`?\s+ENUM\(([^)]*)\)/i';
            if (preg_match_all($pattern, $up, $matches) > 0) {
                $latest = end($matches[1]);
                $latestFile = basename($file);
            }
        }

        self::assertIsString($latest, "No migration redefines {$column}.");
        preg_match_all("/'([^']+)'/", $latest, $values);
        foreach ($required as $type) {
            self::assertContains($type, $values[1], "{$latestFile} leaves '{$type}' out of {$column}.");
        }
    }
}

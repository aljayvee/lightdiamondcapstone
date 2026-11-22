<?php

declare(strict_types=1);

namespace Wbpms\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Wbpms\Infrastructure\Logging\ErrorLog;

/**
 * Production had no record of crashes: error_reporting(0) and an exception
 * handler that only rendered the 500 page. ErrorLog writes one line per
 * failure; these tests pin what that line contains and what it never does.
 */
final class ErrorLogFormatTest extends TestCase
{
    /** @return array{ref: string, caught: ?string, method: string, path: string, user: string, role: string, ip: string} */
    private function context(?string $caught = null): array
    {
        return [
            'ref'    => 'abc123def456',
            'caught' => $caught,
            'method' => 'GET',
            'path'   => '/hr/audit',
            'user'   => '5',
            'role'   => 'HRHead',
            'ip'     => '203.0.113.9',
        ];
    }

    public function testUncaughtLineHasReferenceRequestUserAndLocation(): void
    {
        $e    = new \TypeError('Argument #1 must be of type ?string, int given');
        $line = ErrorLog::format($e, $this->context(), dirname(__DIR__, 2));

        self::assertStringStartsWith('WBPMS UNCAUGHT ref=abc123def456 | GET /hr/audit | user=5 role=HRHead ip=203.0.113.9 | TypeError: ', $line);
        self::assertStringContainsString('Argument #1 must be of type ?string, int given', $line);
        self::assertStringContainsString('@ tests/Unit/ErrorLogFormatTest.php:', $line, 'file path is relative to the app root');
        self::assertStringContainsString('| trace: #0 ', $line);
    }

    public function testCaughtLineNamesWhereItWasCaught(): void
    {
        $line = ErrorLog::format(new \RuntimeException('x'), $this->context('attendance re-match'));

        self::assertStringStartsWith('WBPMS CAUGHT (attendance re-match) ref=abc123def456', $line);
    }

    public function testOutputIsOneLineEvenWhenTheMessageHasNewlines(): void
    {
        $e    = new \RuntimeException("SQLSTATE[42S22]: Column not found:\r\n1054 Unknown column 'action_type'\n");
        $line = ErrorLog::format($e, $this->context());

        self::assertStringNotContainsString("\n", $line);
        self::assertStringNotContainsString("\r", $line);
        self::assertStringContainsString("Column not found: 1054 Unknown column 'action_type'", $line);
    }

    public function testPreviousExceptionsAreIncluded(): void
    {
        $inner = new \PDOException('Duplicate entry');
        $outer = new \RuntimeException('Import failed', 0, $inner);
        $line  = ErrorLog::format($outer, $this->context());

        self::assertStringContainsString('RuntimeException: Import failed', $line);
        self::assertStringContainsString('caused by PDOException: Duplicate entry', $line);
    }

    public function testLongMessagesAreCut(): void
    {
        $line = ErrorLog::format(new \RuntimeException(str_repeat('a', 5000)), $this->context());

        self::assertStringContainsString(str_repeat('a', 1000) . '…', $line);
        self::assertStringNotContainsString(str_repeat('a', 1001), $line);
    }

    public function testFunctionArgumentsNeverAppearInTheTrace(): void
    {
        $line = ErrorLog::format($this->throwWith('hunter2-secret-password'), $this->context());

        self::assertStringContainsString('throwInner()', $line);
        self::assertStringNotContainsString('hunter2-secret-password', $line);
    }

    public function testReferenceIsStableWithinARequest(): void
    {
        $ref = ErrorLog::reference();

        self::assertMatchesRegularExpression('/^[0-9a-f]{12}$/', $ref);
        self::assertSame($ref, ErrorLog::reference());
    }

    private function throwWith(string $password): \Throwable
    {
        try {
            $this->throwInner($password);
        } catch (\Throwable $e) {
            return $e;
        }

        self::fail('expected an exception');
    }

    private function throwInner(string $password): never
    {
        throw new \RuntimeException('login failed for length ' . strlen($password));
    }
}

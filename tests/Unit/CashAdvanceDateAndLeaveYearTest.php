<?php

declare(strict_types=1);

namespace Wbpms\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Wbpms\Domain\CashAdvance\CashAdvanceDate;
use Wbpms\Domain\Leave\LeaveDayCounter;

/**
 * Owner request 2026-11-22: HR computes payroll for past months from old
 * biometric data. (1) A cash advance had no date, so payroll took the ₱500
 * repayment from whichever run was computed next, even a run for a week
 * before the advance was given. (2) Sick leave approved in 2026 for 2025
 * dates used the 2026 credit. [REQ-023, REQ-024, PAY-031]
 */
final class CashAdvanceDateAndLeaveYearTest extends TestCase
{
    private static function today(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-11-22 10:00:00', new DateTimeZone('Asia/Manila'));
    }

    /** @return array<string, array{string, ?string, ?string}> */
    public static function dateCases(): array
    {
        return [
            'today is fine'                 => ['2026-11-22', '2020-01-01', null],
            'past month is fine'            => ['2026-06-05', '2020-01-01', null],
            'last year is fine'             => ['2025-03-14', '2020-01-01', null],
            'on hire date is fine'          => ['2026-06-01', '2026-06-01', null],
            'up to 31 days ahead is fine'   => ['2026-12-23', '2020-01-01', null],
            'more than 31 days ahead'       => ['2026-12-24', '2020-01-01', 'Cash advance date cannot be more than 31 days from today.'],
            'before hire date'              => ['2026-05-31', '2026-06-01', "Cash advance date cannot be before the employee's hire date (06/01/26)."],
            'hire date with time part'      => ['2026-05-31', '2026-06-01 00:00:00', "Cash advance date cannot be before the employee's hire date (06/01/26)."],
            'missing'                       => ['', '2020-01-01', 'Cash advance date is required (MM/DD/YY).'],
            'impossible date'               => ['2026-02-30', '2020-01-01', 'Cash advance date is required (MM/DD/YY).'],
            'wrong format'                  => ['11/22/26', '2020-01-01', 'Cash advance date is required (MM/DD/YY).'],
            'no hire date on file'          => ['2024-01-10', null, null],
        ];
    }

    #[DataProvider('dateCases')]
    public function testAdvanceDateRule(string $date, ?string $hireDate, ?string $expected): void
    {
        self::assertSame($expected, CashAdvanceDate::problem($date, $hireDate, self::today()));
    }

    public function testTodayUsesManilaDateNotUtc(): void
    {
        // 23:30 UTC on 11/22 is already 11/23 in Manila: 12/24 is then 31 days ahead.
        $lateUtc = new DateTimeImmutable('2026-11-22 23:30:00', new DateTimeZone('UTC'));
        self::assertNull(CashAdvanceDate::problem('2026-12-24', null, $lateUtc));
    }

    /** @return array<string, array{string, string, bool}> */
    public static function dueCases(): array
    {
        return [
            'advance inside the week is due'      => ['2026-06-05', '2026-06-11', true],
            'advance on period end is due'        => ['2026-06-11', '2026-06-11', true],
            'later run still repays it'           => ['2026-06-05', '2026-11-26', true],
            'week before the advance is not due'  => ['2026-11-20', '2026-06-11', false],
            'day after period end is not due'     => ['2026-06-12', '2026-06-11', false],
            'datetime values compare by date'     => ['2026-06-11', '2026-06-11 00:00:00', true],
        ];
    }

    #[DataProvider('dueCases')]
    public function testIsDueBy(string $advanceDate, string $periodEnd, bool $expected): void
    {
        self::assertSame($expected, CashAdvanceDate::isDueBy($advanceDate, $periodEnd));
    }

    /** @return array<string, array{string, string, ?int}> */
    public static function yearCases(): array
    {
        return [
            '2025 leave uses 2025 credit'   => ['2025-03-10', '2025-03-12', 2025],
            '2026 leave uses 2026 credit'   => ['2026-11-23', '2026-11-24', 2026],
            'last days of the year'         => ['2025-12-29', '2025-12-31', 2025],
            'crossing new year is refused'  => ['2025-12-30', '2026-01-02', null],
            'empty dates'                   => ['', '', null],
        ];
    }

    #[DataProvider('yearCases')]
    public function testLeaveCreditYear(string $start, string $end, ?int $expected): void
    {
        self::assertSame($expected, LeaveDayCounter::creditYear($start, $end));
    }

    /** Guards the wiring that a unit test cannot run without a database. */
    public function testPayrollAndRequestServiceUseTheDates(): void
    {
        $root = dirname(__DIR__, 2);
        $payroll = (string) file_get_contents($root . '/app/Application/PayrollService.php');
        self::assertStringContainsString('card.advance_date <= :period_end', $payroll);
        self::assertStringContainsString("pr.status IN ('Computed', 'PendingOwnerApproval', 'Returned')", $payroll);

        $requests = (string) file_get_contents($root . '/app/Application/RequestService.php');
        self::assertStringContainsString('(request_id, amount, advance_date)', $requests);
        self::assertMatchesRegularExpression(
            '/function debitLeaveLedger\([^)]*int \$year/s',
            $requests,
            'Leave must be debited from the credit of its own year.',
        );
        self::assertDoesNotMatchRegularExpression(
            '/function (debitLeaveLedger|assertLeaveBalance)\b[^{]*\{[^}]*date\(\'Y\'\)/s',
            $requests,
            'Leave debit and balance checks must not fall back to the current year.',
        );

        $migration = (string) file_get_contents($root . '/database/migrations/20261122000036_add_advance_date_to_cash_advance.php');
        self::assertStringContainsString('MODIFY advance_date DATE NOT NULL', $migration);
        self::assertStringContainsString('WHERE d.advance_date IS NULL', $migration);
    }
}

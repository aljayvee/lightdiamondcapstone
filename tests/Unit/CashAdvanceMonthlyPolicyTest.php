<?php

declare(strict_types=1);

namespace Wbpms\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Wbpms\Domain\CashAdvance\CashAdvanceMonthlyPolicy;

/**
 * Pure unit tests for monthly Cash Advance cap, remaining-paydays scaling,
 * prior-month unpaid balance blocking (Option A), and multi-advance weekly
 * allocation. [REQ-026, PAY-035]
 */
final class CashAdvanceMonthlyPolicyTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function firstPayDateCases(): array
    {
        return [
            'Friday starts new cutoff -> next Friday'       => ['2025-08-01', '2025-08-08'],
            'Monday inside Week 1 -> Friday of Week 1'      => ['2025-08-04', '2025-08-08'],
            'Thursday ends cutoff -> tomorrow Friday'       => ['2025-08-07', '2025-08-08'],
            'Last Thursday of Aug -> last Friday of Aug'    => ['2025-08-28', '2025-08-29'],
            'Last Friday of Aug crosses into Sept payday'   => ['2025-08-29', '2025-09-05'],
            'Month-end Sunday Aug 31 -> Sept 5 payday'      => ['2025-08-31', '2025-09-05'],
            'July 25 Friday -> Aug 1 first Friday payday'   => ['2025-07-25', '2025-08-01'],
        ];
    }

    #[DataProvider('firstPayDateCases')]
    public function testFirstPayDate(string $advanceDate, string $expectedPayDate): void
    {
        self::assertSame($expectedPayDate, CashAdvanceMonthlyPolicy::firstPayDate($advanceDate));
    }

    public function testPayMonthWindowsAreContiguousWithNoGapsOrOverlaps(): void
    {
        $aug = CashAdvanceMonthlyPolicy::payMonthWindow('2025-08-08');
        $sep = CashAdvanceMonthlyPolicy::payMonthWindow('2025-09-05');

        self::assertSame('2025-08', $aug['pay_month']);
        self::assertSame('2025-08-01', $aug['first_pay_date']);
        self::assertSame('2025-08-29', $aug['last_pay_date']);
        self::assertSame('2025-07-25', $aug['first_advance_date']);
        self::assertSame('2025-08-28', $aug['last_advance_date']);

        self::assertSame('2025-09', $sep['pay_month']);
        self::assertSame('2025-09-05', $sep['first_pay_date']);
        self::assertSame('2025-09-26', $sep['last_pay_date']);
        self::assertSame('2025-08-29', $sep['first_advance_date']);
        self::assertSame('2025-09-25', $sep['last_advance_date']);
    }

    /** @return array<string, array{string, float, bool, float, int}> */
    public static function weeklyScalingCases(): array
    {
        return [
            // 5-Friday month (August 2025: paydays 08/01, 08/08, 08/15, 08/22, 08/29)
            '5-Friday month Week 1 still capped at ₱2,000 (not ₱2,500)' => ['2025-07-28', 2000.0, true,  2000.0, 5],
            '5-Friday month Week 1 refuses ₱2,500'                      => ['2025-07-28', 2500.0, false, 2000.0, 5],
            'Aug 04 (4 paydays left) allows ₱2,000'                     => ['2025-08-04', 2000.0, true,  2000.0, 4],
            'Aug 11 (3 paydays left) allows ₱1,500'                     => ['2025-08-11', 1500.0, true,  1500.0, 3],
            'Aug 11 (3 paydays left) refuses ₱2,000'                    => ['2025-08-11', 2000.0, false, 1500.0, 3],
            'Aug 18 (2 paydays left) allows ₱1,000'                     => ['2025-08-18', 1000.0, true,  1000.0, 2],
            'Aug 18 (2 paydays left) refuses ₱1,000.01'                 => ['2025-08-18', 1000.01, false, 1000.0, 2],
            'Aug 25 (1 payday left) allows ₱500'                        => ['2025-08-25', 500.0,  true,  500.0,  1],
            'Aug 25 (1 payday left) refuses ₱2,000 (protects Week 4)'   => ['2025-08-25', 2000.0, false, 500.0,  1],
            // Month-boundary date crosses cleanly into September (4 paydays)
            'Aug 29 crosses to Sept (4 paydays left) allows ₱2,000'     => ['2025-08-29', 2000.0, true,  2000.0, 4],
            'Aug 31 crosses to Sept (4 paydays left) allows ₱2,000'     => ['2025-08-31', 2000.0, true,  2000.0, 4],
        ];
    }

    #[DataProvider('weeklyScalingCases')]
    public function testWeeklyScalingByRemainingPaydays(
        string $advanceDate,
        float $requested,
        bool $expectedAllowed,
        float $expectedMax,
        int $expectedPaydays,
    ): void {
        $res = CashAdvanceMonthlyPolicy::evaluate($requested, $advanceDate);

        self::assertSame($expectedAllowed, $res['allowed'], (string) ($res['message'] ?? ''));
        self::assertSame($expectedMax, $res['max_allowed']);
        self::assertSame($expectedPaydays, $res['remaining_paydays']);
        if ($expectedAllowed) {
            self::assertNull($res['message']);
        } else {
            self::assertNotNull($res['message']);
        }
    }

    public function testApprovedPaydaysReduceRemainingCapacityAndBlockWhenNoneLeft(): void
    {
        // Aug 04 normally has 4 paydays (08/08, 08/15, 08/22, 08/29).
        // If 08/08 and 08/15 are already Approved, only 2 open paydays remain -> max ₱1,000.
        $res = CashAdvanceMonthlyPolicy::evaluate(
            1500.0,
            '2025-08-04',
            0.0,
            0.0,
            0.0,
            ['2025-08-08', '2025-08-15'],
        );
        self::assertFalse($res['allowed']);
        self::assertSame(2, $res['remaining_paydays']);
        self::assertSame(1000.0, $res['max_allowed']);

        // When all 4 remaining paydays are already Approved -> 0 open paydays, refused.
        $closed = CashAdvanceMonthlyPolicy::evaluate(
            300.0,
            '2025-08-04',
            0.0,
            0.0,
            0.0,
            ['2025-08-08', '2025-08-15', '2025-08-22', '2025-08-29'],
        );
        self::assertFalse($closed['allowed']);
        self::assertSame(0, $closed['remaining_paydays']);
        self::assertStringContainsString('already approved', (string) $closed['message']);
    }

    public function testMonthlyCapAndStackingWithinSameMonth(): void
    {
        // Employee took ₱1,000 in Week 1 and already paid it off (unpaid = 0).
        // In Week 3 (2 paydays left = ₱1,000 capacity), ₱1,000 more reaches ₱2,000 monthly max -> allowed.
        $ok = CashAdvanceMonthlyPolicy::evaluate(1000.0, '2025-08-18', 1000.0, 0.0);
        self::assertTrue($ok['allowed']);
        self::assertSame(1000.0, $ok['max_allowed']);

        // Same month, if Week 1's ₱1,000 is STILL unpaid with 2 paydays left -> capacity is 1000 - 1000 = 0 -> refused.
        $blockedCap = CashAdvanceMonthlyPolicy::evaluate(500.0, '2025-08-18', 1000.0, 1000.0);
        self::assertFalse($blockedCap['allowed']);
        self::assertSame(0.0, $blockedCap['max_allowed']);
        self::assertStringContainsString('already unpaid or pending', (string) $blockedCap['message']);

        // Week 1: already requested ₱1,500 (paid or pending); trying ₱600 exceeds ₱2,000 monthly total -> refused.
        $blockedMonthly = CashAdvanceMonthlyPolicy::evaluate(600.0, '2025-08-04', 1500.0, 500.0);
        self::assertFalse($blockedMonthly['allowed']);
        self::assertSame(500.0, $blockedMonthly['max_allowed']);
        self::assertStringContainsString('₱2,000.00 monthly limit', (string) $blockedMonthly['message']);
    }

    public function testPriorMonthUnpaidBalanceBlocksNewAdvanceUnderOptionA(): void
    {
        $blocked = CashAdvanceMonthlyPolicy::evaluate(
            500.0,
            '2025-09-01',
            0.0,
            0.0,
            500.0,
            [],
            true,
        );
        self::assertFalse($blocked['allowed']);
        self::assertSame(0.0, $blocked['max_allowed']);
        self::assertStringContainsString('prior-month balance of ₱500.00', (string) $blocked['message']);
    }

    public function testAllocateWeeklyRepaymentAcrossMultipleAdvancesAndNetPayFloor(): void
    {
        // Two active advances: ₱300 + ₱500 with plenty of available pay -> takes ₱300 + ₱200 = ₱500.
        $alloc = CashAdvanceMonthlyPolicy::allocateWeeklyRepayment(
            [
                ['history_id' => 10, 'owed' => 300.0],
                ['history_id' => 11, 'owed' => 500.0],
            ],
            1800.0,
        );
        self::assertSame([
            ['history_id' => 10, 'amount' => 300.0],
            ['history_id' => 11, 'amount' => 200.0],
        ], $alloc);

        // Low available pay (₱350 after statutory deductions) -> takes ₱300 + ₱50 = ₱350 (never negative net pay).
        $capped = CashAdvanceMonthlyPolicy::allocateWeeklyRepayment(
            [
                ['history_id' => 10, 'owed' => 300.0],
                ['history_id' => 11, 'owed' => 500.0],
            ],
            350.0,
        );
        self::assertSame([
            ['history_id' => 10, 'amount' => 300.0],
            ['history_id' => 11, 'amount' => 50.0],
        ], $capped);

        // Zero available pay -> empty allocations.
        self::assertSame([], CashAdvanceMonthlyPolicy::allocateWeeklyRepayment(
            [['history_id' => 10, 'owed' => 300.0]],
            0.0,
        ));
    }
}

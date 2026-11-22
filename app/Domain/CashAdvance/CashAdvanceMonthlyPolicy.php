<?php

declare(strict_types=1);

namespace Wbpms\Domain\CashAdvance;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Monthly Cash Advance eligibility and weekly allocation policy.
 *
 * Business rules:
 *   1. Maximum ₱2,000.00 total Cash Advance per payroll month (across all
 *      non-cancelled requests whose first payday falls in that month).
 *   2. Fixed ₱500.00/week repayment so Week 4 net pay is never wiped out on
 *      top of month-end statutory contributions (SSS, PhilHealth, Pag-IBIG).
 *   3. To ensure repayment completes within the same payroll month, eligibility
 *      scales to ₱500.00 × remaining open Friday paydays in that payday month
 *      (capped at ₱2,000.00, minus any unpaid/pending balance in that month).
 *   4. New Cash Advances are blocked while an unpaid balance from an earlier
 *      payroll month remains active (Option A).
 *   5. Multiple due advances may share a single week's ₱500.00 budget (oldest
 *      first), capped by available net pay so take-home pay never goes below ₱0.
 *
 * [REQ-026, PAY-035]
 */
final class CashAdvanceMonthlyPolicy
{
    public const MONTHLY_CAP = 2000.00;
    public const WEEKLY_INSTALLMENT = 500.00;

    /**
     * First Friday pay_date of the Friday–Thursday cutoff containing $advanceDate.
     */
    public static function firstPayDate(string $advanceDate): string
    {
        $date = self::parseDate($advanceDate);
        // DateTime::format('w'): Sun=0 .. Sat=6. Days elapsed since Friday.
        $daysSinceFriday = ((int) $date->format('w') + 2) % 7;
        $periodStart = $date->modify('-' . $daysSinceFriday . ' days');

        return $periodStart->modify('+7 days')->format('Y-m-d');
    }

    /**
     * Exact payday-month boundaries and contiguous advance_date window whose
     * Friday–Thursday cutoffs pay out in $payDate's calendar month.
     *
     * @return array{
     *     pay_month: string,
     *     pay_month_label: string,
     *     first_pay_date: string,
     *     last_pay_date: string,
     *     first_advance_date: string,
     *     last_advance_date: string
     * }
     */
    public static function payMonthWindow(string $payDate): array
    {
        $date = self::parseDate($payDate);
        $tz = new DateTimeZone('Asia/Manila');
        $payMonth = $date->format('Y-m');
        $monthStart = new DateTimeImmutable($payMonth . '-01', $tz);
        $monthEnd = $monthStart->modify('last day of this month');

        // ISO N: Mon=1 .. Fri=5 .. Sun=7.
        $daysToFirstFriday = (5 - (int) $monthStart->format('N') + 7) % 7;
        $firstFriday = $monthStart->modify('+' . $daysToFirstFriday . ' days');

        $daysSinceLastFriday = ((int) $monthEnd->format('N') - 5 + 7) % 7;
        $lastFriday = $monthEnd->modify('-' . $daysSinceLastFriday . ' days');

        return [
            'pay_month'          => $payMonth,
            'pay_month_label'    => $monthStart->format('F Y'),
            'first_pay_date'     => $firstFriday->format('Y-m-d'),
            'last_pay_date'      => $lastFriday->format('Y-m-d'),
            'first_advance_date' => $firstFriday->modify('-7 days')->format('Y-m-d'),
            'last_advance_date'  => $lastFriday->modify('-1 day')->format('Y-m-d'),
        ];
    }

    /**
     * Friday paydays from firstPayDate($advanceDate) through the last Friday of
     * that payday month, excluding any paydays whose payroll run is Approved.
     *
     * @param list<string> $approvedPayDates Y-m-d pay_date values already Approved
     * @return list<string> ascending Y-m-d Friday pay_dates
     */
    public static function remainingOpenPayDates(string $advanceDate, array $approvedPayDates = []): array
    {
        $firstPay = self::firstPayDate($advanceDate);
        $window = self::payMonthWindow($firstPay);

        $approved = [];
        foreach ($approvedPayDates as $d) {
            $approved[substr($d, 0, 10)] = true;
        }

        $tz = new DateTimeZone('Asia/Manila');
        $cur = new DateTimeImmutable($firstPay, $tz);
        $last = new DateTimeImmutable($window['last_pay_date'], $tz);

        $open = [];
        while ($cur <= $last) {
            $ymd = $cur->format('Y-m-d');
            if (!isset($approved[$ymd])) {
                $open[] = $ymd;
            }
            $cur = $cur->modify('+7 days');
        }

        return $open;
    }

    /**
     * Evaluate whether a Cash Advance request is eligible under the monthly cap
     * and same-month payoff rules.
     *
     * @param list<string> $approvedPayDates
     * @return array{
     *     allowed: bool,
     *     max_allowed: float,
     *     remaining_paydays: int,
     *     pay_month: string,
     *     pay_month_label: string,
     *     message: ?string
     * }
     */
    public static function evaluate(
        float $requestedAmount,
        string $advanceDate,
        float $alreadyRequestedInMonth = 0.0,
        float $unpaidAndPendingInMonth = 0.0,
        float $priorMonthUnpaid = 0.0,
        array $approvedPayDates = [],
        bool $blockOnPriorMonthUnpaid = true,
    ): array {
        $requested = round($requestedAmount, 2);
        $alreadyRequested = max(0.0, round($alreadyRequestedInMonth, 2));
        $unpaidInMonth = max(0.0, round($unpaidAndPendingInMonth, 2));
        $priorUnpaid = max(0.0, round($priorMonthUnpaid, 2));

        $firstPay = self::firstPayDate($advanceDate);
        $window = self::payMonthWindow($firstPay);
        $openPayDates = self::remainingOpenPayDates($advanceDate, $approvedPayDates);
        $openCount = count($openPayDates);

        $base = [
            'remaining_paydays' => $openCount,
            'pay_month'         => $window['pay_month'],
            'pay_month_label'   => $window['pay_month_label'],
        ];

        if ($requested <= 0.0) {
            return $base + [
                'allowed'     => false,
                'max_allowed' => 0.0,
                'message'     => 'Cash advance amount must be a positive number.',
            ];
        }

        if ($blockOnPriorMonthUnpaid && $priorUnpaid > 0.0) {
            return $base + [
                'allowed'     => false,
                'max_allowed' => 0.0,
                'message'     => sprintf(
                    'Cannot file a new cash advance while a prior-month balance of ₱%s remains unpaid. '
                    . 'Wait for payroll to settle the previous balance first.',
                    number_format($priorUnpaid, 2),
                ),
            ];
        }

        if ($openCount === 0) {
            return $base + [
                'allowed'     => false,
                'max_allowed' => 0.0,
                'message'     => sprintf(
                    'No open payroll weeks remain in %s for this cash advance date (all paydays in that month are already approved).',
                    $window['pay_month_label'],
                ),
            ];
        }

        $monthlyRemaining = max(0.0, round(self::MONTHLY_CAP - $alreadyRequested, 2));
        $payoffCapacity = round($openCount * self::WEEKLY_INSTALLMENT, 2);
        $effectiveOutstanding = $blockOnPriorMonthUnpaid
            ? $unpaidInMonth
            : round($unpaidInMonth + $priorUnpaid, 2);
        $capacityRemaining = max(0.0, round($payoffCapacity - $effectiveOutstanding, 2));
        $maxAllowed = round(min($monthlyRemaining, $capacityRemaining), 2);

        if ($requested <= $maxAllowed + 0.0001) {
            return $base + [
                'allowed'     => true,
                'max_allowed' => $maxAllowed,
                'message'     => null,
            ];
        }

        if ($monthlyRemaining <= $capacityRemaining) {
            $message = $monthlyRemaining <= 0.0
                ? sprintf(
                    'Monthly cash advance limit of ₱%s for %s has already been reached (₱%s requested/approved).',
                    number_format(self::MONTHLY_CAP, 2),
                    $window['pay_month_label'],
                    number_format($alreadyRequested, 2),
                )
                : sprintf(
                    'Cash advance exceeds the ₱%s monthly limit for %s. Already used: ₱%s; remaining eligible amount: ₱%s.',
                    number_format(self::MONTHLY_CAP, 2),
                    $window['pay_month_label'],
                    number_format($alreadyRequested, 2),
                    number_format($monthlyRemaining, 2),
                );
        } elseif ($effectiveOutstanding > 0.0) {
            $message = sprintf(
                'Cash advance cannot exceed ₱%s for %s: %d open payday(s) remain (₱%s capacity at ₱%s/week) '
                . 'with ₱%s already unpaid or pending.',
                number_format($capacityRemaining, 2),
                $window['pay_month_label'],
                $openCount,
                number_format($payoffCapacity, 2),
                number_format(self::WEEKLY_INSTALLMENT, 2),
                number_format($effectiveOutstanding, 2),
            );
        } else {
            $message = sprintf(
                'Cash advance for this week cannot exceed ₱%s (%d open payday(s) left in %s × ₱%s/week '
                . 'so it is repaid within the same month).',
                number_format($capacityRemaining, 2),
                $openCount,
                $window['pay_month_label'],
                number_format(self::WEEKLY_INSTALLMENT, 2),
            );
        }

        return $base + [
            'allowed'     => false,
            'max_allowed' => $maxAllowed,
            'message'     => $message,
        ];
    }

    /**
     * Allocate up to min($weeklyCap, $availableForCa) across one or more due
     * advances (in the supplied order — oldest first).
     *
     * @param list<array{history_id:int,owed:float}> $dueAdvances
     * @return list<array{history_id:int,amount:float}>
     */
    public static function allocateWeeklyRepayment(
        array $dueAdvances,
        float $availableForCa,
        float $weeklyCap = self::WEEKLY_INSTALLMENT,
    ): array {
        $budget = round(min($weeklyCap, max(0.0, $availableForCa)), 2);
        if ($budget <= 0.0) {
            return [];
        }

        $allocations = [];
        foreach ($dueAdvances as $adv) {
            if ($budget <= 0.0) {
                break;
            }
            $owed = round((float) $adv['owed'], 2);
            if ($owed <= 0.0) {
                continue;
            }
            $take = round(min($owed, $budget), 2);
            if ($take > 0.0) {
                $allocations[] = [
                    'history_id' => (int) $adv['history_id'],
                    'amount'     => $take,
                ];
                $budget = round($budget - $take, 2);
            }
        }

        return $allocations;
    }

    private static function parseDate(string $ymd): DateTimeImmutable
    {
        $clean = substr(trim($ymd), 0, 10);
        $tz = new DateTimeZone('Asia/Manila');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $clean, $tz);
        if ($date === false || $date->format('Y-m-d') !== $clean) {
            throw new InvalidArgumentException('Invalid date format; expected YYYY-MM-DD.');
        }

        return $date;
    }
}

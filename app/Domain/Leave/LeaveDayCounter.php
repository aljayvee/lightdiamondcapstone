<?php

declare(strict_types=1);

namespace Wbpms\Domain\Leave;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Which days of a leave request are charged to the sick-leave credit.
 *
 * Payroll pays approved leave only on scheduled working days that are not
 * active holidays (PayrollService::attendancePolicyForPeriod). A rest day
 * (Saturday for the SDA client) or a holiday inside the range is not paid,
 * so it must not use up a credit either. Days with no schedule assignment
 * are not working days for payroll, so they are not charged. A day the
 * employee already has qualifying attendance for is paid as work, not leave,
 * so it is not charged either.
 */
final class LeaveDayCounter
{
    /**
     * @param array<string,bool> $scheduledDates Y-m-d => true for a working day, false for a rest day.
     * @param array<string,mixed> $holidayDates  Y-m-d keys of active holidays.
     * @param array<string,mixed> $workedDates   Y-m-d keys with qualifying attendance (paid as work).
     * @return list<string> Chargeable dates in order.
     */
    public function chargeableDates(
        string $startDate,
        string $endDate,
        array $scheduledDates,
        array $holidayDates,
        array $workedDates = [],
    ): array {
        $timezone = new DateTimeZone('Asia/Manila');
        $start = DateTimeImmutable::createFromFormat('!Y-m-d', $startDate, $timezone);
        $end   = DateTimeImmutable::createFromFormat('!Y-m-d', $endDate, $timezone);
        if ($start === false || $end === false || $end < $start
            || $start->format('Y-m-d') !== $startDate || $end->format('Y-m-d') !== $endDate) {
            return [];
        }

        $dates = [];
        for ($day = $start; $day <= $end; $day = $day->modify('+1 day')) {
            $date = $day->format('Y-m-d');
            if (($scheduledDates[$date] ?? false) === true
                && !array_key_exists($date, $holidayDates)
                && !array_key_exists($date, $workedDates)) {
                $dates[] = $date;
            }
        }

        return $dates;
    }

    /**
     * Credit still free for a new request: ledger balance minus the days of
     * the employee's other leave requests that are waiting for approval.
     */
    public function availableCredit(float $ledgerBalance, float $openRequestDays): float
    {
        return round($ledgerBalance - max(0.0, $openRequestDays), 2);
    }
}

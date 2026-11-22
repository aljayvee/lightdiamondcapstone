<?php

declare(strict_types=1);

namespace Wbpms\Domain\Requests;

/**
 * Builds the sick-leave and cash-advance figures the HR New Request form
 * shows for the chosen employee — the same figures the employee sees on
 * My Requests. [REQ-025]
 *
 * Sick-leave credit is per calendar year (a leave uses the credit of its own
 * dates), so the form gets one balance per year and picks the year of the
 * From date. An employee with no credit row for a year has the untouched
 * 4-day grant (ADR-0001); nothing is written to show that.
 */
final class RequestFormBalances
{
    public const SICK_LEAVE_DAYS = 4.0;
    public const CASH_ADVANCE_MAX = 2000.0;

    /**
     * Years to load: this year, last year (past-month payroll is still being
     * filed) and every year that already has a credit on file.
     *
     * @param list<int> $yearsOnFile
     * @return list<int> ascending, no duplicates
     */
    public static function years(int $currentYear, array $yearsOnFile): array
    {
        $years = array_values(array_unique(array_merge([$currentYear - 1, $currentYear], array_map('intval', $yearsOnFile))));
        $years = array_values(array_filter($years, static fn (int $y): bool => $y >= 2000 && $y <= 2100));
        sort($years);

        return $years;
    }

    /**
     * @param array<int, list<array{employee_id:int, entitled_days:float, balance:float}>> $leaveRowsByYear
     * @param array<int, float> $cashAdvanceByEmployee employee_id => unpaid balance
     * @return array{
     *     currentYear:int,
     *     sickLeaveDays:float,
     *     cashAdvanceMax:float,
     *     leave: array<int, array<int, array{balance:float, entitled:float}>>,
     *     cashAdvance: array<int, float>
     * }
     */
    public static function payload(int $currentYear, array $leaveRowsByYear, array $cashAdvanceByEmployee): array
    {
        $leave = [];
        foreach ($leaveRowsByYear as $year => $rows) {
            foreach ($rows as $row) {
                $leave[(int) $year][(int) $row['employee_id']] = [
                    'balance'  => round((float) $row['balance'], 2),
                    'entitled' => round((float) $row['entitled_days'], 2),
                ];
            }
        }

        $cash = [];
        foreach ($cashAdvanceByEmployee as $employeeId => $amount) {
            $amount = round((float) $amount, 2);
            if ($amount > 0) {
                $cash[(int) $employeeId] = $amount;
            }
        }

        return [
            'currentYear'    => $currentYear,
            'sickLeaveDays'  => self::SICK_LEAVE_DAYS,
            'cashAdvanceMax' => self::CASH_ADVANCE_MAX,
            'leave'          => $leave,
            'cashAdvance'    => $cash,
        ];
    }

    /**
     * Sick-leave balance for one employee and year; the full grant when
     * nothing is on file.
     *
     * @param array{leave: array<int, array<int, array{balance:float, entitled:float}>>} $payload
     * @return array{balance:float, entitled:float}
     */
    public static function leaveFor(array $payload, int $employeeId, int $year): array
    {
        return $payload['leave'][$year][$employeeId]
            ?? ['balance' => self::SICK_LEAVE_DAYS, 'entitled' => self::SICK_LEAVE_DAYS];
    }
}

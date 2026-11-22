<?php

declare(strict_types=1);

namespace Wbpms\Domain\Payroll;

/**
 * Every Philippine year has national holidays. A payroll period in a year
 * with no holidays on file means the calendar was never loaded for that
 * year, and Compute would silently pay holidays as ordinary days.
 *
 * Found 2026-11-22: run 35 (08/15–08/21/25) paid Aug 21, 2025 (Ninoy Aquino
 * Day) at 100 % instead of 130 % because only the 2026 calendar existed.
 * [PAY-034]
 */
final class HolidayCalendarCoverage
{
    /**
     * Years touched by the period that have no active holiday on file.
     *
     * @param list<int> $yearsWithHolidays
     * @return list<int>
     */
    public static function missingYears(string $periodStart, string $periodEnd, array $yearsWithHolidays): array
    {
        $from = (int) substr($periodStart, 0, 4);
        $to   = (int) substr($periodEnd, 0, 4);
        if ($from < 1 || $to < $from) {
            return [];
        }

        $missing = [];
        for ($year = $from; $year <= $to; $year++) {
            if (!in_array($year, $yearsWithHolidays, true)) {
                $missing[] = $year;
            }
        }

        return $missing;
    }

    /**
     * Message shown to HR when Compute is refused.
     *
     * @param list<int> $missingYears
     */
    public static function message(array $missingYears): string
    {
        $years = implode(' and ', array_map('strval', $missingYears));

        return "Payroll was not computed: there are no holidays on file for {$years}. "
            . "Add the {$years} holidays in Settings → Holidays first, so holiday pay is computed correctly.";
    }
}

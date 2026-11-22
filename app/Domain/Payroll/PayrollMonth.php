<?php

declare(strict_types=1);

namespace Wbpms\Domain\Payroll;

use DateTimeImmutable;
use DateTimeZone;

/**
 * A payroll month (YYYY-MM) chosen in Settings → Payroll Periods.
 *
 * Found 2026-11-22: after "Generate Periods" for August 2025 the page came
 * back showing November 2026 (the month box defaulted to the current month
 * and the newest periods filled the first page of the list), so HR thought
 * the months had been changed back. The generated month now travels in the
 * URL (?month=YYYY-MM) to Settings and to New Payroll Run. [PAY-033]
 */
final class PayrollMonth
{
    /** The month if it is a real YYYY-MM between 2000 and 2100, else null. */
    public static function parse(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        if (preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $value, $m) !== 1) {
            return null;
        }
        $year = (int) $m[1];
        if ($year < 2000 || $year > 2100) {
            return null;
        }

        return $value;
    }

    /** "August 2025" for "2025-08". */
    public static function label(string $month): string
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $month . '-01', new DateTimeZone('Asia/Manila'));

        return $date !== false ? $date->format('F Y') : $month;
    }

    /**
     * Whether a cutoff (period_start..period_end) has at least one day in the
     * month: the same rule Generate Periods uses to create cutoffs, so a
     * cutoff that crosses a month boundary belongs to both months.
     */
    public static function overlaps(string $month, string $periodStart, string $periodEnd): bool
    {
        $first = $month . '-01';
        $last  = (new DateTimeImmutable($first))->modify('last day of this month')->format('Y-m-d');

        return substr($periodStart, 0, 10) <= $last && substr($periodEnd, 0, 10) >= $first;
    }

    /**
     * The cutoffs that overlap the month, in the order given.
     *
     * @param list<array<string,mixed>> $periods rows with period_start / period_end
     * @return list<array<string,mixed>>
     */
    public static function filter(string $month, array $periods): array
    {
        return array_values(array_filter(
            $periods,
            static fn (array $p): bool => self::overlaps($month, (string) $p['period_start'], (string) $p['period_end']),
        ));
    }
}

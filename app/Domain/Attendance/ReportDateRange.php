<?php

declare(strict_types=1);

namespace Wbpms\Domain\Attendance;

use DateTimeImmutable;

/**
 * The date range a report runs on, from what HR typed. [ATT-031]
 *
 * The From date HR picks is never moved. The frequency report used to cap a
 * long range by moving From forward (08/01/25 → 11/21/25 when To was today),
 * and the page's filter memory then saved that changed date, so the report
 * kept "jumping" to November 2025. A range that is too long now keeps From
 * and ends earlier, with a notice that says so.
 */
final class ReportDateRange
{
    /** About two years: enough for a past month up to today. */
    public const MAX_DAYS = 731;

    /**
     * @return array{from:string, to:string, notice:?string}
     */
    public static function resolve(?string $from, ?string $to, string $defaultFrom, string $defaultTo, int $maxDays = self::MAX_DAYS): array
    {
        $from = trim((string) $from);
        $to   = trim((string) $to);
        if ($from === '' && $to === '') {
            return ['from' => $defaultFrom, 'to' => $defaultTo, 'notice' => null];
        }
        if (!self::isIsoDate($from) || !self::isIsoDate($to)) {
            return ['from' => $defaultFrom, 'to' => $defaultTo, 'notice' => 'Those dates could not be read, so the report shows the default period.'];
        }
        if ($from > $to) {
            return ['from' => $defaultFrom, 'to' => $defaultTo, 'notice' => 'The From date was after the To date, so the report shows the default period.'];
        }

        $start = new DateTimeImmutable($from);
        if ((int) $start->diff(new DateTimeImmutable($to))->days > $maxDays) {
            $end = $start->modify('+' . $maxDays . ' days')->format('Y-m-d');

            return [
                'from'   => $from,
                'to'     => $end,
                'notice' => sprintf(
                    'A report can cover up to %d days, so it ends on %s instead of %s.',
                    $maxDays + 1,
                    self::display($end),
                    self::display($to),
                ),
            ];
        }

        return ['from' => $from, 'to' => $to, 'notice' => null];
    }

    public static function isIsoDate(string $value): bool
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $parsed !== false && $parsed->format('Y-m-d') === $value;
    }

    private static function display(string $isoDate): string
    {
        return (new DateTimeImmutable($isoDate))->format('m/d/y');
    }
}

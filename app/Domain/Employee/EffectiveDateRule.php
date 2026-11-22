<?php

declare(strict_types=1);

namespace Wbpms\Domain\Employee;

/**
 * Effective-dated records (branch assignment, salary) close the current row
 * the day before the new one starts, and the database refuses a period that
 * ends on or before it begins. So a new record must start strictly after the
 * current one's start date (REQ015, REQ062/063).
 *
 * Before this rule was shown up front, HR could pick "today" in the calendar
 * and only learn after saving that it was refused, with an ISO date in the
 * message.
 */
final class EffectiveDateRule
{
    /** Earliest allowed start (YYYY-MM-DD), or null when there is no current record. */
    public static function earliestAfter(?string $currentStart): ?string
    {
        if ($currentStart === null || $currentStart === '') {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', substr($currentStart, 0, 10));

        return $date === false ? null : $date->modify('+1 day')->format('Y-m-d');
    }

    /** Default for the date field: today, or the earliest allowed date if that is later. */
    public static function defaultDate(?string $currentStart, string $today): string
    {
        $earliest = self::earliestAfter($currentStart);

        return ($earliest !== null && $earliest > $today) ? $earliest : $today;
    }

    /** "The transfer date must be after 12/01/26 (when the current branch assignment starts). The earliest date you can use is 12/02/26." */
    public static function message(string $what, string $currentRecord, string $currentStart): string
    {
        $earliest = self::earliestAfter($currentStart) ?? $currentStart;

        return sprintf(
            'The %s must be after %s (when the %s starts). The earliest date you can use is %s.',
            $what,
            self::short($currentStart),
            $currentRecord,
            self::short($earliest),
        );
    }

    private static function short(string $iso): string
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', substr($iso, 0, 10));

        return $date === false ? $iso : $date->format('m/d/y');
    }
}

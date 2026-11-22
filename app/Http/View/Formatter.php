<?php

declare(strict_types=1);

namespace Wbpms\Http\View;

use DateTimeInterface;

final class Formatter
{
    public static function date(DateTimeInterface|string|null $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }
        $date = is_string($value) ? new \DateTimeImmutable($value) : $value;
        return $date->format('m/d/y');
    }

    public static function time(DateTimeInterface|string|null $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }
        $date = is_string($value) ? new \DateTimeImmutable($value) : $value;
        return $date->format('H:i');
    }

    /** Return a database time value in the HH:MM format accepted by forms. */
    public static function inputTime(DateTimeInterface|string|null $value): string
    {
        return $value === null || $value === '' ? '' : self::time($value);
    }

    public static function dateTime(DateTimeInterface|string|null $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }
        $date = is_string($value) ? new \DateTimeImmutable($value) : $value;
        return $date->format('m/d/y H:i');
    }

    /**
     * A timestamp stored in UTC (e.g. payroll_run.created_at, written with
     * utcNow()) shown in Manila time as MM/DD/YY HH:MM.
     */
    public static function utcDateTime(?string $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }
        return (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))
            ->setTimezone(new \DateTimeZone('Asia/Manila'))
            ->format('m/d/y H:i');
    }

    /** Minutes as "47h 30m" (or "45m" under an hour) for attendance totals. */
    public static function hoursMinutes(int $minutes): string
    {
        $minutes = max(0, $minutes);
        $hours   = intdiv($minutes, 60);
        $rest    = $minutes % 60;

        return $hours === 0 ? $rest . 'm' : sprintf('%dh %02dm', $hours, $rest);
    }

    /** A quantity such as days without trailing zeros: 6, 5.5, 0.25. */
    public static function quantity(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    /**
     * Readable label for a stored code: "CashAdvance" → "Cash Advance",
     * "HRHead" → "HR Head", "SpecialNonWorking" → "Special Non-Working".
     * Plain words come back unchanged. Not an escaper: wrap it in escape().
     */
    public static function label(?string $code): string
    {
        $code = trim((string) $code);
        if ($code === '') {
            return '—';
        }
        $known = [
            'HRHead'                   => 'HR Head',
            'BusinessOwner'            => 'Business Owner',
            'CashAdvance'              => 'Cash Advance',
            'SpecialNonWorking'        => 'Special Non-Working',
            'SpecialNonWorkingHoliday' => 'Special Non-Working Holiday',
            'PagIBIG'                  => 'Pag-IBIG',
            'PhilHealth'               => 'PhilHealth',
            'PendingOwnerApproval'     => 'Pending Owner Approval',
            'ThirteenthMonth'          => '13th Month',
            'FridayThursday'           => 'Friday–Thursday',
            'LegacyFridayThursday'     => 'Friday–Thursday (legacy)',
            'SundayFriday'             => 'Sunday–Friday',
        ];
        if (isset($known[$code])) {
            return $known[$code];
        }
        if (str_contains($code, '_')) {
            return ucwords(strtolower(str_replace('_', ' ', $code)));
        }

        // Split CamelCase, keeping acronyms together: "ReviewRequired" → "Review Required".
        return (string) preg_replace('/(?<=[a-z])(?=[A-Z])|(?<=[A-Z])(?=[A-Z][a-z])/', ' ', $code);
    }

    public static function escape(string|int|float|null $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

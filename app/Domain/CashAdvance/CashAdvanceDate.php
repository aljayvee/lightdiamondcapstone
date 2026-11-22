<?php

declare(strict_types=1);

namespace Wbpms\Domain\CashAdvance;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The date a cash advance is given (cash_advance_request_detail.advance_date).
 *
 * Payroll is often computed for past weeks from old biometric data, so the
 * repayment must follow the advance's own date, not the day it was approved:
 * a payroll run only deducts an advance whose date is on or before the run's
 * period end. An advance dated in June is repaid from the June weeks on; a
 * June run computed today does not take an advance given in November.
 * [REQ-023, PAY-031]
 */
final class CashAdvanceDate
{
    /** How far ahead an advance may be dated (an employee asking for next payday). */
    public const MAX_DAYS_AHEAD = 31;

    /**
     * Why the date cannot be used, or null when it is fine. Messages are safe
     * to show to HR and employees.
     */
    public static function problem(string $advanceDate, ?string $hireDate, DateTimeImmutable $today): ?string
    {
        $timezone = new DateTimeZone('Asia/Manila');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $advanceDate, $timezone);
        if ($date === false || $date->format('Y-m-d') !== $advanceDate) {
            return 'Cash advance date is required (MM/DD/YY).';
        }

        $todayDate = DateTimeImmutable::createFromFormat('!Y-m-d', $today->setTimezone($timezone)->format('Y-m-d'), $timezone);
        if ($todayDate !== false && $date > $todayDate->modify('+' . self::MAX_DAYS_AHEAD . ' days')) {
            return sprintf(
                'Cash advance date cannot be more than %d days from today.',
                self::MAX_DAYS_AHEAD,
            );
        }

        if ($hireDate !== null && $hireDate !== '' && $advanceDate < substr($hireDate, 0, 10)) {
            $hire = DateTimeImmutable::createFromFormat('!Y-m-d', substr($hireDate, 0, 10), $timezone);
            return 'Cash advance date cannot be before the employee\'s hire date ('
                . ($hire !== false ? $hire->format('m/d/y') : substr($hireDate, 0, 10)) . ').';
        }

        return null;
    }

    /** Whether a payroll run ending on $periodEnd may deduct this advance. */
    public static function isDueBy(string $advanceDate, string $periodEnd): bool
    {
        return substr($advanceDate, 0, 10) <= substr($periodEnd, 0, 10);
    }
}

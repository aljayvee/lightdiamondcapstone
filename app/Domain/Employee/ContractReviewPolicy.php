<?php

declare(strict_types=1);

namespace Wbpms\Domain\Employee;

use DateTimeImmutable;

/**
 * Contract review date rules for non-regular employees (pure; no I/O).
 *
 * Contractual and Probationary employees carry a review date: the day HR
 * decides to regularise, renew, or end the contract. The HR Head is notified
 * from DUE_SOON_DAYS before that date until HR acts (changes the type to
 * Regular, which clears the date, or sets a new review date).
 */
final class ContractReviewPolicy
{
    /** Employee types that need a contract review date. */
    public const TYPES = ['Contractual', 'Probationary'];

    /** Default review period after the start date (PH probation is up to 6 months). */
    public const DEFAULT_MONTHS = 6;

    /** HR Head is reminded this many days before the review date. */
    public const DUE_SOON_DAYS = 14;

    /** Upper bound to catch typing mistakes in the year (e.g. 2062). */
    public const MAX_YEARS_AHEAD = 5;

    public static function needsReview(string $employeeType): bool
    {
        return in_array($employeeType, self::TYPES, true);
    }

    /** Start date + 6 months, used to pre-fill the form. */
    public static function defaultDate(string $startDate): ?string
    {
        $start = self::parse($startDate);

        return $start?->modify('+' . self::DEFAULT_MONTHS . ' months')->format('Y-m-d');
    }

    /**
     * Validate the posted review date for the chosen employee type.
     *
     * @param string $startDate Hire date (add/edit) or rehire date; '' when unknown
     * @return string|null Error message, or null when valid / not needed
     */
    public static function validate(
        string $employeeType,
        string $reviewDate,
        string $startDate,
        DateTimeImmutable $today,
    ): ?string {
        if (!self::needsReview($employeeType)) {
            return null;
        }
        if ($reviewDate === '') {
            return sprintf('Contract review date is required for %s employees.', $employeeType);
        }
        $review = self::parse($reviewDate);
        if ($review === null) {
            return 'Contract review date must be a valid date.';
        }
        $start = self::parse($startDate);
        if ($start !== null && $review <= $start) {
            return sprintf('Contract review date must be after the start date (%s).', $start->format('m/d/y'));
        }
        $base = self::parse($today->format('Y-m-d'));
        if ($base !== null && $start !== null && $start > $base) {
            $base = $start;
        }
        if ($base !== null && $review > $base->modify('+' . self::MAX_YEARS_AHEAD . ' years')) {
            return sprintf('Contract review date cannot be more than %d years ahead. Please check the year.', self::MAX_YEARS_AHEAD);
        }

        return null;
    }

    /** Days from today to the review date (negative = overdue). */
    public static function daysUntil(string $reviewDate, DateTimeImmutable $today): ?int
    {
        $review = self::parse($reviewDate);
        $base   = self::parse($today->format('Y-m-d'));
        if ($review === null || $base === null) {
            return null;
        }

        return (int) $base->diff($review)->format('%r%a');
    }

    /** Last review date that is already "due soon" as of today. */
    public static function dueCutoff(DateTimeImmutable $today): string
    {
        return $today->modify('+' . self::DUE_SOON_DAYS . ' days')->format('Y-m-d');
    }

    /** Short status for the bell and the profile, e.g. "Overdue by 3 days". */
    public static function label(?string $reviewDate, DateTimeImmutable $today): string
    {
        if ($reviewDate === null || $reviewDate === '') {
            return 'No review date set';
        }
        $days = self::daysUntil($reviewDate, $today);
        if ($days === null) {
            return 'No review date set';
        }

        return match (true) {
            $days < 0   => sprintf('Overdue by %d day%s', -$days, $days === -1 ? '' : 's'),
            $days === 0 => 'Due today',
            default     => sprintf('Due in %d day%s', $days, $days === 1 ? '' : 's'),
        };
    }

    private static function parse(string $date): ?DateTimeImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        return ($parsed !== false && $parsed->format('Y-m-d') === $date) ? $parsed : null;
    }
}

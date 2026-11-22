<?php

declare(strict_types=1);

namespace Wbpms\Domain\Employee;

/**
 * What saving a bank account does to the employee's effective-dated
 * bank_details rows. [EMP-026]
 *
 * bank_details has CHECK chk_bank_period (effective_to IS NULL OR
 * effective_to > effective_from). Saving a new account closes the current
 * Active row with effective_to = the new account's effective date, so the
 * new date must be strictly later than the current row's effective date:
 *
 *  - no current account          → First      (insert)
 *  - new date  > current date    → Supersede  (close current, insert new)
 *  - new date == current date    → Correct    (fix the current row in place;
 *                                   closing it would give a zero-length period)
 *  - new date  < current date    → Reject     (would end the current account
 *                                   before it started)
 *
 * Dates are ISO YYYY-MM-DD strings, so string comparison is date comparison.
 */
enum BankAccountChange: string
{
    case First     = 'first';
    case Supersede = 'supersede';
    case Correct   = 'correct';
    case Reject    = 'reject';

    public static function decide(?string $currentEffectiveFrom, string $newEffectiveFrom): self
    {
        if ($currentEffectiveFrom === null || $currentEffectiveFrom === '') {
            return self::First;
        }

        $current = substr($currentEffectiveFrom, 0, 10);
        $new     = substr($newEffectiveFrom, 0, 10);

        return match (true) {
            $new > $current   => self::Supersede,
            $new === $current => self::Correct,
            default           => self::Reject,
        };
    }

    /** User-facing message for a rejected effective date (UI date MM/DD/YY). */
    public static function rejectionMessage(string $currentEffectiveFrom): string
    {
        $ts      = strtotime(substr($currentEffectiveFrom, 0, 10));
        $display = $ts !== false ? date('m/d/y', $ts) : $currentEffectiveFrom;

        return 'Effective date must be on or after ' . $display
            . ', the date the current account took effect. Use a later date for a new account, '
            . 'or the same date to correct the current one.';
    }
}

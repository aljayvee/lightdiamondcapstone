<?php

declare(strict_types=1);

namespace Wbpms\Domain\Validation;

/**
 * Daily-rate limits shared by Add/Edit/Rehire Employee, Salary Management
 * and position default rates (Settings).
 *
 * - Floor: the DOLE Region XII minimum daily wage stored in the active
 *   payroll policy (Settings). DOLE sets only a floor; there is no legal
 *   maximum.
 * - Ceiling: MAX is a company rule, not a legal limit (DOLE has no maximum).
 *   Set to ₱755 on 2026-11-22 at the adviser's (Sir Ryan) request, the
 *   highest regional minimum wage in 2026 (NCR, Wage Order NCR-28). Light
 *   Diamond's active rates were ₱460–₱700 on that date. Changing the cap
 *   is a code change here only; every form reads this constant.
 */
final class DailyRateRule
{
    /** Highest daily rate HR may enter (inclusive). */
    public const MAX = 755.00;

    /**
     * Used only when the payroll policy cannot be read. Wage Order
     * RB XII-26, first tranche, effective 22 November 2026 (non-agriculture,
     * retail and service). The second tranche (₱501) starts 1 January 2027.
     */
    public const FALLBACK_MINIMUM = 476.00;

    /**
     * Null when the rate is acceptable, otherwise a message for the user.
     * $raw is the submitted value as typed (already trimmed).
     */
    public static function error(string $raw, float $minimumWage): ?string
    {
        if ($raw === '' || !is_numeric($raw) || (float) $raw <= 0) {
            return 'Daily rate is required and must be greater than 0.';
        }
        $rate = (float) $raw;
        if ($minimumWage > 0.0 && $rate < $minimumWage) {
            return sprintf(
                'Daily rate cannot be below the DOLE Region XII minimum daily wage (₱%s).',
                number_format($minimumWage, 2),
            );
        }
        if ($rate > self::MAX) {
            return self::maxMessage();
        }

        return null;
    }

    public static function maxMessage(): string
    {
        return sprintf(
            'Daily rate cannot be more than ₱%s.',
            number_format(self::MAX, 2),
        );
    }
}

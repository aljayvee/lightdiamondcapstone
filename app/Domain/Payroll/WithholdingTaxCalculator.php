<?php

declare(strict_types=1);

namespace Wbpms\Domain\Payroll;

/**
 * BIR TRAIN Law (RA 10963, 2023+ effective schedule) weekly withholding tax calculator.
 *
 * Weekly taxable compensation = Gross Pay − (Employee SSS + PhilHealth + Pag-IBIG + Late + Undertime).
 *
 * Base 2023+ BIR Weekly Withholding Tax Table (for ₱250,000.00 annual exempt threshold):
 *   1. ₱0.00 – ₱4,807.69:            ₱0.00 (exempt)
 *   2. ₱4,807.70 – ₱7,692.31:        15% of excess over ₱4,807.69
 *   3. ₱7,692.32 – ₱15,384.62:       ₱432.69 + 20% of excess over ₱7,692.31
 *   4. ₱15,384.63 – ₱38,461.54:      ₱1,971.15 + 25% of excess over ₱15,384.62
 *   5. ₱38,461.55 – ₱153,846.15:     ₱7,740.38 + 30% of excess over ₱38,461.54
 *   6. Over ₱153,846.15:             ₱42,355.77 + 35% of excess over ₱153,846.15
 */
final class WithholdingTaxCalculator
{
    public function computeWeekly(float $weeklyTaxableIncome, float $annualTaxThreshold = 250000.00): float
    {
        if ($weeklyTaxableIncome <= 0.0) {
            return 0.0;
        }

        $weeklyExempt = $annualTaxThreshold > 0.0
            ? round($annualTaxThreshold / 52.0, 2)
            : 4807.69;

        if ($weeklyTaxableIncome <= $weeklyExempt) {
            return 0.0;
        }

        // If custom annual threshold is configured higher than bracket 2 ceiling,
        // apply 15% above the configured weekly exempt threshold up to the next bracket.
        $b1 = $weeklyExempt; // 4,807.69 at 250,000 annual threshold
        $b2 = max($b1, 7692.31);
        $b3 = max($b2, 15384.62);
        $b4 = max($b3, 38461.54);
        $b5 = max($b4, 153846.15);

        if ($weeklyTaxableIncome <= $b2) {
            return round(($weeklyTaxableIncome - $b1) * 0.15, 2);
        }

        $base2 = round(($b2 - $b1) * 0.15, 2); // 432.69
        if ($weeklyTaxableIncome <= $b3) {
            return round($base2 + ($weeklyTaxableIncome - $b2) * 0.20, 2);
        }

        $base3 = round($base2 + ($b3 - $b2) * 0.20, 2); // 1,971.15
        if ($weeklyTaxableIncome <= $b4) {
            return round($base3 + ($weeklyTaxableIncome - $b3) * 0.25, 2);
        }

        $base4 = round($base3 + ($b4 - $b3) * 0.25, 2); // 7,740.38
        if ($weeklyTaxableIncome <= $b5) {
            return round($base4 + ($weeklyTaxableIncome - $b4) * 0.30, 2);
        }

        $base5 = round($base4 + ($b5 - $b4) * 0.30, 2); // 42,355.77
        return round($base5 + ($weeklyTaxableIncome - $b5) * 0.35, 2);
    }
}

<?php

declare(strict_types=1);

namespace Wbpms\Domain\Payroll;

/**
 * SSS schedule of contributions for business employers and employees,
 * per SSS Circular No. 2024-006 (effective January 2025, RA 11199).
 *
 * Each Monthly Salary Credit (MSC) covers compensation from MSC - 250.00
 * up to MSC + 249.99. Compensation below 5,250.00 maps to the 5,000.00
 * floor; 34,750.00 and above maps to the 35,000.00 ceiling.
 *
 * Employee share = 5% of MSC. Employer share = 10% of MSC plus the
 * employer-only Employees' Compensation (EC) premium (10.00 below MSC
 * 15,000, 30.00 from MSC 15,000), as the employer total in the circular.
 * The MPF part above MSC 20,000 is included in these totals. `ec` is kept
 * as a separate key so the premium can still be shown on its own.
 */
final class SssContributionSchedule
{
    public const MSC_FLOOR    = 5000;
    public const MSC_CEILING  = 35000;
    public const MSC_STEP     = 500;
    public const HALF_STEP    = 250;
    public const EMPLOYEE_RATE = 0.05;
    public const EMPLOYER_RATE = 0.10;

    /**
     * All 61 brackets in ascending order, shaped like `sss_bracket` rows.
     *
     * @return list<array{msc:int, salary_from:float, salary_to:?float, employee_share:float, employer_share:float, ec:float}>
     */
    public static function brackets(): array
    {
        $rows = [];
        for ($msc = self::MSC_FLOOR; $msc <= self::MSC_CEILING; $msc += self::MSC_STEP) {
            $rows[] = [
                'msc'            => $msc,
                'salary_from'    => $msc === self::MSC_FLOOR ? 0.00 : (float) ($msc - self::HALF_STEP),
                'salary_to'      => $msc === self::MSC_CEILING ? null : round($msc + self::HALF_STEP - 0.01, 2),
                'employee_share' => round($msc * self::EMPLOYEE_RATE, 2),
                'employer_share' => round($msc * self::EMPLOYER_RATE + self::ecFor($msc), 2),
                'ec'             => self::ecFor($msc),
            ];
        }
        return $rows;
    }

    /** MSC for a monthly compensation (e.g. the EEMR), rounded to centavos first. */
    public static function mscFor(float $monthlyCompensation): int
    {
        $amount = round($monthlyCompensation, 2);
        foreach (self::brackets() as $bracket) {
            if ($bracket['salary_to'] === null || $amount <= $bracket['salary_to']) {
                return $bracket['msc'];
            }
        }
        return self::MSC_CEILING;
    }

    /** Employer-only Employees' Compensation premium for an MSC. */
    public static function ecFor(int $msc): float
    {
        return $msc >= 15000 ? 30.00 : 10.00;
    }
}

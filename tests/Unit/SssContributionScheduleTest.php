<?php

declare(strict_types=1);

namespace Wbpms\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Wbpms\Domain\Payroll\SssContributionSchedule;

/**
 * SSS Circular No. 2024-006 schedule (effective January 2025).
 *
 * Regression for the shifted bracket ranges (MSC .. MSC + 499.99) that put
 * Checker, Laborer, HR Head and Warehouse Incharge one MSC step too low.
 */
final class SssContributionScheduleTest extends TestCase
{
    public function testScheduleHasSixtyOneContiguousBrackets(): void
    {
        $brackets = SssContributionSchedule::brackets();
        $this->assertCount(61, $brackets);

        $this->assertSame(5000, $brackets[0]['msc']);
        $this->assertSame(0.00, $brackets[0]['salary_from']);
        $this->assertSame(5249.99, $brackets[0]['salary_to']);

        $last = $brackets[60];
        $this->assertSame(35000, $last['msc']);
        $this->assertSame(34750.00, $last['salary_from']);
        $this->assertNull($last['salary_to']);

        for ($i = 1; $i < 61; $i++) {
            $this->assertSame(
                round((float) $brackets[$i - 1]['salary_to'] + 0.01, 2),
                $brackets[$i]['salary_from'],
                'Brackets must be contiguous with no gap or overlap.',
            );
        }
    }

    /** @return array<string, array{int, float, float, float, float}> msc, from, to, EE, ER */
    public static function circularRows(): array
    {
        return [
            'MSC 5,500'  => [5500, 5250.00, 5749.99, 275.00, 550.00],
            'MSC 12,000' => [12000, 11750.00, 12249.99, 600.00, 1200.00],
            'MSC 12,500' => [12500, 12250.00, 12749.99, 625.00, 1250.00],
            'MSC 14,500' => [14500, 14250.00, 14749.99, 725.00, 1450.00],
            'MSC 15,000' => [15000, 14750.00, 15249.99, 750.00, 1500.00],
            'MSC 18,500' => [18500, 18250.00, 18749.99, 925.00, 1850.00],
            'MSC 20,500' => [20500, 20250.00, 20749.99, 1025.00, 2050.00],
            'MSC 34,500' => [34500, 34250.00, 34749.99, 1725.00, 3450.00],
        ];
    }

    #[DataProvider('circularRows')]
    public function testBracketMatchesCircularRow(int $msc, float $from, float $to, float $ee, float $er): void
    {
        $row = $this->bracketFor($msc);
        $this->assertSame($from, $row['salary_from']);
        $this->assertSame($to, $row['salary_to']);
        $this->assertSame($ee, $row['employee_share']);
        $this->assertSame($er, $row['employer_share']);
    }

    public function testEcPremiumSwitchesAtMsc15000(): void
    {
        $this->assertSame(10.00, $this->bracketFor(14500)['ec']);
        $this->assertSame(30.00, $this->bracketFor(15000)['ec']);
        $this->assertSame(30.00, $this->bracketFor(35000)['ec']);
    }

    /** @return array<string, array{float, int}> */
    public static function edgeCases(): array
    {
        return [
            'zero'               => [0.00, 5000],
            'below 5,250'        => [5249.99, 5000],
            'at 5,250'           => [5250.00, 5500],
            'upper half 5,499'   => [5499.99, 5500],
            'below 34,750'       => [34749.99, 34500],
            'at 34,750'          => [34750.00, 35000],
            'far above ceiling'  => [120000.00, 35000],
            'HR Head boundary'   => [18249.99, 18000],
            'HR Head just over'  => [18250.00, 18500],
        ];
    }

    #[DataProvider('edgeCases')]
    public function testMscForEdges(float $compensation, int $expectedMsc): void
    {
        $this->assertSame($expectedMsc, SssContributionSchedule::mscFor($compensation));
    }

    /** @return array<string, array{int, int, float}> daily rate, MSC, total employee deduction */
    public static function positions(): array
    {
        return [
            'Store Incharge'     => [600, 15500, 1366.25],
            'Sales Clerk'        => [465, 12000, 1103.22],
            'Cashier'            => [480, 12500, 1138.00],
            'Checker'            => [470, 12500, 1131.48],
            'Encoder'            => [500, 13000, 1176.04],
            'Driver'             => [520, 13500, 1214.08],
            'Laborer'            => [460, 12000, 1099.96],
            'Secretary'          => [500, 13000, 1176.04],
            'HR Head'            => [700, 18500, 1581.46],
            'Warehouse Incharge' => [470, 12500, 1131.48],
        ];
    }

    /**
     * Mirrors PayrollService: EEMR = rate × 313 ÷ 12; SSS share from the first
     * row with salary_from ≤ EEMR ≤ salary_to; PhilHealth 2.5%; Pag-IBIG 2% of
     * min(EEMR, 10,000). Expected totals are from the payroll calculation sheet.
     */
    #[DataProvider('positions')]
    public function testPositionTotalsMatchPayrollSheet(int $dailyRate, int $expectedMsc, float $expectedTotal): void
    {
        $eemr = round($dailyRate * 313 / 12, 2);

        $sss = null;
        foreach (SssContributionSchedule::brackets() as $row) {
            $to = $row['salary_to'] ?? PHP_FLOAT_MAX;
            if ($eemr >= $row['salary_from'] && $eemr <= $to) {
                $this->assertSame($expectedMsc, $row['msc']);
                $sss = $row['employee_share'];
                break;
            }
        }
        $this->assertNotNull($sss, 'EEMR must fall inside a bracket.');

        $philhealth = round(max(10000.0, min(100000.0, $eemr)) * 0.025, 2);
        $pagibig    = round(min(10000.0, $eemr) * 0.02, 2);

        $this->assertSame($expectedTotal, round($sss + $philhealth + $pagibig, 2));
    }

    /** @return array{msc:int, salary_from:float, salary_to:?float, employee_share:float, employer_share:float, ec:float} */
    private function bracketFor(int $msc): array
    {
        foreach (SssContributionSchedule::brackets() as $row) {
            if ($row['msc'] === $msc) {
                return $row;
            }
        }
        $this->fail("No bracket for MSC {$msc}.");
    }
}

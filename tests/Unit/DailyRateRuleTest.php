<?php

declare(strict_types=1);

namespace Wbpms\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Wbpms\Application\SalaryService;
use Wbpms\Domain\Validation\DailyRateRule;
use Wbpms\Http\Controllers\EmployeeController;

/**
 * Daily-rate floor (DOLE Region XII minimum, from Settings) and the ₱755
 * company ceiling shared by Employee, Salary and Settings forms. [EMP-030]
 */
final class DailyRateRuleTest extends TestCase
{
    public function testCeilingIs755Everywhere(): void
    {
        self::assertSame(755.00, DailyRateRule::MAX);
        self::assertSame(DailyRateRule::MAX, EmployeeController::MAX_DAILY_RATE);
        self::assertSame(DailyRateRule::MAX, SalaryService::MAX_DAILY_RATE);
    }

    public function testFallbackMinimumIsWageOrderXii26FirstTranche(): void
    {
        self::assertSame(476.00, DailyRateRule::FALLBACK_MINIMUM);
        self::assertLessThan(DailyRateRule::MAX, DailyRateRule::FALLBACK_MINIMUM);
    }

    /** @return array<string, array{string}> */
    public static function acceptedRates(): array
    {
        return [
            'exact minimum'   => ['476'],
            'with cents'      => ['610.50'],
            'highest today'   => ['700.00'],
            'exact ceiling'   => ['755.00'],
        ];
    }

    #[DataProvider('acceptedRates')]
    public function testAcceptsRatesBetweenMinimumAndCeiling(string $rate): void
    {
        self::assertNull(DailyRateRule::error($rate, 476.00));
    }

    public function testRejectsAnythingAboveCeiling(): void
    {
        foreach (['755.01', '1000', '10000', '1000000'] as $rate) {
            self::assertSame(
                'Daily rate cannot be more than ₱755.00.',
                DailyRateRule::error($rate, 476.00),
                $rate,
            );
        }
    }

    public function testRejectsOldMinimumAfterWageOrder(): void
    {
        self::assertSame(
            'Daily rate cannot be below the DOLE Region XII minimum daily wage (₱476.00).',
            DailyRateRule::error('460.00', 476.00),
        );
    }

    public function testRejectsBlankZeroAndText(): void
    {
        foreach (['', '0', '-5', 'abc'] as $rate) {
            self::assertSame('Daily rate is required and must be greater than 0.', DailyRateRule::error($rate, 476.00), $rate);
        }
    }

    public function testNoMinimumConfiguredSkipsFloorButKeepsCeiling(): void
    {
        self::assertNull(DailyRateRule::error('100', 0.0));
        self::assertNotNull(DailyRateRule::error('756', 0.0));
    }
}

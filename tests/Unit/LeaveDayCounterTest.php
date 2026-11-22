<?php

declare(strict_types=1);

namespace Wbpms\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Wbpms\Domain\Leave\LeaveDayCounter;

/**
 * Sick leave is charged only for the days payroll pays: scheduled working
 * days that are not holidays. Found 2026-11-22 when a Sat–Mon request took
 * 3 credits but paid 1 day.
 */
final class LeaveDayCounterTest extends TestCase
{
    /** Sun–Fri schedule (SDA client), Saturday rest, 08/09/25 is a Saturday. */
    private const WEEK = [
        '2025-08-08' => true,  // Fri
        '2025-08-09' => false, // Sat (rest)
        '2025-08-10' => true,  // Sun
        '2025-08-11' => true,  // Mon
        '2025-08-12' => true,  // Tue
    ];

    /** @return array<string, array{string, string, array<string,bool>, array<string,bool>, list<string>}> */
    public static function cases(): array
    {
        return [
            'Sat to Mon skips the rest day' => ['2025-08-09', '2025-08-11', self::WEEK, [], ['2025-08-10', '2025-08-11']],
            'rest day only charges nothing' => ['2025-08-09', '2025-08-09', self::WEEK, [], []],
            'holiday is not charged'        => ['2025-08-10', '2025-08-12', self::WEEK, ['2025-08-11' => true], ['2025-08-10', '2025-08-12']],
            'single working day'            => ['2025-08-08', '2025-08-08', self::WEEK, [], ['2025-08-08']],
            'date with no schedule is not charged' => ['2025-08-12', '2025-08-13', self::WEEK, [], ['2025-08-12']],
            'end before start'              => ['2025-08-12', '2025-08-10', self::WEEK, [], []],
            'invalid date'                  => ['2025-02-30', '2025-08-10', self::WEEK, [], []],
        ];
    }

    /**
     * @param array<string,bool> $scheduled
     * @param array<string,bool> $holidays
     * @param list<string> $expected
     */
    #[DataProvider('cases')]
    public function testChargeableDates(string $start, string $end, array $scheduled, array $holidays, array $expected): void
    {
        self::assertSame($expected, (new LeaveDayCounter())->chargeableDates($start, $end, $scheduled, $holidays));
    }

    public function testDayAlreadyWorkedIsNotCharged(): void
    {
        // Real case: emp 6 filed Sat 08/09–Mon 08/11/25 but worked Sunday 08/10.
        self::assertSame(
            ['2025-08-11'],
            (new LeaveDayCounter())->chargeableDates('2025-08-09', '2025-08-11', self::WEEK, [], ['2025-08-10' => true]),
        );
    }

    public function testAvailableCreditSubtractsRequestsWaitingForApproval(): void
    {
        $counter = new LeaveDayCounter();
        self::assertSame(1.0, $counter->availableCredit(4.0, 3.0));
        self::assertSame(-2.0, $counter->availableCredit(1.0, 3.0));
        self::assertSame(4.0, $counter->availableCredit(4.0, 0.0));
        self::assertSame(4.0, $counter->availableCredit(4.0, -1.0), 'negative open days are ignored');
    }
}

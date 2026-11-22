<?php

declare(strict_types=1);

namespace Wbpms\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Wbpms\Domain\Payroll\HolidayCalendarCoverage;

/**
 * Bug 2026-11-22: payroll run 35 (08/15–08/21/25) paid Aug 21, 2025 (Ninoy
 * Aquino Day) as an ordinary day — the holiday calendar only had 2026.
 * The 2025 list is now seeded and Compute refuses a period whose year has
 * no holidays on file. [PAY-034]
 */
final class HolidayCalendar2025Test extends TestCase
{
    /** @return list<array{0:string,1:string,2:string,3:float}> */
    private static function holidays(): array
    {
        require_once dirname(__DIR__, 2) . '/database/seeds/HolidayCalendar2025Seeder.php';

        return \HolidayCalendar2025Seeder::holidays();
    }

    public function testListMatchesTheProclamations(): void
    {
        $byDate = [];
        foreach (self::holidays() as [$date, $name, $type, $multiplier]) {
            self::assertArrayNotHasKey($date, $byDate, "Duplicate date {$date}");
            self::assertMatchesRegularExpression('/^2025-\d{2}-\d{2}$/', $date);
            self::assertNotFalse(\DateTimeImmutable::createFromFormat('!Y-m-d', $date));
            $expectedMultiplier = ['Regular' => 2.00, 'SpecialNonWorking' => 1.30, 'Special' => 1.00][$type] ?? null;
            self::assertSame($expectedMultiplier, $multiplier, "{$date} {$name}");
            $byDate[$date] = $type;
        }

        // Proc. 727: 10 regular + 4 special + 4 additional special + 1 special working;
        // Proc. 839/911: Eid'l Fitr, Eid'l Adha; Proc. 878/729: election day, INC anniversary.
        self::assertCount(23, $byDate);
        self::assertSame(12, count(array_filter($byDate, static fn (string $t): bool => $t === 'Regular')));
        self::assertSame(10, count(array_filter($byDate, static fn (string $t): bool => $t === 'SpecialNonWorking')));
        self::assertSame(1, count(array_filter($byDate, static fn (string $t): bool => $t === 'Special')));

        // The days that matter for the August 2025 payroll.
        self::assertSame('SpecialNonWorking', $byDate['2025-08-21']); // Ninoy Aquino Day (run 35)
        self::assertSame('Regular', $byDate['2025-08-25']);           // National Heroes Day (next cutoff)
        self::assertSame('Regular', $byDate['2025-04-01']);           // Eid'l Fitr
        self::assertSame('Regular', $byDate['2025-06-06']);           // Eid'l Adha
        self::assertSame('Special', $byDate['2025-02-25']);           // EDSA: special working day
    }

    /** @return array<string, array{string, string, list<int>, list<int>}> */
    public static function coverageCases(): array
    {
        return [
            '2025 period, only 2026 on file'      => ['2025-08-15', '2025-08-21', [2026], [2025]],
            '2025 period, 2025 on file'           => ['2025-08-15', '2025-08-21', [2025, 2026], []],
            'period across new year, one missing' => ['2025-12-26', '2026-01-01', [2026], [2025]],
            'period across new year, both on file'=> ['2025-12-26', '2026-01-01', [2025, 2026], []],
            'nothing on file at all'              => ['2026-06-26', '2026-07-02', [], [2026]],
        ];
    }

    /**
     * @param list<int> $onFile
     * @param list<int> $expected
     */
    #[DataProvider('coverageCases')]
    public function testMissingYears(string $start, string $end, array $onFile, array $expected): void
    {
        self::assertSame($expected, HolidayCalendarCoverage::missingYears($start, $end, $onFile));
    }

    public function testMessageNamesTheYearAndWhereToFixIt(): void
    {
        $message = HolidayCalendarCoverage::message([2025]);
        self::assertStringContainsString('no holidays on file for 2025', $message);
        self::assertStringContainsString('Settings → Holidays', $message);
    }

    /** Guards the wiring a unit test cannot run without a database. */
    public function testComputeChecksTheCalendarBeforeAnything(): void
    {
        $service = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Application/PayrollService.php');
        $start = (int) strpos($service, 'public function computeRunIfReady(');
        $body  = substr($service, $start, (int) strpos($service, '$issues = $this->readinessIssues($runId);', $start) - $start);

        self::assertStringContainsString('HolidayCalendarCoverage::missingYears(', $body);
        self::assertStringContainsString('throw new RuntimeException(HolidayCalendarCoverage::message($missingYears));', $body);
    }
}

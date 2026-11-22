<?php

declare(strict_types=1);

namespace Wbpms\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Wbpms\Domain\Attendance\ReportDateRange;

/**
 * HR picked From 08/01/25 on the Attendance Frequency Report and it came back
 * as 11/21/25: a range over 366 days moved From forward, and the page's
 * filter memory saved that date. From is now kept. [ATT-031]
 */
final class ReportDateRangeTest extends TestCase
{
    private const DEF_FROM = '2026-11-01';
    private const DEF_TO = '2026-11-22';

    public function testHrsAugust2025ToTodayIsKeptAsChosen(): void
    {
        self::assertSame(
            ['from' => '2025-08-01', 'to' => '2026-11-22', 'notice' => null],
            ReportDateRange::resolve('2025-08-01', '2026-11-22', self::DEF_FROM, self::DEF_TO),
        );
        // The "This month" preset ends on 11/30/26.
        self::assertSame('2025-08-01', ReportDateRange::resolve('2025-08-01', '2026-11-30', self::DEF_FROM, self::DEF_TO)['from']);
    }

    public function testTooLongKeepsFromAndEndsEarlierWithANotice(): void
    {
        $r = ReportDateRange::resolve('2024-01-01', '2026-11-22', self::DEF_FROM, self::DEF_TO);

        self::assertSame('2024-01-01', $r['from'], 'From is never moved');
        self::assertSame('2026-01-01', $r['to']);
        self::assertSame('A report can cover up to 732 days, so it ends on 01/01/26 instead of 11/22/26.', $r['notice']);
    }

    public function testExactlyTheLimitIsAllowed(): void
    {
        self::assertSame(
            ['from' => '2024-11-21', 'to' => '2026-11-22', 'notice' => null],
            ReportDateRange::resolve('2024-11-21', '2026-11-22', self::DEF_FROM, self::DEF_TO),
        );
    }

    public function testNoDatesGivesTheDefaultsQuietly(): void
    {
        self::assertSame(
            ['from' => self::DEF_FROM, 'to' => self::DEF_TO, 'notice' => null],
            ReportDateRange::resolve(null, null, self::DEF_FROM, self::DEF_TO),
        );
        self::assertNull(ReportDateRange::resolve('', '', self::DEF_FROM, self::DEF_TO)['notice']);
    }

    public function testBadOrReversedDatesFallBackWithANotice(): void
    {
        foreach ([['2025-02-30', '2025-03-01'], ['08/01/25', '2025-08-31'], ['2025-08-01', ''], ['2025-09-01', '2025-08-01']] as [$from, $to]) {
            $r = ReportDateRange::resolve($from, $to, self::DEF_FROM, self::DEF_TO);
            self::assertSame([self::DEF_FROM, self::DEF_TO], [$r['from'], $r['to']], "{$from} / {$to}");
            self::assertNotNull($r['notice'], "{$from} / {$to}");
        }
    }

    public function testFrequencyReportShowsTheNoticeEscaped(): void
    {
        $html = self::renderReport('A report can cover up to 732 days, so it ends on 01/01/26 <b>instead</b>.');

        self::assertStringContainsString('class="frequency-range-notice" role="status"', $html);
        self::assertStringContainsString('&lt;b&gt;instead&lt;/b&gt;', $html);
        self::assertStringContainsString('name="date_from" value="2024-01-01"', $html);
        self::assertStringNotContainsString('frequency-range-notice', self::renderReport(null));
    }

    private static function renderReport(?string $notice): string
    {
        $rows = [];
        $summary = ['tardy_days' => 0, 'late_minutes' => 0, 'absence_days' => 0, 'employees_affected' => 0];
        $branches = [];
        $dateFrom = '2024-01-01';
        $dateTo = '2026-01-01';
        $rangeNotice = $notice;
        $branchId = null;
        $search = '';
        $base = '';

        ob_start();
        include dirname(__DIR__, 2) . '/resources/views/hr/attendance/frequency-report.php';

        return (string) ob_get_clean();
    }
}

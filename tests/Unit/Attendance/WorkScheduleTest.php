<?php

declare(strict_types=1);

namespace Wbpms\Tests\Unit\Attendance;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Wbpms\Domain\Attendance\WorkSchedule;

final class WorkScheduleTest extends TestCase
{
    private function at(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable("2025-08-11 {$time}", new DateTimeZone('Asia/Manila'));
    }

    public function testALonePunchFromMidShiftOnwardIsATimeOut(): void
    {
        $schedule = new WorkSchedule(1, '07:00', '16:00');   // midpoint 11:30

        self::assertFalse($schedule->isTimeOutPunch($this->at('06:55')));
        self::assertFalse($schedule->isTimeOutPunch($this->at('11:29')));
        self::assertTrue($schedule->isTimeOutPunch($this->at('11:30')));
        self::assertTrue($schedule->isTimeOutPunch($this->at('16:57')));
    }

    public function testMinutesMatchTheAugust2025WorkbookForAlbertIndo(): void
    {
        // 2025-08-11 punches 07:06 and 16:55 on the 07:00-16:00 shift.
        $schedule = new WorkSchedule(1, '07:00', '16:00');
        self::assertSame([529, 6, 0], $schedule->minutesFor($this->at('07:06'), $this->at('16:55')));

        // The same day against a 07:30-17:00 shift gives the screenshot's figures.
        $wrongShift = new WorkSchedule(2, '07:30', '17:00', 60, 510);
        self::assertSame([529, 0, 5], $wrongShift->minutesFor($this->at('07:06'), $this->at('16:55')));
    }

    public function testGraceReducesLatenessOnly(): void
    {
        $schedule = new WorkSchedule(1, '07:00', '16:00', 60, 480, 5);
        self::assertSame([533, 3, 0], $schedule->minutesFor($this->at('07:08'), $this->at('17:01')));
    }

    public function testHalfDaysExcludeUnpaidBreakOutsideWorkedWindow(): void
    {
        // Standard 07:00-16:00 shift with 12:00-13:00 unpaid break (480 working mins).
        $shift7 = new WorkSchedule(1, '07:00', '16:00', 60, 480, 0, true, '12:00', '13:00');

        // Full day still deducts the full 60-minute break (matches existing Albert Indo case).
        self::assertSame([529, 6, 0], $shift7->minutesFor($this->at('07:06'), $this->at('16:55')));

        // Morning half-day ending before break (june.xls: Catayoc, Barbie 06/19 07:06-11:59).
        // Worked 293m, late 6m, undertime 181m (excludes 12:00-13:00 unpaid break). 293+6+181=480.
        self::assertSame([293, 6, 181], $shift7->minutesFor($this->at('07:06'), $this->at('11:59')));

        // Morning half-day ending 5 min into break (june.xls: Catayoc, Barbie 06/02 07:13-12:05).
        // Overlap with break = 5m -> worked 287m, late 13m, undertime 180m. 287+13+180=480.
        self::assertSame([287, 13, 180], $shift7->minutesFor($this->at('07:13'), $this->at('12:05')));

        // Standard 08:00-17:00 shift with 12:00-13:00 unpaid break.
        $shift8 = new WorkSchedule(2, '08:00', '17:00', 60, 480, 0, true, '12:00', '13:00');

        // Afternoon half-day starting after break (dec 2025_.xls: Defiño, Nyven 12/18 13:07-17:00).
        // Worked 233m, late 247m (excludes 12:00-13:00 unpaid break), undertime 0m. 233+247=480.
        self::assertSame([233, 247, 0], $shift8->minutesFor($this->at('13:07'), $this->at('17:00')));

        // Afternoon half-day starting inside break (dec 2025_.xls: Paguntalan, lovely 12/21 12:43-17:02).
        // Worked overlap = 17m -> 259-17=242m; late overlap = 43m -> 283-43=240m; undertime 0m.
        self::assertSame([242, 240, 0], $shift8->minutesFor($this->at('12:43'), $this->at('17:02')));
    }

    public function testDuplicateTapSpanIdentifiesSameSideDoubleScansOnly(): void
    {
        $schedule = new WorkSchedule(1, '07:00', '16:00', 60, 480, 0, true, '12:00', '13:00');

        // Real double-tap spans from the 2025/2026 .xls daily logs:
        self::assertTrue($schedule->isDuplicateTapSpan($this->at('16:48'), $this->at('16:49')));  // 1m PM
        self::assertTrue($schedule->isDuplicateTapSpan($this->at('07:13'), $this->at('07:20')));  // 7m AM
        self::assertTrue($schedule->isDuplicateTapSpan($this->at('06:49'), $this->at('06:59')));  // 10m AM
        self::assertTrue($schedule->isDuplicateTapSpan($this->at('06:31'), $this->at('07:04')));  // 33m AM

        // Genuine short shift (nov 2025_.xls: Sabidal, Jenny 07:04-09:11 = 127m) is NOT a double-tap.
        self::assertFalse($schedule->isDuplicateTapSpan($this->at('07:04'), $this->at('09:11')));
        // Cross-midpoint pair >15m is NOT treated as a double-tap.
        self::assertFalse($schedule->isDuplicateTapSpan($this->at('11:20'), $this->at('11:45')));
        // Immediate double-tap (<=15m) straddling shift midpoint IS caught.
        self::assertTrue($schedule->isDuplicateTapSpan($this->at('11:28'), $this->at('11:32')));
        // Normal full day is NOT a double-tap.
        self::assertFalse($schedule->isDuplicateTapSpan($this->at('07:00'), $this->at('16:00')));
    }

    public function testLatenessAndUndertimeAreClampedToShiftBoundaries(): void
    {
        $schedule = new WorkSchedule(1, '07:00', '16:00', 60, 480, 0, true, '12:00', '13:00');

        // Arrival after shift end (16:15-18:15) cannot incur >480 min lateness.
        self::assertSame([120, 480, 0], $schedule->minutesFor($this->at('16:15'), $this->at('18:15')));

        // Departure before shift start (05:00-06:45) cannot incur >480 min undertime.
        self::assertSame([105, 0, 480], $schedule->minutesFor($this->at('05:00'), $this->at('06:45')));
    }
}

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
}

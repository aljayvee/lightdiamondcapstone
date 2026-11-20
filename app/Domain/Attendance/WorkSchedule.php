<?php

declare(strict_types=1);

namespace Wbpms\Domain\Attendance;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/** A single effective work schedule; overlap validation is a persistence concern. */
final class WorkSchedule
{
    public function __construct(
        public int $scheduleId,
        public string $startTime,
        public string $endTime,
        public int $unpaidBreakMinutes = 60,
        public int $standardMinutes = 480,
        public int $graceMinutes = 0,
        public bool $overtimeAllowed = false,
    ) {
        if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $startTime)
            || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $endTime)
            || $unpaidBreakMinutes < 0
            || $standardMinutes < 1
            || $graceMinutes < 0) {
            throw new InvalidArgumentException('Invalid work schedule.');
        }
    }

    public function startOn(DateTimeImmutable $date, DateTimeZone $timezone): DateTimeImmutable
    {
        return new DateTimeImmutable($date->format('Y-m-d') . " {$this->startTime}", $timezone);
    }

    public function endOn(DateTimeImmutable $date, DateTimeZone $timezone): DateTimeImmutable
    {
        return new DateTimeImmutable($date->format('Y-m-d') . " {$this->endTime}", $timezone);
    }

    /**
     * A day's only punch is a time-out (the time-in was missed) when it falls
     * on or after the midpoint of the shift; before it, it is a time-in.
     */
    public function isTimeOutPunch(DateTimeImmutable $punch): bool
    {
        $timezone = $punch->getTimezone();
        $start    = $this->startOn($punch, $timezone);
        $end      = $this->endOn($punch, $timezone);
        if ($end <= $start) {
            $end = $end->modify('+1 day');
        }
        $midpoint = intdiv($start->getTimestamp() + $end->getTimestamp(), 2);
        return $punch->getTimestamp() >= $midpoint;
    }

    /**
     * Worked, late and undertime minutes for one day's first and last punch,
     * measured against this schedule. The grace period reduces chargeable
     * lateness only; it never moves the recorded time-in.
     *
     * @return array{int, int, int} worked, late, undertime
     */
    public function minutesFor(DateTimeImmutable $timeIn, DateTimeImmutable $timeOut): array
    {
        $timezone       = $timeIn->getTimezone();
        $scheduledStart = $this->startOn($timeIn, $timezone);
        $scheduledEnd   = $this->endOn($timeIn, $timezone);
        if ($scheduledEnd <= $scheduledStart) {
            $scheduledEnd = $scheduledEnd->modify('+1 day');
            if ($timeOut <= $timeIn) {
                $timeOut = $timeOut->modify('+1 day');
            }
        }
        $elapsed   = max(0, intdiv($timeOut->getTimestamp() - $timeIn->getTimestamp(), 60));
        $worked    = max(0, $elapsed - $this->unpaidBreakMinutes);
        $late      = max(0, intdiv($timeIn->getTimestamp() - $scheduledStart->getTimestamp(), 60) - $this->graceMinutes);
        $undertime = max(0, intdiv($scheduledEnd->getTimestamp() - $timeOut->getTimestamp(), 60));
        return [$worked, $late, $undertime];
    }
}

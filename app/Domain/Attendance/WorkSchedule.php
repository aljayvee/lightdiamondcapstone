<?php

declare(strict_types=1);

namespace Wbpms\Domain\Attendance;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/** A single effective work schedule; overlap validation is a persistence concern. */
final class WorkSchedule
{
    /**
     * Maximum span (in minutes) between multiple punches on the SAME side of
     * the shift midpoint that is treated as an accidental double-tap rather
     * than a completed shift (e.g. 06:49 + 06:59 or 16:48 + 16:49).
     */
    public const DUPLICATE_TAP_MAX_MINUTES = 60;

    /**
     * Rapid double-tap threshold (in minutes) treated as an accidental
     * duplicate scan even when the two punches straddle the shift midpoint
     * (e.g. 11:28 + 11:32 on a 07:00-16:00 shift whose midpoint is 11:30).
     */
    public const IMMEDIATE_DOUBLE_TAP_MINUTES = 15;

    public ?string $breakStartTime;
    public ?string $breakEndTime;

    public function __construct(
        public int $scheduleId,
        public string $startTime,
        public string $endTime,
        public int $unpaidBreakMinutes = 60,
        public int $standardMinutes = 480,
        public int $graceMinutes = 0,
        public bool $overtimeAllowed = false,
        ?string $breakStartTime = null,
        ?string $breakEndTime = null,
    ) {
        if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $startTime)
            || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $endTime)
            || $unpaidBreakMinutes < 0
            || $standardMinutes < 1
            || $graceMinutes < 0) {
            throw new InvalidArgumentException('Invalid work schedule.');
        }

        $startNorm = self::normaliseOptionalTime($breakStartTime);
        $endNorm   = self::normaliseOptionalTime($breakEndTime);
        if ($startNorm !== null && $endNorm !== null && $startNorm !== $endNorm) {
            $this->breakStartTime = $startNorm;
            $this->breakEndTime   = $endNorm;
        } else {
            $this->breakStartTime = null;
            $this->breakEndTime   = null;
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

    public function hasBreakWindow(): bool
    {
        return $this->breakStartTime !== null
            && $this->breakEndTime !== null
            && $this->unpaidBreakMinutes > 0;
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
     * True when all punches for a day sit on the same side of the shift
     * midpoint within DUPLICATE_TAP_MAX_MINUTES of each other (e.g. a double
     * scan at arrival `07:05 07:18` or departure `16:48 16:49` with the other
     * punch missing). Treating these as a completed workday produces 0 worked
     * minutes and full-day late/undertime penalties.
     */
    public function isDuplicateTapSpan(DateTimeImmutable $first, DateTimeImmutable $last): bool
    {
        $elapsed = intdiv($last->getTimestamp() - $first->getTimestamp(), 60);
        if ($elapsed < 0 || $elapsed > self::DUPLICATE_TAP_MAX_MINUTES) {
            return false;
        }
        if ($elapsed <= self::IMMEDIATE_DOUBLE_TAP_MINUTES) {
            return true;
        }
        return $this->isTimeOutPunch($first) === $this->isTimeOutPunch($last);
    }

    /**
     * Minutes of overlap between [$intervalStart, $intervalEnd] and the
     * schedule's configured unpaid break window on $shiftAnchor's shift date.
     */
    public function breakOverlapMinutes(
        DateTimeImmutable $intervalStart,
        DateTimeImmutable $intervalEnd,
        DateTimeImmutable $shiftAnchor,
    ): int {
        if (!$this->hasBreakWindow() || $intervalEnd <= $intervalStart) {
            return 0;
        }

        $timezone       = $shiftAnchor->getTimezone();
        $scheduledStart = $this->startOn($shiftAnchor, $timezone);
        $breakStart     = new DateTimeImmutable($shiftAnchor->format('Y-m-d') . " {$this->breakStartTime}", $timezone);
        $breakEnd       = new DateTimeImmutable($shiftAnchor->format('Y-m-d') . " {$this->breakEndTime}", $timezone);

        if ($breakStart < $scheduledStart) {
            $breakStart = $breakStart->modify('+1 day');
        }
        if ($breakEnd <= $breakStart) {
            $breakEnd = $breakEnd->modify('+1 day');
        }

        $overlapStart = max($intervalStart->getTimestamp(), $breakStart->getTimestamp());
        $overlapEnd   = min($intervalEnd->getTimestamp(), $breakEnd->getTimestamp());
        if ($overlapEnd <= $overlapStart) {
            return 0;
        }

        return min($this->unpaidBreakMinutes, intdiv($overlapEnd - $overlapStart, 60));
    }

    /**
     * Worked, late and undertime minutes for one day's first and last punch,
     * measured against this schedule. The grace period reduces chargeable
     * lateness only; it never moves the recorded time-in.
     *
     * When the schedule defines an explicit break window (e.g. 12:00–13:00),
     * only the minutes actually overlapping that unpaid break window are
     * deducted from worked time, lateness, and undertime — preventing half-day
     * shifts (such as 07:06–11:59 or 13:07–17:00) from being docked for an
     * unpaid lunch break they never crossed.
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
        $elapsed = max(0, intdiv($timeOut->getTimestamp() - $timeIn->getTimestamp(), 60));

        if ($this->hasBreakWindow()) {
            $workedBreak    = $this->breakOverlapMinutes($timeIn, $timeOut, $scheduledStart);
            $worked         = max(0, $elapsed - $workedBreak);

            $lateEnd        = $timeIn > $scheduledEnd ? $scheduledEnd : $timeIn;
            $lateRaw        = max(0, intdiv($lateEnd->getTimestamp() - $scheduledStart->getTimestamp(), 60));
            $lateBreak      = $lateRaw > 0 ? $this->breakOverlapMinutes($scheduledStart, $lateEnd, $scheduledStart) : 0;
            $late           = max(0, ($lateRaw - $lateBreak) - $this->graceMinutes);

            $undertimeStart = $timeOut < $scheduledStart ? $scheduledStart : $timeOut;
            $undertimeRaw   = max(0, intdiv($scheduledEnd->getTimestamp() - $undertimeStart->getTimestamp(), 60));
            $undertimeBreak = $undertimeRaw > 0 ? $this->breakOverlapMinutes($undertimeStart, $scheduledEnd, $scheduledStart) : 0;
            $undertime      = max(0, $undertimeRaw - $undertimeBreak);

            return [$worked, $late, $undertime];
        }

        $worked    = max(0, $elapsed - $this->unpaidBreakMinutes);
        $lateEnd   = $timeIn > $scheduledEnd ? $scheduledEnd : $timeIn;
        $late      = min($this->standardMinutes, max(0, intdiv($lateEnd->getTimestamp() - $scheduledStart->getTimestamp(), 60) - $this->graceMinutes));
        $utStart   = $timeOut < $scheduledStart ? $scheduledStart : $timeOut;
        $undertime = min($this->standardMinutes, max(0, intdiv($scheduledEnd->getTimestamp() - $utStart->getTimestamp(), 60)));
        return [$worked, $late, $undertime];
    }

    private static function normaliseOptionalTime(?string $time): ?string
    {
        if ($time === null) {
            return null;
        }
        $trimmed = trim($time);
        if ($trimmed === '') {
            return null;
        }
        if (preg_match('/^((?:[01]\d|2[0-3]):[0-5]\d)(?::[0-5]\d)?$/', $trimmed, $m)) {
            return $m[1];
        }
        return null;
    }
}

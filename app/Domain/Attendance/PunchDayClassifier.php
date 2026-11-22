<?php

declare(strict_types=1);

namespace Wbpms\Domain\Attendance;

use DateTimeImmutable;

/**
 * The single rule for turning one employee-day of raw biometric punches into
 * a time-in, a time-out and a status (Complete / Incomplete / ReviewRequired).
 *
 * Used by the import (TimesheetGenerator) and by every re-check in the import
 * gateway, so a day is classified the same way however it was reached.
 *
 * Rules (owner logic review C7, checked against 4,993 real employee-days):
 *   - 1 punch                      → Incomplete (time-in or time-out by the
 *                                    shift midpoint).
 *   - all punches one scan event   → Incomplete (double scan at arrival or
 *                                    departure, the other punch is missing).
 *   - 2 punches                    → Complete.
 *   - 3+ punches: repeated scans at the same moment are merged into one scan
 *     event (same side of the shift midpoint and within
 *     WorkSchedule::DUPLICATE_TAP_MAX_MINUTES of the event's first scan, or
 *     within IMMEDIATE_DOUBLE_TAP_MINUTES of the previous scan). Then:
 *       2 events (arrival + departure)          → Complete.
 *       4 events = in, lunch out, lunch in, out → Complete when the lunch
 *         gap is no longer than the unpaid break plus grace and touches the
 *         schedule's break window (when one is defined).
 *       anything else                           → ReviewRequired.
 *
 * Time-in is always the first raw punch and time-out the last, so worked,
 * late and undertime minutes are the same as for a plain two-punch day.
 */
final class PunchDayClassifier
{
    /** @param list<DateTimeImmutable> $punches */
    public function classify(array $punches, WorkSchedule $schedule): PunchDayResult
    {
        usort($punches, static fn (DateTimeImmutable $a, DateTimeImmutable $b): int => $a <=> $b);
        $count = count($punches);
        if ($count === 0) {
            return new PunchDayResult(null, null, PunchDayResult::STATUS_INCOMPLETE, ['INCOMPLETE']);
        }

        $first = $punches[0];
        $last  = $punches[$count - 1];

        if ($count === 1) {
            if ($schedule->isTimeOutPunch($first)) {
                return new PunchDayResult(null, $first, PunchDayResult::STATUS_INCOMPLETE, ['INCOMPLETE', 'TIMEOUT_ONLY']);
            }
            return new PunchDayResult($first, null, PunchDayResult::STATUS_INCOMPLETE, ['INCOMPLETE']);
        }

        if ($schedule->isDuplicateTapSpan($first, $last)) {
            // Every scan is one arrival or one departure; the opposite punch
            // is missing. Paying it as a worked day would give 0 minutes and
            // a full day of late or undertime.
            $flags = ['INCOMPLETE', 'DUPLICATE_TAP'];
            if ($count > 2) {
                $flags[] = 'MULTI_PUNCH_REVIEW';
            }
            if ($schedule->isTimeOutPunch($last)) {
                $flags[] = 'TIMEOUT_ONLY';
                return new PunchDayResult(null, $last, PunchDayResult::STATUS_INCOMPLETE, $flags);
            }
            return new PunchDayResult($first, null, PunchDayResult::STATUS_INCOMPLETE, $flags);
        }

        $flags  = [];
        $status = PunchDayResult::STATUS_COMPLETE;

        if ($count > 2) {
            $events = $this->scanEvents($punches, $schedule);
            if (count($events) < $count) {
                // Not DUPLICATE_TAP: that flag means the opposite punch is missing.
                $flags[] = 'REPEATED_SCAN';
            }

            $eventCount   = count($events);
            $arrival      = $events[0][0];
            $departureEnd = $events[$eventCount - 1][count($events[$eventCount - 1]) - 1];
            $inAndOut     = !$schedule->isTimeOutPunch($arrival) && $schedule->isTimeOutPunch($departureEnd);

            if ($inAndOut && $eventCount === 2) {
                // Arrival and departure, one of them scanned more than once.
            } elseif ($inAndOut && $eventCount === 4
                && $this->isLunchBreak($events[1][count($events[1]) - 1], $events[2][0], $first, $schedule)) {
                $flags[] = 'LUNCH_PUNCHES';
            } else {
                $flags[] = 'MULTI_PUNCH_REVIEW';
                $status  = PunchDayResult::STATUS_REVIEW_REQUIRED;
            }
        }

        [$worked, $late, $undertime] = $schedule->minutesFor($first, $last);
        $overtime = max(0, $worked - $schedule->standardMinutes);

        return new PunchDayResult($first, $last, $status, $flags, $worked, $late, $undertime, $overtime);
    }

    /**
     * Groups repeated scans of the same moment into one scan event.
     *
     * Arrival: later scans join the first punch while they stay on the
     * arrival side of the midpoint and within DUPLICATE_TAP_MAX_MINUTES of
     * it (e.g. 06:44 + 07:13). Departure: the same, counted back from the
     * last punch (e.g. 16:31 + 16:42). Scans in between join only as an
     * immediate double tap (IMMEDIATE_DOUBLE_TAP_MINUTES), so a lunch out at
     * 12:02 and lunch in at 12:58 stay two separate events.
     *
     * @param list<DateTimeImmutable> $punches Sorted ascending, at least 2.
     * @return list<list<DateTimeImmutable>> At least 2 events, none empty.
     */
    private function scanEvents(array $punches, WorkSchedule $schedule): array
    {
        $count = count($punches);
        $first = $punches[0];
        $last  = $punches[$count - 1];

        // Arrival scan event: indexes [0, $arrivalEnd].
        $arrivalEnd = 0;
        for ($i = 1; $i < $count - 1; $i++) {
            if (!$this->sameScan($punches[$i - 1], $punches[$i], $punches[$i], $first, $schedule)) {
                break;
            }
            $arrivalEnd = $i;
        }

        // Departure scan event: indexes [$departureStart, $count - 1].
        $departureStart = $count - 1;
        for ($i = $count - 2; $i > $arrivalEnd; $i--) {
            if (!$this->sameScan($punches[$i], $punches[$i + 1], $punches[$i], $last, $schedule)) {
                break;
            }
            $departureStart = $i;
        }

        $events = [array_slice($punches, 0, $arrivalEnd + 1)];
        $middle = [];
        for ($i = $arrivalEnd + 1; $i < $departureStart; $i++) {
            $previous = $middle === [] ? null : $middle[count($middle) - 1];
            if ($previous !== null
                && intdiv($punches[$i]->getTimestamp() - $previous[count($previous) - 1]->getTimestamp(), 60)
                    <= WorkSchedule::IMMEDIATE_DOUBLE_TAP_MINUTES) {
                $middle[count($middle) - 1][] = $punches[$i];
                continue;
            }
            $middle[] = [$punches[$i]];
        }
        foreach ($middle as $event) {
            $events[] = $event;
        }
        $events[] = array_slice($punches, $departureStart);

        return $events;
    }

    /**
     * $candidate (one of the neighbours $earlier/$later) belongs to the same
     * arrival or departure scan as $anchor (the day's first or last punch).
     */
    private function sameScan(
        DateTimeImmutable $earlier,
        DateTimeImmutable $later,
        DateTimeImmutable $candidate,
        DateTimeImmutable $anchor,
        WorkSchedule $schedule,
    ): bool {
        $gap = intdiv($later->getTimestamp() - $earlier->getTimestamp(), 60);
        if ($gap <= WorkSchedule::IMMEDIATE_DOUBLE_TAP_MINUTES) {
            return true;
        }
        $fromAnchor = abs(intdiv($candidate->getTimestamp() - $anchor->getTimestamp(), 60));

        return $fromAnchor <= WorkSchedule::DUPLICATE_TAP_MAX_MINUTES
            && $schedule->isTimeOutPunch($candidate) === $schedule->isTimeOutPunch($anchor);
    }

    /** A lunch out/in pair: no longer than the unpaid break plus grace, and inside the break window if one is set. */
    private function isLunchBreak(
        DateTimeImmutable $lunchOut,
        DateTimeImmutable $lunchIn,
        DateTimeImmutable $shiftAnchor,
        WorkSchedule $schedule,
    ): bool {
        $allowance = $schedule->unpaidBreakMinutes + $schedule->graceMinutes;
        if ($schedule->unpaidBreakMinutes <= 0) {
            return false;
        }
        $gap = intdiv($lunchIn->getTimestamp() - $lunchOut->getTimestamp(), 60);
        if ($gap <= 0 || $gap > $allowance) {
            return false;
        }
        if ($schedule->hasBreakWindow()) {
            return $schedule->breakOverlapMinutes($lunchOut, $lunchIn, $shiftAnchor) > 0;
        }
        return true;
    }
}

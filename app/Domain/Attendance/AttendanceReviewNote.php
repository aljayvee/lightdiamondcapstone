<?php

declare(strict_types=1);

namespace Wbpms\Domain\Attendance;

use DateTimeImmutable;

/**
 * Explains in plain language why an imported attendance day is in
 * ReviewRequired or Incomplete status, using its immutable biometric punches
 * and the effective WorkSchedule.
 */
final class AttendanceReviewNote
{
    /**
     * @param list<DateTimeImmutable> $punches
     */
    public static function describe(string $status, array $punches, WorkSchedule $schedule): ?string
    {
        if ($punches === []) {
            return null;
        }

        usort($punches, static fn (DateTimeImmutable $a, DateTimeImmutable $b): int => $a <=> $b);
        $count = count($punches);

        if ($status === PunchDayResult::STATUS_REVIEW_REQUIRED) {
            return self::describeReviewRequired($punches, $count, $schedule);
        }

        if ($status === PunchDayResult::STATUS_INCOMPLETE) {
            return self::describeIncomplete($punches, $count, $schedule);
        }

        return null;
    }

    /**
     * @param list<DateTimeImmutable> $punches Sorted ascending, non-empty.
     */
    private static function describeReviewRequired(array $punches, int $count, WorkSchedule $schedule): string
    {
        if ($count === 4) {
            $first    = $punches[0];
            $midStart = $punches[1];
            $midEnd   = $punches[2];
            $last     = $punches[3];

            $gapMinutes = max(0, intdiv($midEnd->getTimestamp() - $midStart->getTimestamp(), 60));
            $allowance  = $schedule->unpaidBreakMinutes + $schedule->graceMinutes;

            if ($schedule->unpaidBreakMinutes > 0 && $gapMinutes > $allowance) {
                return sprintf(
                    '4 biometric punches were recorded. The midday gap between %s and %s is %d min (%s), '
                    . 'which exceeds the %d-min scheduled break allowance. '
                    . 'Confirm %s–%s as-is with a reason, or enter corrected times below.',
                    $midStart->format('H:i'),
                    $midEnd->format('H:i'),
                    $gapMinutes,
                    self::formatDuration($gapMinutes),
                    $allowance,
                    $first->format('H:i'),
                    $last->format('H:i'),
                );
            }

            if ($schedule->hasBreakWindow() && $schedule->breakOverlapMinutes($midStart, $midEnd, $first) === 0) {
                return sprintf(
                    '4 biometric punches were recorded. The middle scans (%s–%s) fall outside the '
                    . 'scheduled break window (%s–%s). Confirm %s–%s as-is with a reason, or enter corrected times below.',
                    $midStart->format('H:i'),
                    $midEnd->format('H:i'),
                    (string) $schedule->breakStartTime,
                    (string) $schedule->breakEndTime,
                    $first->format('H:i'),
                    $last->format('H:i'),
                );
            }
        }

        return sprintf(
            '%d biometric punches were recorded that did not match a standard arrival/departure or scheduled lunch break pattern. '
            . 'Confirm the paired times below with a reason, or enter corrected times.',
            $count,
        );
    }

    /**
     * @param list<DateTimeImmutable> $punches Sorted ascending, non-empty.
     */
    private static function describeIncomplete(array $punches, int $count, WorkSchedule $schedule): string
    {
        $first = $punches[0];
        $last  = $punches[$count - 1];

        if ($count === 1) {
            if ($schedule->isTimeOutPunch($first)) {
                return sprintf(
                    'Only 1 biometric punch (%s) was recorded after the shift midpoint (treated as Time Out). '
                    . 'Enter the missing Time In with supporting evidence.',
                    $first->format('H:i'),
                );
            }

            return sprintf(
                'Only 1 biometric punch (%s) was recorded before the shift midpoint (treated as Time In). '
                . 'Enter the missing Time Out with supporting evidence.',
                $first->format('H:i'),
            );
        }

        if ($schedule->isDuplicateTapSpan($first, $last)) {
            $side = $schedule->isTimeOutPunch($last) ? 'departure (Time Out)' : 'arrival (Time In)';
            return sprintf(
                '%d biometric punches (%s–%s) were recorded within one %s window (double scan), '
                . 'with the opposite punch missing. Enter both valid times with supporting evidence.',
                $count,
                $first->format('H:i'),
                $last->format('H:i'),
                $side,
            );
        }

        return 'This day is marked Incomplete. Verify the raw punches above and enter both Time In and Time Out with supporting evidence.';
    }

    private static function formatDuration(int $minutes): string
    {
        $hours = intdiv($minutes, 60);
        $mins  = $minutes % 60;
        if ($hours === 0) {
            return sprintf('%dm', $mins);
        }
        return sprintf('%dh %02dm', $hours, $mins);
    }
}

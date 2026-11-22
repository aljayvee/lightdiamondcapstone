<?php

declare(strict_types=1);

namespace Wbpms\Domain\Attendance;

use Wbpms\Domain\Attendance\Parsing\ParsedPunch;

/** Builds one day's attendance from parsed punches using the shared PunchDayClassifier rule. */
final class TimesheetGenerator
{
    private PunchDayClassifier $classifier;

    public function __construct(?PunchDayClassifier $classifier = null)
    {
        $this->classifier = $classifier ?? new PunchDayClassifier();
    }

    /** @param list<ParsedPunch> $punches */
    public function generate(int $employeeId, string $attendanceDate, array $punches, WorkSchedule $schedule): GeneratedAttendance
    {
        usort($punches, static fn (ParsedPunch $left, ParsedPunch $right): int => $left->localTimestamp <=> $right->localTimestamp);

        $result = $this->classifier->classify(
            array_map(static fn (ParsedPunch $punch) => $punch->localTimestamp, $punches),
            $schedule,
        );

        return new GeneratedAttendance(
            $employeeId,
            $attendanceDate,
            $result->timeIn,
            $result->timeOut,
            $result->workedMinutes,
            $result->lateMinutes,
            $result->undertimeMinutes,
            $result->overtimeMinutes,
            $punches,
            $result->flags,
            $result->status,
        );
    }
}

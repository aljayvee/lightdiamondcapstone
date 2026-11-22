<?php

declare(strict_types=1);

namespace Wbpms\Domain\Attendance;

use Wbpms\Domain\Attendance\Parsing\ParsedPunch;
use DateTimeImmutable;

final class GeneratedAttendance
{
    /** @param list<ParsedPunch> $punches @param list<string> $flags */
    public function __construct(
        public int $employeeId,
        public string $attendanceDate,
        public ?DateTimeImmutable $timeIn,
        public ?DateTimeImmutable $timeOut,
        public int $workedMinutes,
        public int $lateMinutes,
        public int $undertimeMinutes,
        public int $overtimeMinutes,
        public array $punches,
        public array $flags,
        private ?string $status = null,
    ) {
    }

    public function isIncomplete(): bool
    {
        return in_array('INCOMPLETE', $this->flags, true);
    }

    /** Stored attendance status: Complete, Incomplete or ReviewRequired. */
    public function status(): string
    {
        if ($this->status !== null) {
            return $this->status;
        }
        if ($this->isIncomplete()) {
            return PunchDayResult::STATUS_INCOMPLETE;
        }
        return in_array('MULTI_PUNCH_REVIEW', $this->flags, true)
            ? PunchDayResult::STATUS_REVIEW_REQUIRED
            : PunchDayResult::STATUS_COMPLETE;
    }
}

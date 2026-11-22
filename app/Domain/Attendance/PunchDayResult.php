<?php

declare(strict_types=1);

namespace Wbpms\Domain\Attendance;

use DateTimeImmutable;

/** How one employee-day of raw biometric punches is stored and paid. */
final class PunchDayResult
{
    public const STATUS_COMPLETE        = 'Complete';
    public const STATUS_INCOMPLETE      = 'Incomplete';
    public const STATUS_REVIEW_REQUIRED = 'ReviewRequired';

    /** @param list<string> $flags */
    public function __construct(
        public readonly ?DateTimeImmutable $timeIn,
        public readonly ?DateTimeImmutable $timeOut,
        public readonly string $status,
        public readonly array $flags,
        public readonly int $workedMinutes = 0,
        public readonly int $lateMinutes = 0,
        public readonly int $undertimeMinutes = 0,
        public readonly int $overtimeMinutes = 0,
    ) {
    }
}

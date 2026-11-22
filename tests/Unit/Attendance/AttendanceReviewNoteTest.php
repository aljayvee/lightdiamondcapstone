<?php

declare(strict_types=1);

namespace Wbpms\Tests\Unit\Attendance;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Wbpms\Domain\Attendance\AttendanceReviewNote;
use Wbpms\Domain\Attendance\PunchDayResult;
use Wbpms\Domain\Attendance\WorkSchedule;

final class AttendanceReviewNoteTest extends TestCase
{
    private DateTimeZone $tz;
    private WorkSchedule $dayShift;

    protected function setUp(): void
    {
        $this->tz       = new DateTimeZone('Asia/Manila');
        $this->dayShift = new WorkSchedule(5, '07:00', '16:30', 60, 510, 0, false, '12:00', '13:00');
    }

    public function testExplainsExcessiveMiddayGapForFourPunchReviewRequiredDay(): void
    {
        $punches = $this->punches('2026-07-20', ['07:02', '11:47', '14:46', '16:32']);

        $note = AttendanceReviewNote::describe(PunchDayResult::STATUS_REVIEW_REQUIRED, $punches, $this->dayShift);

        self::assertNotNull($note);
        self::assertStringContainsString('4 biometric punches', $note);
        self::assertStringContainsString('11:47 and 14:46', $note);
        self::assertStringContainsString('179 min (2h 59m)', $note);
        self::assertStringContainsString('60-min scheduled break', $note);
        self::assertStringContainsString('07:02', $note);
        self::assertStringContainsString('16:32', $note);
    }

    public function testExplainsFourPunchesOutsideBreakWindow(): void
    {
        $punches = $this->punches('2026-07-20', ['07:02', '09:00', '09:40', '16:32']);

        $note = AttendanceReviewNote::describe(PunchDayResult::STATUS_REVIEW_REQUIRED, $punches, $this->dayShift);

        self::assertNotNull($note);
        self::assertStringContainsString('09:00', $note);
        self::assertStringContainsString('09:40', $note);
        self::assertStringContainsString('outside the scheduled break window (12:00', $note);
    }

    public function testExplainsOddThreePunchPatternForReviewRequired(): void
    {
        $punches = $this->punches('2026-08-21', ['07:47', '17:08', '20:36']);

        $note = AttendanceReviewNote::describe(PunchDayResult::STATUS_REVIEW_REQUIRED, $punches, $this->dayShift);

        self::assertNotNull($note);
        self::assertStringContainsString('3 biometric punches', $note);
        self::assertStringContainsString('did not match a standard arrival/departure or scheduled lunch break pattern', $note);
    }

    public function testExplainsSingleMorningPunchIncomplete(): void
    {
        $punches = $this->punches('2026-07-24', ['07:11']);

        $note = AttendanceReviewNote::describe(PunchDayResult::STATUS_INCOMPLETE, $punches, $this->dayShift);

        self::assertNotNull($note);
        self::assertStringContainsString('Only 1 biometric punch (07:11)', $note);
        self::assertStringContainsString('treated as Time In', $note);
        self::assertStringContainsString('missing Time Out', $note);
    }

    public function testExplainsSingleAfternoonPunchIncomplete(): void
    {
        $punches = $this->punches('2026-07-24', ['16:45']);

        $note = AttendanceReviewNote::describe(PunchDayResult::STATUS_INCOMPLETE, $punches, $this->dayShift);

        self::assertNotNull($note);
        self::assertStringContainsString('Only 1 biometric punch (16:45)', $note);
        self::assertStringContainsString('treated as Time Out', $note);
        self::assertStringContainsString('missing Time In', $note);
    }

    public function testExplainsDuplicateTapIncomplete(): void
    {
        $punches = $this->punches('2026-07-02', ['06:49', '06:59']);

        $note = AttendanceReviewNote::describe(PunchDayResult::STATUS_INCOMPLETE, $punches, $this->dayShift);

        self::assertNotNull($note);
        self::assertStringContainsString('2 biometric punches (06:49', $note);
        self::assertStringContainsString('arrival (Time In)', $note);
        self::assertStringContainsString('double scan', $note);
    }

    public function testReturnsNullForCompleteOrEmptyPunches(): void
    {
        $punches = $this->punches('2026-07-20', ['07:02', '16:32']);

        self::assertNull(AttendanceReviewNote::describe(PunchDayResult::STATUS_COMPLETE, $punches, $this->dayShift));
        self::assertNull(AttendanceReviewNote::describe('Approved', $punches, $this->dayShift));
        self::assertNull(AttendanceReviewNote::describe(PunchDayResult::STATUS_REVIEW_REQUIRED, [], $this->dayShift));
    }

    /**
     * @param list<string> $times
     * @return list<DateTimeImmutable>
     */
    private function punches(string $date, array $times): array
    {
        return array_map(
            fn (string $hm): DateTimeImmutable => new DateTimeImmutable("{$date} {$hm}:00", $this->tz),
            $times,
        );
    }
}

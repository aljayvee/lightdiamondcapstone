<?php

declare(strict_types=1);

namespace Wbpms\Tests\Unit\Attendance;

use Wbpms\Domain\Attendance\AttendancePolicyEvaluator;
use PHPUnit\Framework\TestCase;

/**
 * Pure unit tests for AttendancePolicyEvaluator (REQ006 AC16–AC17).
 *
 * The evaluator has no PDO/HTTP/session dependency, so these tests run
 * without a database.
 */
final class AttendancePolicyEvaluatorTest extends TestCase
{
    private function attended(string $date, int $late = 0): array
    {
        return [
            'attendance_date' => $date,
            'time_in' => '08:00:00',
            'late_minutes' => $late,
            'status' => 'Complete',
        ];
    }

    private function cancelled(string $date): array
    {
        return [
            'attendance_date' => $date,
            'time_in' => null,
            'late_minutes' => 0,
            'status' => 'Cancelled',
        ];
    }

    private function rowsOf(array $flags, string $type): array
    {
        return array_filter($flags, fn (array $f): bool => $f['flag_type'] === $type);
    }

    public function testThreeConsecutiveLatesRaiseAConsecutiveLateFlag(): void
    {
        $proposals = (new AttendancePolicyEvaluator())->evaluate([
            $this->attended('2026-08-03', 5),
            $this->attended('2026-08-04', 10),
            $this->attended('2026-08-05', 20),
        ]);

        self::assertCount(1, $proposals);
        self::assertSame('ConsecutiveLate', $proposals[0]['flag_type']);
        self::assertSame('2026-08-05', $proposals[0]['triggering_date']);
    }

    public function testFewerThanThreeLatesDoNotRaise(): void
    {
        $proposals = (new AttendancePolicyEvaluator())->evaluate([
            $this->attended('2026-08-03', 5),
            $this->attended('2026-08-04', 10),
        ]);
        self::assertSame([], $proposals);
    }

    public function testAnOnTimeDayBreaksTheLateStreak(): void
    {
        $proposals = (new AttendancePolicyEvaluator())->evaluate([
            $this->attended('2026-08-03', 5),
            $this->attended('2026-08-04', 10),
            $this->attended('2026-08-05', 0),
            $this->attended('2026-08-06', 15),
            $this->attended('2026-08-07', 25),
            $this->attended('2026-08-08', 35),
        ]);

        $lates = $this->rowsOf($proposals, 'ConsecutiveLate');
        self::assertCount(1, $lates);
        self::assertSame('2026-08-08', $lates[0]['triggering_date']);
    }

    public function testNonConsecutiveLateDaysDoNotRaise(): void
    {
        $proposals = (new AttendancePolicyEvaluator())->evaluate([
            $this->attended('2026-08-03', 5),
            $this->attended('2026-08-05', 10),
            $this->attended('2026-08-07', 20),
        ]);
        self::assertSame([], $proposals);
    }

    public function testExistingFlagSuppressesTheDuplicateProposal(): void
    {
        $existing = [['flag_type' => 'ConsecutiveLate', 'triggering_date' => '2026-08-05']];
        $proposals = (new AttendancePolicyEvaluator())->evaluate([
            $this->attended('2026-08-03', 5),
            $this->attended('2026-08-04', 10),
            $this->attended('2026-08-05', 20),
        ], $existing);
        self::assertSame([], $proposals);
    }

    public function testThirdTardinessMemoRaisesSuspensionReviewFlag(): void
    {
        $proposals = (new AttendancePolicyEvaluator())->evaluate([
            $this->attended('2026-08-03', 5),
            $this->attended('2026-08-04', 10),
            $this->attended('2026-08-05', 15),
        ], [], [], 2);

        self::assertSame(['ConsecutiveLate', 'TardinessMemoCap'], array_column($proposals, 'flag_type'));
        self::assertSame('2026-08-05', $proposals[1]['triggering_date']);
    }

    public function testThreeUnexcusedAbsencesRaiseAnAwolReviewFlag(): void
    {
        $proposals = (new AttendancePolicyEvaluator())->evaluate([
            $this->attended('2026-08-03'),
            $this->attended('2026-08-07'),
        ]);

        $awol = array_values($this->rowsOf($proposals, 'ConsecutiveAWOL'));
        self::assertCount(1, $awol);
        self::assertSame('2026-08-06', $awol[0]['triggering_date']);
    }

    public function testApprovedLeaveResetsAnUnexcusedAbsenceRun(): void
    {
        $proposals = (new AttendancePolicyEvaluator())->evaluate([
            $this->attended('2026-08-03'),
            $this->attended('2026-08-08'),
        ], [], ['2026-08-05']);

        self::assertSame([], $this->rowsOf($proposals, 'ConsecutiveAWOL'));
    }

    public function testFourteenUnexcusedCalendarDaysRaiseTwoWeekAbsenceFlag(): void
    {
        $proposals = (new AttendancePolicyEvaluator())->evaluate([
            $this->attended('2026-08-01'),
            $this->attended('2026-08-16'),
        ]);

        $twoWeek = array_values($this->rowsOf($proposals, 'TwoWeekAbsence'));
        self::assertCount(1, $twoWeek);
        self::assertSame('2026-08-15', $twoWeek[0]['triggering_date']);
    }

    // 2026-08-08 and 2026-08-15 are Saturdays — the client's Sabbath rest day.

    public function testARestDayDoesNotBreakALateStreak(): void
    {
        $proposals = (new AttendancePolicyEvaluator())->evaluate([
            $this->attended('2026-08-07', 5),   // Fri
            $this->attended('2026-08-09', 5),   // Sun (Sat 8 is rest)
            $this->attended('2026-08-10', 5),   // Mon
        ], [], [], 0, ['2026-08-08']);

        $late = array_values($this->rowsOf($proposals, 'ConsecutiveLate'));
        self::assertCount(1, $late);
        self::assertSame('2026-08-10', $late[0]['triggering_date']);
    }

    public function testARestDayIsNotCountedAsAnAbsence(): void
    {
        // Fri 7 and Sun 9 missed, Sat 8 is rest: two missed working days, not three.
        $proposals = (new AttendancePolicyEvaluator())->evaluate([
            $this->attended('2026-08-06'),
            $this->attended('2026-08-10'),
        ], [], [], 0, ['2026-08-08']);

        self::assertSame([], $this->rowsOf($proposals, 'ConsecutiveAWOL'));
    }

    public function testThreeMissedWorkingDaysAcrossARestDayRaiseAwol(): void
    {
        $proposals = (new AttendancePolicyEvaluator())->evaluate([
            $this->attended('2026-08-06'),
            $this->attended('2026-08-11'),
        ], [], [], 0, ['2026-08-08']);

        $awol = array_values($this->rowsOf($proposals, 'ConsecutiveAWOL'));
        self::assertCount(1, $awol);
        self::assertSame('2026-08-10', $awol[0]['triggering_date']);
    }

    public function testTwoWeekAbsenceStillCountsCalendarDaysIncludingRestDays(): void
    {
        $proposals = (new AttendancePolicyEvaluator())->evaluate([
            $this->attended('2026-08-01'),
            $this->attended('2026-08-16'),
        ], [], [], 0, ['2026-08-08', '2026-08-15']);

        $twoWeek = array_values($this->rowsOf($proposals, 'TwoWeekAbsence'));
        self::assertCount(1, $twoWeek);
        self::assertSame('2026-08-15', $twoWeek[0]['triggering_date']);
    }

    public function testATimeOutOnlyDayCountsAsReporting(): void
    {
        $proposals = (new AttendancePolicyEvaluator())->evaluate([
            $this->attended('2026-08-03'),
            ['attendance_date' => '2026-08-04', 'time_in' => null, 'time_out' => '16:57:00', 'late_minutes' => 0, 'status' => 'Incomplete'],
            $this->attended('2026-08-07'),
        ]);

        self::assertSame([], $this->rowsOf($proposals, 'ConsecutiveAWOL'));
    }
}

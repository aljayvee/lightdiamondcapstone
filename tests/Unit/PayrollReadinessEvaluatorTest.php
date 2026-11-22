<?php

declare(strict_types=1);

namespace Wbpms\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Wbpms\Domain\Payroll\PayrollReadinessEvaluator;

/** Owner logic review C6: days payroll would leave unpaid must be listed before Compute. */
final class PayrollReadinessEvaluatorTest extends TestCase
{
    /** Fri 2026-11-20 .. Thu 2026-11-26; Saturday 11-21 is the Sabbath rest day. */
    private const WEEK = ['2026-11-20', '2026-11-21', '2026-11-22', '2026-11-23', '2026-11-24', '2026-11-25', '2026-11-26'];

    /** @return array<string,bool> */
    private function sundayToFriday(): array
    {
        $scheduled = array_fill_keys(self::WEEK, true);
        $scheduled['2026-11-21'] = false;
        return $scheduled;
    }

    /**
     * @param array<string,array{status:string,upload_approved:bool}> $attendance
     * @param array<string,bool> $leave
     * @param array<string,bool> $holidays
     * @param array<string,bool>|null $scheduled
     * @return list<array{date:string,issue:string}>
     */
    private function evaluate(array $attendance, array $leave = [], array $holidays = [], ?array $scheduled = null): array
    {
        return (new PayrollReadinessEvaluator())->evaluate(
            self::WEEK,
            $scheduled ?? $this->sundayToFriday(),
            $attendance,
            $holidays,
            $leave,
            array_fill_keys(self::WEEK, true),
        );
    }

    /** @return array<string,array{status:string,upload_approved:bool}> */
    private function fullWeek(): array
    {
        $rows = [];
        foreach (self::WEEK as $date) {
            if ($date !== '2026-11-21') {
                $rows[$date] = ['status' => 'Complete', 'upload_approved' => true];
            }
        }
        return $rows;
    }

    public function testAFullyResolvedWeekHasNoIssues(): void
    {
        self::assertSame([], $this->evaluate($this->fullWeek()));
    }

    public function testIncompleteAndReviewDaysAreListed(): void
    {
        $attendance = $this->fullWeek();
        $attendance['2026-11-23'] = ['status' => 'Incomplete', 'upload_approved' => true];
        $attendance['2026-11-24'] = ['status' => 'ReviewRequired', 'upload_approved' => true];

        self::assertSame([
            ['date' => '2026-11-23', 'issue' => PayrollReadinessEvaluator::INCOMPLETE],
            ['date' => '2026-11-24', 'issue' => PayrollReadinessEvaluator::REVIEW_REQUIRED],
        ], $this->evaluate($attendance));
    }

    public function testAttendanceFromAnUnapprovedUploadIsListed(): void
    {
        $attendance = $this->fullWeek();
        $attendance['2026-11-25'] = ['status' => 'Complete', 'upload_approved' => false];

        self::assertSame(
            [['date' => '2026-11-25', 'issue' => PayrollReadinessEvaluator::UPLOAD_NOT_APPROVED]],
            $this->evaluate($attendance),
        );
    }

    public function testDaysAfterTheEndOfTheUploadedFileAreListed(): void
    {
        $attendance = $this->fullWeek();
        unset($attendance['2026-11-25'], $attendance['2026-11-26']);
        $uploaded = array_fill_keys(['2026-11-20', '2026-11-21', '2026-11-22', '2026-11-23', '2026-11-24'], true);

        $issues = (new PayrollReadinessEvaluator())->evaluate(
            self::WEEK, $this->sundayToFriday(), $attendance, [], [], $uploaded,
        );

        self::assertSame([
            ['date' => '2026-11-25', 'issue' => PayrollReadinessEvaluator::NO_UPLOAD],
            ['date' => '2026-11-26', 'issue' => PayrollReadinessEvaluator::NO_UPLOAD],
        ], $issues);
    }

    public function testKnownAbsencesRestDaysHolidaysAndLeaveAreNotListed(): void
    {
        $attendance = $this->fullWeek();
        unset($attendance['2026-11-23'], $attendance['2026-11-24'], $attendance['2026-11-25']);

        // 11-23 absent inside an approved upload (already shown as Unpaid absence),
        // 11-24 on approved leave, 11-25 a holiday, 11-21 the rest day.
        self::assertSame([], $this->evaluate(
            $attendance,
            ['2026-11-24' => true],
            ['2026-11-25' => true],
        ));
    }

    public function testAnUnresolvedRestDayIsStillListed(): void
    {
        $attendance = $this->fullWeek();
        $attendance['2026-11-21'] = ['status' => 'Incomplete', 'upload_approved' => true];

        self::assertSame(
            [['date' => '2026-11-21', 'issue' => PayrollReadinessEvaluator::INCOMPLETE]],
            $this->evaluate($attendance),
        );
    }

    public function testDatesWithoutAScheduleAreListed(): void
    {
        $issues = $this->evaluate(['2026-11-20' => ['status' => 'Complete', 'upload_approved' => true]], [], [], []);

        self::assertCount(6, $issues);
        self::assertSame(PayrollReadinessEvaluator::NO_SCHEDULE, $issues[0]['issue']);
        self::assertSame('2026-11-21', $issues[0]['date']);
    }

    public function testEveryIssueHasAPlainLabel(): void
    {
        foreach ([
            PayrollReadinessEvaluator::INCOMPLETE,
            PayrollReadinessEvaluator::REVIEW_REQUIRED,
            PayrollReadinessEvaluator::UPLOAD_NOT_APPROVED,
            PayrollReadinessEvaluator::NO_UPLOAD,
            PayrollReadinessEvaluator::NO_SCHEDULE,
        ] as $issue) {
            self::assertNotSame($issue, PayrollReadinessEvaluator::label($issue));
        }
    }
}

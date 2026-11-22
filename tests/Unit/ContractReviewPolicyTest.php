<?php

declare(strict_types=1);

namespace Wbpms\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Wbpms\Domain\Employee\ContractReviewPolicy;

/** Contract review date for Contractual / Probationary employees. [EMP-024] */
final class ContractReviewPolicyTest extends TestCase
{
    private function today(string $date = '2026-11-21'): DateTimeImmutable
    {
        return new DateTimeImmutable($date, new DateTimeZone('Asia/Manila'));
    }

    #[Test]
    public function onlyContractualAndProbationaryNeedAReviewDate(): void
    {
        self::assertTrue(ContractReviewPolicy::needsReview('Contractual'));
        self::assertTrue(ContractReviewPolicy::needsReview('Probationary'));
        self::assertFalse(ContractReviewPolicy::needsReview('Regular'));
        self::assertFalse(ContractReviewPolicy::needsReview(''));
    }

    #[Test]
    public function regularEmployeesNeverFailTheReviewDateCheck(): void
    {
        self::assertNull(ContractReviewPolicy::validate('Regular', '', '2026-11-01', $this->today()));
        self::assertNull(ContractReviewPolicy::validate('Regular', 'garbage', '2026-11-01', $this->today()));
    }

    #[Test]
    public function contractualEmployeeRequiresAReviewDate(): void
    {
        $error = ContractReviewPolicy::validate('Contractual', '', '2026-11-01', $this->today());
        self::assertSame('Contract review date is required for Contractual employees.', $error);
    }

    #[Test]
    public function reviewDateMustBeARealDate(): void
    {
        self::assertSame(
            'Contract review date must be a valid date.',
            ContractReviewPolicy::validate('Contractual', '2027-02-30', '2026-11-01', $this->today()),
        );
        self::assertSame(
            'Contract review date must be a valid date.',
            ContractReviewPolicy::validate('Contractual', '05/01/27', '2026-11-01', $this->today()),
        );
    }

    #[Test]
    public function reviewDateMustBeAfterTheStartDate(): void
    {
        self::assertNotNull(ContractReviewPolicy::validate('Contractual', '2026-11-01', '2026-11-01', $this->today()));
        self::assertNotNull(ContractReviewPolicy::validate('Probationary', '2026-10-01', '2026-11-01', $this->today()));
        self::assertNull(ContractReviewPolicy::validate('Contractual', '2026-11-02', '2026-11-01', $this->today()));
    }

    #[Test]
    public function anOverdueDateOnAnOldHireIsStillAccepted(): void
    {
        // HR may save other edits before acting on an overdue review.
        self::assertNull(ContractReviewPolicy::validate('Contractual', '2026-05-01', '2025-11-01', $this->today()));
    }

    #[Test]
    public function aYearTypoFarInTheFutureIsRejected(): void
    {
        self::assertNotNull(ContractReviewPolicy::validate('Contractual', '2062-05-01', '2026-11-01', $this->today()));
        self::assertNull(ContractReviewPolicy::validate('Contractual', '2031-11-21', '2026-11-01', $this->today()));
    }

    #[Test]
    public function defaultIsSixMonthsAfterTheStartDate(): void
    {
        self::assertSame('2027-05-21', ContractReviewPolicy::defaultDate('2026-11-21'));
        self::assertNull(ContractReviewPolicy::defaultDate(''));
    }

    #[Test]
    public function hrHeadIsRemindedFourteenDaysAhead(): void
    {
        self::assertSame('2026-12-05', ContractReviewPolicy::dueCutoff($this->today()));
    }

    #[Test]
    public function labelsCountDaysInManilaCalendarDays(): void
    {
        $today = $this->today();
        self::assertSame('Due today', ContractReviewPolicy::label('2026-11-21', $today));
        self::assertSame('Due in 1 day', ContractReviewPolicy::label('2026-11-22', $today));
        self::assertSame('Due in 14 days', ContractReviewPolicy::label('2026-12-05', $today));
        self::assertSame('Overdue by 1 day', ContractReviewPolicy::label('2026-11-20', $today));
        self::assertSame('Overdue by 31 days', ContractReviewPolicy::label('2026-10-21', $today));
        self::assertSame('No review date set', ContractReviewPolicy::label(null, $today));
        self::assertSame(-31, ContractReviewPolicy::daysUntil('2026-10-21', $today));
    }
}

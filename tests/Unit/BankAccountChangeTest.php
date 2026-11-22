<?php

declare(strict_types=1);

namespace Wbpms\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Wbpms\Domain\Employee\BankAccountChange;
use Wbpms\Domain\Employee\BankAccountDateRejected;

/**
 * Bank account effective-date rule vs CHECK chk_bank_period
 * (effective_to IS NULL OR effective_to > effective_from). [EMP-026]
 */
final class BankAccountChangeTest extends TestCase
{
    #[Test]
    public function firstAccountIsAPlainInsert(): void
    {
        self::assertSame(BankAccountChange::First, BankAccountChange::decide(null, '2026-11-22'));
        self::assertSame(BankAccountChange::First, BankAccountChange::decide('', '2026-11-22'));
    }

    #[Test]
    public function laterDateSupersedesTheCurrentAccount(): void
    {
        self::assertSame(BankAccountChange::Supersede, BankAccountChange::decide('2020-01-01', '2026-11-22'));
        self::assertSame(BankAccountChange::Supersede, BankAccountChange::decide('2026-11-21', '2026-11-22'));
    }

    /** Prod 11/22/26, employee 36: current 2020-01-01, form re-submitted 2020-01-01. */
    #[Test]
    public function sameDateCorrectsInPlaceInsteadOfClosingAZeroLengthPeriod(): void
    {
        self::assertSame(BankAccountChange::Correct, BankAccountChange::decide('2020-01-01', '2020-01-01'));
        // DATE columns may come back with a time part from some drivers.
        self::assertSame(BankAccountChange::Correct, BankAccountChange::decide('2020-01-01 00:00:00', '2020-01-01'));
    }

    #[Test]
    public function earlierDateIsRejected(): void
    {
        self::assertSame(BankAccountChange::Reject, BankAccountChange::decide('2020-01-01', '2019-12-31'));
        self::assertSame(BankAccountChange::Reject, BankAccountChange::decide('2026-11-23', '2026-11-22'));
    }

    /** Whatever decide() lets through to a close must satisfy chk_bank_period. */
    #[Test]
    public function supersedeAlwaysClosesWithEffectiveToAfterEffectiveFrom(): void
    {
        $current = '2025-05-01';
        foreach (['2025-04-30', '2025-05-01', '2025-05-02', '2026-11-22'] as $new) {
            $closes = BankAccountChange::decide($current, $new) === BankAccountChange::Supersede;
            // The old row gets effective_to = $new.
            self::assertSame($new > $current, $closes, "new date {$new}");
        }
    }

    #[Test]
    public function rejectionMessageNamesTheCurrentDateInUiFormat(): void
    {
        $e = BankAccountDateRejected::before('2020-01-01');

        self::assertInstanceOf(\DomainException::class, $e);
        self::assertStringContainsString('01/01/20', $e->getMessage());
        self::assertStringNotContainsString('SQLSTATE', $e->getMessage());
    }
}

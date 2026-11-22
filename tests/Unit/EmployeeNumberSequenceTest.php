<?php

declare(strict_types=1);

namespace Wbpms\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Wbpms\Domain\Employee\EmployeeNumberSequence;

/**
 * Auto-generated employee numbers: EMP-001, EMP-002, ... [EMP-020]
 */
final class EmployeeNumberSequenceTest extends TestCase
{
    #[Test]
    public function theFirstNumberIsEmp001(): void
    {
        self::assertSame('EMP-001', EmployeeNumberSequence::next([]));
    }

    #[Test]
    public function theNextNumberFollowsTheLastAddedOne(): void
    {
        self::assertSame('EMP-002', EmployeeNumberSequence::next(['EMP-001']));
        self::assertSame('EMP-013', EmployeeNumberSequence::next(['EMP-003', 'EMP-012', 'EMP-007']));
    }

    #[Test]
    public function otherFormatsAreIgnored(): void
    {
        // Older numeric numbers and the demo account do not shift the sequence.
        self::assertSame('EMP-001', EmployeeNumberSequence::next(['126', '138', 'EMP-DEMO-001', 'EMP-', 'XEMP-005']));
        self::assertSame('EMP-005', EmployeeNumberSequence::next(['126', 'EMP-004', 'EMP-DEMO-001']));
    }

    #[Test]
    public function gapsAreNotReused(): void
    {
        self::assertSame('EMP-006', EmployeeNumberSequence::next(['EMP-001', 'EMP-005']));
    }

    #[Test]
    public function itGrowsPastThreeDigits(): void
    {
        self::assertSame('EMP-1000', EmployeeNumberSequence::next(['EMP-999']));
        self::assertSame('EMP-1001', EmployeeNumberSequence::next(['EMP-1000', 'EMP-998']));
    }

    #[Test]
    public function sequenceOfParsesOnlyEmpDigits(): void
    {
        self::assertSame(12, EmployeeNumberSequence::sequenceOf('EMP-012'));
        self::assertNull(EmployeeNumberSequence::sequenceOf('EMP-DEMO-001'));
        self::assertNull(EmployeeNumberSequence::sequenceOf('126'));
    }
}

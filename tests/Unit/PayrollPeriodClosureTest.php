<?php

declare(strict_types=1);

namespace Wbpms\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Wbpms\Domain\Payroll\PayrollPeriodClosure;

/**
 * A payroll week (Fri–Thu) may be submitted/approved only from the day after
 * it ends (payday Friday). Prod run 26 (11/20–11/26/26) was approved on
 * 11/21, which locked out leave and OT approved later that week.
 */
final class PayrollPeriodClosureTest extends TestCase
{
    public function testWeekHasEndedOnlyAfterItsLastDay(): void
    {
        $closure = new PayrollPeriodClosure();
        self::assertFalse($closure->hasEnded('2026-11-26', '2026-11-21'));
        self::assertFalse($closure->hasEnded('2026-11-26', '2026-11-26'), 'last day is still being worked');
        self::assertTrue($closure->hasEnded('2026-11-26', '2026-11-27'), 'payday Friday is allowed');
        self::assertTrue($closure->hasEnded('2025-08-14', '2026-11-22'));
    }

    public function testAssertEndedPassesForPastWeek(): void
    {
        (new PayrollPeriodClosure())->assertEnded('2026-11-19', '2026-11-22', 'approved');
        $this->addToAssertionCount(1);
    }

    public function testAssertEndedExplainsWhenItCanBeDone(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('This payroll week ends on 11/26/26. It can be approved from 11/27/26');
        (new PayrollPeriodClosure())->assertEnded('2026-11-26', '2026-11-21', 'approved');
    }
}

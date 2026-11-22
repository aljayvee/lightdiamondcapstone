<?php

declare(strict_types=1);

namespace Wbpms\Domain\Payroll;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

/**
 * A payroll week can be finalized only after its last day.
 *
 * Approving a run locks the week: leave or overtime approved later for those
 * dates can never reach a payslip. The cutoff is Friday–Thursday with pay
 * date on Friday, so submitting and approving from the day after
 * period_end (Manila time) keeps payday approvals working.
 */
final class PayrollPeriodClosure
{
    public function hasEnded(string $periodEnd, string $today): bool
    {
        return $today > $periodEnd;
    }

    /**
     * @throws RuntimeException when the week has not ended yet.
     */
    public function assertEnded(string $periodEnd, string $today, string $action): void
    {
        if ($this->hasEnded($periodEnd, $today)) {
            return;
        }
        $tz = new DateTimeZone('Asia/Manila');
        $end = DateTimeImmutable::createFromFormat('!Y-m-d', $periodEnd, $tz);
        if ($end === false) {
            throw new RuntimeException("This payroll can't be {$action} yet: its period end date is invalid.");
        }
        throw new RuntimeException(sprintf(
            'This payroll week ends on %s. It can be %s from %s, so leave and overtime approved during the week are still paid.',
            $end->format('m/d/y'),
            $action,
            $end->modify('+1 day')->format('m/d/y'),
        ));
    }
}

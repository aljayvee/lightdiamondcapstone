<?php

declare(strict_types=1);

namespace Wbpms\Domain\Employee;

/**
 * Thrown when a new bank account's effective date is earlier than the
 * current account's, which would break CHECK chk_bank_period. The message
 * is safe to show to HR. [EMP-026]
 */
final class BankAccountDateRejected extends \DomainException
{
    public static function before(string $currentEffectiveFrom): self
    {
        return new self(BankAccountChange::rejectionMessage($currentEffectiveFrom));
    }
}

<?php

declare(strict_types=1);

namespace Wbpms\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Wbpms\Domain\Validation\FieldRules;

/**
 * Light Diamond pays salaries through BDO only, and the payroll bank slip
 * lists only bank_name = 'BDO' accounts, so no other bank may be saved. [EMP-031]
 */
final class PayrollBankRuleTest extends TestCase
{
    public function testBdoIsAccepted(): void
    {
        self::assertSame('BDO', FieldRules::PAYROLL_BANK);
        self::assertNull(FieldRules::payrollBank('BDO'));
    }

    public function testOtherBanksBlankAndLookalikesAreRefused(): void
    {
        foreach (['BPI', 'Metrobank', 'Other', '', 'bdo', 'BDO Unibank', ' BDO'] as $bank) {
            self::assertSame(
                'Only BDO accounts are accepted. Light Diamond pays salaries through BDO.',
                FieldRules::payrollBank($bank),
                var_export($bank, true),
            );
        }
    }
}

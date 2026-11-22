<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Add the SSS Employees' Compensation (EC) premium to the employer share.
 *
 * SSS Circular No. 2024-006 lists the employer total as the 10% regular
 * share plus the employer-only EC premium: 10.00 below MSC 15,000 and 30.00
 * from MSC 15,000. Our brackets stored only the 10% part, so employer
 * remittance and cost figures were 10.00–30.00 short per employee per month.
 * Employee shares (and so net pay) do not change.
 *
 * MSC is derived from the stored employee share (5% of MSC), so the EC
 * threshold MSC 15,000 is employee_share 750.00. Only rows whose employer
 * share is still exactly 2 × employee share are updated: re-running is a
 * no-op and any hand-tuned row is left alone. Approved payroll runs keep
 * their stored contribution amounts; only payroll computed after this
 * migration picks up the new figure.
 */
final class IncludeSssEcInEmployerShare extends AbstractMigration
{
    private const POLICY_CODES = ['PH-STATUTORY-2026', 'CONTRIB-2026'];

    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        foreach (self::POLICY_CODES as $code) {
            $row = $this->fetchRow(
                "SELECT contribution_policy_id
                   FROM contribution_policy_version
                  WHERE policy_code = '{$code}' AND version = 'V1'
                  LIMIT 1"
            );
            if ($row === false) {
                continue;
            }
            $pid = (int) $row['contribution_policy_id'];

            $this->execute(
                "UPDATE sss_bracket
                    SET employer_share = ROUND(employee_share * 2, 2)
                                         + CASE WHEN employee_share >= 750.00 THEN 30.00 ELSE 10.00 END,
                        updated_at = '{$now}'
                  WHERE contribution_policy_id = {$pid}
                    AND employer_share = ROUND(employee_share * 2, 2)"
            );
        }
    }

    public function down(): void
    {
        // Forward-only: removing EC would understate the employer remittance again.
    }
}

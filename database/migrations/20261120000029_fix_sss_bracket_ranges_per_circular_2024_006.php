<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Fix SSS bracket ranges to match SSS Circular No. 2024-006 (effective Jan 2025).
 *
 * Migration 20261111000027 (and Contribution2026Seeder) built each bracket as
 * MSC .. MSC + 499.99. The official schedule centres each bracket on the MSC:
 * MSC - 250.00 .. MSC + 249.99 (below 5,250 -> MSC 5,000; 34,750+ -> 35,000).
 * The shift put any EEMR in the upper half of a band one MSC step too low
 * (e.g. EEMR 12,259.17 -> MSC 12,000 instead of 12,500: -25.00 EE, -50.00 ER).
 *
 * Shares are unchanged: 5% EE / 10% ER of MSC, MSC 5,000..35,000 in 500 steps.
 *
 * Applies to the 2025-schedule policies PH-STATUTORY-2026 and CONTRIB-2026
 * when present. Brackets are rebuilt, not edited, so the migration is
 * idempotent. Approved payroll runs are unaffected because their contribution
 * amounts are stored, not recomputed.
 *
 * The rows are generated inline (not from app code) so this migration keeps
 * producing the same data even if the domain schedule class changes later.
 */
final class FixSssBracketRangesPerCircular2024006 extends AbstractMigration
{
    private const POLICY_CODES = ['PH-STATUTORY-2026', 'CONTRIB-2026'];

    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        foreach (self::POLICY_CODES as $code) {
            $row = $this->fetchRow(
                "SELECT contribution_policy_id, effective_from
                   FROM contribution_policy_version
                  WHERE policy_code = '{$code}' AND version = 'V1'
                  LIMIT 1"
            );
            if ($row === false) {
                continue;
            }

            $pid = (int) $row['contribution_policy_id'];
            $eff = (string) $row['effective_from'];

            $this->execute("DELETE FROM sss_bracket WHERE contribution_policy_id = {$pid}");

            $values = [];
            for ($msc = 5000; $msc <= 35000; $msc += 500) {
                $from = $msc === 5000 ? '0.00' : sprintf('%.2f', $msc - 250);
                $to   = $msc === 35000 ? 'NULL' : sprintf("'%.2f'", $msc + 249.99);
                $values[] = sprintf(
                    "(%d, '%s', %s, '%.2f', '%.2f', '%s', NULL, '%s', '%s')",
                    $pid,
                    $from,
                    $to,
                    $msc * 0.05,
                    $msc * 0.10,
                    $eff,
                    $now,
                    $now
                );
            }

            $this->execute(
                'INSERT INTO sss_bracket
                    (contribution_policy_id, salary_from, salary_to,
                     employee_share, employer_share, effective_from, effective_to,
                     created_at, updated_at)
                 VALUES ' . implode(', ', $values)
            );
        }
    }

    public function down(): void
    {
        // Forward-only: restoring the shifted ranges would reintroduce wrong
        // SSS deductions, so rollback intentionally leaves the corrected table.
    }
}

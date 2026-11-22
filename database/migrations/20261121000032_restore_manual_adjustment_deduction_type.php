<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Migration: Restore 'ManualAdjustment' in deduction.deduction_type ENUM.
 *
 * Root cause:
 *   20260906000012 added 'ManualAdjustment' (HR Manual Payroll Adjustment) and
 *   20260913000018 kept it, but 20261018000024 (add 'Absence') redefined the
 *   ENUM from an older list and dropped it:
 *     ENUM('Late','Undertime','Absence','CashAdvance','SSS','PhilHealth','PagIBIG','IncomeTax')
 *
 *   Saving a manual deduction then failed on strict MySQL/MariaDB with
 *   SQLSTATE[01000] Warning 1265: Data truncated for column 'deduction_type'.
 *   (Manual additional pay was not affected: payroll_earnings still has it.)
 *
 * Idempotent: checks COLUMN_TYPE before altering. Keeps every existing value.
 */
final class RestoreManualAdjustmentDeductionType extends AbstractMigration
{
    public function up(): void
    {
        $row = $this->fetchRow(
            "SELECT COLUMN_TYPE
               FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'deduction'
                AND COLUMN_NAME = 'deduction_type'"
        );
        if ($row !== false && str_contains((string) $row['COLUMN_TYPE'], "'ManualAdjustment'")) {
            return;
        }

        $this->execute(
            "ALTER TABLE `deduction`
             MODIFY COLUMN `deduction_type`
                ENUM('Late','Undertime','Absence','CashAdvance','SSS','PhilHealth','PagIBIG','IncomeTax','ManualAdjustment')
                NOT NULL
                COMMENT 'Absence = unpaid day already excluded from Basic Pay; recorded for audit'"
        );
    }

    public function down(): void
    {
        // Forward-only migration: removing 'ManualAdjustment' would truncate
        // existing manual deduction rows.
    }
}

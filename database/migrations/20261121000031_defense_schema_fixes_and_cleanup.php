<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Migration 031: Capstone Defense Schema Fixes & Cleanup.
 *
 * Addresses all database schema issues identified during the Capstone Defense:
 *   1. Relax salary.chk_salary_period from strict `>` to `>=` (matching migration 028
 *      on chk_esa_period) so 1-day-tenure archives and consecutive-day salary changes
 *      do not violate the check constraint.
 *   2. Add configurable `minimum_daily_wage DECIMAL(12,2) NOT NULL DEFAULT 460.00`
 *      to `payroll_policy_version` (DOLE Region XII minimum wage enforcement).
 *   3. Add `default_daily_rate DECIMAL(10,2) NULL` and `requires_regular TINYINT(1) NOT NULL DEFAULT 0`
 *      to `job_position`, and seed sensible defaults for existing positions.
 *   4. Add `employee.position_id BIGINT UNSIGNED NULL` with `FOREIGN KEY (position_id)
 *      REFERENCES job_position(position_id) ON DELETE RESTRICT`, backfilled from
 *      `job_position.position_title`, and extend `employee.employee_type` ENUM to
 *      include `'Probationary'`.
 *   5. Add `FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE` on
 *      `sessions.user_id` (fixing floating `sessions` table).
 *   6. Add email verification columns (`email_verified_at`, `email_verification_token_hash`,
 *      `email_verification_expires_at`) to `users` and backfill existing users as verified.
 *   7. Drop unused / floating legacy tables that have zero `app/` references:
 *      `deposit_slip`, `disbursement_batch`, `employee.address_id` + `address`,
 *      `barangay`, `city`, `province`, `attendance_punch`, `employment_contract_review`,
 *      and `password_reset_challenge`.
 *
 * All steps are idempotent and forward-safe.
 */
final class DefenseSchemaFixesAndCleanup extends AbstractMigration
{
    public function up(): void
    {
        // -------------------------------------------------------------------
        // 1. Relax salary.chk_salary_period from `>` to `>=`
        // -------------------------------------------------------------------
        $chkRow = $this->fetchRow(
            "SELECT CHECK_CLAUSE
               FROM information_schema.CHECK_CONSTRAINTS
              WHERE CONSTRAINT_SCHEMA = DATABASE()
                AND CONSTRAINT_NAME = 'chk_salary_period'"
        );
        if ($chkRow === false || !str_contains((string) $chkRow['CHECK_CLAUSE'], '>=')) {
            if ($chkRow !== false) {
                $this->execute('ALTER TABLE salary DROP CONSTRAINT chk_salary_period');
            }
            $this->execute(
                'ALTER TABLE salary
                    ADD CONSTRAINT chk_salary_period CHECK (effective_to IS NULL OR effective_to >= effective_from)'
            );
        }

        // -------------------------------------------------------------------
        // 2. Add minimum_daily_wage to payroll_policy_version
        // -------------------------------------------------------------------
        if (!$this->hasColumn('payroll_policy_version', 'minimum_daily_wage')) {
            $this->execute(
                "ALTER TABLE payroll_policy_version
                    ADD COLUMN minimum_daily_wage DECIMAL(12,2) NOT NULL DEFAULT 460.00
                    COMMENT 'DOLE Region XII minimum daily wage floor'
                    AFTER eemr_days_per_year"
            );
        }

        // -------------------------------------------------------------------
        // 3. Add default_daily_rate and requires_regular to job_position
        // -------------------------------------------------------------------
        if (!$this->hasColumn('job_position', 'default_daily_rate')) {
            $this->execute(
                "ALTER TABLE job_position
                    ADD COLUMN default_daily_rate DECIMAL(10,2) NULL DEFAULT NULL
                    COMMENT 'Default daily rate auto-populated on employee form'
                    AFTER department"
            );
        }
        if (!$this->hasColumn('job_position', 'requires_regular')) {
            $this->execute(
                "ALTER TABLE job_position
                    ADD COLUMN requires_regular TINYINT(1) NOT NULL DEFAULT 0
                    COMMENT '1 if position requires Regular employment status'
                    AFTER default_daily_rate"
            );
        }

        // Seed sensible defaults on existing job_position rows
        $positionDefaults = [
            ['Business Owner',      1000.00, 1],
            ['Store In-charge',      650.00, 1],
            ['HR Head',              700.00, 1],
            ['Accounting Staff',     550.00, 1],
            ['Secretary',            500.00, 0],
            ['Warehouse In-charge',  550.00, 1],
            ['Sales Clerk',          476.00, 0],
            ['Cashier',              480.00, 0],
            ['Checker',              480.00, 0],
            ['Encoder',              480.00, 0],
            ['Driver',               500.00, 0],
            ['Laborer',              476.00, 0],
        ];
        foreach ($positionDefaults as [$title, $rate, $reqReg]) {
            $safeTitle = addslashes($title);
            $this->execute(
                "UPDATE job_position
                    SET default_daily_rate = COALESCE(default_daily_rate, {$rate}),
                        requires_regular   = {$reqReg}
                  WHERE position_title = '{$safeTitle}'"
            );
        }
        $this->execute(
            "UPDATE job_position
                SET default_daily_rate = 476.00
              WHERE default_daily_rate IS NULL"
        );

        // -------------------------------------------------------------------
        // 4. Extend employee.employee_type ENUM + add employee.position_id FK
        // -------------------------------------------------------------------
        $empTypeRow = $this->fetchRow(
            "SELECT COLUMN_TYPE
               FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'employee'
                AND COLUMN_NAME = 'employee_type'"
        );
        if ($empTypeRow !== false && !str_contains((string) $empTypeRow['COLUMN_TYPE'], 'Probationary')) {
            $this->execute(
                "ALTER TABLE employee
                 MODIFY COLUMN employee_type ENUM('Regular', 'Probationary', 'Contractual') NOT NULL"
            );
        }

        if (!$this->hasColumn('employee', 'position_id')) {
            $this->execute(
                "ALTER TABLE employee
                    ADD COLUMN position_id BIGINT UNSIGNED NULL DEFAULT NULL
                    COMMENT 'FK to job_position.position_id'
                    AFTER position"
            );
        }

        // Backfill employee.position_id from job_position.position_title
        $this->execute(
            "UPDATE employee e
               JOIN job_position jp ON LOWER(TRIM(jp.position_title)) = LOWER(TRIM(e.position))
                SET e.position_id = jp.position_id
              WHERE e.position_id IS NULL"
        );

        if (!$this->hasForeignKey('employee', 'fk_employee_position_id')) {
            $this->execute(
                "ALTER TABLE employee
                    ADD CONSTRAINT fk_employee_position_id
                    FOREIGN KEY (position_id) REFERENCES job_position(position_id)
                    ON DELETE RESTRICT ON UPDATE CASCADE"
            );
        }

        // -------------------------------------------------------------------
        // 5. Add FK on sessions.user_id -> users.user_id ON DELETE CASCADE
        // -------------------------------------------------------------------
        if ($this->hasColumn('sessions', 'user_id') && !$this->hasForeignKey('sessions', 'fk_sessions_user_id')) {
            // Clean up any orphaned user_id values before adding FK
            $this->execute(
                "UPDATE sessions s
                   LEFT JOIN users u ON u.user_id = s.user_id
                    SET s.user_id = NULL
                  WHERE s.user_id IS NOT NULL AND u.user_id IS NULL"
            );
            $this->execute(
                "ALTER TABLE sessions
                    ADD CONSTRAINT fk_sessions_user_id
                    FOREIGN KEY (user_id) REFERENCES users(user_id)
                    ON DELETE CASCADE ON UPDATE CASCADE"
            );
        }

        // -------------------------------------------------------------------
        // 6. Add email verification columns to users + backfill existing users
        // -------------------------------------------------------------------
        if (!$this->hasColumn('users', 'email_verified_at')) {
            $this->execute(
                "ALTER TABLE users
                    ADD COLUMN email_verified_at DATETIME NULL DEFAULT NULL
                    COMMENT 'UTC timestamp when email was verified'
                    AFTER account_email,
                    ADD COLUMN email_verification_token_hash VARCHAR(64) NULL DEFAULT NULL
                    COMMENT 'SHA-256 hex digest of raw verification token'
                    AFTER email_verified_at,
                    ADD COLUMN email_verification_expires_at DATETIME NULL DEFAULT NULL
                    COMMENT 'UTC expiry for email verification token'
                    AFTER email_verification_token_hash,
                    ADD INDEX idx_users_email_verif_token (email_verification_token_hash)"
            );
            // Backfill existing users as verified so existing accounts can continue logging in
            $this->execute(
                "UPDATE users
                    SET email_verified_at = COALESCE(created_at, UTC_TIMESTAMP())
                  WHERE email_verified_at IS NULL"
            );
        }

        // -------------------------------------------------------------------
        // 7. Drop employee.address_id FK & column, then drop unused tables
        // -------------------------------------------------------------------
        if ($this->hasColumn('employee', 'address_id')) {
            $fkRows = $this->fetchAll(
                "SELECT CONSTRAINT_NAME
                   FROM information_schema.KEY_COLUMN_USAGE
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = 'employee'
                    AND COLUMN_NAME = 'address_id'
                    AND REFERENCED_TABLE_NAME IS NOT NULL"
            );
            foreach ($fkRows as $fkRow) {
                $fkName = (string) $fkRow['CONSTRAINT_NAME'];
                $this->execute("ALTER TABLE employee DROP FOREIGN KEY `{$fkName}`");
            }
            $this->execute("ALTER TABLE employee DROP COLUMN address_id");
        }

        $unusedTables = [
            'deposit_slip',
            'disbursement_batch',
            'address',
            'barangay',
            'city',
            'province',
            'attendance_punch',
            'employment_contract_review',
            'password_reset_challenge',
        ];
        foreach ($unusedTables as $table) {
            $this->execute("DROP TABLE IF EXISTS `{$table}`");
        }
    }

    public function down(): void
    {
        // Forward-only migration per repository invariants.
    }

    private function hasColumn(string $table, string $column): bool
    {
        $row = $this->fetchRow(
            "SELECT 1
               FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = '" . addslashes($table) . "'
                AND COLUMN_NAME = '" . addslashes($column) . "'
              LIMIT 1"
        );
        return $row !== false;
    }

    private function hasForeignKey(string $table, string $constraintName): bool
    {
        $row = $this->fetchRow(
            "SELECT 1
               FROM information_schema.TABLE_CONSTRAINTS
              WHERE CONSTRAINT_SCHEMA = DATABASE()
                AND TABLE_NAME = '" . addslashes($table) . "'
                AND CONSTRAINT_NAME = '" . addslashes($constraintName) . "'
                AND CONSTRAINT_TYPE = 'FOREIGN KEY'
              LIMIT 1"
        );
        return $row !== false;
    }
}

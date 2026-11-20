<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Allow a schedule assignment that lasts a single day.
 *
 * employee_schedule_assignment.effective_to is an INCLUSIVE last day:
 * ScheduleRepository::assign() closes the previous assignment on the day
 * before the new effective date, and payroll, the timesheet and the import
 * all read it with `effective_to >= date`.
 *
 * chk_esa_period required effective_to > effective_from, so reassigning an
 * employee the day after their current assignment started (old period =
 * one day, to == from) violated the constraint and showed an HTTP 500
 * (QA sheet "CAPSTONE - PAYROLL", Work Schedule TC2). The corrected check
 * still rejects an end date before the start date.
 *
 * Idempotent: it only replaces the constraint while it still has the old
 * strict comparison.
 */
final class AllowSingleDayScheduleAssignment extends AbstractMigration
{
    public function up(): void
    {
        $row = $this->fetchRow(
            "SELECT CHECK_CLAUSE
               FROM information_schema.CHECK_CONSTRAINTS
              WHERE CONSTRAINT_SCHEMA = DATABASE()
                AND CONSTRAINT_NAME = 'chk_esa_period'"
        );
        if ($row !== false && str_contains((string) $row['CHECK_CLAUSE'], '>=')) {
            return; // already relaxed
        }
        if ($row !== false) {
            $this->execute('ALTER TABLE employee_schedule_assignment DROP CONSTRAINT chk_esa_period');
        }
        $this->execute(
            'ALTER TABLE employee_schedule_assignment
                ADD CONSTRAINT chk_esa_period CHECK (effective_to IS NULL OR effective_to >= effective_from)'
        );
    }

    public function down(): void
    {
        // Restoring the strict check could fail on legitimate one-day periods
        // already stored, so the rollback is intentionally a no-op.
    }
}

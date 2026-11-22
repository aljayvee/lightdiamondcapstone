<?php

declare(strict_types=1);

namespace Wbpms\Infrastructure\Persistence;

use PDO;

/**
 * Attendance behind each employee's Basic Pay in a payroll run.
 *
 * Basic Pay is days present × daily rate. HR checks it against attendance, so
 * the run page shows, per employee, the days and hours that Compute counted.
 * Uses the same rows as PayrollService::compute(): Complete/Approved
 * attendance from non-cancelled (Approved/Completed) uploads, inside the
 * period and the employee's branch assignment window. [PAY-026]
 */
final class PayrollAttendanceSummaryRepository extends AbstractRepository
{
    /**
     * @return array<int, array{days:int, worked_minutes:int, late_minutes:int, undertime_minutes:int}>
     *         keyed by payroll_id
     */
    public function forRun(int $runId): array
    {
        // effective_to is exclusive (PayrollService::employeeWindow subtracts a day).
        // Each placeholder appears once (native prepares, HY093).
        $stmt = $this->pdo()->prepare(
            "SELECT p.payroll_id,
                    COUNT(a.attendance_id)                    AS days,
                    COALESCE(SUM(a.hours_worked_minutes), 0)  AS worked_minutes,
                    COALESCE(SUM(a.late_minutes), 0)          AS late_minutes,
                    COALESCE(SUM(a.undertime_minutes), 0)     AS undertime_minutes
               FROM payroll p
               JOIN payroll_run pr
                 ON pr.payroll_run_id = p.payroll_run_id
               JOIN payroll_period pp
                 ON pp.payroll_period_id = pr.payroll_period_id
               LEFT JOIN employee_branch_assignment eba
                 ON eba.branch_assignment_id = p.branch_assignment_id
               JOIN attendance a
                 ON a.employee_id = p.employee_id
                AND a.attendance_date BETWEEN pp.period_start AND pp.period_end
                AND (eba.effective_from IS NULL OR a.attendance_date >= eba.effective_from)
                AND (eba.effective_to IS NULL OR a.attendance_date < eba.effective_to)
               LEFT JOIN attendance_import_batch aib
                 ON aib.import_batch_id = a.import_batch_id
              WHERE p.payroll_run_id = :run_id
                AND a.status IN ('Complete','Approved')
                AND (a.import_batch_id IS NULL OR aib.status IN ('Approved','Completed'))
              GROUP BY p.payroll_id"
        );
        $stmt->execute([':run_id' => $runId]);

        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[(int) $row['payroll_id']] = [
                'days'              => (int) $row['days'],
                'worked_minutes'    => (int) $row['worked_minutes'],
                'late_minutes'      => (int) $row['late_minutes'],
                'undertime_minutes' => (int) $row['undertime_minutes'],
            ];
        }

        return $rows;
    }
}

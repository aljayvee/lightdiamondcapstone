<?php

declare(strict_types=1);

namespace Wbpms\Infrastructure\Persistence;

use PDO;

/**
 * Other branches with attendance in a payroll run's period.
 *
 * A payroll run covers one branch, but one biometric device can serve several
 * branches, so one uploaded workbook holds attendance for all of them. This
 * lists the other branches that have attendance in the same period and says
 * whether each already has its own (non-cancelled) run, so HR does not miss a
 * branch. [PAY-025]
 */
final class PayrollBranchCoverageRepository extends AbstractRepository
{
    /**
     * @return list<array{branch_id:int, branch_name:string, employees:int, attendance_days:int, run_id:int|null, run_status:string|null}>
     */
    public function otherBranchesWithAttendance(int $runId): array
    {
        // Attendance is counted under the branch assignment it was recorded
        // with. Cancelled attendance and attendance from cancelled uploads are
        // left out. Each placeholder appears once (native prepares, HY093).
        $stmt = $this->pdo()->prepare(
            "SELECT b.branch_id,
                    b.branch_name,
                    COUNT(DISTINCT a.employee_id) AS employees,
                    COUNT(*)                      AS attendance_days,
                    other.payroll_run_id          AS run_id,
                    other.status                  AS run_status
               FROM payroll_run cur
               JOIN payroll_period pp
                 ON pp.payroll_period_id = cur.payroll_period_id
               JOIN attendance a
                 ON a.attendance_date BETWEEN pp.period_start AND pp.period_end
               JOIN employee_branch_assignment eba
                 ON eba.branch_assignment_id = a.branch_assignment_id
               JOIN branch b
                 ON b.branch_id = eba.branch_id
               LEFT JOIN attendance_import_batch aib
                 ON aib.import_batch_id = a.import_batch_id
               LEFT JOIN payroll_run other
                 ON other.branch_id = b.branch_id
                AND other.payroll_period_id = cur.payroll_period_id
                AND other.status <> 'Cancelled'
              WHERE cur.payroll_run_id = :run_id
                AND b.branch_id <> cur.branch_id
                AND a.status <> 'Cancelled'
                AND (aib.import_batch_id IS NULL OR aib.status <> 'Cancelled')
              GROUP BY b.branch_id, b.branch_name, other.payroll_run_id, other.status
              ORDER BY b.branch_name, other.payroll_run_id"
        );
        $stmt->execute([':run_id' => $runId]);

        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[] = [
                'branch_id'       => (int) $row['branch_id'],
                'branch_name'     => (string) $row['branch_name'],
                'employees'       => (int) $row['employees'],
                'attendance_days' => (int) $row['attendance_days'],
                'run_id'          => $row['run_id'] !== null ? (int) $row['run_id'] : null,
                'run_status'      => $row['run_status'] !== null ? (string) $row['run_status'] : null,
            ];
        }

        return $rows;
    }
}

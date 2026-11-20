<?php

declare(strict_types=1);

namespace Wbpms\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * Re-derives late, undertime, worked and overtime minutes for attendance
 * rows after HR changes which schedule applies to them.
 *
 * Imported minutes are computed once, against the schedule in force at
 * import time. Without this, assigning the correct shift afterwards (for
 * example 07:00-16:00 instead of 08:00-17:00) left every earlier day carrying
 * the old undertime, and a re-upload of the same workbook was refused as a
 * duplicate file.
 *
 * Only the derived minutes and schedule_id change. Recorded time-in and
 * time-out (including HR adjustments) are kept as they are. Rows that are
 * Approved or Cancelled, rows of a cancelled import, and days inside a payroll
 * run that is Approved or awaiting owner approval are never touched.
 */
final class AttendanceScheduleRecalculator
{
    private PdoAttendanceImportGateway $gateway;

    public function __construct(private PDO $pdo)
    {
        $this->gateway = new PdoAttendanceImportGateway($pdo);
    }

    /**
     * Recalculate one employee's attendance on and after $fromDate.
     *
     * @return array{updated:int, locked:int, openPayroll:int}
     */
    public function recalculateEmployee(int $employeeId, string $fromDate, ?string $toDate = null): array
    {
        $sql = "SELECT a.attendance_id, a.employee_id, a.attendance_date, a.time_in, a.time_out,
                       a.schedule_id, a.hours_worked_minutes, a.late_minutes,
                       a.undertime_minutes, a.overtime_minutes
                  FROM attendance a
                  LEFT JOIN attendance_import_batch aib ON aib.import_batch_id = a.import_batch_id
                 WHERE a.employee_id = :employee_id
                   AND a.attendance_date >= :from_date
                   AND a.status NOT IN ('Approved', 'Cancelled')
                   AND (aib.status IS NULL OR aib.status <> 'Cancelled')";
        $params = [':employee_id' => $employeeId, ':from_date' => $fromDate];
        if ($toDate !== null) {
            $sql .= ' AND a.attendance_date <= :to_date';
            $params[':to_date'] = $toDate;
        }
        $stmt = $this->pdo->prepare($sql . ' ORDER BY a.attendance_date FOR UPDATE');
        $stmt->execute($params);

        $result = ['updated' => 0, 'locked' => 0, 'openPayroll' => 0];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $payrollState = $this->payrollState((int) $row['employee_id'], (string) $row['attendance_date']);
            if ($payrollState === 'locked') {
                $result['locked']++;
                continue;
            }
            if ($this->recalculateRow($row)) {
                $result['updated']++;
                if ($payrollState === 'open') {
                    $result['openPayroll']++;
                }
            }
        }
        return $result;
    }

    /**
     * Recalculate every assignment period that uses $scheduleId, after the
     * schedule template itself was edited.
     *
     * @return array{updated:int, locked:int, openPayroll:int}
     */
    public function recalculateSchedule(int $scheduleId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT employee_id, effective_from, effective_to
               FROM employee_schedule_assignment
              WHERE schedule_id = :schedule_id
                AND (status = 'Active' OR effective_to IS NOT NULL)"
        );
        $stmt->execute([':schedule_id' => $scheduleId]);

        $total = ['updated' => 0, 'locked' => 0, 'openPayroll' => 0];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $assignment) {
            $part = $this->recalculateEmployee(
                (int) $assignment['employee_id'],
                (string) $assignment['effective_from'],
                $assignment['effective_to'] !== null ? (string) $assignment['effective_to'] : null,
            );
            foreach ($part as $key => $count) {
                $total[$key] += $count;
            }
        }
        return $total;
    }

    /** @param array<string, mixed> $row */
    private function recalculateRow(array $row): bool
    {
        $employeeId = (int) $row['employee_id'];
        $date       = (string) $row['attendance_date'];
        $scheduleId = $this->gateway->scheduleIdOn($employeeId, $date);
        $schedule   = $this->gateway->effectiveSchedule($scheduleId, $date);

        $worked    = (int) $row['hours_worked_minutes'];
        $late      = (int) $row['late_minutes'];
        $undertime = (int) $row['undertime_minutes'];
        $overtime  = (int) $row['overtime_minutes'];

        if ($row['time_in'] !== null && $row['time_out'] !== null) {
            $timezone = new DateTimeZone('Asia/Manila');
            $timeIn   = new DateTimeImmutable("{$date} {$row['time_in']}", $timezone);
            $timeOut  = new DateTimeImmutable("{$date} {$row['time_out']}", $timezone);
            [$worked, $late, $undertime] = $schedule->minutesFor($timeIn, $timeOut);
            $overtime = $this->gateway->approvedOvertimeMinutes(
                $employeeId,
                $date,
                (string) $row['time_in'],
                (string) $row['time_out'],
                $schedule,
            );
        }

        $unchanged = ($row['schedule_id'] === null ? null : (int) $row['schedule_id']) === $scheduleId
            && $worked === (int) $row['hours_worked_minutes']
            && $late === (int) $row['late_minutes']
            && $undertime === (int) $row['undertime_minutes']
            && $overtime === (int) $row['overtime_minutes'];
        if ($unchanged) {
            return false;
        }

        $this->pdo->prepare(
            "UPDATE attendance
                SET schedule_id          = :schedule_id,
                    hours_worked_minutes = :worked,
                    late_minutes         = :late,
                    undertime_minutes    = :undertime,
                    overtime_minutes     = :overtime,
                    updated_at           = UTC_TIMESTAMP()
              WHERE attendance_id = :id"
        )->execute([
            ':schedule_id' => $scheduleId,
            ':worked'      => $worked,
            ':late'        => $late,
            ':undertime'   => $undertime,
            ':overtime'    => $overtime,
            ':id'          => (int) $row['attendance_id'],
        ]);
        return true;
    }

    /**
     * 'locked' — inside a payroll run that is Approved or awaiting owner
     * approval; 'open' — inside a run HR must recompute; 'none' otherwise.
     */
    private function payrollState(int $employeeId, string $date): string
    {
        $stmt = $this->pdo->prepare(
            "SELECT pr.status
               FROM payroll p
               JOIN payroll_run pr ON pr.payroll_run_id = p.payroll_run_id
               JOIN payroll_period pp ON pp.payroll_period_id = p.payroll_period_id
              WHERE p.employee_id = :employee_id
                AND pr.status <> 'Cancelled'
                AND :attendance_date BETWEEN pp.period_start AND pp.period_end"
        );
        $stmt->execute([':employee_id' => $employeeId, ':attendance_date' => $date]);
        $statuses = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (array_intersect($statuses, ['Approved', 'PendingOwnerApproval']) !== []) {
            return 'locked';
        }
        return $statuses === [] ? 'none' : 'open';
    }
}

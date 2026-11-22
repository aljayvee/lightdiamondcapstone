<?php

declare(strict_types=1);

namespace Wbpms\Application;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;
use Wbpms\Domain\Attendance\WorkSchedule;
use Wbpms\Domain\Payroll\ApprovedOvertimeCalculator;
use Wbpms\Infrastructure\Database\Connection;

/**
 * Applies auditable HR corrections to imported attendance records.
 *
 * Raw biometric punches remain immutable.  This service changes only the
 * generated attendance row and appends both a domain adjustment record and
 * an audit-log event.  Attendance belonging to an approved payroll is never
 * mutable.
 */
final class AttendanceAdjustmentService
{
    private ApprovedOvertimeCalculator $approvedOvertimeCalculator;

    public function __construct(private Connection $connection)
    {
        $this->approvedOvertimeCalculator = new ApprovedOvertimeCalculator();
    }

    /** @return array<string, mixed> */
    public function findForAdjustment(int $attendanceId): array
    {
        $stmt = $this->connection->pdo()->prepare(
            "SELECT a.attendance_id, a.employee_id, a.attendance_date,
                    a.time_in, a.time_out, a.hours_worked_minutes,
                    a.late_minutes, a.undertime_minutes, a.overtime_minutes,
                    a.status, a.source,
                    CONCAT(e.last_name, ', ', e.first_name) AS employee_name,
                    e.employee_number,
                    COALESCE(ws.schedule_name, 'Unassigned schedule (default 7:00 AM–4:00 PM)') AS schedule_name,
                    COALESCE(ws.work_start_time, '07:00:00') AS work_start_time,
                    COALESCE(ws.work_end_time, '16:00:00') AS work_end_time,
                    COALESCE(ws.break_minutes, 60) AS break_minutes,
                    CASE WHEN ws.schedule_id IS NULL THEN '12:00:00' ELSE ws.break_start_time END AS break_start_time,
                    CASE WHEN ws.schedule_id IS NULL THEN '13:00:00' ELSE ws.break_end_time END AS break_end_time,
                    COALESCE(ws.grace_minutes, 0) AS grace_minutes,
                    COALESCE(ws.standard_minutes, 480) AS standard_minutes,
                    COALESCE(ws.overtime_allowed, 0) AS overtime_allowed
               FROM attendance a
               JOIN employee e ON e.employee_id = a.employee_id
               LEFT JOIN work_schedule ws ON ws.schedule_id = a.schedule_id
              WHERE a.attendance_id = :id"
        );
        $stmt->execute([':id' => $attendanceId]);
        $attendance = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$attendance) {
            throw new RuntimeException('Attendance record not found.');
        }

        return $attendance;
    }

    /**
     * Return immutable adjustment history for an attendance row, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function adjustmentHistory(int $attendanceId): array
    {
        $stmt = $this->connection->pdo()->prepare(
            "SELECT aa.adjustment_id, aa.old_time_in, aa.new_time_in,
                    aa.old_time_out, aa.new_time_out,
                    aa.old_overtime_minutes, aa.new_overtime_minutes,
                    aa.adjustment_type, aa.reason, aa.adjustment_at,
                    COALESCE(u.username, 'System') AS adjusted_by_name
               FROM attendance_adjustment aa
               LEFT JOIN users u ON u.user_id = aa.adjusted_by
              WHERE aa.attendance_id = :id
              ORDER BY aa.adjustment_at DESC, aa.adjustment_id DESC"
        );
        $stmt->execute([':id' => $attendanceId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Prepare context for recording a manual attendance entry (e.g. overriding an ABSENT cell
     * using guard logbook or field dispatch evidence).
     *
     * @return array<string, mixed>
     */
    public function findForManualEntry(int $employeeId, string $attendanceDate): array
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $attendanceDate)) {
            throw new RuntimeException('Invalid attendance date.');
        }

        $pdo = $this->connection->pdo();
        $empStmt = $pdo->prepare(
            "SELECT employee_id, employee_number, hire_date, status,
                    CONCAT(last_name, ', ', first_name) AS employee_name
               FROM employee
              WHERE employee_id = :id"
        );
        $empStmt->execute([':id' => $employeeId]);
        $emp = $empStmt->fetch(PDO::FETCH_ASSOC);
        if (!$emp) {
            throw new RuntimeException('Employee not found.');
        }

        $scheduleRow = $this->resolveEffectiveScheduleRow($pdo, $employeeId, $attendanceDate);

        return [
            'employee_id'      => (int) $emp['employee_id'],
            'employee_number'  => (string) $emp['employee_number'],
            'employee_name'    => (string) $emp['employee_name'],
            'attendance_date'  => $attendanceDate,
            'schedule_id'      => $scheduleRow['schedule_id'],
            'schedule_name'    => $scheduleRow['schedule_name'],
            'work_start_time'  => $scheduleRow['work_start_time'],
            'work_end_time'    => $scheduleRow['work_end_time'],
            'break_minutes'    => $scheduleRow['break_minutes'],
            'break_start_time' => $scheduleRow['break_start_time'],
            'break_end_time'   => $scheduleRow['break_end_time'],
            'grace_minutes'    => $scheduleRow['grace_minutes'],
            'standard_minutes' => $scheduleRow['standard_minutes'],
            'overtime_allowed' => $scheduleRow['overtime_allowed'],
        ];
    }

    /**
     * Record a manual attendance row for an employee/date (e.g. overriding an ABSENT day
     * with logbook/field work evidence). If an attendance row already exists for that
     * employee+date, delegates to adjust() or replaces a Cancelled row.
     *
     * @param array{time_in:string,time_out:string,reason:string} $input
     */
    public function recordManualAttendance(int $employeeId, string $attendanceDate, array $input, int $actingUserId): int
    {
        if ($actingUserId < 1) {
            throw new RuntimeException('A signed-in HR user is required to record manual attendance.');
        }
        $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $attendanceDate);
        if ($parsedDate === false || $parsedDate->format('Y-m-d') !== $attendanceDate) {
            throw new RuntimeException('Attendance date must be a valid YYYY-MM-DD date.');
        }
        $today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d');
        if ($attendanceDate > $today) {
            throw new RuntimeException('Manual attendance cannot be recorded for a future date.');
        }

        $reason = trim($input['reason']);
        if ($reason === '' || mb_strlen($reason) > 1000) {
            throw new RuntimeException('Provide a mandatory reason and supporting evidence of up to 1,000 characters.');
        }

        $timeIn = $this->normaliseTime($input['time_in'], 'Time in');
        $timeOut = $this->normaliseTime($input['time_out'], 'Time out');
        if ($timeIn === null || $timeOut === null) {
            throw new RuntimeException('Time in and time out are both required to record manual attendance.');
        }

        return $this->connection->transaction(function (PDO $pdo) use (
            $employeeId, $attendanceDate, $actingUserId, $reason, $timeIn, $timeOut
        ): int {
            if ($this->hasApprovedPayroll($pdo, $employeeId, $attendanceDate)) {
                throw new RuntimeException('Attendance cannot be recorded because its payroll period has already been approved.');
            }

            // Check if an attendance row already exists for this employee and date
            $existingStmt = $pdo->prepare(
                "SELECT attendance_id, status, time_in, time_out, overtime_minutes
                   FROM attendance
                  WHERE employee_id = :emp_id AND attendance_date = :dt
                  FOR UPDATE"
            );
            $existingStmt->execute([':emp_id' => $employeeId, ':dt' => $attendanceDate]);
            $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);

            // Resolve branch assignment active on $attendanceDate
            $baStmt = $pdo->prepare(
                "SELECT branch_assignment_id
                   FROM employee_branch_assignment
                  WHERE employee_id = :emp_id
                    AND effective_from <= :d1
                    AND (effective_to IS NULL OR effective_to > :d2)
                  ORDER BY effective_from DESC
                  LIMIT 1"
            );
            $baStmt->execute([':emp_id' => $employeeId, ':d1' => $attendanceDate, ':d2' => $attendanceDate]);
            $baId = $baStmt->fetchColumn();
            if ($baId === false) {
                throw new RuntimeException('No active branch assignment covers this employee on ' . $attendanceDate . '.');
            }
            $baId = (int) $baId;

            $scheduleRow = $this->resolveEffectiveScheduleRow($pdo, $employeeId, $attendanceDate);
            $scheduleContext = $scheduleRow + ['attendance_date' => $attendanceDate];

            [$worked, $late, $undertime] = $this->calculateMinutes($scheduleContext, $timeIn, $timeOut);
            $overtime = $this->approvedOvertimeMinutes(
                $pdo,
                $employeeId,
                $attendanceDate,
                $timeIn,
                $timeOut,
                (string) $scheduleRow['work_start_time'],
                (string) $scheduleRow['work_end_time'],
                (int) $scheduleRow['break_minutes'],
                (int) $scheduleRow['standard_minutes'],
                $scheduleRow['break_start_time'] !== null ? (string) $scheduleRow['break_start_time'] : null,
                $scheduleRow['break_end_time'] !== null ? (string) $scheduleRow['break_end_time'] : null,
            );

            $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');

            if ($existing !== false) {
                $attendanceId = (int) $existing['attendance_id'];
                $oldTimeIn    = $existing['time_in'];
                $oldTimeOut   = $existing['time_out'];
                $oldOt        = (int) $existing['overtime_minutes'];

                $pdo->prepare(
                    "UPDATE attendance
                        SET branch_assignment_id = :ba_id,
                            schedule_id          = :sch_id,
                            time_in              = :time_in,
                            time_out             = :time_out,
                            hours_worked_minutes = :worked,
                            late_minutes         = :late,
                            undertime_minutes    = :undertime,
                            overtime_minutes     = :overtime,
                            status               = 'Complete',
                            source               = 'manual',
                            import_batch_id      = NULL,
                            updated_at           = :now
                      WHERE attendance_id = :id"
                )->execute([
                    ':ba_id'     => $baId,
                    ':sch_id'    => $scheduleRow['schedule_id'],
                    ':time_in'   => $timeIn,
                    ':time_out'  => $timeOut,
                    ':worked'    => $worked,
                    ':late'      => $late,
                    ':undertime' => $undertime,
                    ':overtime'  => $overtime,
                    ':now'       => $now,
                    ':id'        => $attendanceId,
                ]);
            } else {
                $oldTimeIn  = null;
                $oldTimeOut = null;
                $oldOt      = 0;

                $pdo->prepare(
                    "INSERT INTO attendance
                        (employee_id, branch_assignment_id, schedule_id,
                         attendance_date, time_in, time_out,
                         hours_worked_minutes, late_minutes, undertime_minutes, overtime_minutes,
                         status, source, import_batch_id, created_at, updated_at)
                     VALUES
                        (:emp_id, :ba_id, :sch_id,
                         :dt, :time_in, :time_out,
                         :worked, :late, :undertime, :overtime,
                         'Complete', 'manual', NULL, :created_at, :updated_at)"
                )->execute([
                    ':emp_id'     => $employeeId,
                    ':ba_id'      => $baId,
                    ':sch_id'     => $scheduleRow['schedule_id'],
                    ':dt'         => $attendanceDate,
                    ':time_in'    => $timeIn,
                    ':time_out'   => $timeOut,
                    ':worked'     => $worked,
                    ':late'       => $late,
                    ':undertime'  => $undertime,
                    ':overtime'   => $overtime,
                    ':created_at' => $now,
                    ':updated_at' => $now,
                ]);
                $attendanceId = (int) $pdo->lastInsertId();
            }

            $pdo->prepare(
                "INSERT INTO attendance_adjustment
                    (attendance_id, adjusted_by, old_time_in, new_time_in,
                     old_time_out, new_time_out, old_overtime_minutes,
                     new_overtime_minutes, adjustment_type, reason, adjustment_at)
                 VALUES
                    (:attendance_id, :adjusted_by, :old_time_in, :new_time_in,
                     :old_time_out, :new_time_out, :old_overtime, :new_overtime,
                     'manual_absence_override', :reason, :adjustment_at)"
            )->execute([
                ':attendance_id' => $attendanceId,
                ':adjusted_by'   => $actingUserId,
                ':old_time_in'   => $oldTimeIn,
                ':new_time_in'   => $timeIn,
                ':old_time_out'  => $oldTimeOut,
                ':new_time_out'  => $timeOut,
                ':old_overtime'  => $oldOt,
                ':new_overtime'  => $overtime,
                ':reason'        => $reason,
                ':adjustment_at' => $now,
            ]);

            $description = json_encode([
                'employee_id'     => $employeeId,
                'attendance_date' => $attendanceDate,
                'reason'          => $reason,
                'new'             => [
                    'time_in'          => $timeIn,
                    'time_out'         => $timeOut,
                    'worked_minutes'   => $worked,
                    'late_minutes'     => $late,
                    'undertime_minutes'=> $undertime,
                    'overtime_minutes' => $overtime,
                ],
            ], JSON_UNESCAPED_SLASHES);

            $pdo->prepare(
                "INSERT INTO audit_logs
                    (user_id, event_type, action_performed, table_affected, record_id,
                     description, action_at, created_at)
                 VALUES
                    (:user_id, 'attendance_manual_override', 'record_manual_attendance', 'attendance', :record_id,
                     :description, :action_at, :created_at)"
            )->execute([
                ':user_id'     => $actingUserId,
                ':record_id'   => $attendanceId,
                ':description' => $description,
                ':action_at'   => $now,
                ':created_at'  => $now,
            ]);

            return $attendanceId;
        });
    }

    /**
     * @return array{schedule_id:int|null,schedule_name:string,work_start_time:string,work_end_time:string,break_minutes:int,break_start_time:?string,break_end_time:?string,grace_minutes:int,standard_minutes:int,overtime_allowed:int}
     */
    private function resolveEffectiveScheduleRow(PDO $pdo, int $employeeId, string $date): array
    {
        $stmt = $pdo->prepare(
            "SELECT ws.schedule_id, ws.schedule_name, ws.work_start_time, ws.work_end_time,
                    ws.break_minutes, ws.break_start_time, ws.break_end_time,
                    ws.grace_minutes, ws.standard_minutes, ws.overtime_allowed
               FROM employee_schedule_assignment esa
               JOIN work_schedule ws ON ws.schedule_id = esa.schedule_id
              WHERE esa.employee_id = :emp_id
                AND esa.effective_from <= :d1
                AND (esa.effective_to IS NULL OR esa.effective_to >= :d2)
                AND (esa.status = 'Active' OR esa.effective_to IS NOT NULL)
              ORDER BY esa.effective_from DESC
              LIMIT 1"
        );
        $stmt->execute([':emp_id' => $employeeId, ':d1' => $date, ':d2' => $date]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return [
                'schedule_id'      => null,
                'schedule_name'    => 'Unassigned schedule (default 7:00 AM–4:00 PM)',
                'work_start_time'  => '07:00:00',
                'work_end_time'    => '16:00:00',
                'break_minutes'    => 60,
                'break_start_time' => '12:00:00',
                'break_end_time'   => '13:00:00',
                'grace_minutes'    => 0,
                'standard_minutes' => 480,
                'overtime_allowed' => 0,
            ];
        }

        return [
            'schedule_id'      => (int) $row['schedule_id'],
            'schedule_name'    => (string) $row['schedule_name'],
            'work_start_time'  => (string) $row['work_start_time'],
            'work_end_time'    => (string) $row['work_end_time'],
            'break_minutes'    => (int) $row['break_minutes'],
            'break_start_time' => $row['break_start_time'] !== null ? (string) $row['break_start_time'] : null,
            'break_end_time'   => $row['break_end_time'] !== null ? (string) $row['break_end_time'] : null,
            'grace_minutes'    => (int) $row['grace_minutes'],
            'standard_minutes' => (int) $row['standard_minutes'],
            'overtime_allowed' => (int) $row['overtime_allowed'],
        ];
    }

    /**
     * @param array{time_in:string,time_out:string,reason:string} $input
     */
    public function adjust(int $attendanceId, array $input, int $actingUserId): void
    {
        if ($actingUserId < 1) {
            throw new RuntimeException('A signed-in HR user is required to adjust attendance.');
        }

        $reason = trim($input['reason']);
        if ($reason === '' || mb_strlen($reason) > 1000) {
            throw new RuntimeException('Provide an adjustment reason of up to 1,000 characters.');
        }

        $timeIn = $this->normaliseTime($input['time_in'], 'Time in');
        $timeOut = $this->normaliseTime($input['time_out'], 'Time out');
        if ($timeIn === null || $timeOut === null) {
            throw new RuntimeException('Time in and time out are both required to resolve attendance.');
        }

        $this->connection->transaction(function (PDO $pdo) use (
            $attendanceId, $actingUserId, $reason, $timeIn, $timeOut
        ): void {
            $attendance = $this->findForUpdate($pdo, $attendanceId);
            if ($this->hasApprovedPayroll($pdo, (int) $attendance['employee_id'], (string) $attendance['attendance_date'])) {
                throw new RuntimeException('Attendance cannot be adjusted because its payroll has already been approved.');
            }

            [$worked, $late, $undertime] = $this->calculateMinutes($attendance, $timeIn, $timeOut);
            $overtime = $this->approvedOvertimeMinutes(
                $pdo,
                (int) $attendance['employee_id'],
                (string) $attendance['attendance_date'],
                $timeIn,
                $timeOut,
                (string) $attendance['work_start_time'],
                (string) $attendance['work_end_time'],
                (int) $attendance['break_minutes'],
                (int) $attendance['standard_minutes'],
                $attendance['break_start_time'] !== null ? (string) $attendance['break_start_time'] : null,
                $attendance['break_end_time'] !== null ? (string) $attendance['break_end_time'] : null,
            );

            if (!(bool) $attendance['overtime_allowed'] && $overtime > 0) {
                throw new RuntimeException('This work schedule does not allow overtime.');
            }

            $timeChanged = $timeIn !== $attendance['time_in'] || $timeOut !== $attendance['time_out'];
            $overtimeChanged = $overtime !== (int) $attendance['overtime_minutes'];
            if (!$timeChanged && !$overtimeChanged) {
                throw new RuntimeException('No attendance values were changed.');
            }

            $type = $timeChanged ? 'time_correction' : 'overtime_recalculated';
            $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');

            $pdo->prepare(
                "UPDATE attendance
                    SET time_in = :time_in, time_out = :time_out,
                        hours_worked_minutes = :worked, late_minutes = :late,
                        undertime_minutes = :undertime, overtime_minutes = :overtime,
                        status = 'Complete', updated_at = :updated_at
                  WHERE attendance_id = :id"
            )->execute([
                ':time_in' => $timeIn,
                ':time_out' => $timeOut,
                ':worked' => $worked,
                ':late' => $late,
                ':undertime' => $undertime,
                ':overtime' => $overtime,
                ':updated_at' => $now,
                ':id' => $attendanceId,
            ]);

            $pdo->prepare(
                "INSERT INTO attendance_adjustment
                    (attendance_id, adjusted_by, old_time_in, new_time_in,
                     old_time_out, new_time_out, old_overtime_minutes,
                     new_overtime_minutes, adjustment_type, reason, adjustment_at)
                 VALUES
                    (:attendance_id, :adjusted_by, :old_time_in, :new_time_in,
                     :old_time_out, :new_time_out, :old_overtime, :new_overtime,
                     :type, :reason, :adjustment_at)"
            )->execute([
                ':attendance_id' => $attendanceId,
                ':adjusted_by' => $actingUserId,
                ':old_time_in' => $attendance['time_in'],
                ':new_time_in' => $timeIn,
                ':old_time_out' => $attendance['time_out'],
                ':new_time_out' => $timeOut,
                ':old_overtime' => (int) $attendance['overtime_minutes'],
                ':new_overtime' => $overtime,
                ':type' => $type,
                ':reason' => $reason,
                ':adjustment_at' => $now,
            ]);

            $description = json_encode([
                'attendance_date' => $attendance['attendance_date'],
                'reason' => $reason,
                'old' => ['time_in' => $attendance['time_in'], 'time_out' => $attendance['time_out'], 'overtime_minutes' => (int) $attendance['overtime_minutes']],
                'new' => ['time_in' => $timeIn, 'time_out' => $timeOut, 'overtime_minutes' => $overtime],
            ], JSON_UNESCAPED_SLASHES);
            $pdo->prepare(
                "INSERT INTO audit_logs
                    (user_id, event_type, action_performed, table_affected, record_id,
                     description, action_at, created_at)
                 VALUES
                    (:user_id, 'attendance_adjusted', 'adjust_attendance', 'attendance', :record_id,
                     :description, :action_at, :created_at)"
            )->execute([
                ':user_id' => $actingUserId,
                ':record_id' => $attendanceId,
                ':description' => $description,
                ':action_at' => $now,
                ':created_at' => $now,
            ]);
        });
    }

    /** @return array<string, mixed> */
    private function findForUpdate(PDO $pdo, int $attendanceId): array
    {
        $stmt = $pdo->prepare(
            "SELECT a.attendance_id, a.employee_id, a.attendance_date, a.time_in, a.time_out,
                    a.overtime_minutes,
                    COALESCE(ws.work_start_time, '07:00:00') AS work_start_time,
                    COALESCE(ws.work_end_time, '16:00:00') AS work_end_time,
                    COALESCE(ws.break_minutes, 60) AS break_minutes,
                    CASE WHEN ws.schedule_id IS NULL THEN '12:00:00' ELSE ws.break_start_time END AS break_start_time,
                    CASE WHEN ws.schedule_id IS NULL THEN '13:00:00' ELSE ws.break_end_time END AS break_end_time,
                    COALESCE(ws.grace_minutes, 0) AS grace_minutes,
                    COALESCE(ws.standard_minutes, 480) AS standard_minutes,
                    COALESCE(ws.overtime_allowed, 0) AS overtime_allowed
               FROM attendance a
               LEFT JOIN work_schedule ws ON ws.schedule_id = a.schedule_id
              WHERE a.attendance_id = :id FOR UPDATE"
        );
        $stmt->execute([':id' => $attendanceId]);
        $attendance = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$attendance) {
            throw new RuntimeException('Attendance record not found.');
        }
        return $attendance;
    }

    private function hasApprovedPayroll(PDO $pdo, int $employeeId, string $attendanceDate): bool
    {
        $stmt = $pdo->prepare(
            "SELECT 1
               FROM payroll p
               JOIN payroll_run pr ON pr.payroll_run_id = p.payroll_run_id
               JOIN payroll_period pp ON pp.payroll_period_id = p.payroll_period_id
              WHERE p.employee_id = :employee_id
                AND pr.status = 'Approved'
                AND :attendance_date BETWEEN pp.period_start AND pp.period_end
              LIMIT 1"
        );
        $stmt->execute([':employee_id' => $employeeId, ':attendance_date' => $attendanceDate]);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * @param array<string, mixed> $attendance
     * @return array{int, int, int} worked, late, undertime
     */
    /**
     * Worked / late / undertime minutes the save would record, without saving
     * (REQ019: the Adjust and Manual forms show them while HR types).
     * Uses the same calculation as adjust() and recordManualAttendance().
     *
     * @param  array<string, mixed> $scheduleContext row from findForAdjustment() / findForManualEntry()
     * @return array{worked: int, late: int, undertime: int}
     */
    public function previewMinutes(array $scheduleContext, string $timeIn, string $timeOut): array
    {
        $in  = $this->normaliseTime($timeIn, 'Time in');
        $out = $this->normaliseTime($timeOut, 'Time out');
        if ($in === null || $out === null) {
            throw new RuntimeException('Enter both times to see the result.');
        }
        [$worked, $late, $undertime] = $this->calculateMinutes($scheduleContext, $in, $out);

        return ['worked' => (int) $worked, 'late' => (int) $late, 'undertime' => (int) $undertime];
    }

    private function calculateMinutes(array $attendance, string $timeIn, string $timeOut): array
    {
        $timezone = new DateTimeZone('Asia/Manila');
        $date = (string) $attendance['attendance_date'];
        $actualIn = new DateTimeImmutable("{$date} {$timeIn}", $timezone);
        $actualOut = new DateTimeImmutable("{$date} {$timeOut}", $timezone);
        $scheduledStart = new DateTimeImmutable("{$date} {$attendance['work_start_time']}", $timezone);
        $scheduledEnd = new DateTimeImmutable("{$date} {$attendance['work_end_time']}", $timezone);
        if ($scheduledEnd <= $scheduledStart) {
            if ($actualOut <= $actualIn) {
                $actualOut = $actualOut->modify('+1 day');
            }
        }
        if ($actualOut <= $actualIn) {
            throw new RuntimeException('Time out must be later than time in.');
        }

        $schedule = new WorkSchedule(
            0,
            substr((string) $attendance['work_start_time'], 0, 5),
            substr((string) $attendance['work_end_time'], 0, 5),
            (int) $attendance['break_minutes'],
            (int) $attendance['standard_minutes'],
            (int) $attendance['grace_minutes'],
            (bool) $attendance['overtime_allowed'],
            $attendance['break_start_time'] !== null ? substr((string) $attendance['break_start_time'], 0, 5) : null,
            $attendance['break_end_time'] !== null ? substr((string) $attendance['break_end_time'], 0, 5) : null,
        );

        return $schedule->minutesFor($actualIn, $actualOut);
    }

    private function normaliseTime(string $value, string $label): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (!preg_match('/^(?:[01]\\d|2[0-3]):[0-5]\\d(?::[0-5]\\d)?$/', $value)) {
            throw new RuntimeException("{$label} must use a valid 24-hour time.");
        }
        return strlen($value) === 5 ? $value . ':00' : $value;
    }

    /** Overtime is retained only when an approved request overlaps actual work. */
    private function approvedOvertimeMinutes(
        PDO $pdo,
        int $employeeId,
        string $attendanceDate,
        string $timeIn,
        string $timeOut,
        string $scheduledStart,
        string $scheduledEnd,
        int $breakMinutes,
        int $standardMinutes,
        ?string $breakStartTime = null,
        ?string $breakEndTime = null,
    ): int {
        $stmt = $pdo->prepare(
            "SELECT ord.start_time, ord.end_time
               FROM request r
               JOIN request_type rt ON rt.request_type_id = r.request_type_id
               JOIN overtime_request_detail ord ON ord.request_id = r.request_id
              WHERE r.employee_id = :employee_id
                AND rt.type_name = 'Overtime'
                AND r.status = 'Approved'
                AND :attendance_date BETWEEN ord.date_from AND ord.date_to
              ORDER BY ord.start_time"
        );
        $stmt->execute([
            ':employee_id'     => $employeeId,
            ':attendance_date' => $attendanceDate,
        ]);

        /** @var list<array{start_time:string,end_time:string}> $requests */
        $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $this->approvedOvertimeCalculator->payableMinutesForAttendance(
            $timeIn,
            $timeOut,
            $scheduledStart,
            $scheduledEnd,
            $breakMinutes,
            $standardMinutes,
            $requests,
            $breakStartTime,
            $breakEndTime,
        );
    }
}

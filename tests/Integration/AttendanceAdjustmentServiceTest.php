<?php

declare(strict_types=1);

namespace Wbpms\Tests\Integration;

use RuntimeException;
use Wbpms\Application\AttendanceAdjustmentService;
use Wbpms\Infrastructure\Database\Connection;

final class AttendanceAdjustmentServiceTest extends IntegrationTestCase
{
    private AttendanceAdjustmentService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AttendanceAdjustmentService(new Connection([], $this->pdo));
    }

    public function testAdjustmentDoesNotRecordOvertimeWithoutAnApprovedRequest(): void
    {
        [$attendanceId, $userId] = $this->createAttendanceFixture('2026-09-07');

        $this->service->adjust($attendanceId, [
            'time_in' => '07:10',
            'time_out' => '17:30',
            'reason' => 'Gate log verified.',
        ], $userId);

        $stmt = $this->pdo->prepare(
            'SELECT time_in, time_out, hours_worked_minutes, late_minutes,
                    undertime_minutes, overtime_minutes, status
               FROM attendance WHERE attendance_id = :id'
        );
        $stmt->execute([':id' => $attendanceId]);
        $attendance = $stmt->fetch();
        $this->assertSame('07:10:00', $attendance['time_in']);
        $this->assertSame('17:30:00', $attendance['time_out']);
        $this->assertSame(560, (int) $attendance['hours_worked_minutes']);
        $this->assertSame(10, (int) $attendance['late_minutes']);
        $this->assertSame(0, (int) $attendance['undertime_minutes']);
        $this->assertSame(0, (int) $attendance['overtime_minutes']);
        $this->assertSame('Complete', $attendance['status']);

        $adjustment = $this->pdo->query(
            "SELECT old_time_in, new_time_in, old_overtime_minutes, new_overtime_minutes, adjustment_type
               FROM attendance_adjustment ORDER BY adjustment_id DESC LIMIT 1"
        )->fetch();
        $this->assertNull($adjustment['old_time_in']);
        $this->assertSame('07:10:00', $adjustment['new_time_in']);
        $this->assertSame(0, (int) $adjustment['old_overtime_minutes']);
        $this->assertSame(0, (int) $adjustment['new_overtime_minutes']);
        $this->assertSame('time_correction', $adjustment['adjustment_type']);

        $audit = $this->pdo->query(
            "SELECT event_type FROM audit_logs WHERE event_type = 'attendance_adjusted' ORDER BY log_id DESC LIMIT 1"
        )->fetchColumn();
        $this->assertSame('attendance_adjusted', $audit);
    }

    public function testAdjustmentIsBlockedWhenRelatedPayrollIsApproved(): void
    {
        [$attendanceId, $userId, $employeeId, $branchId, $assignmentId, $scheduleId] = $this->createAttendanceFixture('2026-09-07');
        $periodId = $this->insertPayrollPeriod('2026-09-06', '2026-09-11', '2026-09-11');
        $policyId = $this->insertPayrollPolicy();
        $runId = $this->insertPayrollRun($periodId, $branchId, $policyId, 'Approved');
        $salaryId = $this->insertSalary($employeeId);
        $this->insertPayroll($runId, $periodId, $employeeId, $assignmentId, $salaryId);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('payroll has already been approved');
        $this->service->adjust($attendanceId, [
            'time_in' => '07:00',
            'time_out' => '16:00',
            'reason' => 'Late biometric synchronization.',
        ], $userId);
    }

    public function testReviewRequiredDayCanBeConfirmedWithoutChangingTimes(): void
    {
        [$attendanceId, $userId] = $this->createAttendanceFixture('2026-07-20', 'ReviewRequired', '07:02:00', '16:32:00');

        $this->service->adjust($attendanceId, [
            'time_in' => '07:02',
            'time_out' => '16:32',
            'reason' => 'Verified midday punches with supervisor; full shift approved.',
        ], $userId);

        $stmt = $this->pdo->prepare(
            'SELECT time_in, time_out, hours_worked_minutes, late_minutes, status
               FROM attendance WHERE attendance_id = :id'
        );
        $stmt->execute([':id' => $attendanceId]);
        $attendance = $stmt->fetch();
        $this->assertSame('07:02:00', $attendance['time_in']);
        $this->assertSame('16:32:00', $attendance['time_out']);
        $this->assertSame(510, (int) $attendance['hours_worked_minutes']);
        $this->assertSame(2, (int) $attendance['late_minutes']);
        $this->assertSame('Complete', $attendance['status']);

        $adjustment = $this->pdo->query(
            'SELECT old_time_in, new_time_in, old_time_out, new_time_out, adjustment_type
               FROM attendance_adjustment ORDER BY adjustment_id DESC LIMIT 1'
        )->fetch();
        $this->assertSame('07:02:00', $adjustment['old_time_in']);
        $this->assertSame('07:02:00', $adjustment['new_time_in']);
        $this->assertSame('16:32:00', $adjustment['old_time_out']);
        $this->assertSame('16:32:00', $adjustment['new_time_out']);
        $this->assertSame('review_confirmed', $adjustment['adjustment_type']);

        $auditDesc = (string) $this->pdo->query(
            "SELECT description FROM audit_logs WHERE event_type = 'attendance_adjusted' ORDER BY log_id DESC LIMIT 1"
        )->fetchColumn();
        $decoded = json_decode($auditDesc, true);
        $this->assertSame('ReviewRequired', $decoded['old']['status'] ?? null);
        $this->assertSame('Complete', $decoded['new']['status'] ?? null);
    }

    public function testUnchangedCompleteAttendanceIsStillRejected(): void
    {
        [$attendanceId, $userId] = $this->createAttendanceFixture('2026-07-20', 'Complete', '07:02:00', '16:32:00');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No attendance values were changed.');
        $this->service->adjust($attendanceId, [
            'time_in' => '07:02',
            'time_out' => '16:32',
            'reason' => 'No actual change.',
        ], $userId);
    }

    public function testRawBiometricPunchesReturnsMatchedLivePunchesOnly(): void
    {
        [$attendanceId, $userId, $employeeId, $branchId, $assignmentId] = $this->createAttendanceFixture('2026-07-20', 'ReviewRequired', '07:02:00', '16:32:00');
        $deviceId = $this->insertDevice();
        $liveBatchId = $this->insertImportBatch($deviceId, $userId, 2026, 7, 'Approved');
        $cancelledBatchId = $this->insertImportBatch($deviceId, $userId, 2026, 7, 'Cancelled');

        foreach (['07:02:00', '11:47:00', '14:46:00', '16:32:00'] as $t) {
            $this->insertPunch($liveBatchId, $deviceId, $employeeId, $assignmentId, "2026-07-20 {$t}", '07:02 11:47 14:46 16:32', 34, 'X');
        }
        $this->insertPunch($cancelledBatchId, $deviceId, $employeeId, $assignmentId, '2026-07-20 08:00:00', '08:00', 34, 'X');

        $punches = $this->service->rawBiometricPunches($employeeId, '2026-07-20');
        $this->assertCount(4, $punches);
        $this->assertSame(
            ['2026-07-20 07:02:00', '2026-07-20 11:47:00', '2026-07-20 14:46:00', '2026-07-20 16:32:00'],
            array_column($punches, 'source_local_at'),
        );
        $this->assertSame('X', $punches[0]['source_date_column']);
        $this->assertSame(34, (int) $punches[0]['source_workbook_row']);
    }

    /** @return array{int, int, int, int, int, int} */
    private function createAttendanceFixture(
        string $date,
        string $status = 'Incomplete',
        ?string $timeIn = null,
        ?string $timeOut = null,
    ): array {
        $roleId = $this->insertRole();
        $employeeId = $this->insertEmployee();
        $userId = $this->insertUser($roleId);
        $branchId = $this->insertBranch();
        $assignmentId = $this->insertBranchAssignment($employeeId, $branchId, '2026-01-01');
        $scheduleId = $this->insertSchedule();
        $stmt = $this->pdo->prepare(
            "INSERT INTO attendance
                (employee_id, branch_assignment_id, schedule_id, attendance_date,
                 time_in, time_out,
                 hours_worked_minutes, late_minutes, undertime_minutes, overtime_minutes, status, source)
             VALUES (:employee_id, :assignment_id, :schedule_id, :attendance_date,
                     :time_in, :time_out,
                     0, 0, 0, 0, :status, 'manual')"
        );
        $stmt->execute([
            ':employee_id' => $employeeId,
            ':assignment_id' => $assignmentId,
            ':schedule_id' => $scheduleId,
            ':attendance_date' => $date,
            ':time_in' => $timeIn,
            ':time_out' => $timeOut,
            ':status' => $status,
        ]);

        return [(int) $this->pdo->lastInsertId(), $userId, $employeeId, $branchId, $assignmentId, $scheduleId];
    }

    private function insertDevice(): int
    {
        $code = 'DEV-' . uniqid();
        $stmt = $this->pdo->prepare(
            "INSERT INTO biometric_device (device_code, device_name, status)
             VALUES (:code, 'Hardware Biometric', 'Active')"
        );
        $stmt->execute([':code' => $code]);
        return (int) $this->pdo->lastInsertId();
    }

    private function insertImportBatch(int $deviceId, int $userId, int $year, int $month, string $status): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO attendance_import_batch
                (device_id, uploaded_by, file_name, file_checksum, source_year, source_month, parser_version, status, uploaded_at)
             VALUES (:dev, :usr, 'july.xls', :cs, :yr, :mo, 'LDE_XLS_DAILY_LOG_V1', :st, '2026-11-22 00:00:00')"
        );
        $stmt->execute([
            ':dev' => $deviceId,
            ':usr' => $userId,
            ':cs' => hash('sha256', uniqid('', true)),
            ':yr' => $year,
            ':mo' => $month,
            ':st' => $status,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    private function insertPunch(
        int $batchId,
        int $deviceId,
        int $employeeId,
        int $assignmentId,
        string $localAt,
        string $raw,
        int $row,
        string $col,
    ): void {
        $stmt = $this->pdo->prepare(
            "INSERT INTO biometric_punch
                (import_batch_id, device_id, employee_id, branch_assignment_id,
                 device_employee_code, source_local_at, punched_at_utc, punch_type, match_status,
                 raw_record, source_workbook_row, source_date_column, created_at)
             VALUES (:batch, :dev, :emp, :ba, '6', :local_at, :utc_at, 'Unknown', 'matched',
                     :raw, :row, :col, '2026-11-22 00:00:00')"
        );
        $stmt->execute([
            ':batch' => $batchId,
            ':dev' => $deviceId,
            ':emp' => $employeeId,
            ':ba' => $assignmentId,
            ':local_at' => $localAt,
            ':utc_at' => $localAt,
            ':raw' => $raw,
            ':row' => $row,
            ':col' => $col,
        ]);
    }

    private function insertSchedule(): int
    {
        $name = 'Schedule ' . uniqid();
        $stmt = $this->pdo->prepare(
            "INSERT INTO work_schedule
                (schedule_name, working_days, rest_days, break_minutes, grace_minutes,
                 overtime_allowed, work_start_time, work_end_time, standard_minutes,
                 effective_from, status)
             VALUES (:name, :working_days, :rest_days, 60, 0,
                     1, '07:00:00', '16:00:00', 480, '2026-01-01', 'Active')"
        );
        $stmt->execute([
            ':name' => $name,
            ':working_days' => '["Monday","Tuesday","Wednesday","Thursday","Friday"]',
            ':rest_days' => '["Saturday","Sunday"]',
        ]);
        return (int) $this->pdo->lastInsertId();
    }
}

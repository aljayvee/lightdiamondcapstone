<?php

declare(strict_types=1);

namespace Wbpms\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Wbpms\Application\Attendance\AttendanceImportGateway;
use Wbpms\Application\Attendance\AttendanceImportSummary;
use Wbpms\Application\Attendance\AttendancePunchMatch;
use Wbpms\Domain\Attendance\GeneratedAttendance;
use Wbpms\Domain\Attendance\PunchDayClassifier;
use Wbpms\Domain\Attendance\WorkSchedule;
use Wbpms\Domain\Attendance\Parsing\ParsedPunch;
use Wbpms\Domain\Payroll\ApprovedOvertimeCalculator;
use Wbpms\Infrastructure\Database\Connection;

/**
 * PDO implementation of AttendanceImportGateway.
 *
 * All SQL lives here — no SQL in controllers or services (ADR-0003).
 * Accepts a raw PDO so the controller can hand in the same connection
 * that it wraps in Connection::transaction().
 */
final class PdoAttendanceImportGateway implements AttendanceImportGateway
{
    private PDO $pdo;
    private Connection $connection;
    private ApprovedOvertimeCalculator $approvedOvertimeCalculator;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        // Wrap PDO in Connection so we can delegate transaction()
        $this->connection = new Connection([], $pdo);
        $this->approvedOvertimeCalculator = new ApprovedOvertimeCalculator();
    }

    // -----------------------------------------------------------------------
    // Transaction
    // -----------------------------------------------------------------------

    public function transactional(callable $operation): mixed
    {
        return $this->connection->transaction(fn () => $operation());
    }

    // -----------------------------------------------------------------------
    // Duplicate-checksum guard
    // -----------------------------------------------------------------------

    public function hasCompletedChecksum(string $sha256): bool
    {
        // Cancelled and Processing batches are excluded:
        // - Cancelled: their file may be re-uploaded to replace the cancelled import.
        // - Processing: a stale Processing row (abandoned mid-import) should not
        //   block a fresh import of the same file; createImportBatch handles cleanup.
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM attendance_import_batch
              WHERE file_checksum = :cs
                AND status IN ('Draft', 'Approved', 'Completed')"
        );
        $stmt->execute([':cs' => $sha256]);
        return (int) $stmt->fetchColumn() > 0;
    }

    // -----------------------------------------------------------------------
    // Batch lifecycle
    // -----------------------------------------------------------------------

    public function createImportBatch(
        int    $deviceId,
        int    $uploadedByUserId,
        int    $year,
        int    $month,
        string $sha256,
        string $parserVersion,
    ): int {
        $now  = $this->utcNow();

        // Remove any stale Processing or Cancelled row for this checksum before
        // inserting a fresh batch. Processing rows are left behind when a confirm
        // request fails mid-transaction; Cancelled rows are superseded by a
        // re-upload of the same file. Both have no usable data.
        // Child rows must be deleted first due to FK constraints (RESTRICT).
        $this->pdo->prepare(
            "DELETE bp FROM biometric_punch bp
               JOIN attendance_import_batch aib ON aib.import_batch_id = bp.import_batch_id
              WHERE aib.file_checksum = :cs
                AND aib.status IN ('Processing', 'Cancelled')"
        )->execute([':cs' => $sha256]);

        $this->pdo->prepare(
            "DELETE a FROM attendance a
               JOIN attendance_import_batch aib ON aib.import_batch_id = a.import_batch_id
              WHERE aib.file_checksum = :cs
                AND aib.status IN ('Processing', 'Cancelled')
                AND NOT EXISTS (
                    SELECT 1
                      FROM attendance_adjustment aa
                     WHERE aa.attendance_id = a.attendance_id
                )"
        )->execute([':cs' => $sha256]);

        // Preserve any cancelled/stale attendance row that has immutable
        // attendance_adjustment audit entries (ON DELETE RESTRICT). Detach it
        // from the superseded batch so the batch row can be removed cleanly;
        // saveGeneratedAttendance() will update and re-attach it in-place.
        $this->pdo->prepare(
            "UPDATE attendance a
               JOIN attendance_import_batch aib ON aib.import_batch_id = a.import_batch_id
                SET a.status          = 'Cancelled',
                    a.import_batch_id = NULL,
                    a.updated_at      = :now
              WHERE aib.file_checksum = :cs
                AND aib.status IN ('Processing', 'Cancelled')"
        )->execute([':now' => $now, ':cs' => $sha256]);

        $this->pdo->prepare(
            "DELETE FROM attendance_import_batch
              WHERE file_checksum = :cs
                AND status IN ('Processing', 'Cancelled')"
        )->execute([':cs' => $sha256]);

        $stmt = $this->pdo->prepare(
            "INSERT INTO attendance_import_batch
                (device_id, uploaded_by, file_name, file_checksum,
                 source_year, source_month, parser_version, status,
                 records_parsed, records_matched, records_unmatched,
                 duplicates_skipped, incomplete_days, multi_punch_days,
                 uploaded_at)
             VALUES
                (:device_id, :uploaded_by, '', :checksum,
                 :year, :month, :version, 'Processing',
                 0, 0, 0, 0, 0, 0,
                 :now)"
        );
        $stmt->execute([
            ':device_id'   => $deviceId,
            ':uploaded_by' => $uploadedByUserId,
            ':checksum'    => $sha256,
            ':year'        => $year,
            ':month'       => $month,
            ':version'     => $parserVersion,
            ':now'         => $now,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function completeImportBatch(int $batchId, AttendanceImportSummary $summary): void
    {
        $now  = $this->utcNow();
        $stmt = $this->pdo->prepare(
            "UPDATE attendance_import_batch SET
                status              = 'Draft',
                records_parsed      = :parsed,
                records_matched     = :matched,
                records_unmatched   = :unmatched,
                duplicates_skipped  = :duplicates,
                incomplete_days     = :incomplete,
                multi_punch_days    = :multi,
                completed_at        = :now
              WHERE import_batch_id = :id"
        );
        $stmt->execute([
            ':parsed'     => $summary->parsedTokens,
            ':matched'    => $summary->matchedPunches,
            ':unmatched'  => $summary->unmatchedPunches,
            ':duplicates' => $summary->duplicatePunches,
            ':incomplete' => $summary->incompleteDays,
            ':multi'      => $summary->multiPunchDays,
            ':now'        => $now,
            ':id'         => $batchId,
        ]);
    }

    // -----------------------------------------------------------------------
    // Punch matching
    // -----------------------------------------------------------------------

    /**
     * Match a punch against employee_biometric_enrollment effective at the
     * punch timestamp, then resolve branch and schedule.
     */
    public function match(ParsedPunch $punch): AttendancePunchMatch
    {
        $punchLocal = $punch->localTimestamp->format('Y-m-d H:i:s');
        $punchDate  = $punch->localTimestamp->format('Y-m-d');

        // 1. Find enrollment effective at punch time for this device + code.
        // Historical closed enrollments (effective_to IS NOT NULL) remain valid
        // for dates inside [effective_from, effective_to), so pre-separation
        // punches match even if the monthly workbook is imported after archiving.
        $stmt = $this->pdo->prepare(
            "SELECT ebe.employee_id, ebe.enrollment_id
               FROM employee_biometric_enrollment ebe
              WHERE ebe.device_id            = :device_id
                AND ebe.device_employee_code = :code
                AND ebe.effective_from      <= :enrollment_from_date
                AND (ebe.effective_to IS NULL OR ebe.effective_to > :enrollment_to_date)
                AND (ebe.status = 'Active' OR ebe.effective_to IS NOT NULL)
              ORDER BY ebe.effective_from DESC
              LIMIT 1"
        );
        $stmt->execute([
            ':device_id'  => $punch->deviceId,
            ':code'       => $punch->enrollmentCode,
            ':enrollment_from_date' => $punchDate,
            ':enrollment_to_date'   => $punchDate,
        ]);
        $enrollment = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$enrollment) {
            return AttendancePunchMatch::unmatched(AttendancePunchMatch::UNMATCHED_ENROLLMENT);
        }

        $employeeId = (int) $enrollment['employee_id'];

        // 2. Find branch assignment effective at punch date
        $stmt = $this->pdo->prepare(
            "SELECT eba.branch_assignment_id, eba.branch_id
               FROM employee_branch_assignment eba
              WHERE eba.employee_id    = :emp_id
                AND eba.effective_from <= :branch_from_date
                AND (eba.effective_to IS NULL OR eba.effective_to > :branch_to_date)
              ORDER BY eba.effective_from DESC
              LIMIT 1"
        );
        $stmt->execute([
            ':emp_id' => $employeeId,
            ':branch_from_date' => $punchDate,
            ':branch_to_date' => $punchDate,
        ]);
        $branch = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$branch) {
            return AttendancePunchMatch::unmatched(AttendancePunchMatch::OUT_OF_COVERAGE);
        }

        $branchId = (int) $branch['branch_id'];

        // 3. Verify device covers this branch on punch date
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM biometric_device_branch
              WHERE device_id    = :device_id
                AND branch_id    = :branch_id
                AND effective_from <= :coverage_from_date
                AND (effective_to IS NULL OR effective_to > :coverage_to_date)
                AND status = 'Active'"
        );
        $stmt->execute([
            ':device_id'  => $punch->deviceId,
            ':branch_id'  => $branchId,
            ':coverage_from_date' => $punchDate,
            ':coverage_to_date'   => $punchDate,
        ]);
        if ((int) $stmt->fetchColumn() === 0) {
            return AttendancePunchMatch::unmatched(AttendancePunchMatch::OUT_OF_COVERAGE);
        }

        // 4. Find the work schedule assigned to the employee on punch date.
        $scheduleId = $this->scheduleIdOn($employeeId, $punchDate);

        return AttendancePunchMatch::matched($employeeId, $branchId, $scheduleId);
    }

    /**
     * The schedule assignment covering $date, using the same rule as payroll
     * and the timesheet matrix: effective_to is an inclusive last day.
     *
     * ScheduleRepository::assign() supersedes an assignment by setting its
     * effective_to and status 'Archived'. A superseded assignment still governs
     * the dates it covered, so only an Archived row with no end date (a
     * withdrawn assignment) is ignored. Filtering on status = 'Active' alone
     * made every re-import of an earlier month fall back to the default shift.
     */
    public function scheduleIdOn(int $employeeId, string $date): ?int
    {
        $stmt = $this->pdo->prepare(
            "SELECT esa.schedule_id
               FROM employee_schedule_assignment esa
              WHERE esa.employee_id    = :emp_id
                AND esa.effective_from <= :schedule_from_date
                AND (esa.effective_to IS NULL OR esa.effective_to >= :schedule_to_date)
                AND (esa.status = 'Active' OR esa.effective_to IS NOT NULL)
              ORDER BY esa.effective_from DESC
              LIMIT 1"
        );
        $stmt->execute([
            ':emp_id' => $employeeId,
            ':schedule_from_date' => $date,
            ':schedule_to_date' => $date,
        ]);
        $scheduleId = $stmt->fetchColumn();
        return $scheduleId === false ? null : (int) $scheduleId;
    }

    // -----------------------------------------------------------------------
    // Effective schedule loader
    // -----------------------------------------------------------------------

    public function effectiveSchedule(?int $scheduleId, string $attendanceDate): WorkSchedule
    {
        if ($scheduleId === null || $scheduleId === 0) {
            // No schedule assignment found for this employee on this date.
            // Fall back to a standard 7am-4pm default with a 12:00-13:00 break
            // so hours/late/undertime can still be computed; HR should assign a proper schedule.
            return new WorkSchedule(0, '07:00', '16:00', 60, 480, 0, false, '12:00', '13:00');
        }

        $stmt = $this->pdo->prepare(
            "SELECT schedule_id, work_start_time, work_end_time,
                    break_minutes, break_start_time, break_end_time,
                    standard_minutes, grace_minutes, overtime_allowed
               FROM work_schedule
              WHERE schedule_id = :id"
        );
        $stmt->execute([':id' => $scheduleId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return new WorkSchedule(0, '07:00', '16:00', 60, 480, 0, false, '12:00', '13:00');
        }

        return new WorkSchedule(
            (int) $row['schedule_id'],
            substr((string) $row['work_start_time'], 0, 5),
            substr((string) $row['work_end_time'], 0, 5),
            (int) $row['break_minutes'],
            (int) $row['standard_minutes'],
            (int) $row['grace_minutes'],
            (bool) $row['overtime_allowed'],
            $row['break_start_time'] !== null ? substr((string) $row['break_start_time'], 0, 5) : null,
            $row['break_end_time'] !== null ? substr((string) $row['break_end_time'], 0, 5) : null,
        );
    }

    // -----------------------------------------------------------------------
    // Duplicate punch check
    // -----------------------------------------------------------------------

    public function isDuplicatePunch(ParsedPunch $punch): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM biometric_punch
              WHERE device_id            = :device_id
                AND device_employee_code = :code
                AND source_local_at      = :local_at
                AND match_status         = 'matched'"
        );
        $stmt->execute([
            ':device_id' => $punch->deviceId,
            ':code'      => $punch->enrollmentCode,
            ':local_at'  => $punch->localTimestamp->format('Y-m-d H:i:s'),
        ]);
        return (int) $stmt->fetchColumn() > 0;
    }

    // -----------------------------------------------------------------------
    // Punch persistence
    // -----------------------------------------------------------------------

    public function retainUnmatchedPunch(int $batchId, ParsedPunch $punch, string $reason): void
    {
        $this->insertPunch($batchId, $punch, null, null, $reason);
    }

    public function retainMatchedPunch(int $batchId, ParsedPunch $punch, int $employeeId, int $branchId): void
    {
        // Look up branch_assignment_id for this employee on punch date
        $punchDate = $punch->localTimestamp->format('Y-m-d');
        $stmt = $this->pdo->prepare(
            "SELECT branch_assignment_id FROM employee_branch_assignment
              WHERE employee_id = :emp_id
                AND effective_from <= :branch_from_date
                AND (effective_to IS NULL OR effective_to > :branch_to_date)
              ORDER BY effective_from DESC LIMIT 1"
        );
        $stmt->execute([
            ':emp_id' => $employeeId,
            ':branch_from_date' => $punchDate,
            ':branch_to_date' => $punchDate,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $baId = $row ? (int) $row['branch_assignment_id'] : null;

        $this->insertPunch($batchId, $punch, $employeeId, $baId, AttendancePunchMatch::MATCHED);
    }

    private function insertPunch(int $batchId, ParsedPunch $punch, ?int $employeeId, ?int $branchAssignmentId, string $matchStatus): void
    {
        $localAt  = $punch->localTimestamp->format('Y-m-d H:i:s');
        $utcAt    = $punch->localTimestamp->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $now      = $this->utcNow();

        $stmt = $this->pdo->prepare(
            "INSERT INTO biometric_punch
                (import_batch_id, device_id, employee_id, branch_assignment_id,
                 device_employee_code, source_local_at, punched_at_utc,
                 punch_type, match_status,
                 source_department, source_user_id, source_employee_name,
                 raw_record, source_workbook_row, source_date_column,
                 created_at)
             VALUES
                (:batch_id, :device_id, :emp_id, :ba_id,
                 :code, :local_at, :utc_at,
                 'Unknown', :match_status,
                 :dept, :src_user_id, :src_name,
                 :raw, :row, :col,
                 :now)
             ON DUPLICATE KEY UPDATE
                import_batch_id      = VALUES(import_batch_id),
                employee_id          = VALUES(employee_id),
                branch_assignment_id = VALUES(branch_assignment_id),
                match_status         = VALUES(match_status),
                source_department    = VALUES(source_department),
                source_user_id       = VALUES(source_user_id),
                source_employee_name = VALUES(source_employee_name)"
        );
        $stmt->execute([
            ':batch_id'     => $batchId,
            ':device_id'    => $punch->deviceId,
            ':emp_id'       => $employeeId,
            ':ba_id'        => $branchAssignmentId,
            ':code'         => $punch->enrollmentCode,
            ':local_at'     => $localAt,
            ':utc_at'       => $utcAt,
            ':match_status' => $matchStatus,
            ':dept'         => $punch->department,
            ':src_user_id'  => $punch->sourceUserId,
            ':src_name'     => $punch->sourceName,
            ':raw'          => $punch->rawCellValue,
            ':row'          => $punch->sourceRow,
            ':col'          => (string) $punch->sourceColumn,
            ':now'          => $now,
        ]);
    }

    // -----------------------------------------------------------------------
    // Generated attendance persistence
    // -----------------------------------------------------------------------

    public function saveGeneratedAttendance(int $batchId, GeneratedAttendance $attendance): void
    {
        $now = $this->utcNow();

        // Resolve branch_assignment_id and schedule_id for this employee/date
        $punchDate = $attendance->attendanceDate;
        $stmt = $this->pdo->prepare(
            "SELECT branch_assignment_id FROM employee_branch_assignment
              WHERE employee_id = :emp_id
                AND effective_from <= :branch_from_date
                AND (effective_to IS NULL OR effective_to > :branch_to_date)
              ORDER BY effective_from DESC LIMIT 1"
        );
        $stmt->execute([
            ':emp_id'            => $attendance->employeeId,
            ':branch_from_date'  => $punchDate,
            ':branch_to_date'    => $punchDate,
        ]);
        $baRow = $stmt->fetch(PDO::FETCH_ASSOC);
        $baId  = $baRow ? (int) $baRow['branch_assignment_id'] : null;

        $scheduleId = $this->scheduleIdOn($attendance->employeeId, $punchDate);
        $schedule   = $this->effectiveSchedule($scheduleId, $punchDate);
        $resolved   = $this->reconcileAttendance($attendance, $schedule);
        $timeIn     = $resolved['time_in'];
        $timeOut    = $resolved['time_out'];
        $overtime   = $this->approvedOvertimeMinutes(
            $attendance->employeeId,
            $attendance->attendanceDate,
            $timeIn,
            $timeOut,
            $schedule,
        );

        // Check for an existing attendance row for this employee+date.
        //
        // The weekly-upload workflow means HR uploads the same month's XLS again
        // each week as the device accumulates more data. Days from prior weeks
        // will already have attendance rows. Rules:
        //
        //   Existing row belongs to a Cancelled batch → treat as absent (INSERT/UPDATE over it)
        //   Different branch              → throw  (data conflict; HR must resolve)
        //   Same branch + Approved        → throw  (immutable per ADR-0002)
        //   Same branch + Incomplete
        //     AND new data has both punches → UPDATE (boundary-day completion)
        //   Same branch + anything else   → skip silently (already fully captured)
        $existing = $this->pdo->prepare(
            "SELECT a.attendance_id, a.status, eba.branch_id,
                    COALESCE(aib.status, 'manual') AS batch_status
               FROM attendance a
               JOIN employee_branch_assignment eba
                 ON eba.branch_assignment_id = a.branch_assignment_id
               LEFT JOIN attendance_import_batch aib
                 ON aib.import_batch_id = a.import_batch_id
              WHERE a.employee_id    = :emp_id
                AND a.attendance_date = :date
              FOR UPDATE"
        );
        $existing->execute([':emp_id' => $attendance->employeeId, ':date' => $punchDate]);
        $existingRow = $existing->fetch(PDO::FETCH_ASSOC);

        if ($existingRow !== false) {
            // If the existing row is Cancelled or came from a Cancelled batch,
            // it is superseded — overwrite it in-place rather than DELETE+INSERT
            // so attendance_id and any append-only attendance_adjustment audit
            // history (ON DELETE RESTRICT) remain intact.
            if (
                (string) $existingRow['batch_status'] === 'Cancelled'
                || (string) $existingRow['status'] === 'Cancelled'
            ) {
                $this->pdo->prepare(
                    "UPDATE attendance
                        SET branch_assignment_id = :ba_id,
                            schedule_id          = :sch_id,
                            time_in              = :time_in,
                            time_out             = :time_out,
                            hours_worked_minutes = :worked,
                            late_minutes         = :late,
                            undertime_minutes    = :undertime,
                            overtime_minutes     = :overtime,
                            status               = :status,
                            source               = 'xls_import',
                            import_batch_id      = :batch_id,
                            updated_at           = :updated_at
                      WHERE attendance_id = :id"
                )->execute([
                    ':ba_id'      => $baId,
                    ':sch_id'     => $scheduleId,
                    ':time_in'    => $timeIn,
                    ':time_out'   => $timeOut,
                    ':worked'     => $resolved['worked'],
                    ':late'       => $resolved['late'],
                    ':undertime'  => $resolved['undertime'],
                    ':overtime'   => $overtime,
                    ':status'     => $resolved['status'],
                    ':batch_id'   => $batchId,
                    ':updated_at' => $now,
                    ':id'         => (int) $existingRow['attendance_id'],
                ]);
                return;
            } else {
                $sameBranch     = (int) $existingRow['branch_id'] === $this->branchIdForAssignment($baId);
                $existingStatus = (string) $existingRow['status'];
                $existingId     = (int) $existingRow['attendance_id'];

                if (!$sameBranch) {
                    throw new \RuntimeException(
                        'Attendance already exists for this employee and date under another branch. '
                        . 'Resolve that branch assignment before importing.'
                    );
                }

                if ($existingStatus === 'Approved') {
                    throw new \RuntimeException(
                        'Attendance for this employee and date has already been approved and is immutable.'
                    );
                }

                // Incomplete row + new upload now supplies both time-in and time-out
                // → complete the record in-place so the weekly payroll can use it.
                if ($existingStatus === 'Incomplete' && $timeIn !== null && $timeOut !== null) {
                    $this->pdo->prepare(
                        "UPDATE attendance
                            SET time_in              = :time_in,
                                time_out             = :time_out,
                                schedule_id          = :schedule_id,
                                hours_worked_minutes = :worked,
                                late_minutes         = :late,
                                undertime_minutes    = :undertime,
                                overtime_minutes     = :overtime,
                                status               = :status,
                                import_batch_id      = :batch_id,
                                updated_at           = :updated_at
                          WHERE attendance_id = :id"
                    )->execute([
                        ':time_in'    => $timeIn,
                        ':time_out'   => $timeOut,
                        ':schedule_id'=> $scheduleId,
                        ':worked'     => $resolved['worked'],
                        ':late'       => $resolved['late'],
                        ':undertime'  => $resolved['undertime'],
                        ':overtime'   => $overtime,
                        ':status'     => $resolved['status'],
                        ':batch_id'   => $batchId,
                        ':updated_at' => $now,
                        ':id'         => $existingId,
                    ]);
                    return;
                }

                // Complete or ReviewRequired (same branch, not approved): the prior
                // import already captured this day fully — skip silently.
                return;
            }
        }

        $stmt = $this->pdo->prepare(
            "INSERT INTO attendance
                (employee_id, branch_assignment_id, schedule_id,
                 attendance_date, time_in, time_out,
                 hours_worked_minutes, late_minutes, undertime_minutes, overtime_minutes,
                 status, source, import_batch_id,
                 created_at, updated_at)
             VALUES
                (:emp_id, :ba_id, :sch_id,
                 :date, :time_in, :time_out,
                 :worked, :late, :undertime, :overtime,
                 :status, 'xls_import', :batch_id,
                 :created_at, :updated_at)"
        );
        $stmt->execute([
            ':emp_id'     => $attendance->employeeId,
            ':ba_id'      => $baId,
            ':sch_id'     => $scheduleId,
            ':date'       => $punchDate,
            ':time_in'    => $timeIn,
            ':time_out'   => $timeOut,
            ':worked'     => $resolved['worked'],
            ':late'       => $resolved['late'],
            ':undertime'  => $resolved['undertime'],
            ':overtime'   => $overtime,
            ':status'     => $resolved['status'],
            ':batch_id'   => $batchId,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
    }

    // -----------------------------------------------------------------------
    // Cancellation support
    // -----------------------------------------------------------------------

    /**
     * Rebuild one attendance day from the matched punches that live
     * (Draft/Approved/Completed) batches still retain, and hand the row to
     * the newest of those batches.
     *
     * Used after a batch is cancelled: a day is often owned by the newest
     * upload that touched it (for example a later file that supplied a
     * missing time-out) while other punches of that day belong to an earlier
     * upload. Cancelling the newer upload must leave that earlier evidence in
     * force instead of marking the whole day Cancelled.
     *
     * @return bool false when no live punches remain (the day stays Cancelled)
     */
    public function rebuildFromLivePunches(int $attendanceId): bool
    {
        $row = $this->pdo->prepare(
            'SELECT employee_id, attendance_date FROM attendance WHERE attendance_id = :id'
        );
        $row->execute([':id' => $attendanceId]);
        $attendance = $row->fetch(PDO::FETCH_ASSOC);
        if (!$attendance) {
            return false;
        }
        $employeeId = (int) $attendance['employee_id'];
        $date       = (string) $attendance['attendance_date'];

        $stmt = $this->pdo->prepare(
            "SELECT bp.source_local_at, bp.import_batch_id
               FROM biometric_punch bp
               JOIN attendance_import_batch aib ON aib.import_batch_id = bp.import_batch_id
              WHERE bp.employee_id = :employee_id
                AND bp.match_status = :matched
                AND DATE(bp.source_local_at) = :attendance_date
                AND aib.status IN ('Draft', 'Approved', 'Completed')
              ORDER BY bp.source_local_at"
        );
        $stmt->execute([
            ':employee_id'     => $employeeId,
            ':matched'         => AttendancePunchMatch::MATCHED,
            ':attendance_date' => $date,
        ]);
        $punches = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($punches === []) {
            return false;
        }

        $scheduleId = $this->scheduleIdOn($employeeId, $date);
        $schedule   = $this->effectiveSchedule($scheduleId, $date);
        $timezone   = new DateTimeZone('Asia/Manila');
        $result     = (new PunchDayClassifier())->classify(
            array_map(
                static fn (array $p): DateTimeImmutable => new DateTimeImmutable((string) $p['source_local_at'], $timezone),
                $punches,
            ),
            $schedule,
        );

        $timeIn    = $result->timeIn?->format('H:i:s');
        $timeOut   = $result->timeOut?->format('H:i:s');
        $worked    = $result->workedMinutes;
        $late      = $result->lateMinutes;
        $undertime = $result->undertimeMinutes;
        $status    = $result->status;
        $overtime  = ($timeIn !== null && $timeOut !== null)
            ? $this->approvedOvertimeMinutes($employeeId, $date, $timeIn, $timeOut, $schedule)
            : 0;
        $ownerBatch = max(array_map(static fn (array $p): int => (int) $p['import_batch_id'], $punches));

        $this->pdo->prepare(
            "UPDATE attendance
                SET time_in              = :time_in,
                    time_out             = :time_out,
                    schedule_id          = :schedule_id,
                    hours_worked_minutes = :worked,
                    late_minutes         = :late,
                    undertime_minutes    = :undertime,
                    overtime_minutes     = :overtime,
                    status               = :status,
                    import_batch_id      = :batch_id,
                    updated_at           = :updated_at
              WHERE attendance_id = :id"
        )->execute([
            ':time_in'     => $timeIn,
            ':time_out'    => $timeOut,
            ':schedule_id' => $scheduleId,
            ':worked'      => $worked,
            ':late'        => $late,
            ':undertime'   => $undertime,
            ':overtime'    => $overtime,
            ':status'      => $status,
            ':batch_id'    => $ownerBatch,
            ':updated_at'  => $this->utcNow(),
            ':id'          => $attendanceId,
        ]);
        return true;
    }

    /**
     * Re-match previously unmatched or coverage_exception punches across live
     * import batches and generate/rebuild affected attendance rows.
     *
     * Automatically invoked after creating/updating/rehiring an employee's
     * biometric enrollment, or triggered explicitly by HR from the Attendance
     * screen ("Re-match Unmatched Punches").
     *
     * @return array{rematched_punches:int,still_unmatched:int,days_updated:int,batches_updated:int}
     */
    public function rematchUnmatchedPunches(?int $deviceId = null, ?string $enrollmentCode = null, ?int $actingUserId = null): array
    {
        $where = [
            "bp.match_status IN ('unmatched', 'coverage_exception')",
            "aib.status IN ('Draft', 'Approved', 'Completed')",
        ];
        $params = [];
        if ($deviceId !== null && $deviceId > 0) {
            $where[] = 'bp.device_id = :device_id';
            $params[':device_id'] = $deviceId;
        }
        if ($enrollmentCode !== null && $enrollmentCode !== '') {
            $where[] = 'bp.device_employee_code = :code';
            $params[':code'] = $enrollmentCode;
        }

        $stmt = $this->pdo->prepare(
            "SELECT bp.punch_id, bp.import_batch_id, bp.device_id, bp.device_employee_code,
                    bp.source_local_at, bp.source_workbook_row, bp.source_date_column,
                    bp.raw_record, bp.source_department, bp.source_user_id, bp.source_employee_name
               FROM biometric_punch bp
               JOIN attendance_import_batch aib ON aib.import_batch_id = bp.import_batch_id
              WHERE " . implode(' AND ', $where) . "
              ORDER BY bp.source_local_at, bp.punch_id"
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($rows === []) {
            return [
                'rematched_punches' => 0,
                'still_unmatched'   => 0,
                'days_updated'      => 0,
                'batches_updated'   => 0,
            ];
        }

        $timezone = new DateTimeZone('Asia/Manila');
        $now = $this->utcNow();
        $generator = new \Wbpms\Domain\Attendance\TimesheetGenerator();

        $approvedPayrollStmt = $this->pdo->prepare(
            "SELECT COUNT(*)
               FROM payroll p
               JOIN payroll_run pr ON pr.payroll_run_id = p.payroll_run_id
               JOIN payroll_period pp ON pp.payroll_period_id = pr.payroll_period_id
              WHERE p.employee_id = :emp_id
                AND pr.status = 'Approved'
                AND :punch_date BETWEEN pp.period_start AND pp.period_end"
        );
        $assignmentStmt = $this->pdo->prepare(
            "SELECT branch_assignment_id
               FROM employee_branch_assignment
              WHERE employee_id = :emp_id
                AND effective_from <= :from_date
                AND (effective_to IS NULL OR effective_to > :to_date)
              ORDER BY effective_from DESC
              LIMIT 1"
        );
        $updatePunchStmt = $this->pdo->prepare(
            "UPDATE biometric_punch
                SET employee_id          = :emp_id,
                    branch_assignment_id = :ba_id,
                    match_status         = :status,
                    resolved_by          = :resolved_by,
                    resolved_at          = :resolved_at
              WHERE punch_id = :punch_id"
        );

        $rematched = 0;
        $stillUnmatched = 0;
        $groups = [];
        $touchedBatches = [];

        foreach ($rows as $row) {
            $localTimestamp = new DateTimeImmutable((string) $row['source_local_at'], $timezone);
            $punch = new ParsedPunch(
                (int) $row['device_id'],
                (string) $row['device_employee_code'],
                $localTimestamp,
                (int) $row['source_workbook_row'],
                (string) $row['source_date_column'],
                (string) $row['raw_record'],
                $row['source_department'] !== null ? (string) $row['source_department'] : null,
                $row['source_user_id'] !== null ? (string) $row['source_user_id'] : null,
                $row['source_employee_name'] !== null ? (string) $row['source_employee_name'] : null,
            );
            $match = $this->match($punch);
            if ($match->status !== AttendancePunchMatch::MATCHED || $match->employeeId === null) {
                $stillUnmatched++;
                if ($match->status !== '') {
                    $updatePunchStmt->execute([
                        ':emp_id'      => null,
                        ':ba_id'       => null,
                        ':status'      => $match->status,
                        ':resolved_by' => null,
                        ':resolved_at' => null,
                        ':punch_id'    => (int) $row['punch_id'],
                    ]);
                }
                continue;
            }

            $punchDate = $localTimestamp->format('Y-m-d');
            $approvedPayrollStmt->execute([
                ':emp_id'     => $match->employeeId,
                ':punch_date' => $punchDate,
            ]);
            if ((int) $approvedPayrollStmt->fetchColumn() > 0) {
                $stillUnmatched++;
                continue;
            }

            $assignmentStmt->execute([
                ':emp_id'    => $match->employeeId,
                ':from_date' => $punchDate,
                ':to_date'   => $punchDate,
            ]);
            $baId = $assignmentStmt->fetchColumn();

            $updatePunchStmt->execute([
                ':emp_id'      => $match->employeeId,
                ':ba_id'       => $baId !== false ? (int) $baId : null,
                ':status'      => AttendancePunchMatch::MATCHED,
                ':resolved_by' => $actingUserId !== null && $actingUserId > 0 ? $actingUserId : null,
                ':resolved_at' => $now,
                ':punch_id'    => (int) $row['punch_id'],
            ]);

            $rematched++;
            $batchId = (int) $row['import_batch_id'];
            $touchedBatches[$batchId] = true;

            $groupKey = $match->employeeId . ':' . $punchDate;
            $groups[$groupKey] ??= [
                'batchId'    => $batchId,
                'employeeId' => $match->employeeId,
                'scheduleId' => $match->scheduleId,
                'date'       => $punchDate,
                'punches'    => [],
            ];
            $groups[$groupKey]['batchId'] = max($groups[$groupKey]['batchId'], $batchId);
            $groups[$groupKey]['punches'][] = $punch;
        }

        $daysUpdated = 0;
        foreach ($groups as $group) {
            $attendance = $generator->generate(
                $group['employeeId'],
                $group['date'],
                $group['punches'],
                $this->effectiveSchedule($group['scheduleId'], $group['date']),
            );
            $this->saveGeneratedAttendance($group['batchId'], $attendance);
            $daysUpdated++;
        }

        // Recount batch statistics for all affected batches
        foreach (array_keys($touchedBatches) as $batchId) {
            $this->recountBatchCounters($batchId);
        }

        return [
            'rematched_punches' => $rematched,
            'still_unmatched'   => $stillUnmatched,
            'days_updated'      => $daysUpdated,
            'batches_updated'   => count($touchedBatches),
        ];
    }

    /**
     * Recompute matched / unmatched / incomplete / multi-punch counters on a batch.
     */
    public function recountBatchCounters(int $batchId): void
    {
        $punchCounts = $this->pdo->prepare(
            "SELECT
                SUM(CASE WHEN match_status = 'matched' THEN 1 ELSE 0 END) AS matched,
                SUM(CASE WHEN match_status IN ('unmatched', 'coverage_exception') THEN 1 ELSE 0 END) AS unmatched
               FROM biometric_punch
              WHERE import_batch_id = :id"
        );
        $punchCounts->execute([':id' => $batchId]);
        $pc = $punchCounts->fetch(PDO::FETCH_ASSOC) ?: [];

        $attCounts = $this->pdo->prepare(
            "SELECT
                SUM(CASE WHEN status = 'Incomplete' THEN 1 ELSE 0 END) AS incomplete,
                SUM(CASE WHEN status = 'ReviewRequired' THEN 1 ELSE 0 END) AS multi
               FROM attendance
              WHERE import_batch_id = :id AND status <> 'Cancelled'"
        );
        $attCounts->execute([':id' => $batchId]);
        $ac = $attCounts->fetch(PDO::FETCH_ASSOC) ?: [];

        $this->pdo->prepare(
            "UPDATE attendance_import_batch
                SET records_matched   = :matched,
                    records_unmatched = :unmatched,
                    incomplete_days   = :incomplete,
                    multi_punch_days  = :multi
              WHERE import_batch_id = :id"
        )->execute([
            ':matched'    => (int) ($pc['matched']    ?? 0),
            ':unmatched'  => (int) ($pc['unmatched']  ?? 0),
            ':incomplete' => (int) ($ac['incomplete'] ?? 0),
            ':multi'      => (int) ($ac['multi']      ?? 0),
            ':id'         => $batchId,
        ]);
    }

    /**
     * Dry-run diagnostic for an enrollment code on a device across a set of dates.
     *
     * @param list<string> $dates
     * @return array{status:string,employee_id:int|null,employee_name:string|null,employee_number:string|null,branch_name:string|null,reason:string}
     */
    public function diagnoseEnrollmentMatch(int $deviceId, string $enrollmentCode, array $dates): array
    {
        $sampleDate = $dates[0] ?? date('Y-m-d');
        $lastDate   = $dates[array_key_last($dates)] ?? $sampleDate;

        // Check if enrollment code exists on this device at all
        $stmt = $this->pdo->prepare(
            "SELECT ebe.employee_id, ebe.effective_from, ebe.effective_to, ebe.status AS ebe_status,
                    e.employee_number, CONCAT(e.last_name, ', ', e.first_name) AS employee_name,
                    e.status AS emp_status
               FROM employee_biometric_enrollment ebe
               JOIN employee e ON e.employee_id = ebe.employee_id
              WHERE ebe.device_id = :device_id
                AND ebe.device_employee_code = :code
              ORDER BY ebe.effective_from DESC
              LIMIT 1"
        );
        $stmt->execute([':device_id' => $deviceId, ':code' => $enrollmentCode]);
        $ebe = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$ebe) {
            return [
                'status'          => 'unmatched',
                'employee_id'     => null,
                'employee_name'   => null,
                'employee_number' => null,
                'branch_name'     => null,
                'reason'          => 'Unregistered Enroll ID on this biometric device',
            ];
        }

        $employeeId = (int) $ebe['employee_id'];
        $empName    = (string) $ebe['employee_name'];
        $empNum     = (string) $ebe['employee_number'];
        $ebeFrom    = (string) $ebe['effective_from'];
        $ebeTo      = $ebe['effective_to'] !== null ? (string) $ebe['effective_to'] : null;

        if ($ebeFrom > $lastDate) {
            return [
                'status'          => 'unmatched',
                'employee_id'     => $employeeId,
                'employee_name'   => $empName,
                'employee_number' => $empNum,
                'branch_name'     => null,
                'reason'          => "Hire / enrollment effective date ({$ebeFrom}) is after the workbook punch dates",
            ];
        }

        if ($ebeTo !== null && $ebeTo <= $sampleDate) {
            return [
                'status'          => 'unmatched',
                'employee_id'     => $employeeId,
                'employee_name'   => $empName,
                'employee_number' => $empNum,
                'branch_name'     => null,
                'reason'          => "Enrollment ended on {$ebeTo} before the workbook punch dates",
            ];
        }

        // Check branch assignment on sample/last date
        $checkDate = max($sampleDate, $ebeFrom);
        $baStmt = $this->pdo->prepare(
            "SELECT eba.branch_id, b.branch_name
               FROM employee_branch_assignment eba
               JOIN branch b ON b.branch_id = eba.branch_id
              WHERE eba.employee_id = :emp_id
                AND eba.effective_from <= :d1
                AND (eba.effective_to IS NULL OR eba.effective_to > :d2)
              ORDER BY eba.effective_from DESC
              LIMIT 1"
        );
        $baStmt->execute([':emp_id' => $employeeId, ':d1' => $checkDate, ':d2' => $checkDate]);
        $ba = $baStmt->fetch(PDO::FETCH_ASSOC);

        if (!$ba) {
            return [
                'status'          => 'coverage_exception',
                'employee_id'     => $employeeId,
                'employee_name'   => $empName,
                'employee_number' => $empNum,
                'branch_name'     => null,
                'reason'          => "No active branch assignment covers {$checkDate}",
            ];
        }

        $branchId   = (int) $ba['branch_id'];
        $branchName = (string) $ba['branch_name'];

        $covStmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM biometric_device_branch
              WHERE device_id = :device_id
                AND branch_id = :branch_id
                AND effective_from <= :d1
                AND (effective_to IS NULL OR effective_to > :d2)
                AND status = 'Active'"
        );
        $covStmt->execute([
            ':device_id' => $deviceId,
            ':branch_id' => $branchId,
            ':d1'        => $checkDate,
            ':d2'        => $checkDate,
        ]);
        if ((int) $covStmt->fetchColumn() === 0) {
            return [
                'status'          => 'coverage_exception',
                'employee_id'     => $employeeId,
                'employee_name'   => $empName,
                'employee_number' => $empNum,
                'branch_name'     => $branchName,
                'reason'          => "Device-branch mismatch: selected device does not cover branch '{$branchName}'",
            ];
        }

        $partialNote = $ebeFrom > $sampleDate
            ? "Matched from {$ebeFrom} onward (pre-hire punches before {$ebeFrom} skipped)"
            : 'Matched';

        return [
            'status'          => 'matched',
            'employee_id'     => $employeeId,
            'employee_name'   => $empName,
            'employee_number' => $empNum,
            'branch_name'     => $branchName,
            'reason'          => $partialNote,
        ];
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Attendance records only retain overtime that an approved request
     * authorizes and that overlaps the employee's actual overtime window.
     */
    public function approvedOvertimeMinutes(int $employeeId, string $attendanceDate, ?string $timeIn, ?string $timeOut, WorkSchedule $schedule): int
    {
        if ($timeIn === null || $timeOut === null || !$schedule->overtimeAllowed) {
            return 0;
        }

        $stmt = $this->pdo->prepare(
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
            $schedule->startTime,
            $schedule->endTime,
            $schedule->unpaidBreakMinutes,
            $schedule->standardMinutes,
            $requests,
            $schedule->breakStartTime,
            $schedule->breakEndTime,
        );
    }

    /**
     * Combine all retained punches for the employee/date. This lets a later
     * import containing only a missing time-out complete an earlier one-punch
     * record without discarding either raw biometric event.
     *
     * @return array{time_in:?string,time_out:?string,worked:int,late:int,undertime:int,status:string}
     */
    private function reconcileAttendance(GeneratedAttendance $attendance, WorkSchedule $schedule): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT source_local_at
               FROM biometric_punch
              WHERE employee_id = :employee_id
                AND match_status = :matched
                AND DATE(source_local_at) = :attendance_date
              ORDER BY source_local_at"
        );
        $stmt->execute([
            ':employee_id' => $attendance->employeeId,
            ':matched' => AttendancePunchMatch::MATCHED,
            ':attendance_date' => $attendance->attendanceDate,
        ]);
        $punches = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if ($punches === []) {
            return [
                'time_in' => $attendance->timeIn?->format('H:i:s'),
                'time_out' => $attendance->timeOut?->format('H:i:s'),
                'worked' => $attendance->workedMinutes,
                'late' => $attendance->lateMinutes,
                'undertime' => $attendance->undertimeMinutes,
                'status' => $attendance->status(),
            ];
        }

        // Every retained punch for the day (earlier uploads and this one),
        // classified with the same rule as the import itself.
        $timezone = new DateTimeZone('Asia/Manila');
        $result   = (new PunchDayClassifier())->classify(
            array_map(static fn (mixed $at): DateTimeImmutable => new DateTimeImmutable((string) $at, $timezone), $punches),
            $schedule,
        );

        return [
            'time_in' => $result->timeIn?->format('H:i:s'),
            'time_out' => $result->timeOut?->format('H:i:s'),
            'worked' => $result->workedMinutes,
            'late' => $result->lateMinutes,
            'undertime' => $result->undertimeMinutes,
            'status' => $result->status,
        ];
    }

    private function utcNow(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }

    private function branchIdForAssignment(?int $branchAssignmentId): int
    {
        if ($branchAssignmentId === null) {
            return 0;
        }
        $stmt = $this->pdo->prepare(
            'SELECT branch_id FROM employee_branch_assignment WHERE branch_assignment_id = :id'
        );
        $stmt->execute([':id' => $branchAssignmentId]);
        return (int) $stmt->fetchColumn();
    }
}

<?php

declare(strict_types=1);

namespace Wbpms\Application;

use PDO;
use RuntimeException;
use Wbpms\Domain\CashAdvance\CashAdvanceDate;
use Wbpms\Domain\Leave\LeaveDayCounter;
use Wbpms\Domain\Payroll\ApprovedOvertimeCalculator;
use Wbpms\Infrastructure\Database\Connection;
use Wbpms\Infrastructure\Persistence\WorkCalendarRepository;

/**
 * RequestService — application service for leave/overtime/cash-advance requests.
 *
 * Implements REQ032–REQ046, REQ076–REQ080:
 *   REQ032  HR lists all requests with filters
 *   REQ033  HR views a single request
 *   REQ034  HR pre-approves a request (Pending → HRApproved)
 *   REQ034b Owner finally approves (HRApproved → Approved) — side-effects applied here
 *   REQ035  HR or Owner rejects a request with a note
 *   REQ036  HR archives a request
 *   REQ076  Employee submits a leave request
 *   REQ077  Employee submits an overtime request
 *   REQ078  Sick-leave balance check via leave_entitlement + leave_ledger
 *   REQ079  Employee submits a cash-advance request
 *   REQ080  Employee cancels a Pending request
 *
 * Two-stage approval workflow:
 *   Pending → HR pre-approves → HRApproved → Owner approves → Approved
 *                                           → Owner returns  → Returned
 *   Returned → HR re-submits (pre-approve again) → HRApproved
 *
 * Business rules:
 *   - Only Pending or Returned requests may be pre-approved by HR.
 *   - Only HRApproved requests may be finally approved or returned by Owner.
 *   - Leave ledger debit and CashAdvance obligation are created on Owner final approval.
 *   - Leave balance is checked against the leave_entitlement before submission
 *     (minus the employee's other leave requests still waiting for approval)
 *     and again, with the entitlement row locked, on Owner final approval.
 *     If no entitlement row exists for the year, a 4-day default is auto-provisioned.
 *   - Leave days are the scheduled working days in the range that are not
 *     holidays: the same days payroll pays. Rest days are not charged.
 *   - Leave and overtime cannot be submitted or approved for dates already
 *     covered by an Approved payroll run (it could never be paid).
 *   - Leave uses the credit of the year its dates fall in, not the year it
 *     is approved in; a range crossing into another year is refused. [REQ-024]
 *   - A cash advance carries the date it is given (advance_date); payroll
 *     repays it only in runs whose period ends on or after it. [REQ-023]
 */
final class RequestService
{
    private ApprovedOvertimeCalculator $approvedOvertimeCalculator;
    private LeaveDayCounter $leaveDayCounter;
    private WorkCalendarRepository $calendar;

    public function __construct(private Connection $connection)
    {
        $this->approvedOvertimeCalculator = new ApprovedOvertimeCalculator();
        $this->leaveDayCounter = new LeaveDayCounter();
        $this->calendar = new WorkCalendarRepository($connection);
    }

    // -----------------------------------------------------------------------
    // Employee — REQ076–REQ080
    // -----------------------------------------------------------------------

    /**
     * Submit a new request.
     *
     * Leave requests require start_date, end_date (YYYY-MM-DD).
     * Overtime requests require date_from, date_to (YYYY-MM-DD), start_time, end_time (HH:MM).
     *   date_from / date_to define the range of days; start_time / end_time apply to every
     *   day in that range (same window each day).
     * Cash advance requests require amount_requested (numeric string > 0) and
     * advance_date (YYYY-MM-DD, the date the cash is given; payroll repays it
     * from the run whose period contains that date onward).
     *
     * @param array{
     *   request_type_id:   int,
     *   reason:            string,
     *   start_date?:       string,
     *   end_date?:         string,
     *   date_from?:        string,
     *   date_to?:          string,
     *   start_time?:       string,
     *   end_time?:         string,
     *   amount_requested?: string,
     *   advance_date?:     string,
     * } $data
     * @throws RuntimeException on validation failure or insufficient leave balance
     */
    public function submit(int $employeeId, array $data, int $actingUserId = 0): int
    {
        $typeId = (int) ($data['request_type_id'] ?? 0);
        $reason = trim($data['reason'] ?? '');

        if ($typeId === 0) {
            throw new RuntimeException('Request type is required.');
        }
        if ($reason === '') {
            throw new RuntimeException('Reason is required.');
        }

        $typeName = $this->resolveTypeName($typeId);
        $leaveDayCount = 0.0;

        // --- Type-specific validation -----------------------------------------

        if ($typeName === 'Leave') {
            $startDate = trim($data['start_date'] ?? '');
            $endDate   = trim($data['end_date']   ?? '');

            if ($startDate === '' || !$this->isValidDate($startDate)) {
                throw new RuntimeException('Leave start date is required (YYYY-MM-DD).');
            }
            if ($endDate === '' || !$this->isValidDate($endDate)) {
                throw new RuntimeException('Leave end date is required (YYYY-MM-DD).');
            }
            if ($endDate < $startDate) {
                throw new RuntimeException('Leave end date must be on or after start date.');
            }
            $leaveYear = $this->leaveCreditYear($startDate, $endDate);

            $leaveDates    = $this->chargeableLeaveDates($employeeId, $startDate, $endDate);
            $leaveDayCount = (float) count($leaveDates);
            $this->assertNotInApprovedPayroll($employeeId, $leaveDates, 'Leave');

            // REQ078: check the credit of the leave's own year, counting that
            // year's other requests still waiting for approval
            $this->assertLeaveBalance($employeeId, $leaveDayCount, $leaveYear);
        }

        if ($typeName === 'Overtime') {
            $dateFrom  = trim($data['date_from']   ?? '');
            $dateTo    = trim($data['date_to']     ?? '');
            $startTime = trim($data['start_time']  ?? '');
            $endTime   = trim($data['end_time']    ?? '');

            if ($dateFrom === '' || !$this->isValidDate($dateFrom)) {
                throw new RuntimeException('Overtime start date (From) is required (YYYY-MM-DD).');
            }
            if ($dateTo === '' || !$this->isValidDate($dateTo)) {
                throw new RuntimeException('Overtime end date (To) is required (YYYY-MM-DD).');
            }
            if ($dateTo < $dateFrom) {
                throw new RuntimeException('Overtime end date must be on or after the start date.');
            }
            if ($startTime === '' || !$this->isValidTime($startTime)) {
                throw new RuntimeException('Overtime start time is required (HH:MM).');
            }
            if ($endTime === '' || !$this->isValidTime($endTime)) {
                throw new RuntimeException('Overtime end time is required (HH:MM).');
            }
            if ($endTime <= $startTime && $dateTo === $dateFrom) {
                throw new RuntimeException('Overtime end time must be after start time.');
            }
            $this->assertNotInApprovedPayroll($employeeId, $this->dateRange($dateFrom, $dateTo), 'Overtime');
        }

        if ($typeName === 'CashAdvance') {
            $amount = trim($data['amount_requested'] ?? '');
            if ($amount === '' || !is_numeric($amount) || (float) $amount <= 0) {
                throw new RuntimeException('Cash advance amount must be a positive number.');
            }
            if ((float) $amount > 2000.00) {
                throw new RuntimeException('Cash advance amount cannot exceed ₱2,000.00.');
            }

            // The date the advance is given decides which payroll weeks repay it.
            $advanceDate = trim($data['advance_date'] ?? '');
            $dateProblem = CashAdvanceDate::problem(
                $advanceDate,
                $this->employeeHireDate($employeeId),
                new \DateTimeImmutable('now', new \DateTimeZone('Asia/Manila')),
            );
            if ($dateProblem !== null) {
                throw new RuntimeException($dateProblem);
            }
        }

        // --- Persist (header + detail in one transaction) --------------------

        return $this->connection->transaction(function (PDO $pdo) use (
            $employeeId, $typeId, $typeName, $reason, $data, $actingUserId, $leaveDayCount
        ): int {
            $hrRoleStmt = $pdo->prepare(
                "SELECT COUNT(*)
                   FROM users u
                   JOIN role r ON r.role_id = u.role_id
                  WHERE u.employee_id = :emp_id
                    AND r.role_name = 'HRHead'
                    AND u.status != 'Archived'"
            );
            $hrRoleStmt->execute([':emp_id' => $employeeId]);
            $initialStatus = ((int) $hrRoleStmt->fetchColumn() > 0) ? 'HRApproved' : 'Pending';

            $stmt = $pdo->prepare(
                "INSERT INTO request
                    (employee_id, request_type_id, reason, status, submitted_at, created_at, updated_at)
                 VALUES
                    (:emp, :type, :reason, :status, NOW(), NOW(), NOW())"
            );
            $stmt->execute([
                ':emp'    => $employeeId,
                ':type'   => $typeId,
                ':reason' => $reason,
                ':status' => $initialStatus,
            ]);
            $requestId = (int) $pdo->lastInsertId();

            // Insert type-specific detail row
            if ($typeName === 'Leave') {
                $startDate     = $data['start_date'];
                $endDate       = $data['end_date'];
                $daysRequested = $leaveDayCount;

                $pdo->prepare(
                    "INSERT INTO leave_request_detail
                        (request_id, leave_type, start_date, end_date, days_requested)
                     VALUES
                        (:rid, 'Sick', :start, :end, :days)"
                )->execute([
                    ':rid'   => $requestId,
                    ':start' => $startDate,
                    ':end'   => $endDate,
                    ':days'  => number_format($daysRequested, 2, '.', ''),
                ]);
            }

            if ($typeName === 'Overtime') {
                $startTime = $data['start_time'];
                $endTime   = $data['end_time'];
                $minutes   = $this->calcMinutes($startTime, $endTime);

                $pdo->prepare(
                    "INSERT INTO overtime_request_detail
                        (request_id, date_from, date_to, start_time, end_time, requested_minutes)
                     VALUES
                        (:rid, :date_from, :date_to, :start, :end, :mins)"
                )->execute([
                    ':rid'       => $requestId,
                    ':date_from' => $data['date_from'],
                    ':date_to'   => $data['date_to'],
                    ':start'     => $startTime . ':00',
                    ':end'       => $endTime . ':00',
                    ':mins'      => $minutes,
                ]);
            }

            if ($typeName === 'CashAdvance') {
                $pdo->prepare(
                    "INSERT INTO cash_advance_request_detail
                        (request_id, amount, advance_date)
                     VALUES
                        (:rid, :amt, :advance_date)"
                )->execute([
                    ':rid'          => $requestId,
                    ':amt'          => $data['amount_requested'],
                    ':advance_date' => trim($data['advance_date'] ?? ''),
                ]);
            }

            $pdo->prepare(
                "INSERT INTO audit_logs
                    (user_id, event_type, action_performed, table_affected, record_id,
                     description, action_at, created_at)
                 VALUES
                    (:user_id, 'request_submitted', 'submit_request', 'request', :record_id,
                     :description, NOW(), NOW())"
            )->execute([
                ':user_id'     => $actingUserId > 0 ? $actingUserId : null,
                ':record_id'   => $requestId,
                ':description' => "Employee {$employeeId} submitted a {$typeName} request (request_id={$requestId}).",
            ]);

            return $requestId;
        });
    }

    /**
     * Cancel a Pending or Returned request (employee action — REQ080).
     *
     * @throws RuntimeException when request not found, not owned by employee, or not cancellable
     */
    public function cancel(int $requestId, int $employeeId, int $actingUserId = 0): void
    {
        $row = $this->findOrFail($requestId);

        if ((int) $row['employee_id'] !== $employeeId) {
            throw new RuntimeException('You may only cancel your own requests.');
        }
        $isHrSelfDirectRequest = ($row['status'] === 'HRApproved' && empty($row['reviewed_by']));
        if (!in_array($row['status'], ['Pending', 'Returned'], true) && !$isHrSelfDirectRequest) {
            throw new RuntimeException('Only Pending or Returned requests can be cancelled.');
        }

        $this->connection->transaction(function (PDO $pdo) use ($requestId, $employeeId, $actingUserId): void {
            $pdo->prepare(
                "UPDATE request SET status = 'Cancelled', updated_at = NOW() WHERE request_id = :id"
            )->execute([':id' => $requestId]);

            $pdo->prepare(
                "INSERT INTO audit_logs
                    (user_id, event_type, action_performed, table_affected, record_id,
                     description, action_at, created_at)
                 VALUES
                    (:user_id, 'request_cancelled', 'cancel_request', 'request', :record_id,
                     :description, NOW(), NOW())"
            )->execute([
                ':user_id'     => $actingUserId > 0 ? $actingUserId : null,
                ':record_id'   => $requestId,
                ':description' => "Employee {$employeeId} cancelled request {$requestId}.",
            ]);
        });
    }

    // -----------------------------------------------------------------------
    // HR — REQ032–REQ036
    // -----------------------------------------------------------------------

    /**
     * List all requests with optional filters.
     *
     * @param array{
     *   status?:  string,
     *   type_id?: int,
     *   search?:  string,
     * } $filters
     * @return list<array<string,mixed>>
     */
    public function list(array $filters = []): array
    {
        $where  = ['r.archived_at IS NULL'];
        $params = [];

        if (!empty($filters['status'])) {
            $where[]           = 'r.status = :status';
            $params[':status'] = $filters['status'];
        }
        if (!empty($filters['type_id'])) {
            $where[]            = 'r.request_type_id = :type_id';
            $params[':type_id'] = (int) $filters['type_id'];
        }
        if (!empty($filters['search'])) {
            // Every word in any order: first, middle, last name or number (REQ012).
            [$nameSql, $nameParams] = \Wbpms\Infrastructure\Persistence\EmployeeNameSearch::condition((string) $filters['search'], 'e', 'search_q');
            if ($nameSql !== '') {
                $where[] = $nameSql;
                $params  = array_merge($params, $nameParams);
            }
        }

        $whereSql = 'WHERE ' . implode(' AND ', $where);

        $stmt = $this->connection->pdo()->prepare(
            "SELECT r.request_id,
                    CONCAT(e.last_name, ', ', e.first_name) AS employee_name,
                    e.employee_number,
                    rt.type_name,
                    r.status,
                    r.submitted_at,
                    r.reason,
                    r.review_notes
               FROM request r
               JOIN employee e      ON e.employee_id      = r.employee_id
               JOIN request_type rt ON rt.request_type_id = r.request_type_id
              {$whereSql}
              ORDER BY r.submitted_at DESC
              LIMIT 300"
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Return a single request row (with detail payload) or throw.
     *
     * @return array<string,mixed>
     * @throws RuntimeException
     */
    public function findOrFail(int $requestId): array
    {
        $stmt = $this->connection->pdo()->prepare(
            "SELECT r.request_id,
                    r.employee_id,
                    r.request_type_id,
                    r.reason,
                    r.status,
                    r.submitted_at,
                    r.reviewed_by,
                    r.reviewed_at,
                    r.review_notes,
                    r.owner_reviewed_by,
                    r.owner_reviewed_at,
                    r.owner_notes,
                    r.archived_at,
                    CONCAT(e.last_name, ', ', e.first_name) AS employee_name,
                    e.employee_number,
                    rt.type_name,
                    -- Leave detail
                    lrd.start_date,
                    lrd.end_date,
                    lrd.days_requested,
                    -- Overtime detail
                    ord2.date_from,
                    ord2.date_to,
                    ord2.start_time   AS overtime_start_time,
                    ord2.end_time     AS overtime_end_time,
                    ord2.requested_minutes,
                    -- Cash advance detail
                    card.amount       AS amount_requested,
                    card.advance_date
               FROM request r
               JOIN employee e      ON e.employee_id      = r.employee_id
               JOIN request_type rt ON rt.request_type_id = r.request_type_id
               LEFT JOIN leave_request_detail    lrd   ON lrd.request_id  = r.request_id
               LEFT JOIN overtime_request_detail ord2  ON ord2.request_id = r.request_id
               LEFT JOIN cash_advance_request_detail card ON card.request_id = r.request_id
              WHERE r.request_id = :id"
        );
        $stmt->execute([':id' => $requestId]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new RuntimeException("Request #{$requestId} not found.");
        }
        return $row;
    }

    /**
     * HR pre-approves a Pending or Returned request (REQ034).
     *
     * Moves status to HRApproved. Side-effects (leave debit, cash-advance
     * obligation) are NOT applied here — they happen on Owner final approval.
     *
     * @throws RuntimeException when not found or not in Pending/Returned status
     */
    public function approve(int $requestId, int $actingUserId): void
    {
        $row = $this->findOrFail($requestId);

        if ($actingUserId > 0) {
            $empStmt = $this->connection->pdo()->prepare('SELECT employee_id FROM users WHERE user_id = :uid LIMIT 1');
            $empStmt->execute([':uid' => $actingUserId]);
            $actorEmpId = $empStmt->fetchColumn();
            if ($actorEmpId !== false && $actorEmpId !== null && (int) $actorEmpId === (int) $row['employee_id']) {
                throw new RuntimeException('You cannot pre-approve your own request. It must be reviewed by the Business Owner.');
            }
        }

        if (!in_array($row['status'], ['Pending', 'Returned'], true)) {
            throw new RuntimeException('Only Pending or Returned requests can be pre-approved by HR.');
        }

        // Fail early with the same checks the Owner approval enforces.
        $this->assertPayableRequest($row);

        $this->connection->transaction(function (PDO $pdo) use ($requestId, $actingUserId): void {
            $pdo->prepare(
                "UPDATE request
                    SET status      = 'HRApproved',
                        reviewed_by = :reviewer,
                        reviewed_at = NOW(),
                        updated_at  = NOW()
                  WHERE request_id  = :id"
            )->execute([':reviewer' => $actingUserId, ':id' => $requestId]);

            $pdo->prepare(
                "INSERT INTO audit_logs
                    (user_id, event_type, action_performed, table_affected, record_id,
                     description, action_at, created_at)
                 VALUES
                    (:user_id, 'request_hr_approved', 'hr_approve_request', 'request', :record_id,
                     :description, NOW(), NOW())"
            )->execute([
                ':user_id'     => $actingUserId,
                ':record_id'   => $requestId,
                ':description' => "HR pre-approved request {$requestId} (user_id={$actingUserId}).",
            ]);
        });
    }

    /**
     * Owner finally approves an HRApproved request (REQ034b).
     *
     * Side effects applied here:
     *   - Leave: appends a Usage ledger entry debiting the balance.
     *   - CashAdvance: creates a cash_advance_history obligation row.
     *
     * @throws RuntimeException when not found or not in HRApproved status
     */
    public function ownerApprove(int $requestId, int $actingUserId): void
    {
        $row = $this->findOrFail($requestId);

        if ($row['status'] !== 'HRApproved') {
            throw new RuntimeException('Only HR-approved requests can be finally approved by the Owner.');
        }

        $this->connection->transaction(function (PDO $pdo) use ($requestId, $actingUserId, $row): void {
            $pdo->prepare(
                "UPDATE request
                    SET status             = 'Approved',
                        owner_reviewed_by  = :owner,
                        owner_reviewed_at  = NOW(),
                        updated_at         = NOW()
                  WHERE request_id = :id"
            )->execute([':owner' => $actingUserId, ':id' => $requestId]);

            // REQ078: approved Leave → debit leave_ledger for the working days payroll will pay.
            // Re-counted here because days_requested on requests filed before
            // 2026-11-22 counted rest days, and the schedule may have changed.
            if ($row['type_name'] === 'Leave') {
                $chargeDays = $this->assertPayableRequest($row, $pdo);
                if (abs($chargeDays - (float) $row['days_requested']) > 0.001) {
                    $pdo->prepare(
                        "UPDATE leave_request_detail SET days_requested = :days WHERE request_id = :rid"
                    )->execute([':days' => number_format($chargeDays, 2, '.', ''), ':rid' => $requestId]);
                }
                $this->debitLeaveLedger(
                    $pdo,
                    (int) $row['employee_id'],
                    $chargeDays,
                    $requestId,
                    $actingUserId,
                    $this->leaveCreditYear((string) $row['start_date'], (string) $row['end_date']),
                );
            }
            if ($row['type_name'] === 'Overtime') {
                $this->assertPayableRequest($row, $pdo);
            }

            // REQ034: approved CashAdvance → create obligation in cash_advance_history
            if ($row['type_name'] === 'CashAdvance') {
                $amount = (string) ($row['amount_requested'] ?? '0.00');
                $pdo->prepare(
                    "INSERT INTO cash_advance_history
                        (request_id, employee_id, original_amount, remaining_balance,
                         status, approved_at, created_at, updated_at)
                     VALUES
                        (:req, :emp, :orig_amount, :remaining_amount,
                         'Active', NOW(), NOW(), NOW())"
                )->execute([
                    ':req'              => $requestId,
                    ':emp'              => $row['employee_id'],
                    ':orig_amount'      => $amount,
                    ':remaining_amount' => $amount,
                ]);
            }

            if ($row['type_name'] === 'Overtime') {
                // Synchronise overtime on every date in the approved range.
                $dateFrom = new \DateTimeImmutable((string) $row['date_from']);
                $dateTo   = new \DateTimeImmutable((string) $row['date_to']);
                $current  = $dateFrom;
                while ($current <= $dateTo) {
                    $this->synchroniseApprovedOvertime(
                        $pdo,
                        (int) $row['employee_id'],
                        $current->format('Y-m-d'),
                        $actingUserId,
                        $requestId,
                    );
                    $current = $current->modify('+1 day');
                }
            }

            $pdo->prepare(
                "INSERT INTO audit_logs
                    (user_id, event_type, action_performed, table_affected, record_id,
                     description, action_at, created_at)
                 VALUES
                    (:user_id, 'request_owner_approved', 'owner_approve_request', 'request', :record_id,
                     :description, NOW(), NOW())"
            )->execute([
                ':user_id'     => $actingUserId,
                ':record_id'   => $requestId,
                ':description' => "Owner finally approved request {$requestId} ({$row['type_name']}) for employee {$row['employee_id']}.",
            ]);
        });
    }

    /**
     * An OT request can be approved after its biometric attendance is imported.
     * Recalculate that unapproved attendance row so the attendance screen and
     * payroll use the same authorised OT result.
     */
    private function synchroniseApprovedOvertime(PDO $pdo, int $employeeId, string $attendanceDate, int $actingUserId, int $requestId): void
    {
        $stmt = $pdo->prepare(
            "SELECT a.attendance_id, a.time_in, a.time_out, a.overtime_minutes,
                    COALESCE(ws.work_start_time, '07:00:00') AS work_start_time,
                    COALESCE(ws.work_end_time, '16:00:00') AS work_end_time,
                    COALESCE(ws.break_minutes, 60) AS break_minutes,
                    CASE WHEN ws.schedule_id IS NULL THEN '12:00:00' ELSE ws.break_start_time END AS break_start_time,
                    CASE WHEN ws.schedule_id IS NULL THEN '13:00:00' ELSE ws.break_end_time END AS break_end_time,
                    COALESCE(ws.standard_minutes, 480) AS standard_minutes,
                    COALESCE(ws.overtime_allowed, 0) AS overtime_allowed
               FROM attendance a
               LEFT JOIN work_schedule ws ON ws.schedule_id = a.schedule_id
              WHERE a.employee_id = :employee_id
                AND a.attendance_date = :attendance_date
                AND a.status IN ('Complete', 'Approved')
                AND a.time_in IS NOT NULL
                AND a.time_out IS NOT NULL
                AND NOT EXISTS (
                    SELECT 1
                      FROM payroll p
                      JOIN payroll_run pr ON pr.payroll_run_id = p.payroll_run_id
                      JOIN payroll_period pp ON pp.payroll_period_id = p.payroll_period_id
                     WHERE p.employee_id = a.employee_id
                       AND pr.status = 'Approved'
                       AND a.attendance_date BETWEEN pp.period_start AND pp.period_end
                )
              FOR UPDATE"
        );
        $stmt->execute([
            ':employee_id' => $employeeId,
            ':attendance_date' => $attendanceDate,
        ]);
        $attendance = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$attendance || !(bool) $attendance['overtime_allowed']) {
            return;
        }

        $requestsStmt = $pdo->prepare(
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
        $requestsStmt->execute([
            ':employee_id'    => $employeeId,
            ':attendance_date' => $attendanceDate,
        ]);
        /** @var list<array{start_time:string,end_time:string}> $requests */
        $requests = $requestsStmt->fetchAll(PDO::FETCH_ASSOC);

        $overtime = $this->approvedOvertimeCalculator->payableMinutesForAttendance(
            (string) $attendance['time_in'],
            (string) $attendance['time_out'],
            (string) $attendance['work_start_time'],
            (string) $attendance['work_end_time'],
            (int) $attendance['break_minutes'],
            (int) $attendance['standard_minutes'],
            $requests,
            $attendance['break_start_time'] !== null ? (string) $attendance['break_start_time'] : null,
            $attendance['break_end_time'] !== null ? (string) $attendance['break_end_time'] : null,
        );
        if ($overtime === (int) $attendance['overtime_minutes']) {
            return;
        }

        $pdo->prepare(
            'UPDATE attendance SET overtime_minutes = :overtime, updated_at = NOW() WHERE attendance_id = :id'
        )->execute([':overtime' => $overtime, ':id' => $attendance['attendance_id']]);
        $reason = "OT request #{$requestId} was finally approved.";
        $pdo->prepare(
            "INSERT INTO attendance_adjustment
                (attendance_id, adjusted_by, old_time_in, new_time_in, old_time_out, new_time_out,
                 old_overtime_minutes, new_overtime_minutes, adjustment_type, reason, adjustment_at)
             VALUES
                (:attendance_id, :adjusted_by, :old_time_in, :new_time_in, :old_time_out, :new_time_out,
                 :old_overtime, :new_overtime, 'overtime_request_approved', :reason, NOW())"
        )->execute([
            ':attendance_id' => $attendance['attendance_id'],
            ':adjusted_by'   => $actingUserId,
            ':old_time_in'   => $attendance['time_in'],
            ':new_time_in'   => $attendance['time_in'],
            ':old_time_out'  => $attendance['time_out'],
            ':new_time_out'  => $attendance['time_out'],
            ':old_overtime'  => $attendance['overtime_minutes'],
            ':new_overtime'  => $overtime,
            ':reason'        => $reason,
        ]);
    }

    /**
     * Owner directly cancels any active request (Pending, HRApproved, or Returned).
     *
     * [HR interview 2026-09-21] The Owner may cancel any employee request outright
     * without going through the HR return workflow.
     *
     * @throws RuntimeException when not found, not in a cancellable status, or note is empty
     */
    public function ownerCancel(int $requestId, int $actingUserId, string $note): void
    {
        $row = $this->findOrFail($requestId);

        if (!in_array($row['status'], ['Pending', 'HRApproved', 'Returned'], true)) {
            throw new RuntimeException('Only Pending, HR-approved, or Returned requests can be cancelled.');
        }

        $this->connection->transaction(function (PDO $pdo) use ($requestId, $actingUserId, $note): void {
            $pdo->prepare(
                "UPDATE request
                    SET status            = 'Cancelled',
                        owner_reviewed_by = :owner,
                        owner_reviewed_at = NOW(),
                        owner_notes       = :note,
                        updated_at        = NOW()
                  WHERE request_id = :id"
            )->execute([':owner' => $actingUserId, ':note' => trim($note), ':id' => $requestId]);

            $pdo->prepare(
                "INSERT INTO audit_logs
                    (user_id, event_type, action_performed, table_affected, record_id,
                     description, action_at, created_at)
                 VALUES
                    (:user_id, 'request_owner_cancelled', 'owner_cancel_request', 'request', :record_id,
                     :description, NOW(), NOW())"
            )->execute([
                ':user_id'     => $actingUserId,
                ':record_id'   => $requestId,
                ':description' => "Owner cancelled request {$requestId}."
                    . ($note !== '' ? " Note: {$note}" : ''),
            ]);
        });
    }

    /**
     * Owner returns an HRApproved request to HR for revision.
     *
     * Status becomes Returned so HR can address the feedback and re-submit
     * for pre-approval.
     *
     * @throws RuntimeException when not found, not HRApproved, or note is empty
     */
    public function ownerReturn(int $requestId, int $actingUserId, string $note): void
    {
        $row = $this->findOrFail($requestId);

        if ($row['status'] !== 'HRApproved') {
            throw new RuntimeException('Only HR-approved requests can be returned by the Owner.');
        }
        if (trim($note) === '') {
            throw new RuntimeException('A return note is required.');
        }

        $this->connection->transaction(function (PDO $pdo) use ($requestId, $actingUserId, $note): void {
            $pdo->prepare(
                "UPDATE request
                    SET status            = 'Returned',
                        owner_reviewed_by = :owner,
                        owner_reviewed_at = NOW(),
                        owner_notes       = :note,
                        updated_at        = NOW()
                  WHERE request_id = :id"
            )->execute([':owner' => $actingUserId, ':note' => trim($note), ':id' => $requestId]);

            $pdo->prepare(
                "INSERT INTO audit_logs
                    (user_id, event_type, action_performed, table_affected, record_id,
                     description, action_at, created_at)
                 VALUES
                    (:user_id, 'request_owner_returned', 'owner_return_request', 'request', :record_id,
                     :description, NOW(), NOW())"
            )->execute([
                ':user_id'     => $actingUserId,
                ':record_id'   => $requestId,
                ':description' => "Owner returned request {$requestId} to HR for revision. Note: {$note}",
            ]);
        });
    }

    /**
     * Cancel a request with a note (HR or Owner action).
     *
     * HR may cancel any Pending, HRApproved, or Returned request.
     * Owner may cancel any Pending, HRApproved, or Returned request.
     *
     * @throws RuntimeException when not found, not in a cancellable status, or note is empty
     */
    public function cancelRequest(int $requestId, int $actingUserId, string $note): void
    {
        $row = $this->findOrFail($requestId);

        if (!in_array($row['status'], ['Pending', 'HRApproved', 'Returned'], true)) {
            throw new RuntimeException('Only Pending, HR-approved, or Returned requests can be cancelled.');
        }
        if (trim($note) === '') {
            throw new RuntimeException('A cancellation note is required.');
        }

        $this->connection->transaction(function (PDO $pdo) use ($requestId, $actingUserId, $note): void {
            $pdo->prepare(
                "UPDATE request
                    SET status       = 'Cancelled',
                        reviewed_by  = :reviewer,
                        reviewed_at  = NOW(),
                        review_notes = :note,
                        updated_at   = NOW()
                  WHERE request_id   = :id"
            )->execute([':reviewer' => $actingUserId, ':note' => trim($note), ':id' => $requestId]);

            $pdo->prepare(
                "INSERT INTO audit_logs
                    (user_id, event_type, action_performed, table_affected, record_id,
                     description, action_at, created_at)
                 VALUES
                    (:user_id, 'request_cancelled', 'cancel_request', 'request', :record_id,
                     :description, NOW(), NOW())"
            )->execute([
                ':user_id'     => $actingUserId,
                ':record_id'   => $requestId,
                ':description' => "Request {$requestId} cancelled by user {$actingUserId}. Note: {$note}",
            ]);
        });
    }

    /**
     * @deprecated Use cancelRequest() instead. Kept for backwards compatibility.
     */
    public function reject(int $requestId, int $actingUserId, string $note): void
    {
        $this->cancelRequest($requestId, $actingUserId, $note);
    }

    /**
     * Soft-archive a request (HR action — REQ036).
     *
     * @throws RuntimeException when not found or already archived
     */
    public function archive(int $requestId, int $actingUserId): void
    {
        $row = $this->findOrFail($requestId);

        if (($row['archived_at'] ?? null) !== null) {
            throw new RuntimeException('Request is already archived.');
        }

        $this->connection->transaction(function (PDO $pdo) use ($requestId, $actingUserId): void {
            $pdo->prepare(
                "UPDATE request
                    SET archived_by = :by,
                        archived_at = NOW(),
                        updated_at  = NOW()
                  WHERE request_id  = :id"
            )->execute([':by' => $actingUserId, ':id' => $requestId]);

            $pdo->prepare(
                "INSERT INTO audit_logs
                    (user_id, event_type, action_performed, table_affected, record_id,
                     description, action_at, created_at)
                 VALUES
                    (:user_id, 'request_archived', 'archive_request', 'request', :record_id,
                     :description, NOW(), NOW())"
            )->execute([
                ':user_id'     => $actingUserId,
                ':record_id'   => $requestId,
                ':description' => "Request {$requestId} archived by user {$actingUserId}.",
            ]);
        });
    }

    /**
     * List all requests the Owner can act on (Pending, HRApproved, or Returned).
     *
     * [HR interview 2026-09-21] Owner can approve or cancel any active request.
     *
     * @return list<array<string,mixed>>
     */
    public function ownerPendingList(): array
    {
        $stmt = $this->connection->pdo()->query(
            "SELECT r.request_id,
                    CONCAT(e.last_name, ', ', e.first_name) AS employee_name,
                    e.employee_number,
                    rt.type_name,
                    r.status,
                    r.submitted_at,
                    r.reviewed_at   AS hr_reviewed_at,
                    r.reason
               FROM request r
               JOIN employee e      ON e.employee_id      = r.employee_id
               JOIN request_type rt ON rt.request_type_id = r.request_type_id
              WHERE r.status IN ('Pending', 'HRApproved', 'Returned')
                AND r.archived_at IS NULL
              ORDER BY r.submitted_at ASC"
        );
        return $stmt ? $stmt->fetchAll() : [];
    }

    /**
     * Filtered version of ownerPendingList() with optional type and employee search.
     *
     * @param array{type_id?:int,search?:string} $filters
     * @return list<array<string,mixed>>
     */
    public function ownerPendingListFiltered(array $filters = []): array
    {
        $where  = ["r.status IN ('Pending', 'HRApproved', 'Returned')", 'r.archived_at IS NULL'];
        $params = [];

        if (!empty($filters['type_id'])) {
            $where[]            = 'r.request_type_id = :type_id';
            $params[':type_id'] = (int) $filters['type_id'];
        }
        if (!empty($filters['search'])) {
            // Every word in any order: first, middle, last name or number (REQ012).
            [$nameSql, $nameParams] = \Wbpms\Infrastructure\Persistence\EmployeeNameSearch::condition((string) $filters['search'], 'e', 'search_q');
            if ($nameSql !== '') {
                $where[] = $nameSql;
                $params  = array_merge($params, $nameParams);
            }
        }

        $whereSql = 'WHERE ' . implode(' AND ', $where);
        $stmt = $this->connection->pdo()->prepare(
            "SELECT r.request_id,
                    CONCAT(e.last_name, ', ', e.first_name) AS employee_name,
                    e.employee_number,
                    rt.type_name,
                    r.status,
                    r.submitted_at,
                    r.reviewed_at   AS hr_reviewed_at,
                    r.reason
               FROM request r
               JOIN employee e      ON e.employee_id      = r.employee_id
               JOIN request_type rt ON rt.request_type_id = r.request_type_id
              {$whereSql}
              ORDER BY r.submitted_at ASC"
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * List all requests the Owner has already actioned (Approved or Cancelled).
     *
     * Used to show the Owner a complete history of their decisions.
     *
     * @return list<array<string,mixed>>
     */
    public function ownerActionedList(): array
    {
        $stmt = $this->connection->pdo()->query(
            "SELECT r.request_id,
                    CONCAT(e.last_name, ', ', e.first_name) AS employee_name,
                    e.employee_number,
                    rt.type_name,
                    r.status,
                    r.submitted_at,
                    r.reviewed_at        AS hr_reviewed_at,
                    r.owner_reviewed_at,
                    r.owner_notes,
                    r.reason
               FROM request r
               JOIN employee e      ON e.employee_id      = r.employee_id
               JOIN request_type rt ON rt.request_type_id = r.request_type_id
              WHERE r.status IN ('Approved', 'Cancelled')
                AND r.archived_at IS NULL
              ORDER BY r.owner_reviewed_at DESC
              LIMIT 100"
        );
        return $stmt ? $stmt->fetchAll() : [];
    }

    /**
     * Filtered version of ownerActionedList() with optional type and employee search.
     *
     * @param array{type_id?:int,search?:string} $filters
     * @return list<array<string,mixed>>
     */
    public function ownerActionedListFiltered(array $filters = []): array
    {
        $where  = ["r.status IN ('Approved', 'Cancelled')", 'r.archived_at IS NULL'];
        $params = [];

        if (!empty($filters['type_id'])) {
            $where[]            = 'r.request_type_id = :type_id';
            $params[':type_id'] = (int) $filters['type_id'];
        }
        if (!empty($filters['search'])) {
            // Every word in any order: first, middle, last name or number (REQ012).
            [$nameSql, $nameParams] = \Wbpms\Infrastructure\Persistence\EmployeeNameSearch::condition((string) $filters['search'], 'e', 'search_q');
            if ($nameSql !== '') {
                $where[] = $nameSql;
                $params  = array_merge($params, $nameParams);
            }
        }

        $whereSql = 'WHERE ' . implode(' AND ', $where);
        $stmt = $this->connection->pdo()->prepare(
            "SELECT r.request_id,
                    CONCAT(e.last_name, ', ', e.first_name) AS employee_name,
                    e.employee_number,
                    rt.type_name,
                    r.status,
                    r.submitted_at,
                    r.reviewed_at        AS hr_reviewed_at,
                    r.owner_reviewed_at,
                    r.owner_notes,
                    r.reason
               FROM request r
               JOIN employee e      ON e.employee_id      = r.employee_id
               JOIN request_type rt ON rt.request_type_id = r.request_type_id
              {$whereSql}
              ORDER BY r.owner_reviewed_at DESC
              LIMIT 300"
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Return all active request types for dropdowns.
     *
     * @return list<array{request_type_id: int, type_name: string}>
     */
    public function requestTypes(): array
    {
        return $this->connection->pdo()
            ->query("SELECT request_type_id, type_name FROM request_type WHERE status = 'Active' ORDER BY type_name")
            ->fetchAll();
    }

    /**
     * Return the current sick-leave balance for an employee in a given year.
     * Auto-provisions a 4-day entitlement row if none exists.
     */
    public function leaveBalance(int $employeeId, int $year = 0): float
    {
        if ($year === 0) {
            $year = (int) date('Y');
        }

        $pdo = $this->connection->pdo();
        $entitlementId = $this->ensureLeaveEntitlement($pdo, $employeeId, $year);

        $stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(days_delta), 0)
               FROM leave_ledger
              WHERE entitlement_id = :eid"
        );
        $stmt->execute([':eid' => $entitlementId]);
        // Round to 2 decimal places to avoid floating-point artifacts (e.g. 7.509999 → 7.51)
        return round((float) $stmt->fetchColumn(), 2);
    }

    /**
     * Return leave credit summary for all active employees for a given year.
     * Used by HR leave credit management page.
     *
     * @return list<array{employee_id:int,employee_number:string,employee_name:string,entitled_days:float,balance:float,used:float}>
     */
    public function leaveCreditsForAll(int $year = 0): array
    {
        if ($year === 0) {
            $year = (int) date('Y');
        }
        $pdo = $this->connection->pdo();

        $stmt = $pdo->prepare(
            "SELECT e.employee_id,
                    e.employee_number,
                    CONCAT(e.last_name, ', ', e.first_name) AS employee_name,
                    COALESCE(le.entitlement_id, 0)          AS entitlement_id,
                    COALESCE(le.entitled_days, 4.00)        AS entitled_days,
                    -- No entitlement row yet means the 4-day grant has not been
                    -- provisioned (ensureLeaveEntitlement runs on first view or
                    -- request). Show the balance that grant will give, not 0,
                    -- so the list does not change just because HR opened a row.
                    CASE
                        WHEN le.entitlement_id IS NULL THEN 4.00
                        ELSE COALESCE(
                            (SELECT SUM(ll.days_delta)
                               FROM leave_ledger ll
                              WHERE ll.entitlement_id = le.entitlement_id),
                            0
                        )
                    END AS balance
               FROM employee e
               LEFT JOIN leave_entitlement le
                      ON le.employee_id = e.employee_id
                     AND le.leave_type  = 'Sick'
                     AND le.leave_year  = :yr
              WHERE e.status = 'Active'
              ORDER BY e.last_name, e.first_name"
        );
        $stmt->execute([':yr' => $year]);
        $rows = $stmt->fetchAll();

        return array_map(static function (array $r): array {
            $balance = (float) $r['balance'];
            $entitled = (float) $r['entitled_days'];
            return [
                'employee_id'     => (int) $r['employee_id'],
                'employee_number' => (string) $r['employee_number'],
                'employee_name'   => (string) $r['employee_name'],
                'entitled_days'   => $entitled,
                'balance'         => $balance,
                'used'            => round($entitled - $balance, 2),
                'entitlement_id'  => (int) $r['entitlement_id'],
            ];
        }, $rows);
    }

    /**
     * Return the ledger history for one employee's sick-leave entitlement.
     *
     * @return list<array{entry_type:string,days_delta:float,notes:string,created_at:string}>
     */
    public function leaveLedger(int $employeeId, int $year = 0): array
    {
        if ($year === 0) {
            $year = (int) date('Y');
        }
        $pdo = $this->connection->pdo();
        $entitlementId = $this->ensureLeaveEntitlement($pdo, $employeeId, $year);

        $stmt = $pdo->prepare(
            "SELECT ll.entry_id,
                    ll.entry_type,
                    ll.days_delta,
                    COALESCE(ll.notes, '') AS notes,
                    ll.created_at,
                    COALESCE(u.username, 'System') AS recorded_by_name
               FROM leave_ledger ll
               LEFT JOIN users u ON u.user_id = ll.recorded_by
              WHERE ll.entitlement_id = :eid
              ORDER BY ll.entry_id ASC"
        );
        $stmt->execute([':eid' => $entitlementId]);
        return $stmt->fetchAll();
    }

    /**
     * HR manual leave credit adjustment (add or deduct days).
     *
     * @throws RuntimeException on invalid input
     */
    public function adjustLeaveCredit(
        int $employeeId,
        float $days,
        string $entryType,
        string $notes,
        int $actingUserId,
        int $year = 0
    ): void {
        if ($year === 0) {
            $year = (int) date('Y');
        }
        if (!in_array($entryType, ['Grant', 'Adjustment', 'Usage'], true)) {
            throw new RuntimeException("Invalid entry type '{$entryType}'.");
        }
        if ($days < 0.5) {
            throw new RuntimeException('Days must be at least 0.5 (half day).');
        }
        if (trim($notes) === '') {
            throw new RuntimeException('A reason/note is required for manual adjustments.');
        }

        $delta = $entryType === 'Usage' ? -$days : $days;

        // Pre-flight balance checks
        $currentBalance = $this->leaveBalance($employeeId, $year);
        if ($entryType === 'Usage' && $days > $currentBalance) {
            throw new RuntimeException(
                "Cannot deduct {$days} day(s) — current balance is only {$currentBalance} day(s)."
            );
        }
        if (in_array($entryType, ['Grant', 'Adjustment'], true)) {
            $newBalance = round($currentBalance + $days, 2);
            if ($newBalance > 4.00) {
                throw new RuntimeException(
                    "This adjustment would set the balance to {$newBalance} days, which exceeds "
                    . "the 4-day annual entitlement. Reduce the amount or use a smaller value."
                );
            }
        }

        $this->connection->transaction(function (\PDO $pdo) use (
            $employeeId, $delta, $entryType, $notes, $actingUserId, $year
        ): void {
            $entitlementId = $this->ensureLeaveEntitlement($pdo, $employeeId, $year);
            $pdo->prepare(
                "INSERT INTO leave_ledger
                    (entitlement_id, request_id, entry_type, days_delta, recorded_by, notes)
                 VALUES
                    (:eid, NULL, :type, :delta, :by, :notes)"
            )->execute([
                ':eid'   => $entitlementId,
                ':type'  => $entryType,
                ':delta' => number_format($delta, 2, '.', ''),
                ':by'    => $actingUserId,
                ':notes' => trim($notes),
            ]);
        });
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    private function resolveTypeName(int $typeId): string
    {
        $stmt = $this->connection->pdo()->prepare(
            "SELECT type_name FROM request_type WHERE request_type_id = :id"
        );
        $stmt->execute([':id' => $typeId]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new RuntimeException("Request type #{$typeId} does not exist.");
        }
        return (string) $row['type_name'];
    }

    /**
     * REQ078: Block leave submission when sick-leave balance is insufficient.
     *
     * Uses the leave_entitlement + leave_ledger system.
     * Auto-provisions a 4-day entitlement row for that year if none exists.
     */
    private function assertLeaveBalance(int $employeeId, float $daysRequested, int $year): void
    {
        $balance   = $this->leaveBalance($employeeId, $year);
        $openDays  = $this->openLeaveDays($employeeId, $year);
        $available = $this->leaveDayCounter->availableCredit($balance, $openDays);

        if ($available < $daysRequested) {
            $balFmt = number_format(max(0.0, $available), 2);
            $reqFmt = number_format($daysRequested, 2);
            $openNote = $openDays > 0
                ? ' (' . number_format($openDays, 2) . ' day(s) are already in requests waiting for approval)'
                : '';
            throw new RuntimeException(
                "Insufficient sick-leave balance for {$year}. You have {$balFmt} day(s) remaining{$openNote} "
                . "but requested {$reqFmt} working day(s)."
            );
        }
    }

    /**
     * Sick-leave days in the employee's leave requests for $year that are
     * still waiting for a decision (not yet debited from the ledger).
     */
    private function openLeaveDays(int $employeeId, int $year, int $excludeRequestId = 0): float
    {
        $stmt = $this->connection->pdo()->prepare(
            "SELECT COALESCE(SUM(lrd.days_requested), 0)
               FROM request r
               JOIN request_type rt ON rt.request_type_id = r.request_type_id
               JOIN leave_request_detail lrd ON lrd.request_id = r.request_id
              WHERE r.employee_id = :employee_id
                AND rt.type_name = 'Leave'
                AND r.status IN ('Pending', 'HRApproved', 'Returned')
                AND lrd.start_date BETWEEN :year_start AND :year_end
                AND r.request_id <> :exclude_id"
        );
        $stmt->execute([
            ':employee_id' => $employeeId,
            ':year_start'  => sprintf('%04d-01-01', $year),
            ':year_end'    => sprintf('%04d-12-31', $year),
            ':exclude_id'  => $excludeRequestId,
        ]);

        return round((float) $stmt->fetchColumn(), 2);
    }

    /**
     * The year whose sick-leave credit a leave range uses (its own dates).
     *
     * @throws RuntimeException when the range crosses into another year.
     */
    private function leaveCreditYear(string $startDate, string $endDate): int
    {
        $year = LeaveDayCounter::creditYear($startDate, $endDate);
        if ($year === null) {
            throw new RuntimeException(sprintf(
                'A sick-leave request cannot cross from %s into %s: each year has its own credit. '
                . 'File one request up to 12/31 and another from 01/01.',
                substr($startDate, 0, 4),
                substr($endDate, 0, 4),
            ));
        }

        return $year;
    }

    private function employeeHireDate(int $employeeId): ?string
    {
        $stmt = $this->connection->pdo()->prepare('SELECT hire_date FROM employee WHERE employee_id = :id');
        $stmt->execute([':id' => $employeeId]);
        $hire = $stmt->fetchColumn();

        return $hire !== false && $hire !== null ? (string) $hire : null;
    }

    /**
     * Checks a Leave or Overtime request can still be paid: its dates are not
     * in an Approved payroll run, and (Leave) the credit covers the working
     * days. With $lockPdo (Owner approval, inside the transaction) the
     * entitlement row is locked so two approvals cannot both spend the
     * same credit.
     *
     * @param array<string,mixed> $row from findOrFail()
     * @return float Leave days to charge (0 for other types).
     */
    private function assertPayableRequest(array $row, ?PDO $lockPdo = null): float
    {
        $employeeId = (int) $row['employee_id'];

        if ($row['type_name'] === 'Overtime') {
            $this->assertNotInApprovedPayroll(
                $employeeId,
                $this->dateRange((string) $row['date_from'], (string) $row['date_to']),
                'Overtime',
            );
            return 0.0;
        }
        if ($row['type_name'] !== 'Leave') {
            return 0.0;
        }

        // The credit of the leave's own year, not the year it is approved in.
        $year  = $this->leaveCreditYear((string) $row['start_date'], (string) $row['end_date']);
        $dates = $this->chargeableLeaveDates($employeeId, (string) $row['start_date'], (string) $row['end_date']);
        $this->assertNotInApprovedPayroll($employeeId, $dates, 'Leave');
        $days = (float) count($dates);

        if ($lockPdo !== null) {
            $entitlementId = $this->ensureLeaveEntitlement($lockPdo, $employeeId, $year);
            $lockPdo->prepare('SELECT entitlement_id FROM leave_entitlement WHERE entitlement_id = :eid FOR UPDATE')
                ->execute([':eid' => $entitlementId]);
            $sum = $lockPdo->prepare('SELECT COALESCE(SUM(days_delta), 0) FROM leave_ledger WHERE entitlement_id = :eid');
            $sum->execute([':eid' => $entitlementId]);
            $balance = round((float) $sum->fetchColumn(), 2);
        } else {
            $balance = $this->leaveBalance($employeeId, $year);
        }

        if ($balance < $days) {
            throw new RuntimeException(sprintf(
                'Not enough sick-leave credit to approve this request: %s day(s) left, but it needs %s working day(s). '
                . 'Return it so the employee can shorten the dates.',
                number_format(max(0.0, $balance), 2),
                number_format($days, 2),
            ));
        }

        return $days;
    }

    /**
     * The days of a leave range that payroll would pay (scheduled working
     * days, not holidays), which are also the days charged to the credit.
     *
     * @return list<string>
     */
    private function chargeableLeaveDates(int $employeeId, string $startDate, string $endDate): array
    {
        $dates = $this->leaveDayCounter->chargeableDates(
            $startDate,
            $endDate,
            $this->calendar->scheduledDates($employeeId, $startDate, $endDate),
            $this->calendar->activeHolidayDates($startDate, $endDate),
            $this->calendar->workedDates($employeeId, $startDate, $endDate),
        );
        if ($dates === []) {
            throw new RuntimeException(
                'The selected dates have no scheduled working days left to charge for this employee. '
                . 'Rest days, holidays and days already worked are not charged to sick leave.'
            );
        }

        return $dates;
    }

    /**
     * @param list<string> $dates
     * @throws RuntimeException when any date is already in an Approved payroll run.
     */
    private function assertNotInApprovedPayroll(int $employeeId, array $dates, string $label): void
    {
        if ($dates === []) {
            return;
        }
        $covered = $this->calendar->datesInApprovedPayroll($employeeId, min($dates), max($dates));
        $hits = array_values(array_filter($dates, static fn (string $d): bool => isset($covered[$d])));
        if ($hits === []) {
            return;
        }

        $labels = array_map(
            static fn (string $d): string => (new \DateTimeImmutable($d))->format('m/d/y'),
            $hits,
        );
        $runIds = array_values(array_unique(array_map(static fn (string $d): int => $covered[$d], $hits)));
        throw new RuntimeException(sprintf(
            '%s on %s can no longer be paid: %s already approved (payroll run #%s). '
            . 'Use a Manual Payroll Adjustment in the next payroll run instead.',
            $label,
            implode(', ', $labels),
            count($hits) === 1 ? 'that day is' : 'those days are',
            implode(', #', $runIds),
        ));
    }

    /** @return list<string> */
    private function dateRange(string $from, string $to): array
    {
        $start = \DateTimeImmutable::createFromFormat('!Y-m-d', $from);
        $end   = \DateTimeImmutable::createFromFormat('!Y-m-d', $to);
        if ($start === false || $end === false || $end < $start) {
            return [];
        }
        $dates = [];
        for ($day = $start; $day <= $end; $day = $day->modify('+1 day')) {
            $dates[] = $day->format('Y-m-d');
        }

        return $dates;
    }

    /**
     * Ensure a leave_entitlement row exists for the employee/year.
     * If none exists, auto-provisions 4 days (ADR-0001) and writes a Grant ledger entry.
     * Returns the entitlement_id.
     */
    private function ensureLeaveEntitlement(PDO $pdo, int $employeeId, int $year): int
    {
        $stmt = $pdo->prepare(
            "SELECT entitlement_id, entitled_days
               FROM leave_entitlement
              WHERE employee_id = :emp AND leave_type = 'Sick' AND leave_year = :yr"
        );
        $stmt->execute([':emp' => $employeeId, ':yr' => $year]);
        $row = $stmt->fetch();

        if ($row !== false) {
            return (int) $row['entitlement_id'];
        }

        // Auto-provision 4-day entitlement (ADR-0001)
        $pdo->prepare(
            "INSERT INTO leave_entitlement
                (employee_id, leave_type, leave_year, entitled_days)
             VALUES
                (:emp, 'Sick', :yr, 4.00)"
        )->execute([':emp' => $employeeId, ':yr' => $year]);
        $entitlementId = (int) $pdo->lastInsertId();

        // Grant ledger entry
        $pdo->prepare(
            "INSERT INTO leave_ledger
                (entitlement_id, request_id, entry_type, days_delta, notes)
             VALUES
                (:eid, NULL, 'Grant', 4.00, 'Annual sick leave auto-grant')"
        )->execute([':eid' => $entitlementId]);

        return $entitlementId;
    }

    /**
     * Append a Usage ledger entry debiting the sick-leave balance of $year
     * (the leave's own year). Called inside an existing transaction when a
     * Leave request is approved.
     */
    private function debitLeaveLedger(
        PDO $pdo,
        int $employeeId,
        float $days,
        int $requestId,
        int $actingUserId,
        int $year,
    ): void {
        $entitlementId = $this->ensureLeaveEntitlement($pdo, $employeeId, $year);

        $pdo->prepare(
            "INSERT INTO leave_ledger
                (entitlement_id, request_id, entry_type, days_delta, recorded_by, notes)
             VALUES
                (:eid, :rid, 'Usage', :delta, :by, 'Deducted on approval')"
        )->execute([
            ':eid'   => $entitlementId,
            ':rid'   => $requestId,
            ':delta' => number_format(-$days, 2, '.', ''),
            ':by'    => $actingUserId,
        ]);
    }

    /**
     * Calculate overtime minutes between two HH:MM time strings.
     */
    private function calcMinutes(string $startTime, string $endTime): int
    {
        [$h1, $m1] = array_map('intval', explode(':', $startTime));
        [$h2, $m2] = array_map('intval', explode(':', $endTime));
        $diff = ($h2 * 60 + $m2) - ($h1 * 60 + $m1);
        // Handle overnight shifts (e.g. 20:00 → 00:00 spanning midnight).
        if ($diff <= 0) {
            $diff += 1440; // add 24 hours
        }
        return $diff;
    }

    private function isValidDate(string $date): bool
    {
        $d = \DateTimeImmutable::createFromFormat('Y-m-d', $date);
        return $d !== false && $d->format('Y-m-d') === $date;
    }

    private function isValidTime(string $time): bool
    {
        return (bool) preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time);
    }
}

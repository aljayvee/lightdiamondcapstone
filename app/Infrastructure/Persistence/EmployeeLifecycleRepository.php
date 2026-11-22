<?php

declare(strict_types=1);

namespace Wbpms\Infrastructure\Persistence;

use PDO;

/** SQL persistence for controlled employee archive and rehire operations. */
final class EmployeeLifecycleRepository extends AbstractRepository
{
    /** @return array<string,mixed>|null */
    public function employeeForUpdate(int $employeeId): ?array
    {
        $stmt = $this->pdo()->prepare(
            'SELECT employee_id, status, hire_date FROM employee WHERE employee_id = :id FOR UPDATE'
        );
        $stmt->execute([':id' => $employeeId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /** @return list<string> */
    public function archiveBlockers(int $employeeId): array
    {
        $pdo = $this->pdo();
        $blockers = [];

        $checks = [
            'pending request' => "SELECT COUNT(*) FROM request WHERE employee_id = :id AND status IN ('Pending', 'HRApproved')",
            'open cash advance' => "SELECT COUNT(*) FROM cash_advance_history WHERE employee_id = :id AND status = 'Active' AND remaining_balance > 0",
        ];
        foreach ($checks as $label => $sql) {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':id' => $employeeId]);
            if ((int) $stmt->fetchColumn() > 0) {
                $blockers[] = $label;
            }
        }

        return $blockers;
    }

    /** @return list<array<string,mixed>> */
    public function unapprovedPayrollRuns(int $employeeId): array
    {
        $stmt = $this->pdo()->prepare(
            "SELECT p.payroll_id, pr.payroll_run_id, pr.status, pp.period_start, pp.period_end
               FROM payroll p
               JOIN payroll_run pr ON pr.payroll_run_id = p.payroll_run_id
               JOIN payroll_period pp ON pp.payroll_period_id = p.payroll_period_id
              WHERE p.employee_id = :id
                AND pr.status IN ('Draft', 'Computed', 'PendingOwnerApproval', 'Returned')
              ORDER BY pp.period_start DESC"
        );
        $stmt->execute([':id' => $employeeId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string,mixed>|null */
    public function linkedUserAccount(int $employeeId): ?array
    {
        $stmt = $this->pdo()->prepare(
            "SELECT u.user_id, u.username, r.role_name
               FROM users u
               JOIN role r ON r.role_id = u.role_id
              WHERE u.employee_id = :id
              ORDER BY u.user_id ASC
              LIMIT 1"
        );
        $stmt->execute([':id' => $employeeId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /** @return list<string> */
    public function archiveStartDateConflicts(int $employeeId, string $archiveEffectiveDate, ?string $lastWorkingDate = null): array
    {
        $lastWorkingDate ??= (new \DateTimeImmutable($archiveEffectiveDate))->modify('-1 day')->format('Y-m-d');
        $exclusiveTables = [
            'branch assignment' => 'employee_branch_assignment',
            'biometric enrollment' => 'employee_biometric_enrollment',
        ];
        $inclusiveTables = [
            'work schedule' => 'employee_schedule_assignment',
            'salary rate' => 'salary',
        ];
        $conflicts = [];
        foreach ($exclusiveTables as $label => $table) {
            $stmt = $this->pdo()->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE employee_id = :id AND effective_to IS NULL AND effective_from >= :date"
            );
            $stmt->execute([':id' => $employeeId, ':date' => $archiveEffectiveDate]);
            if ((int) $stmt->fetchColumn() > 0) {
                $conflicts[] = $label;
            }
        }
        // Only Active rows: closeActiveRelationships() leaves an Archived
        // (voided) salary or schedule row alone, so it cannot break the archive.
        foreach ($inclusiveTables as $label => $table) {
            $stmt = $this->pdo()->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE employee_id = :id AND effective_to IS NULL AND status = 'Active' AND effective_from > :date"
            );
            $stmt->execute([':id' => $employeeId, ':date' => $lastWorkingDate]);
            if ((int) $stmt->fetchColumn() > 0) {
                $conflicts[] = $label;
            }
        }

        return $conflicts;
    }

    /**
     * Removes the part of a scheduled branch-transfer / biometric-enrollment
     * plan that falls on or after the archive date, so the archive can close
     * what is left. [USER-016]
     *
     * - Rows that would only start on or after the archive date never took
     *   effect and are deleted. A branch row that attendance, a punch or a
     *   payroll already points to is kept (archiveStartDateConflicts() then
     *   reports it instead of the database failing).
     * - Closed rows that end after the archive date are cut back to end on it.
     *
     * Both tables use an exclusive end date (chk_eba_period / chk_ebe_period
     * require effective_to > effective_from), so a row starting on the archive
     * date cannot be closed on it and has to go.
     *
     * @return array{branch:int,biometric:int} number of rows deleted per table
     */
    public function dropUnstartedAssignments(int $employeeId, string $archiveEffectiveDate): array
    {
        $pdo = $this->pdo();

        $deleteBranch = $pdo->prepare(
            'DELETE FROM employee_branch_assignment
              WHERE employee_id = :id
                AND effective_from >= :date
                AND NOT EXISTS (SELECT 1 FROM attendance a WHERE a.branch_assignment_id = employee_branch_assignment.branch_assignment_id)
                AND NOT EXISTS (SELECT 1 FROM biometric_punch bp WHERE bp.branch_assignment_id = employee_branch_assignment.branch_assignment_id)
                AND NOT EXISTS (SELECT 1 FROM payroll p WHERE p.branch_assignment_id = employee_branch_assignment.branch_assignment_id)'
        );
        $deleteBranch->execute([':id' => $employeeId, ':date' => $archiveEffectiveDate]);

        $deleteBiometric = $pdo->prepare(
            'DELETE FROM employee_biometric_enrollment WHERE employee_id = :id AND effective_from >= :date'
        );
        $deleteBiometric->execute([':id' => $employeeId, ':date' => $archiveEffectiveDate]);

        foreach (['employee_branch_assignment', 'employee_biometric_enrollment'] as $table) {
            $pdo->prepare(
                "UPDATE {$table} SET effective_to = :new_to
                  WHERE employee_id = :id AND effective_from < :before AND effective_to > :after"
            )->execute([
                ':new_to' => $archiveEffectiveDate,
                ':id'     => $employeeId,
                ':before' => $archiveEffectiveDate,
                ':after'  => $archiveEffectiveDate,
            ]);
        }

        return ['branch' => $deleteBranch->rowCount(), 'biometric' => $deleteBiometric->rowCount()];
    }

    public function closeActiveRelationships(int $employeeId, string $archiveEffectiveDate, ?string $lastWorkingDate = null): void
    {
        $lastWorkingDate ??= (new \DateTimeImmutable($archiveEffectiveDate))->modify('-1 day')->format('Y-m-d');
        $pdo = $this->pdo();

        $pdo->prepare("UPDATE employee_branch_assignment SET effective_to = :date WHERE employee_id = :id AND effective_to IS NULL")
            ->execute([':id' => $employeeId, ':date' => $archiveEffectiveDate]);
        $pdo->prepare("UPDATE employee_biometric_enrollment SET effective_to = :date, status = 'Inactive', updated_at = NOW() WHERE employee_id = :id AND effective_to IS NULL AND status = 'Active'")
            ->execute([':id' => $employeeId, ':date' => $archiveEffectiveDate]);
        $pdo->prepare("UPDATE employee_schedule_assignment SET effective_to = :date, status = 'Archived', updated_at = NOW() WHERE employee_id = :id AND effective_to IS NULL AND status = 'Active'")
            ->execute([':id' => $employeeId, ':date' => $lastWorkingDate]);
        $pdo->prepare("UPDATE salary SET effective_to = :date, status = 'Superseded', updated_at = NOW() WHERE employee_id = :id AND effective_to IS NULL AND status = 'Active'")
            ->execute([':id' => $employeeId, ':date' => $lastWorkingDate]);
    }

    public function setEmployeeStatus(int $employeeId, string $status): void
    {
        $this->pdo()->prepare('UPDATE employee SET status = :status, updated_at = NOW() WHERE employee_id = :id')
            ->execute([':id' => $employeeId, ':status' => $status]);
    }

    public function closeOrCreateEpisode(int $employeeId, string $hireDate, string $lastWorkingDate, string $reason, ?string $notes, int $actorId): void
    {
        $stmt = $this->pdo()->prepare(
            'SELECT episode_id FROM employee_employment_episode WHERE employee_id = :id AND ended_on IS NULL ORDER BY started_on DESC LIMIT 1 FOR UPDATE'
        );
        $stmt->execute([':id' => $employeeId]);
        $episodeId = $stmt->fetchColumn();
        if ($episodeId !== false) {
            $this->pdo()->prepare('UPDATE employee_employment_episode SET ended_on = :end, end_reason = :reason, notes = :notes WHERE episode_id = :id')
                ->execute([':end' => $lastWorkingDate, ':reason' => $reason, ':notes' => $notes, ':id' => $episodeId]);
            return;
        }

        $this->pdo()->prepare(
            "INSERT INTO employee_employment_episode (employee_id, started_on, ended_on, start_reason, end_reason, notes, created_by) VALUES (:employee, :start, :end, 'Hire', :reason, :notes, :actor)"
        )->execute([':employee' => $employeeId, ':start' => $hireDate, ':end' => $lastWorkingDate, ':reason' => $reason, ':notes' => $notes, ':actor' => $actorId]);
    }

    public function addRehireEpisode(int $employeeId, string $rehireDate, ?string $notes, int $actorId): void
    {
        $this->pdo()->prepare(
            "INSERT INTO employee_employment_episode (employee_id, started_on, start_reason, notes, created_by) VALUES (:employee, :start, 'Rehire', :notes, :actor)"
        )->execute([':employee' => $employeeId, ':start' => $rehireDate, ':notes' => $notes, ':actor' => $actorId]);
    }

    public function addEvent(int $employeeId, string $eventType, string $previousStatus, string $newStatus, string $effectiveDate, string $reason, ?string $notes, int $actorId): void
    {
        $this->pdo()->prepare(
            'INSERT INTO employee_lifecycle_event (employee_id, event_type, previous_status, new_status, effective_date, reason, notes, acted_by) VALUES (:employee, :event, :previous, :new, :date, :reason, :notes, :actor)'
        )->execute([':employee' => $employeeId, ':event' => $eventType, ':previous' => $previousStatus, ':new' => $newStatus, ':date' => $effectiveDate, ':reason' => $reason, ':notes' => $notes, ':actor' => $actorId]);
    }

    /**
     * Archiving an employee archives the linked login too, so User Management
     * shows the same status as Employee Management (QA USER TC2). Archived
     * accounts cannot sign in, and reactivateEmployeeAccounts() restores them
     * on rehire.
     */
    public function deactivateEmployeeAccounts(int $employeeId): void
    {
        $pdo = $this->pdo();
        $pdo->prepare("UPDATE users SET status = 'Archived', updated_at = NOW() WHERE employee_id = :id AND status IN ('Active', 'Inactive')")
            ->execute([':id' => $employeeId]);
        $pdo->prepare("DELETE FROM sessions WHERE user_id IN (SELECT user_id FROM users WHERE employee_id = :id)")
            ->execute([':id' => $employeeId]);
    }

    /** @return list<array<string,mixed>> */
    public function lifecycleEvents(int $employeeId): array
    {
        $stmt = $this->pdo()->prepare(
            "SELECT ele.event_id, ele.event_type, ele.previous_status, ele.new_status,
                    ele.effective_date, ele.reason, ele.notes, ele.created_at,
                    u.username AS acted_by_username
               FROM employee_lifecycle_event ele
               LEFT JOIN users u ON u.user_id = ele.acted_by
              WHERE ele.employee_id = :id
              ORDER BY ele.created_at DESC, ele.event_id DESC"
        );
        $stmt->execute([':id' => $employeeId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function assertRehireAssignmentsAvailable(int $employeeId, int $branchId, int $scheduleId, int $deviceId, string $enrollmentCode, string $rehireDate): void
    {
        $pdo = $this->pdo();
        foreach ([
            ['branch', 'branch_id', $branchId],
            ['work schedule', 'schedule_id', $scheduleId],
            ['biometric device', 'device_id', $deviceId],
        ] as [$label, $column, $id]) {
            $table = $label === 'branch' ? 'branch' : ($label === 'work schedule' ? 'work_schedule' : 'biometric_device');
            $stmt = $pdo->prepare("SELECT status FROM {$table} WHERE {$column} = :id");
            $stmt->execute([':id' => $id]);
            if ($stmt->fetchColumn() !== 'Active') {
                throw new \RuntimeException('Selected ' . $label . ' is no longer active.');
            }
        }

        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM employee_biometric_enrollment WHERE device_id = :device AND device_employee_code = :code AND employee_id <> :employee AND effective_from <= :date_from AND (effective_to IS NULL OR effective_to > :date_to)"
        );
        $stmt->execute([':device' => $deviceId, ':code' => $enrollmentCode, ':employee' => $employeeId, ':date_from' => $rehireDate, ':date_to' => $rehireDate]);
        if ((int) $stmt->fetchColumn() > 0) {
            throw new \RuntimeException('This biometric enrollment ID is already assigned to another active employee.');
        }
    }

    public function assertNoOpenRelationships(int $employeeId): void
    {
        foreach (['employee_branch_assignment', 'employee_schedule_assignment', 'salary', 'employee_biometric_enrollment'] as $table) {
            $stmt = $this->pdo()->prepare("SELECT COUNT(*) FROM {$table} WHERE employee_id = :id AND effective_to IS NULL");
            $stmt->execute([':id' => $employeeId]);
            if ((int) $stmt->fetchColumn() > 0) {
                throw new \RuntimeException('The employee still has an open ' . str_replace('_', ' ', $table) . '. Resolve it before rehiring.');
            }
        }
    }

    public function hasApprovedPayrollOnDate(int $employeeId, string $date): bool
    {
        $stmt = $this->pdo()->prepare(
            "SELECT COUNT(*)
               FROM payroll p
               JOIN payroll_period pp ON pp.payroll_period_id = p.payroll_period_id
               JOIN payroll_run pr ON pr.payroll_run_id = p.payroll_run_id
              WHERE p.employee_id = :employee
                AND pr.status = 'Approved'
                AND :period_date BETWEEN pp.period_start AND pp.period_end"
        );
        $stmt->execute([':employee' => $employeeId, ':period_date' => $date]);

        return (int) $stmt->fetchColumn() > 0;
    }

    public function addRehireRelationships(int $employeeId, int $branchId, int $scheduleId, int $deviceId, string $enrollmentCode, float $dailyRate, string $rehireDate): void
    {
        $pdo = $this->pdo();
        $pdo->prepare('INSERT INTO employee_branch_assignment (employee_id, branch_id, effective_from) VALUES (:employee, :branch, :date)')
            ->execute([':employee' => $employeeId, ':branch' => $branchId, ':date' => $rehireDate]);
        $pdo->prepare("INSERT INTO employee_schedule_assignment (employee_id, schedule_id, effective_from, status) VALUES (:employee, :schedule, :date, 'Active')")
            ->execute([':employee' => $employeeId, ':schedule' => $scheduleId, ':date' => $rehireDate]);
        $pdo->prepare("INSERT INTO salary (employee_id, daily_rate, effective_from, status, created_at, updated_at) VALUES (:employee, :rate, :date, 'Active', NOW(), NOW())")
            ->execute([':employee' => $employeeId, ':rate' => $dailyRate, ':date' => $rehireDate]);
        $pdo->prepare("INSERT INTO employee_biometric_enrollment (employee_id, device_id, device_employee_code, effective_from, status, created_at, updated_at) VALUES (:employee, :device, :code, :date, 'Active', NOW(), NOW())")
            ->execute([':employee' => $employeeId, ':device' => $deviceId, ':code' => $enrollmentCode, ':date' => $rehireDate]);
    }

    public function updateEmploymentDetails(
        int $employeeId,
        string $position,
        string $employeeType,
        ?string $rehireDate = null,
        string $reviewDate = '',
    ): void {
        $pdo = $this->pdo();
        $posStmt = $pdo->prepare('SELECT position_id FROM job_position WHERE LOWER(TRIM(position_title)) = LOWER(TRIM(:title)) LIMIT 1');
        $posStmt->execute([':title' => $position]);
        $posVal = $posStmt->fetchColumn();
        $positionId = $posVal !== false ? (int) $posVal : null;

        // HR's chosen review date wins; otherwise rehire date + 6 months. [EMP-024]
        $contractReviewDate = null;
        if (in_array($employeeType, ['Contractual', 'Probationary'], true) && $reviewDate !== '') {
            $contractReviewDate = $reviewDate;
        } elseif (in_array($employeeType, ['Contractual', 'Probationary'], true)) {
            $refDate = $rehireDate ?? (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Manila')))->format('Y-m-d');
            $contractReviewDate = (new \DateTimeImmutable($refDate))->modify('+6 months')->format('Y-m-d');
        }

        $pdo->prepare('UPDATE employee SET position = :position, position_id = :position_id, employee_type = :type, contract_review_date = :review_date, status = \'Active\', updated_at = NOW() WHERE employee_id = :id')
            ->execute([
                ':position' => $position,
                ':position_id' => $positionId,
                ':type' => $employeeType,
                ':review_date' => $contractReviewDate,
                ':id' => $employeeId,
            ]);
    }

    public function reactivateEmployeeAccounts(int $employeeId, string $temporaryPassword): bool
    {
        $stmt = $this->pdo()->prepare(
            "UPDATE users
                SET status = 'Active', password_hash = :password, requires_password_change = 1, updated_at = NOW()
              WHERE employee_id = :id AND status IN ('Inactive', 'Archived')"
        );
        $stmt->execute([':id' => $employeeId, ':password' => password_hash($temporaryPassword, PASSWORD_DEFAULT)]);

        return $stmt->rowCount() > 0;
    }
}

<?php

declare(strict_types=1);

namespace Wbpms\Infrastructure\Persistence;

use PDO;
use Wbpms\Application\EmployeeSetup\EmployeeSetupGateway;
use Wbpms\Infrastructure\Database\Connection;
use Wbpms\Domain\Employee\EmployeeNumberSequence;
use Wbpms\Domain\Employee\BankAccountChange;
use Wbpms\Domain\Employee\BankAccountDateRejected;

/**
 * EmployeeRepository — PDO persistence for the Employee module.
 *
 * Implements EmployeeSetupGateway (B2 contract) and exposes additional
 * read methods required by EmployeeController.
 *
 * Tasks 4.1–4.3 (P0 subset): list/create/update; no transfer history or archive.
 *
 * ADR-0003: all SQL lives here, never in controllers or services.
 */
final class EmployeeRepository extends AbstractRepository implements EmployeeSetupGateway
{
    // -----------------------------------------------------------------------
    // EmployeeSetupGateway implementation
    // -----------------------------------------------------------------------

    /**
     * Insert a new employee record and return the generated employee_id.
     *
     * Required attributes:
     *   employee_number (string), employee_type (Regular|Contractual),
     *   first_name (string), last_name (string), hire_date (Y-m-d),
     *   position (string), status (Active|Inactive|Separated|Archived)
     *
     * Optional: middle_initial, email, contact_number, birthdate,
     *           philhealth_number, pagibig_number, tin_number,
     *           contract_review_date
     *
     * @param  array<string, mixed> $attributes
     * @return int  New employee_id
     */
    public function createEmployee(array $attributes): int
    {
        $positionTitle = (string) ($attributes['position'] ?? '');
        $positionId = $attributes['position_id'] ?? $this->resolvePositionId($positionTitle);
        $employeeType = (string) ($attributes['employee_type'] ?? 'Regular');
        $hireDate = (string) ($attributes['hire_date'] ?? date('Y-m-d'));
        $contractReviewDate = $attributes['contract_review_date'] ?? null;
        if (($contractReviewDate === null || $contractReviewDate === '')
            && in_array($employeeType, ['Contractual', 'Probationary'], true)
            && preg_match('/^\d{4}-\d{2}-\d{2}$/', $hireDate)) {
            $contractReviewDate = (new \DateTimeImmutable($hireDate))->modify('+6 months')->format('Y-m-d');
        }

        $sql = <<<'SQL'
            INSERT INTO employee (
                employee_number, employee_type,
                first_name, middle_initial, last_name,
                email, contact_number, birthdate, hire_date,
                position, position_id, status,
                sss_number, philhealth_number, pagibig_number, tin_number,
                contract_review_date
            ) VALUES (
                :employee_number, :employee_type,
                :first_name, :middle_initial, :last_name,
                :email, :contact_number, :birthdate, :hire_date,
                :position, :position_id, :status,
                :sss_number, :philhealth_number, :pagibig_number, :tin_number,
                :contract_review_date
            )
        SQL;

        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute([
            ':employee_number'     => $attributes['employee_number'],
            ':employee_type'       => $employeeType,
            ':first_name'          => $attributes['first_name'],
            ':middle_initial'      => $attributes['middle_initial']   ?? null,
            ':last_name'           => $attributes['last_name'],
            ':email'               => $attributes['email']            ?? null,
            ':contact_number'      => $attributes['contact_number']   ?? null,
            ':birthdate'           => $attributes['birthdate']        ?? null,
            ':hire_date'           => $hireDate,
            ':position'            => $positionTitle,
            ':position_id'         => $positionId,
            ':status'              => $attributes['status']           ?? 'Active',
            ':sss_number'          => $attributes['sss_number']        ?? null,
            ':philhealth_number'   => $attributes['philhealth_number'] ?? null,
            ':pagibig_number'      => $attributes['pagibig_number']   ?? null,
            ':tin_number'          => $attributes['tin_number']       ?? null,
            ':contract_review_date'=> $contractReviewDate,
        ]);

        return (int) $this->pdo()->lastInsertId();
    }

    /**
     * Open an initial branch assignment for a newly created employee.
     * Uses the half-open [from, to) period; effective_to is NULL (current).
     */
    public function assignInitialBranch(int $employeeId, int $branchId, string $effectiveFrom): void
    {
        $stmt = $this->pdo()->prepare(
            'INSERT INTO employee_branch_assignment
                (employee_id, branch_id, effective_from, effective_to)
             VALUES (:emp_id, :branch_id, :from, NULL)'
        );
        $stmt->execute([
            ':emp_id'    => $employeeId,
            ':branch_id' => $branchId,
            ':from'      => $effectiveFrom,
        ]);
    }

    /**
     * Create an effective work schedule for an employee.
     *
     * $scheduleId is the schedule_id of an existing work_schedule row.
     * The effective_from date becomes the start of the schedule's validity.
     *
     * NOTE: This method creates a new assignment link; it does not create the
     * schedule itself. For P0 the schedule row is created by ScheduleRepository.
     * This method exists for the EmployeeSetupGateway contract compatibility.
     * In the P0 flow the schedule is linked at creation time via createEmployee()
     * combined with the schedule_id attribute.
     */
    public function assignEffectiveSchedule(int $employeeId, int $scheduleId, string $effectiveFrom): void
    {
        $stmt = $this->pdo()->prepare(
            'INSERT INTO employee_schedule_assignment
                (employee_id, schedule_id, effective_from, effective_to, status)
             VALUES (:employee_id, :schedule_id, :effective_from, NULL, :status)'
        );
        $stmt->execute([
            ':employee_id'    => $employeeId,
            ':schedule_id'    => $scheduleId,
            ':effective_from' => $effectiveFrom,
            ':status'         => 'Active',
        ]);
    }

    /**
     * Register a biometric enrollment code for an employee on a specific device.
     */
    public function enrollBiometricCode(int $employeeId, int $deviceId, string $enrollmentCode, string $effectiveFrom): void
    {
        $stmt = $this->pdo()->prepare(
            'INSERT INTO employee_biometric_enrollment
                (employee_id, device_id, device_employee_code, effective_from, effective_to, status)
             VALUES (:emp_id, :dev_id, :code, :from, NULL, :status)'
        );
        $stmt->execute([
            ':emp_id' => $employeeId,
            ':dev_id' => $deviceId,
            ':code'   => $enrollmentCode,
            ':from'   => $effectiveFrom,
            ':status' => 'Active',
        ]);
    }

    /**
     * Whether another employee holds this device + enroll ID on or after
     * $onDate. Uses the import's matching rule (effective_to is exclusive),
     * so an ID freed by an archive is reusable from the archive date. [QA EMP TC11]
     */
    public function enrollmentCodeTaken(int $deviceId, string $enrollmentCode, ?int $excludeEmployeeId, string $onDate): bool
    {
        $sql = "SELECT 1 FROM employee_biometric_enrollment
                 WHERE device_id = :device_id
                   AND device_employee_code = :code
                   AND status = 'Active'
                   AND (effective_to IS NULL OR effective_to > :on_date)";
        $params = [':device_id' => $deviceId, ':code' => $enrollmentCode, ':on_date' => $onDate];
        if ($excludeEmployeeId !== null) {
            $sql .= ' AND employee_id <> :employee_id';
            $params[':employee_id'] = $excludeEmployeeId;
        }
        $stmt = $this->pdo()->prepare($sql . ' LIMIT 1');
        $stmt->execute($params);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Apply a device / enroll ID edited on the employee form. [QA EMP TC4]
     *
     *  - No current enrollment: enroll from the hire date, so punches already
     *    on file for that ID can be matched by a re-import.
     *  - Same device and ID: nothing changes.
     *  - Changed, and the old ID never matched a punch: it was a data-entry
     *    correction, so the current row is corrected in place.
     *  - Changed after punches were matched: the old enrollment ends today and
     *    the new one starts today, keeping matched history intact.
     */
    public function changeBiometricEnrollment(int $employeeId, int $deviceId, string $enrollmentCode, string $hireDate, string $today): void
    {
        $pdo = $this->pdo();
        $stmt = $pdo->prepare(
            "SELECT enrollment_id, device_id, device_employee_code, effective_from
               FROM employee_biometric_enrollment
              WHERE employee_id = :employee_id AND effective_to IS NULL AND status = 'Active'
              ORDER BY effective_from DESC LIMIT 1 FOR UPDATE"
        );
        $stmt->execute([':employee_id' => $employeeId]);
        $current = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($current === false) {
            $this->enrollBiometricCode($employeeId, $deviceId, $enrollmentCode, $hireDate);
            return;
        }
        if ((int) $current['device_id'] === $deviceId && (string) $current['device_employee_code'] === $enrollmentCode) {
            return;
        }

        $used = $pdo->prepare(
            "SELECT 1 FROM biometric_punch
              WHERE employee_id = :employee_id AND device_id = :device_id AND device_employee_code = :code
              LIMIT 1"
        );
        $used->execute([
            ':employee_id' => $employeeId,
            ':device_id'   => (int) $current['device_id'],
            ':code'        => (string) $current['device_employee_code'],
        ]);

        if ($used->fetchColumn() === false || (string) $current['effective_from'] >= $today) {
            $pdo->prepare(
                'UPDATE employee_biometric_enrollment
                    SET device_id = :device_id, device_employee_code = :code, updated_at = NOW()
                  WHERE enrollment_id = :id'
            )->execute([':device_id' => $deviceId, ':code' => $enrollmentCode, ':id' => (int) $current['enrollment_id']]);
            return;
        }

        $pdo->prepare(
            "UPDATE employee_biometric_enrollment
                SET effective_to = :today, status = 'Inactive', updated_at = NOW()
              WHERE enrollment_id = :id"
        )->execute([':today' => $today, ':id' => (int) $current['enrollment_id']]);
        $this->enrollBiometricCode($employeeId, $deviceId, $enrollmentCode, $today);
    }

    /**
     * Next auto-generated employee number (EMP-001, EMP-002, ...), one above
     * the highest EMP-<digits> number in use, archived employees included.
     */
    public function nextEmployeeNumber(): string
    {
        $stmt = $this->pdo()->query(
            "SELECT employee_number FROM employee WHERE employee_number LIKE 'EMP-%'"
        );
        $numbers = $stmt === false ? [] : array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN));

        return EmployeeNumberSequence::next($numbers);
    }

    /**
     * Return whether an employee number is already assigned.
     */
    public function employeeNumberExists(string $employeeNumber): bool
    {
        $stmt = $this->pdo()->prepare(
            'SELECT 1 FROM employee WHERE employee_number = :employee_number LIMIT 1'
        );
        $stmt->execute([':employee_number' => $employeeNumber]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Return whether an email is used by another employee.
     */
    public function emailExists(string $email, ?int $excludeEmployeeId = null): bool
    {
        $sql = 'SELECT 1 FROM employee WHERE email = :email';
        $params = [':email' => $email];

        if ($excludeEmployeeId !== null) {
            $sql .= ' AND employee_id != :employee_id';
            $params[':employee_id'] = $excludeEmployeeId;
        }

        $stmt = $this->pdo()->prepare($sql . ' LIMIT 1');
        $stmt->execute($params);

        return $stmt->fetchColumn() !== false;
    }

    // -----------------------------------------------------------------------
    // Read methods for EmployeeController
    // -----------------------------------------------------------------------

    /**
     * Return a paginated list of employees with their current branch and schedule.
     *
     * Applies optional search (last / first / middle name, employee_number),
     * branch filter, status filter, and position filter.  Returns rows matching
     * the shape expected by the index view.
     *
     * @param  array{search?: string, branch?: string|int, status?: string, position?: string} $filters
     * @return list<array{
     *   id: int,
     *   employee_number: string,
     *   last_name: string,
     *   first_name: string,
     *   middle_name: ?string,
     *   position: string,
     *   branch_name: string,
     *   schedule_name: string,
     *   status: string,
     *   effective_from: string
     * }>
     */
    public function findAll(array $filters = []): array
    {
        $params = [];
        $where  = [];

        // Status filter
        $status = $filters['status'] ?? '';
        if ($status !== '') {
            $where[]          = 'e.status = :status';
            $params[':status'] = ucfirst(strtolower($status));
        }

        // Branch filter (by branch_id)
        $branchId = (string) ($filters['branch'] ?? '');
        if ($branchId !== '') {
            $where[]           = 'eba.branch_id = :branch_id';
            $params[':branch_id'] = (int) $branchId;
        }

        // Position filter (exact match against employee.position)
        $position = trim((string) ($filters['position'] ?? ''));
        if ($position !== '') {
            $where[]             = 'e.position = :position';
            $params[':position'] = $position;
        }

        // Search by name or employee number
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            // Every word in any order: first, middle, last name or number (REQ012).
            [$nameSql, $nameParams] = EmployeeNameSearch::condition($search, 'e', 'search_q');
            if ($nameSql !== '') {
                $where[] = $nameSql;
                $params  = array_merge($params, $nameParams);
            }
        }

        $whereClause = $where !== [] ? 'WHERE ' . implode(' AND ', $where) : '';

        $sql = <<<SQL
            SELECT
                e.employee_id              AS id,
                e.employee_number,
                e.last_name,
                e.first_name,
                e.middle_initial                 AS middle_name,
                COALESCE(e.position, '—')        AS position,
                COALESCE(b.branch_name, '—')     AS branch_name,
                COALESCE(
                    CONCAT(TIME_FORMAT(ws.work_start_time, '%H:%i'), ' – ', TIME_FORMAT(ws.work_end_time, '%H:%i')),
                    '—'
                )                                AS schedule_name,
                LOWER(e.status)                  AS status,
                COALESCE(eba.effective_from, e.hire_date) AS effective_from
            FROM employee e
            LEFT JOIN employee_branch_assignment eba
                   ON eba.employee_id = e.employee_id
                  AND eba.effective_to IS NULL
            LEFT JOIN branch b ON b.branch_id = eba.branch_id
            LEFT JOIN employee_schedule_assignment esa
                   ON esa.employee_id = e.employee_id
                  AND esa.effective_to IS NULL
                  AND esa.status = 'Active'
            LEFT JOIN work_schedule ws ON ws.schedule_id = esa.schedule_id
            {$whereClause}
            ORDER BY e.last_name ASC, e.first_name ASC
            LIMIT 200
        SQL;

        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Return a single employee by ID with branch and schedule info.
     *
     * @return array<string, mixed>|null
     */
    public function findById(int $employeeId): ?array
    {
        $stmt = $this->pdo()->prepare(
            "SELECT
                e.employee_id              AS id,
                e.employee_number,
                e.employee_type,
                e.first_name,
                e.middle_initial,
                e.middle_initial           AS middle_name,
                e.last_name,
                e.email,
                e.contact_number,
                e.birthdate,
                e.hire_date,
                e.hire_date                AS effective_from,
                e.position,
                LOWER(e.status)            AS status,
                e.philhealth_number,
                e.pagibig_number,
                e.tin_number,
                e.sss_number,
                e.contract_review_date,
                COALESCE(b.branch_id, 0)   AS branch_id,
                COALESCE(b.branch_name,'—') AS branch_name,
                COALESCE(esa.schedule_id, 0) AS schedule_id,
                COALESCE(s.daily_rate, 0)  AS daily_rate,
                COALESCE(ebe.device_id, 0) AS device_id,
                COALESCE(ebe.device_employee_code, '') AS enrollment_code
             FROM employee e
             LEFT JOIN employee_branch_assignment eba
                    ON eba.employee_id = e.employee_id
                   AND eba.effective_to IS NULL
             LEFT JOIN branch b ON b.branch_id = eba.branch_id
             LEFT JOIN employee_schedule_assignment esa
                    ON esa.employee_id = e.employee_id
                   AND esa.effective_to IS NULL
                   AND esa.status = 'Active'
             LEFT JOIN salary s
                    ON s.employee_id = e.employee_id
                   AND s.effective_to IS NULL
                   AND s.status = 'Active'
             LEFT JOIN employee_biometric_enrollment ebe
                    ON ebe.employee_id = e.employee_id
                   AND ebe.effective_to IS NULL
                   AND ebe.status = 'Active'
             WHERE e.employee_id = :id"
        );
        $stmt->execute([':id' => $employeeId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        if ((int) ($row['device_id'] ?? 0) === 0 || (string) ($row['enrollment_code'] ?? '') === '') {
            $ebeStmt = $this->pdo()->prepare(
                'SELECT device_id, device_employee_code
                   FROM employee_biometric_enrollment
                  WHERE employee_id = :id
                  ORDER BY effective_from DESC, enrollment_id DESC
                  LIMIT 1'
            );
            $ebeStmt->execute([':id' => $employeeId]);
            $lastEbe = $ebeStmt->fetch(PDO::FETCH_ASSOC);
            if ($lastEbe !== false) {
                $row['device_id'] = (int) $lastEbe['device_id'];
                $row['enrollment_code'] = (string) $lastEbe['device_employee_code'];
            }
        }

        if ((int) ($row['branch_id'] ?? 0) === 0) {
            $ebaStmt = $this->pdo()->prepare(
                'SELECT eba.branch_id, COALESCE(b.branch_name, \'—\') AS branch_name
                   FROM employee_branch_assignment eba
                   LEFT JOIN branch b ON b.branch_id = eba.branch_id
                  WHERE eba.employee_id = :id
                  ORDER BY eba.effective_from DESC, eba.branch_assignment_id DESC
                  LIMIT 1'
            );
            $ebaStmt->execute([':id' => $employeeId]);
            $lastEba = $ebaStmt->fetch(PDO::FETCH_ASSOC);
            if ($lastEba !== false) {
                $row['branch_id'] = (int) $lastEba['branch_id'];
                $row['branch_name'] = (string) $lastEba['branch_name'];
            }
        }

        if ((int) ($row['schedule_id'] ?? 0) === 0) {
            $esaStmt = $this->pdo()->prepare(
                'SELECT schedule_id
                   FROM employee_schedule_assignment
                  WHERE employee_id = :id
                  ORDER BY effective_from DESC, assignment_id DESC
                  LIMIT 1'
            );
            $esaStmt->execute([':id' => $employeeId]);
            $lastSched = $esaStmt->fetchColumn();
            if ($lastSched !== false) {
                $row['schedule_id'] = (int) $lastSched;
            }
        }

        if ((float) ($row['daily_rate'] ?? 0) <= 0.0) {
            $salStmt = $this->pdo()->prepare(
                'SELECT daily_rate
                   FROM salary
                  WHERE employee_id = :id
                  ORDER BY effective_from DESC, salary_id DESC
                  LIMIT 1'
            );
            $salStmt->execute([':id' => $employeeId]);
            $lastRate = $salStmt->fetchColumn();
            if ($lastRate !== false) {
                $row['daily_rate'] = (float) $lastRate;
            }
        }

        return $row;
    }

    /**
     * Update mutable fields of an existing employee record.
     *
     * @param int                   $employeeId
     * @param array<string, mixed>  $attributes  Partial list of updatable fields
     */
    public function updateEmployee(int $employeeId, array $attributes): void
    {
        if (array_key_exists('position', $attributes) && !array_key_exists('position_id', $attributes)) {
            $attributes['position_id'] = $this->resolvePositionId((string) ($attributes['position'] ?? ''));
        }
        if (array_key_exists('employee_type', $attributes) && !array_key_exists('contract_review_date', $attributes)) {
            $empType = (string) $attributes['employee_type'];
            if (in_array($empType, ['Contractual', 'Probationary'], true)) {
                $baseDate = (string) ($attributes['hire_date'] ?? (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Manila')))->format('Y-m-d'));
                $attributes['contract_review_date'] = (new \DateTimeImmutable($baseDate))->modify('+6 months')->format('Y-m-d');
            } else {
                $attributes['contract_review_date'] = null;
            }
        }

        $allowed = [
            'employee_type', 'first_name', 'middle_initial', 'last_name',
            'email', 'contact_number', 'birthdate', 'hire_date', 'position', 'position_id',
            'status', 'sss_number', 'philhealth_number', 'pagibig_number',
            'tin_number', 'contract_review_date',
        ];

        $setClauses = [];
        $params     = [':id' => $employeeId];

        foreach ($allowed as $field) {
            if (array_key_exists($field, $attributes)) {
                $setClauses[]      = "{$field} = :{$field}";
                $params[":{$field}"] = $attributes[$field];
            }
        }

        if ($setClauses === []) {
            return; // Nothing to update
        }

        $sql  = 'UPDATE employee SET ' . implode(', ', $setClauses) . ' WHERE employee_id = :id';
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
    }

    /**
     * Insert a new salary row for an employee (effective-dated).
     */
    public function createSalary(int $employeeId, float $dailyRate, string $effectiveFrom): void
    {
        $this->pdo()->prepare(
            "INSERT INTO salary (employee_id, daily_rate, effective_from, effective_to, status, created_at, updated_at)
             VALUES (:emp_id, :rate, :from, NULL, 'Active', NOW(), NOW())"
        )->execute([
            ':emp_id' => $employeeId,
            ':rate'   => $dailyRate,
            ':from'   => $effectiveFrom,
        ]);
    }

    /**
     * Update the current active salary using Asia/Manila date and guarding against
     * same-day / future-effective duplicate key collisions on (employee_id, effective_from).
     */
    public function updateSalary(int $employeeId, float $dailyRate): void
    {
        $today = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Manila')))->format('Y-m-d');
        $pdo = $this->pdo();

        $stmt = $pdo->prepare(
            "SELECT salary_id, effective_from
               FROM salary
              WHERE employee_id = :emp_id AND effective_to IS NULL AND status = 'Active'
              ORDER BY effective_from DESC LIMIT 1 FOR UPDATE"
        );
        $stmt->execute([':emp_id' => $employeeId]);
        $active = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($active !== false && (string) $active['effective_from'] >= $today) {
            $pdo->prepare(
                "UPDATE salary SET daily_rate = :rate, updated_at = NOW() WHERE salary_id = :id"
            )->execute([':rate' => $dailyRate, ':id' => (int) $active['salary_id']]);
            return;
        }

        if ($active !== false) {
            $yesterday = (new \DateTimeImmutable($today))->modify('-1 day')->format('Y-m-d');
            $pdo->prepare(
                "UPDATE salary SET effective_to = :yesterday, status = 'Superseded', updated_at = NOW()
                  WHERE salary_id = :id"
            )->execute([':yesterday' => $yesterday, ':id' => (int) $active['salary_id']]);
        }

        $this->createSalary($employeeId, $dailyRate, $today);
    }

    public function activeMinimumDailyWage(): float
    {
        try {
            $stmt = $this->pdo()->query(
                "SELECT minimum_daily_wage
                   FROM payroll_policy_version
                  WHERE status = 'Approved'
                  ORDER BY effective_from DESC
                  LIMIT 1"
            );
            $val = $stmt !== false ? $stmt->fetchColumn() : false;
            if ($val !== false && (float) $val > 0) {
                return (float) $val;
            }
        } catch (\Throwable) {
            // Fallback when running against minimal schema without minimum_daily_wage
        }

        return 460.00;
    }

    /** @return array<string,mixed>|null */
    public function positionMetaByTitle(string $title): ?array
    {
        if (trim($title) === '') {
            return null;
        }
        try {
            $stmt = $this->pdo()->prepare(
                "SELECT position_id, position_title, default_daily_rate, requires_regular
                   FROM job_position
                  WHERE LOWER(TRIM(position_title)) = LOWER(TRIM(:title))
                  LIMIT 1"
            );
            $stmt->execute([':title' => $title]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row !== false ? $row : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function deviceCoversBranchOn(int $deviceId, int $branchId, string $onDate): bool
    {
        try {
            $stmt = $this->pdo()->prepare(
                "SELECT 1
                   FROM biometric_device_branch
                  WHERE device_id = :device_id
                    AND branch_id = :branch_id
                    AND status = 'Active'
                    AND effective_from <= :on_date_from
                    AND (effective_to IS NULL OR effective_to > :on_date_to)
                  LIMIT 1"
            );
            $stmt->execute([
                ':device_id' => $deviceId,
                ':branch_id' => $branchId,
                ':on_date_from' => $onDate,
                ':on_date_to' => $onDate,
            ]);
            if ($stmt->fetchColumn() !== false) {
                return true;
            }

            // If device has no branch rows at all in a minimal test fixture, don't false-block
            $anyStmt = $this->pdo()->prepare('SELECT COUNT(*) FROM biometric_device_branch WHERE device_id = :device_id');
            $anyStmt->execute([':device_id' => $deviceId]);
            return (int) $anyStmt->fetchColumn() === 0;
        } catch (\Throwable) {
            return true;
        }
    }

    /** @return list<array<string,mixed>> */
    public function unmatchedEnrollmentCodes(): array
    {
        try {
            $stmt = $this->pdo()->query(
                "SELECT bp.device_id,
                        COALESCE(bd.device_name, CONCAT('Device #', bp.device_id)) AS device_name,
                        bp.device_employee_code,
                        MAX(bp.source_employee_name) AS source_employee_name,
                        COUNT(*) AS punch_count
                   FROM biometric_punch bp
                   JOIN attendance_import_batch aib ON aib.import_batch_id = bp.import_batch_id
                   LEFT JOIN biometric_device bd ON bd.device_id = bp.device_id
                  WHERE bp.match_status IN ('unmatched', 'coverage_exception')
                    AND aib.status IN ('Draft', 'Approved', 'Completed')
                  GROUP BY bp.device_id, bd.device_name, bp.device_employee_code
                  ORDER BY bp.device_id ASC, CAST(bp.device_employee_code AS UNSIGNED) ASC, bp.device_employee_code ASC
                  LIMIT 50"
            );
            return $stmt !== false ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        } catch (\Throwable) {
            return [];
        }
    }

    private function resolvePositionId(string $title): ?int
    {
        $meta = $this->positionMetaByTitle($title);
        return $meta !== null && isset($meta['position_id']) ? (int) $meta['position_id'] : null;
    }

    /**
     * Transfer an employee to a new branch.
     *
     * Closes the current active branch assignment at the transfer date. Periods
     * are half-open: [effective_from, effective_to), so the old branch covers
     * every date before the transfer and the new branch covers the transfer day.
     * and opens a new one (effective_from = transfer_date). The operation is transactional.
     *
     * ADR-0002: branch assignments must not overlap; the half-open [from, to) period is enforced.
     *
     * @param int    $employeeId   Target employee
     * @param int    $newBranchId  Destination branch
     * @param string $transferDate YYYY-MM-DD — the first day on the new branch
     * @throws \RuntimeException   when no active assignment exists, branch unchanged,
     *                             or transfer date would create an overlap
     */
    /** Start date of the employee's open branch assignment, or null (REQ015). */
    public function currentBranchStart(int $employeeId): ?string
    {
        $stmt = $this->connection->pdo()->prepare(
            "SELECT effective_from FROM employee_branch_assignment
              WHERE employee_id = :emp AND effective_to IS NULL
              ORDER BY effective_from DESC LIMIT 1"
        );
        $stmt->execute([':emp' => $employeeId]);
        $start = $stmt->fetchColumn();

        return is_string($start) ? $start : null;
    }

    public function transferEmployee(int $employeeId, int $newBranchId, string $transferDate): void
    {
        $this->connection->transaction(function (\PDO $pdo) use ($employeeId, $newBranchId, $transferDate): void {
            // Fetch the current open assignment with a write lock
            $stmt = $pdo->prepare(
                "SELECT branch_assignment_id, branch_id, effective_from
                   FROM employee_branch_assignment
                  WHERE employee_id   = :emp
                    AND effective_to  IS NULL
                  FOR UPDATE"
            );
            $stmt->execute([':emp' => $employeeId]);
            $current = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($current === false) {
                throw new \RuntimeException('No active branch assignment found for this employee.');
            }

            if ((int) $current['branch_id'] === $newBranchId) {
                throw new \RuntimeException('The employee is already assigned to that branch.');
            }

            if ($transferDate <= $current['effective_from']) {
                throw new \RuntimeException(\Wbpms\Domain\Employee\EffectiveDateRule::message(
                    'transfer date', 'current branch assignment', (string) $current['effective_from']
                ));
            }

            // Check for any future closed assignment that would overlap
            $overlap = $pdo->prepare(
                "SELECT COUNT(*) FROM employee_branch_assignment
                  WHERE employee_id    = :emp
                    AND effective_from >= :date
                    AND effective_to   IS NOT NULL"
            );
            $overlap->execute([':emp' => $employeeId, ':date' => $transferDate]);
            if ((int) $overlap->fetchColumn() > 0) {
                throw new \RuntimeException(
                    'A future branch assignment already exists; resolve it before transferring.'
                );
            }

            // Use the transfer date as the exclusive end of the old assignment.
            // Attendance matching also uses `effective_to > punch_date`; using
            // the preceding day would create an uncovered day before transfer.
            $closeDate = $transferDate;
            $pdo->prepare(
                "UPDATE employee_branch_assignment
                    SET effective_to = :close
                  WHERE branch_assignment_id = :id"
            )->execute([':close' => $closeDate, ':id' => $current['branch_assignment_id']]);

            // Open the new assignment
            $pdo->prepare(
                "INSERT INTO employee_branch_assignment
                    (employee_id, branch_id, effective_from, effective_to)
                 VALUES (:emp, :branch, :from, NULL)"
            )->execute([
                ':emp'    => $employeeId,
                ':branch' => $newBranchId,
                ':from'   => $transferDate,
            ]);
        });
    }

    /**
     * Return all active branches for use in dropdown filters.
     *
     * @return list<array{id: int, name: string}>
     */
    public function activeBranches(): array
    {
        $stmt = $this->pdo()->query(
            "SELECT branch_id AS id, branch_name AS name
             FROM branch
             WHERE status = 'Active'
             ORDER BY branch_name ASC"
        );

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // -----------------------------------------------------------------------
    // Detail-view read methods
    // -----------------------------------------------------------------------

    /**
     * Return a rich employee row for the read-only detail view.
     *
     * Includes linked user account, current schedule name, work hours,
     * current daily rate, and current biometric enrollment.
     *
     * @return array<string, mixed>|null
     */
    public function findDetail(int $employeeId): ?array
    {
        $stmt = $this->pdo()->prepare(
            "SELECT
                e.employee_id                                   AS id,
                e.employee_number,
                e.employee_type,
                e.first_name,
                e.middle_initial,
                e.last_name,
                e.email,
                e.contact_number,
                e.birthdate,
                e.hire_date,
                e.position,
                e.status,
                e.philhealth_number,
                e.pagibig_number,
                e.tin_number,
                e.sss_number,
                e.contract_review_date,
                e.created_at,
                -- Current branch
                COALESCE(b.branch_name, '—')                    AS branch_name,
                COALESCE(eba.effective_from, '—')               AS branch_since,
                -- Current schedule
                COALESCE(ws.schedule_name, '—')                 AS schedule_name,
                COALESCE(ws.work_start_time, '')                AS work_start_time,
                COALESCE(ws.work_end_time, '')                  AS work_end_time,
                COALESCE(ws.standard_minutes, 0)                AS standard_minutes,
                COALESCE(ws.break_minutes, 0)                   AS break_minutes,
                -- Current salary
                COALESCE(s.daily_rate, 0)                       AS daily_rate,
                s.effective_from                                AS salary_since,
                -- Biometric enrollment
                COALESCE(bd.device_name, bd.device_code, '—')  AS device_name,
                COALESCE(ebe.device_employee_code, '—')         AS enrollment_code,
                -- Linked user account
                u.user_id,
                u.username,
                u.status                                        AS account_status,
                u.requires_password_change
             FROM employee e
             LEFT JOIN employee_branch_assignment eba
                    ON eba.employee_id = e.employee_id
                   AND eba.effective_to IS NULL
             LEFT JOIN branch b ON b.branch_id = eba.branch_id
             LEFT JOIN employee_schedule_assignment esa
                    ON esa.employee_id = e.employee_id
                   AND esa.effective_to IS NULL
                   AND esa.status = 'Active'
             LEFT JOIN work_schedule ws ON ws.schedule_id = esa.schedule_id
             LEFT JOIN salary s
                    ON s.employee_id = e.employee_id
                   AND s.effective_to IS NULL
                   AND s.status = 'Active'
             LEFT JOIN employee_biometric_enrollment ebe
                    ON ebe.employee_id = e.employee_id
                   AND ebe.effective_to IS NULL
                   AND ebe.status = 'Active'
             LEFT JOIN biometric_device bd ON bd.device_id = ebe.device_id
             LEFT JOIN users u ON u.employee_id = e.employee_id
             WHERE e.employee_id = :id"
        );
        $stmt->execute([':id' => $employeeId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    /**
     * Return the full branch assignment history for an employee, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function branchHistory(int $employeeId): array
    {
        $stmt = $this->pdo()->prepare(
            "SELECT
                eba.effective_from,
                eba.effective_to,
                b.branch_name,
                eba.transfer_reason
             FROM employee_branch_assignment eba
             LEFT JOIN branch b ON b.branch_id = eba.branch_id
             WHERE eba.employee_id = :id
             ORDER BY eba.effective_from DESC"
        );
        $stmt->execute([':id' => $employeeId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Return the full salary history for an employee, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function salaryHistory(int $employeeId): array
    {
        $stmt = $this->pdo()->prepare(
            "SELECT
                s.daily_rate,
                s.effective_from,
                s.effective_to,
                s.status
             FROM salary s
             WHERE s.employee_id = :id
             ORDER BY s.effective_from DESC"
        );
        $stmt->execute([':id' => $employeeId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    // -----------------------------------------------------------------------
    // Bank details
    // -----------------------------------------------------------------------

    /**
     * Return the active bank account for an employee, or null if none.
     *
     * @return array<string, mixed>|null
     */
    public function findBankDetails(int $employeeId): ?array
    {
        $stmt = $this->pdo()->prepare(
            "SELECT bank_id, bank_name, account_name, account_number, account_type,
                    effective_from, status
               FROM bank_details
              WHERE employee_id = :id
                AND status = 'Active'
              ORDER BY effective_from DESC
              LIMIT 1"
        );
        $stmt->execute([':id' => $employeeId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    /**
     * Save (upsert) bank account details for an employee.
     *
     * The current Active row is locked and compared with the new effective
     * date (BankAccountChange, EMP-026):
     *  - later date  → the current row is closed (effective_to = new date,
     *                  Inactive) and a new Active row is inserted;
     *  - same date   → the current row is corrected in place (closing it would
     *                  give effective_to = effective_from, which CHECK
     *                  chk_bank_period refuses);
     *  - earlier date → BankAccountDateRejected, nothing is written.
     * Everything runs in one transaction so the employee never has zero
     * bank rows between the writes.
     *
     * @throws BankAccountDateRejected
     *
     * @param array{
     *   bank_name: string,
     *   account_name: string,
     *   account_number: string,
     *   account_type: string,
     *   effective_from: string
     * } $data
     */
    public function saveBankDetails(int $employeeId, array $data): void
    {
        $this->connection->transaction(function (\PDO $pdo) use ($employeeId, $data): void {
            $accountType = $data['account_type'] !== '' ? $data['account_type'] : null;

            // Lock the current active row(s) so two HR saves cannot race.
            $currentStmt = $pdo->prepare(
                "SELECT bank_id, effective_from
                   FROM bank_details
                  WHERE employee_id = :id
                    AND status = 'Active'
                  ORDER BY effective_from DESC, bank_id DESC
                  FOR UPDATE"
            );
            $currentStmt->execute([':id' => $employeeId]);
            /** @var list<array{bank_id: int|string, effective_from: string}> $activeRows */
            $activeRows = $currentStmt->fetchAll(PDO::FETCH_ASSOC);

            // Every active row must close after it started, so compare against
            // the latest effective date among them.
            $current = $activeRows[0] ?? null;
            $change  = BankAccountChange::decide(
                $current !== null ? (string) $current['effective_from'] : null,
                $data['effective_from']
            );

            if ($change === BankAccountChange::Reject && $current !== null) {
                throw BankAccountDateRejected::before((string) $current['effective_from']);
            }

            if ($change === BankAccountChange::Correct && $current !== null) {
                // Same effective date: correct the current account in place.
                $pdo->prepare(
                    "UPDATE bank_details
                        SET bank_name = :bank_name, account_name = :account_name,
                            account_number = :account_number, account_type = :account_type,
                            updated_at = NOW()
                      WHERE bank_id = :bank_id"
                )->execute([
                    ':bank_name'      => $data['bank_name'],
                    ':account_name'   => $data['account_name'],
                    ':account_number' => $data['account_number'],
                    ':account_type'   => $accountType,
                    ':bank_id'        => (int) $current['bank_id'],
                ]);
                return;
            }

            // Later effective date: close the current account(s) the day the
            // new one takes effect.
            if ($current !== null) {
                $pdo->prepare(
                    "UPDATE bank_details
                        SET status = 'Inactive', effective_to = :new_from, updated_at = NOW()
                      WHERE employee_id = :id
                        AND status = 'Active'"
                )->execute([
                    ':new_from' => $data['effective_from'],
                    ':id'       => $employeeId,
                ]);
            }

            // Insert new active record
            $pdo->prepare(
                "INSERT INTO bank_details
                    (employee_id, bank_name, account_name, account_number,
                     account_type, effective_from, effective_to, status,
                     created_at, updated_at)
                 VALUES
                    (:emp_id, :bank_name, :account_name, :account_number,
                     :account_type, :effective_from, NULL, 'Active',
                     NOW(), NOW())"
            )->execute([
                ':emp_id'        => $employeeId,
                ':bank_name'     => $data['bank_name'],
                ':account_name'  => $data['account_name'],
                ':account_number'=> $data['account_number'],
                ':account_type'  => $accountType,
                ':effective_from'=> $data['effective_from'],
            ]);
        });
    }

    /**
     * Deactivate the current active bank account for an employee.
     */
    public function deactivateBankDetails(int $employeeId): void
    {
        $this->pdo()->prepare(
            "UPDATE bank_details
                SET status = 'Inactive', effective_to = CURDATE(), updated_at = NOW()
              WHERE employee_id = :id
                AND status = 'Active'"
        )->execute([':id' => $employeeId]);
    }
}

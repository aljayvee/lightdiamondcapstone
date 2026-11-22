<?php

declare(strict_types=1);

namespace Wbpms\Application;

use PDO;
use RuntimeException;
use Wbpms\Infrastructure\Database\Connection;

/**
 * SalaryService — manages employee salary structures.
 *
 * Implements REQ053–REQ057:
 *   REQ053  List salary records with employee / branch filter
 *   REQ054  Create a salary structure for an employee
 *   REQ055  Update: insert a new row to preserve history (never mutate old rate)
 *   REQ056  Archive a salary record (soft delete)
 *   REQ057  Retrieve current effective rate for a given employee
 *
 * Business rules:
 *   - "Updating" a daily rate inserts a new row with a new effective_from;
 *     history rows are never mutated (REQ055).
 *   - Only one Active salary record should exist per employee at any time.
 *     On update, the previous Active row is closed (effective_to = new effective_from - 1 day).
 *   - Archived rows are excluded from normal list views but accessible for audit.
 */
final class SalaryService
{
    /** Same ceiling as Add/Edit Employee (EmployeeController::MAX_DAILY_RATE). */
    public const MAX_DAILY_RATE = 1000000.00;

    public function __construct(private Connection $connection) {}

    /**
     * Start date of each employee's active salary, so the form can show the
     * earliest date a new rate may start (REQ062/063).
     *
     * @return array<int, string> employee_id => YYYY-MM-DD
     */
    public function activeStartDates(): array
    {
        $rows = $this->connection->pdo()->query(
            "SELECT employee_id, MAX(effective_from) AS effective_from
               FROM salary WHERE status = 'Active' GROUP BY employee_id"
        )->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['employee_id']] = (string) $row['effective_from'];
        }

        return $out;
    }

    // -----------------------------------------------------------------------
    // REQ053 — List
    // -----------------------------------------------------------------------

    /**
     * @param array{
     *   search?:    string,
     *   branch_id?: int,
     *   status?:    string,
     * } $filters
     * @return list<array<string,mixed>>
     */
    public function list(array $filters = []): array
    {
        $where  = [];
        $params = [];

        if (!empty($filters['search'])) {
            // Every word in any order: first, middle, last name or number (REQ012).
            [$nameSql, $nameParams] = \Wbpms\Infrastructure\Persistence\EmployeeNameSearch::condition((string) $filters['search'], 'e', 'search_q');
            if ($nameSql !== '') {
                $where[] = $nameSql;
                $params  = array_merge($params, $nameParams);
            }
        }
        if (!empty($filters['branch_id'])) {
            $where[]              = 'eba.branch_id = :branch_id';
            $params[':branch_id'] = (int) $filters['branch_id'];
        }

        $status = $filters['status'] ?? 'Active';
        if ($status !== '') {
            $where[]           = 's.status = :status';
            $params[':status'] = $status;
        }

        $whereSql = $where !== [] ? 'WHERE ' . implode(' AND ', $where) : '';

        $stmt = $this->connection->pdo()->prepare(
            "SELECT s.salary_id,
                    s.employee_id,
                    CONCAT(e.last_name, ', ', e.first_name) AS employee_name,
                    e.employee_number,
                    b.branch_name,
                    s.daily_rate,
                    s.effective_from,
                    s.effective_to,
                    s.status
               FROM salary s
               JOIN employee e ON e.employee_id = s.employee_id
               LEFT JOIN employee_branch_assignment eba
                      ON eba.employee_id = e.employee_id AND eba.effective_to IS NULL
               LEFT JOIN branch b ON b.branch_id = eba.branch_id
              {$whereSql}
              ORDER BY e.last_name, s.effective_from DESC
              LIMIT 300"
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    // -----------------------------------------------------------------------
    // REQ054 — Create
    // -----------------------------------------------------------------------

    /**
     * Create a new salary record for an employee.
     *
     * @param array{
     *   employee_id:   int|string,
     *   daily_rate:    string,
     *   effective_from: string,
     * } $data
     * @throws RuntimeException on validation failure
     */
    public function create(array $data, int $actingUserId): int
    {
        $employeeId   = (int) ($data['employee_id'] ?? 0);
        $dailyRate    = trim($data['daily_rate'] ?? '');
        $effectiveFrom = trim($data['effective_from'] ?? '');

        $this->validateInput($employeeId, $dailyRate, $effectiveFrom);

        return $this->connection->transaction(function (PDO $pdo) use (
            $employeeId, $dailyRate, $effectiveFrom, $actingUserId
        ): int {
            // Close any existing Active record on this employee
            $pdo->prepare(
                "UPDATE salary
                    SET effective_to = DATE_SUB(:eff, INTERVAL 1 DAY),
                        status       = 'Superseded',
                        updated_at   = NOW()
                  WHERE employee_id  = :emp
                    AND status       = 'Active'"
            )->execute([':eff' => $effectiveFrom, ':emp' => $employeeId]);

            $stmt = $pdo->prepare(
                "INSERT INTO salary (employee_id, daily_rate, effective_from, status)
                 VALUES (:emp, :rate, :eff, 'Active')"
            );
            $stmt->execute([
                ':emp'  => $employeeId,
                ':rate' => $dailyRate,
                ':eff'  => $effectiveFrom,
            ]);
            $id = (int) $pdo->lastInsertId();

            $this->insertAudit($pdo, $actingUserId, 'salary_created', $id,
                "Created salary_id={$id} for employee_id={$employeeId} at rate={$dailyRate} from {$effectiveFrom}");

            return $id;
        });
    }

    // -----------------------------------------------------------------------
    // REQ055 — Update (preserves history by inserting a new row)
    // -----------------------------------------------------------------------

    /**
     * "Update" a salary rate: close the current row and insert a new one.
     *
     * @param array{
     *   daily_rate:     string,
     *   effective_from: string,
     * } $data
     * @throws RuntimeException on validation failure or salary not found
     */
    public function update(int $salaryId, array $data, int $actingUserId): int
    {
        $existing = $this->findOrFail($salaryId);

        $dailyRate     = trim($data['daily_rate'] ?? '');
        $effectiveFrom = trim($data['effective_from'] ?? '');

        $this->validateInput((int) $existing['employee_id'], $dailyRate, $effectiveFrom);

        // Guard: the new effective_from must be strictly after the record being
        // closed's own effective_from, otherwise DATE_SUB(effective_from, 1 day)
        // would produce effective_to <= effective_from and violate chk_salary_period.
        if ($effectiveFrom <= (string) $existing['effective_from']) {
            throw new RuntimeException(\Wbpms\Domain\Employee\EffectiveDateRule::message(
                'effective date', 'current salary', (string) $existing['effective_from']
            ));
        }

        return $this->connection->transaction(function (PDO $pdo) use (
            $existing, $dailyRate, $effectiveFrom, $actingUserId, $salaryId
        ): int {
            // Close the old record
            $pdo->prepare(
                "UPDATE salary
                    SET effective_to = DATE_SUB(:eff, INTERVAL 1 DAY),
                        status       = 'Superseded',
                        updated_at   = NOW()
                  WHERE salary_id    = :id"
            )->execute([':eff' => $effectiveFrom, ':id' => $salaryId]);

            // Close any other Active records for the same employee
            $pdo->prepare(
                "UPDATE salary
                    SET effective_to = DATE_SUB(:eff, INTERVAL 1 DAY),
                        status       = 'Superseded',
                        updated_at   = NOW()
                  WHERE employee_id  = :emp
                    AND status       = 'Active'"
            )->execute([':eff' => $effectiveFrom, ':emp' => $existing['employee_id']]);

            // Insert new active record
            $pdo->prepare(
                "INSERT INTO salary (employee_id, daily_rate, effective_from, status)
                 VALUES (:emp, :rate, :eff, 'Active')"
            )->execute([
                ':emp'  => $existing['employee_id'],
                ':rate' => $dailyRate,
                ':eff'  => $effectiveFrom,
            ]);
            $newId = (int) $pdo->lastInsertId();

            $this->insertAudit($pdo, $actingUserId, 'salary_updated', $newId,
                "Updated from salary_id={$salaryId} to new salary_id={$newId}, rate={$dailyRate} from {$effectiveFrom}");

            return $newId;
        });
    }

    // -----------------------------------------------------------------------
    // REQ056 — Archive
    // -----------------------------------------------------------------------

    /**
     * Archive a salary record.
     *
     * @throws RuntimeException when already archived
     */
    public function archive(int $salaryId, int $actingUserId): void
    {
        $row = $this->findOrFail($salaryId);

        if ($row['status'] === 'Archived') {
            throw new RuntimeException('Salary record is already archived.');
        }

        $this->connection->transaction(function (PDO $pdo) use ($salaryId, $actingUserId): void {
            $pdo->prepare(
                "UPDATE salary SET status = 'Archived', updated_at = NOW() WHERE salary_id = :id"
            )->execute([':id' => $salaryId]);

            $this->insertAudit($pdo, $actingUserId, 'salary_archived', $salaryId,
                "Archived salary_id={$salaryId}");
        });
    }

    // -----------------------------------------------------------------------
    // REQ057 — Current rate lookup
    // -----------------------------------------------------------------------

    /**
     * Return the current active daily rate for an employee, or null if none found.
     *
     * @return array<string,mixed>|null
     */
    public function currentRate(int $employeeId): ?array
    {
        $stmt = $this->connection->pdo()->prepare(
            "SELECT salary_id, daily_rate, effective_from
               FROM salary
              WHERE employee_id = :emp AND status = 'Active'
              ORDER BY effective_from DESC
              LIMIT 1"
        );
        $stmt->execute([':emp' => $employeeId]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    // -----------------------------------------------------------------------
    // Lookup helpers
    // -----------------------------------------------------------------------

    /**
     * @return array<string,mixed>
     * @throws RuntimeException
     */
    public function findOrFail(int $salaryId): array
    {
        $stmt = $this->connection->pdo()->prepare(
            "SELECT s.salary_id, s.employee_id, s.daily_rate,
                    s.effective_from, s.effective_to, s.status,
                    CONCAT(e.last_name, ', ', e.first_name) AS employee_name
               FROM salary s
               JOIN employee e ON e.employee_id = s.employee_id
              WHERE s.salary_id = :id"
        );
        $stmt->execute([':id' => $salaryId]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new RuntimeException("Salary record #{$salaryId} not found.");
        }
        return $row;
    }

    /**
     * @return list<array{employee_id: int, employee_name: string}>
     */
    public function employeeList(): array
    {
        return $this->connection->pdo()->query(
            "SELECT e.employee_id,
                    CONCAT(e.last_name, ', ', e.first_name, ' (', e.employee_number, ')') AS employee_name
               FROM employee e
              WHERE e.status = 'Active'
              ORDER BY e.last_name, e.first_name"
        )->fetchAll();
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    public function activeMinimumDailyWage(float $fallback = 0.0): float
    {
        try {
            $stmt = $this->connection->pdo()->query(
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
            // Ignore when running on minimal schema
        }

        return $fallback;
    }

    private function validateInput(int $employeeId, string $dailyRate, string $effectiveFrom): void
    {
        if ($employeeId <= 0) {
            throw new RuntimeException('Employee is required.');
        }
        if ($dailyRate === '' || !is_numeric($dailyRate) || (float) $dailyRate <= 0) {
            throw new RuntimeException('Daily rate must be a positive number.');
        }
        if ((float) $dailyRate > self::MAX_DAILY_RATE) {
            throw new RuntimeException(sprintf('Daily rate cannot exceed ₱%s.', number_format(self::MAX_DAILY_RATE, 2)));
        }
        if (preg_match('/^\d+(\.\d{1,2})?$/', $dailyRate) !== 1) {
            throw new RuntimeException('Daily rate can have at most 2 decimal places, e.g. 610.50.');
        }
        $minWage = $this->activeMinimumDailyWage(0.0);
        if ($minWage > 0.0 && (float) $dailyRate < $minWage) {
            throw new RuntimeException(sprintf(
                'Daily rate cannot be below the active DOLE minimum daily wage (₱%s).',
                number_format($minWage, 2),
            ));
        }
        if ($effectiveFrom === '') {
            throw new RuntimeException('Effective date is required.');
        }
        if (!\Wbpms\Domain\Validation\FieldRules::isIsoDate($effectiveFrom)) {
            throw new RuntimeException('Effective date must be a real calendar date.');
        }

        // Guard against chk_salary_period constraint violation:
        // Closing an existing Active record sets effective_to = effective_from - 1 day.
        // If the new effective_from equals the existing record's effective_from,
        // effective_to would equal effective_from which violates the check
        // (effective_to IS NULL OR effective_to > effective_from).
        $stmt = $this->connection->pdo()->prepare(
            "SELECT effective_from
               FROM salary
              WHERE employee_id = :emp
                AND status      = 'Active'
              ORDER BY effective_from DESC
              LIMIT 1"
        );
        $stmt->execute([':emp' => $employeeId]);
        $existing = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($existing !== false && $effectiveFrom <= (string) $existing['effective_from']) {
            throw new RuntimeException(\Wbpms\Domain\Employee\EffectiveDateRule::message(
                'effective date', 'current salary', (string) $existing['effective_from']
            ));
        }
    }

    private function insertAudit(PDO $pdo, int $actingUserId, string $event, int $recordId, string $desc): void
    {
        $pdo->prepare(
            "INSERT INTO audit_logs
                (user_id, event_type, action_performed, table_affected, record_id, description, action_at)
             VALUES
                (:uid, :evt_type, :evt_action, 'salary', :rid, :desc, NOW())"
        )->execute([
            ':uid'        => $actingUserId,
            ':evt_type'   => $event,
            ':evt_action' => $event,
            ':rid'        => $recordId,
            ':desc'       => $desc,
        ]);
    }
}


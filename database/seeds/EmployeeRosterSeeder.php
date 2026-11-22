<?php

declare(strict_types=1);

use Phinx\Seed\AbstractSeed;
use Wbpms\Application\UserService;
use Wbpms\Infrastructure\Database\Connection;
use Wbpms\Infrastructure\Persistence\EmployeeRepository;
use Wbpms\Infrastructure\Persistence\ScheduleRepository;

/**
 * Employee roster import (Light Diamond Enterprises staff).
 *
 * Reads storage/private/employee_roster.csv (gitignored: it holds real
 * personal data, so it never goes into the repo) and creates each employee
 * through the same steps as EmployeeController::store():
 *   employee → branch assignment → schedule assignment → salary →
 *   biometric enrollment → auto-provisioned login account (Requirement 13).
 *
 * Behaviour
 * ---------
 * - Idempotent: rows whose employee_number already exists are skipped, so
 *   the CSV can be filled in gradually and the seeder re-run.
 * - Rows missing required form fields are skipped with the reason printed.
 * - The `shift` column (HH:MM-HH:MM) reuses an existing work_schedule with
 *   the same start/end time, otherwise creates "Day Shift (HH:MM-HH:MM)"
 *   Sun–Fri, Saturday rest (Sabbath), 12:00–13:00 break. Overnight shifts are refused,
 *   matching ScheduleController (they are not supported by the timesheet).
 * - role = HRHead promotes the provisioned account from Employee to HRHead.
 * - Temporary passwords are written to
 *   storage/private/employee_roster_credentials.csv (never to stdout).
 *
 * Run:
 *   vendor/bin/phinx seed:run -s EmployeeRosterSeeder
 */
class EmployeeRosterSeeder extends AbstractSeed
{
    private const NAME_PATTERN = "/^[\p{L}][\p{L} .'\-]{0,48}[\p{L}.]$|^[\p{L}.]$/u";

    public function run(): void
    {
        $root     = dirname(__DIR__, 2);
        $csvPath  = $root . '/storage/private/employee_roster.csv';
        $credPath = $root . '/storage/private/employee_roster_credentials.csv';

        if (!is_file($csvPath)) {
            echo "\n  {$csvPath} not found. Nothing to import.\n\n";
            return;
        }

        $connection   = new Connection([], $this->getAdapter()->getConnection());
        $pdo          = $connection->pdo();
        $employees    = new EmployeeRepository($connection);
        $schedules    = new ScheduleRepository($connection);
        $users        = new UserService($connection);
        $actorId      = $this->fetchActorId();
        $hrHeadRoleId = (int) ($this->fetchRow("SELECT role_id FROM role WHERE role_name = 'HRHead'")['role_id'] ?? 0);

        $created = [];
        $skipped = [];
        $credentials = [];

        foreach ($this->readCsv($csvPath) as $line => $row) {
            $label = trim($row['first_name'] . ' ' . $row['last_name']) . ' (#' . $row['employee_number'] . ')';

            if ($row['employee_number'] !== '' && $employees->employeeNumberExists($row['employee_number'])) {
                $skipped[] = "{$label}: already exists";
                continue;
            }

            $errors = $this->validate($row, $employees);
            $branchId = $this->lookupId('branch', 'branch_id', 'branch_code', $row['branch_code']);
            $deviceId = $this->lookupId('biometric_device', 'device_id', 'device_code', $row['device_code']);
            if ($row['branch_code'] !== '' && $branchId === null) {
                $errors[] = "unknown branch_code '{$row['branch_code']}'";
            }
            if ($row['device_code'] !== '' && $deviceId === null) {
                $errors[] = "unknown device_code '{$row['device_code']}'";
            }
            if ($deviceId !== null && $this->enrollmentTaken($deviceId, $row['enroll_id'])) {
                $errors[] = "enroll_id {$row['enroll_id']} is already active on {$row['device_code']}";
            }

            $shift = $this->parseShift($row['shift']);
            if ($shift === null) {
                $errors[] = "shift '{$row['shift']}' must be HH:MM-HH:MM and end after start (overnight is not supported)";
            }

            if ($errors !== [] || $branchId === null || $deviceId === null || $shift === null) {
                $skipped[] = "{$label} [line {$line}]: " . implode('; ', $errors);
                continue;
            }

            $tempPwd  = $users->generateTemporaryPassword();
            $username = $users->deriveUsername($row['first_name'], $row['last_name']);

            $connection->transaction(function (PDO $pdo) use (
                $row, $employees, $schedules, $users, $actorId, $hrHeadRoleId,
                $branchId, $deviceId, $shift, $username, $tempPwd
            ): void {
                $scheduleId = $this->resolveSchedule($schedules, $shift[0], $shift[1]);

                $employeeId = $employees->createEmployee([
                    'employee_number'   => $row['employee_number'],
                    'employee_type'     => $row['employee_type'],
                    'first_name'        => $row['first_name'],
                    'middle_initial'    => $row['middle_name'] !== '' ? mb_substr($row['middle_name'], 0, 50) : null,
                    'last_name'         => $row['last_name'],
                    'email'             => $row['email'],
                    'contact_number'    => $row['contact_number'],
                    'birthdate'         => $row['birthdate'],
                    'hire_date'         => $row['effective_from'],
                    'position'          => $row['position'],
                    'status'            => 'Active',
                    'sss_number'        => $row['sss_number'] !== '' ? $row['sss_number'] : null,
                    'philhealth_number' => $row['philhealth_number'] !== '' ? $row['philhealth_number'] : null,
                    'pagibig_number'    => $row['pagibig_number'] !== '' ? $row['pagibig_number'] : null,
                    'tin_number'        => $row['tin_number'] !== '' ? $row['tin_number'] : null,
                ]);

                $employees->assignInitialBranch($employeeId, $branchId, $row['effective_from']);
                $employees->assignEffectiveSchedule($employeeId, $scheduleId, $row['effective_from']);
                $employees->createSalary($employeeId, (float) $row['daily_rate'], $row['effective_from']);
                $employees->enrollBiometricCode($employeeId, $deviceId, $row['enroll_id'], $row['effective_from']);

                $userId = $users->provisionForEmployee($pdo, $employeeId, $username, $tempPwd, $actorId, $row['email']);

                if ($row['role'] === 'HRHead' && $hrHeadRoleId > 0) {
                    $pdo->prepare('UPDATE users SET role_id = :role WHERE user_id = :uid')
                        ->execute([':role' => $hrHeadRoleId, ':uid' => $userId]);
                }
            });

            $created[]     = $label;
            $credentials[] = [$row['employee_number'], $row['first_name'] . ' ' . $row['last_name'], $row['email'], $row['role'], $tempPwd];
        }

        if ($credentials !== []) {
            $fh = fopen($credPath, 'ab');
            if ($fh !== false) {
                if (filesize($credPath) === 0) {
                    fputcsv($fh, ['employee_number', 'name', 'login_email', 'role', 'temporary_password'], ',', '"', '');
                }
                foreach ($credentials as $c) {
                    fputcsv($fh, $c, ',', '"', '');
                }
                fclose($fh);
            }
        }

        echo "\n  Created " . count($created) . " employee(s)\n";
        foreach ($created as $c) {
            echo "    + {$c}\n";
        }
        echo "  Skipped " . count($skipped) . " row(s)\n";
        foreach ($skipped as $s) {
            echo "    - {$s}\n";
        }
        if ($credentials !== []) {
            echo "\n  Temporary passwords appended to {$credPath}\n";
        }
        echo "\n";
    }

    /** @return array<int, array<string, string>> keyed by CSV line number */
    private function readCsv(string $path): array
    {
        $fh = fopen($path, 'rb');
        if ($fh === false) {
            throw new RuntimeException("Cannot open {$path}");
        }

        $header = fgetcsv($fh, null, ',', '"', '');
        if ($header === false) {
            fclose($fh);
            return [];
        }
        // Strip a UTF-8 BOM that Excel adds when saving as "CSV UTF-8".
        $header = array_map(static fn($h): string => trim((string) preg_replace('/^\xEF\xBB\xBF/', '', (string) $h)), $header);

        $rows = [];
        $line = 1;
        while (($cols = fgetcsv($fh, null, ',', '"', '')) !== false) {
            $line++;
            if ($cols === [null] || implode('', array_map('strval', $cols)) === '') {
                continue;
            }
            $row = [];
            foreach ($header as $i => $key) {
                $row[$key] = trim((string) ($cols[$i] ?? ''));
            }
            $row['employee_type'] = $row['employee_type'] !== '' ? $row['employee_type'] : 'Regular';
            $row['role']          = $row['role'] !== '' ? $row['role'] : 'Employee';
            $row['birthdate']      = $this->normalizeDate($row['birthdate']);
            $row['effective_from'] = $this->normalizeDate($row['effective_from']);
            $rows[$line] = $row;
        }
        fclose($fh);

        return $rows;
    }

    /** Accept YYYY-MM-DD or MM/DD/YYYY (what Excel shows for PH locale). */
    private function normalizeDate(string $value): string
    {
        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $value, $m)) {
            return sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[1], (int) $m[2]);
        }
        return $value;
    }

    /**
     * Same rules as EmployeeController::validate() for a create.
     *
     * @param array<string, string> $r
     * @return list<string>
     */
    private function validate(array $r, EmployeeRepository $repo): array
    {
        $e = [];
        if ($r['employee_number'] === '' || mb_strlen($r['employee_number']) > 30) {
            $e[] = 'employee_number required (max 30)';
        }
        foreach (['first_name', 'last_name'] as $f) {
            if ($r[$f] === '' || !preg_match(self::NAME_PATTERN, $r[$f])) {
                $e[] = "{$f} missing or invalid";
            }
        }
        if ($r['middle_name'] !== '' && !preg_match(self::NAME_PATTERN, $r['middle_name'])) {
            $e[] = 'middle_name invalid';
        }
        if ($r['email'] === '' || !filter_var($r['email'], FILTER_VALIDATE_EMAIL)) {
            $e[] = 'email missing or invalid';
        } elseif ($repo->emailExists($r['email'])
            || $this->fetchRow("SELECT user_id FROM users WHERE account_email = " . $this->quote($r['email'])) !== false) {
            $e[] = 'email already used';
        }
        if (!$this->isIsoDate($r['birthdate'])) {
            $e[] = 'birthdate missing or invalid';
        }
        if (!preg_match('/^09\d{9}$/', $r['contact_number'])) {
            $e[] = 'contact_number must be 09XXXXXXXXX';
        }
        if ($r['sss_number'] !== '' && !preg_match('/^\d{10}$/', str_replace('-', '', $r['sss_number']))) {
            $e[] = 'sss_number must be 10 digits';
        }
        foreach (['philhealth_number', 'pagibig_number'] as $f) {
            if ($r[$f] !== '' && !preg_match('/^\d{12}$/', str_replace(['-', ' '], '', $r[$f]))) {
                $e[] = "{$f} must be 12 digits";
            }
        }
        if ($r['tin_number'] !== '' && !preg_match('/^\d{9}(\d{3})?$/', str_replace('-', '', $r['tin_number']))) {
            $e[] = 'tin_number must be 9 or 12 digits';
        }
        if ($r['position'] === '' || mb_strlen($r['position']) > 100) {
            $e[] = 'position missing';
        }
        if (!in_array($r['employee_type'], ['Regular', 'Probationary', 'Contractual'], true)) {
            $e[] = 'employee_type must be Regular, Probationary, or Contractual';
        }
        if (!in_array($r['role'], ['Employee', 'HRHead'], true)) {
            $e[] = 'role must be Employee or HRHead';
        }
        $minWage = $repo->activeMinimumDailyWage();
        if (!is_numeric($r['daily_rate']) || (float) $r['daily_rate'] < $minWage || (float) $r['daily_rate'] > 999999.99) {
            $e[] = sprintf('daily_rate missing or below minimum daily wage (%.2f)', $minWage);
        }
        if (!$this->isIsoDate($r['effective_from'])) {
            $e[] = 'effective_from missing or invalid';
        }
        if ($r['branch_code'] === '') {
            $e[] = 'branch_code missing';
        }
        if ($r['device_code'] === '' || $r['enroll_id'] === '') {
            $e[] = 'device_code and enroll_id required';
        }

        return $e;
    }

    private function isIsoDate(string $value): bool
    {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $d !== false && $d->format('Y-m-d') === $value;
    }

    /** @return array{0:string,1:string}|null [start HH:MM:SS, end HH:MM:SS] */
    private function parseShift(string $shift): ?array
    {
        if (!preg_match('/^(\d{1,2}):(\d{2})\s*-\s*(\d{1,2}):(\d{2})$/', $shift, $m)) {
            return null;
        }
        $start = (int) $m[1] * 60 + (int) $m[2];
        $end   = (int) $m[3] * 60 + (int) $m[4];
        if ($end <= $start || $end > 24 * 60 || (int) $m[2] > 59 || (int) $m[4] > 59) {
            return null;
        }
        return [sprintf('%02d:%02d:00', $m[1], $m[2]), sprintf('%02d:%02d:00', $m[3], $m[4])];
    }

    private function resolveSchedule(ScheduleRepository $schedules, string $start, string $end): int
    {
        $row = $this->fetchRow(
            "SELECT schedule_id FROM work_schedule
              WHERE work_start_time = " . $this->quote($start) . "
                AND work_end_time = " . $this->quote($end) . "
                AND status = 'Active'
              ORDER BY schedule_id LIMIT 1"
        );
        if ($row !== false) {
            return (int) $row['schedule_id'];
        }

        $span = (int) ((strtotime("1970-01-01 {$end}") - strtotime("1970-01-01 {$start}")) / 60);

        return $schedules->create([
            'schedule_name'    => 'Day Shift (' . substr($start, 0, 5) . '-' . substr($end, 0, 5) . ')',
            // The owner observes the Seventh-day Sabbath: Saturday is the
            // rest day and Sunday is a regular working day.
            'working_days'     => json_encode(['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday']),
            'rest_days'        => json_encode(['Saturday']),
            'break_minutes'    => 60,
            'grace_minutes'    => 0,
            'overtime_allowed' => 1,
            'break_start_time' => '12:00:00',
            'break_end_time'   => '13:00:00',
            'work_start_time'  => $start,
            'work_end_time'    => $end,
            'standard_minutes' => $span - 60,
            'effective_from'   => '2020-01-01',
            'status'           => 'Active',
        ]);
    }

    private function lookupId(string $table, string $idCol, string $codeCol, string $code): ?int
    {
        if ($code === '') {
            return null;
        }
        $row = $this->fetchRow("SELECT {$idCol} FROM {$table} WHERE {$codeCol} = " . $this->quote($code));
        return $row === false ? null : (int) $row[$idCol];
    }

    private function enrollmentTaken(int $deviceId, string $code): bool
    {
        return $this->fetchRow(
            "SELECT enrollment_id FROM employee_biometric_enrollment
              WHERE device_id = {$deviceId} AND device_employee_code = " . $this->quote($code) . "
                AND status = 'Active'"
        ) !== false;
    }

    /** Audit actor for provisioning: first HRHead, else first BusinessOwner. */
    private function fetchActorId(): int
    {
        $row = $this->fetchRow(
            "SELECT u.user_id FROM users u JOIN role r ON r.role_id = u.role_id
              WHERE r.role_name IN ('HRHead', 'BusinessOwner') AND u.status = 'Active'
              ORDER BY r.role_name = 'HRHead' DESC, u.user_id LIMIT 1"
        );
        if ($row === false) {
            throw new RuntimeException('No active HRHead or BusinessOwner user to attribute the import to.');
        }
        return (int) $row['user_id'];
    }

    private function quote(string $value): string
    {
        return $this->getAdapter()->getConnection()->quote($value);
    }
}

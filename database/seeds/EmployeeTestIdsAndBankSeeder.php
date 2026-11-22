<?php

declare(strict_types=1);

use Phinx\Seed\AbstractSeed;

/**
 * Test data: Government IDs and bank accounts for employees that have none.
 *
 * Fills ONLY what is missing, so real values are never overwritten:
 *   - sss_number, philhealth_number, pagibig_number, tin_number when NULL/empty
 *   - one Active BDO Payroll account when the employee has no Active bank row
 *
 * Every value is fake and derived from employee_id, so it is unique per
 * employee, passes the Add/Edit Employee format checks, and is easy to spot:
 *   SSS        99-0000015-5        (10 digits, starts with 99)
 *   PhilHealth 99-000000015-5      (12 digits, starts with 99)
 *   Pag-IBIG   9999-0000-0015      (12 digits, starts with 9999)
 *   TIN        999-000-015-000     (12 digits, starts with 999)
 *   BDO acct   999900000015        (12 digits, starts with 9999)
 *
 * Refuses to run when APP_ENV is "production" or Phinx targets the
 * "production" environment: fake account numbers must never reach a real
 * BDO payroll upload or a real contribution report.
 *
 * Run (local only):
 *   php vendor/bin/phinx seed:run -c phinx.php -s EmployeeTestIdsAndBankSeeder
 *   php vendor/bin/phinx seed:run -c phinx.php -e testing -s EmployeeTestIdsAndBankSeeder
 */
class EmployeeTestIdsAndBankSeeder extends AbstractSeed
{
    public function run(): void
    {
        if ($this->isProduction()) {
            throw new RuntimeException(
                'EmployeeTestIdsAndBankSeeder writes fake IDs and bank accounts and must not run in production.'
            );
        }

        $employees = $this->fetchAll(
            "SELECT employee_id, first_name, last_name, hire_date,
                    sss_number, philhealth_number, pagibig_number, tin_number,
                    EXISTS(SELECT 1 FROM bank_details b
                            WHERE b.employee_id = e.employee_id
                              AND b.status = 'Active') AS has_bank
               FROM employee e
              ORDER BY employee_id"
        );

        $pdo       = $this->getAdapter()->getConnection();
        $idsFilled = 0;
        $banksAdded = 0;

        $updateIds = $pdo->prepare(
            "UPDATE employee
                SET sss_number        = COALESCE(NULLIF(sss_number, ''), :sss),
                    philhealth_number = COALESCE(NULLIF(philhealth_number, ''), :phil),
                    pagibig_number    = COALESCE(NULLIF(pagibig_number, ''), :pagibig),
                    tin_number        = COALESCE(NULLIF(tin_number, ''), :tin),
                    updated_at        = NOW()
              WHERE employee_id = :id"
        );

        $insertBank = $pdo->prepare(
            "INSERT INTO bank_details
                (employee_id, bank_name, account_name, account_number,
                 account_type, effective_from, effective_to, status,
                 created_at, updated_at)
             VALUES
                (:id, 'BDO', :account_name, :account_number,
                 'Payroll', :effective_from, NULL, 'Active',
                 NOW(), NOW())"
        );

        foreach ($employees as $row) {
            $id = (int) $row['employee_id'];

            $missingId = false;
            foreach (['sss_number', 'philhealth_number', 'pagibig_number', 'tin_number'] as $col) {
                if (trim((string) ($row[$col] ?? '')) === '') {
                    $missingId = true;
                }
            }

            if ($missingId) {
                $values = self::testGovernmentIds($id);
                $updateIds->execute([
                    ':sss'     => $values['sss_number'],
                    ':phil'    => $values['philhealth_number'],
                    ':pagibig' => $values['pagibig_number'],
                    ':tin'     => $values['tin_number'],
                    ':id'      => $id,
                ]);
                $idsFilled++;
            }

            if ((int) $row['has_bank'] === 0) {
                $hireDate = (string) ($row['hire_date'] ?? '');
                $insertBank->execute([
                    ':id'             => $id,
                    ':account_name'   => trim($row['first_name'] . ' ' . $row['last_name']),
                    ':account_number' => self::testAccountNumber($id),
                    ':effective_from' => $hireDate !== '' ? $hireDate : date('Y-m-d'),
                ]);
                $banksAdded++;
            }
        }

        echo "\n  Test Government IDs filled for {$idsFilled} employee(s)."
           . "\n  Test BDO accounts added for {$banksAdded} employee(s).\n\n";
    }

    /**
     * @return array{sss_number: string, philhealth_number: string, pagibig_number: string, tin_number: string}
     */
    public static function testGovernmentIds(int $employeeId): array
    {
        $check = $employeeId % 10;

        return [
            'sss_number'        => sprintf('99-%07d-%d', $employeeId % 10_000_000, $check),
            'philhealth_number' => sprintf('99-%09d-%d', $employeeId, $check),
            'pagibig_number'    => sprintf('9999-%04d-%04d', intdiv($employeeId, 10_000) % 10_000, $employeeId % 10_000),
            'tin_number'        => sprintf('999-%03d-%03d-000', intdiv($employeeId, 1_000) % 1_000, $employeeId % 1_000),
        ];
    }

    public static function testAccountNumber(int $employeeId): string
    {
        return sprintf('9999%08d', $employeeId % 100_000_000);
    }

    private function isProduction(): bool
    {
        $appEnv = strtolower((string) (getenv('APP_ENV') ?: ($_ENV['APP_ENV'] ?? '')));
        if ($appEnv === 'production') {
            return true;
        }

        // $input is a typed property that is unset when the seeder is built
        // outside the Phinx CLI, so read it defensively.
        try {
            $input = $this->getInput();
        } catch (Error) {
            return false;
        }

        return $input->hasOption('environment')
            && strtolower((string) $input->getOption('environment')) === 'production';
    }
}

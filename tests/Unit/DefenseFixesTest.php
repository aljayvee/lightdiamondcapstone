<?php

declare(strict_types=1);

namespace Wbpms\Tests\Unit;

use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Wbpms\Application\AuthenticationPolicy;
use Wbpms\Application\AuthService;
use Wbpms\Domain\Payroll\WithholdingTaxCalculator;
use Wbpms\Http\Controllers\EmployeeController;
use Wbpms\Http\View\Formatter;
use Wbpms\Infrastructure\Database\Connection;
use Wbpms\Infrastructure\Persistence\EmployeeRepository;
use Wbpms\Infrastructure\Persistence\ScheduleRepository;
use Wbpms\Infrastructure\Reports\PdfRenderer;

final class DefenseFixesTest extends TestCase
{
    public function testWithholdingTaxCalculatorBirTrainWeeklyTable(): void
    {
        $calc = new WithholdingTaxCalculator();

        // Exempt up to ₱4,807.69 weekly (₱250,000 / 52)
        self::assertSame(0.0, $calc->computeWeekly(0.0));
        self::assertSame(0.0, $calc->computeWeekly(3500.00));
        self::assertSame(0.0, $calc->computeWeekly(4807.69));

        // Bracket 2 (4,807.69..7,692.31): 15% of excess over 4,807.69
        // At 6,000.00: (6000 - 4807.69) * 0.15 = 1192.31 * 0.15 = 178.85
        self::assertSame(178.85, $calc->computeWeekly(6000.00));
        self::assertSame(432.69, $calc->computeWeekly(7692.31));

        // Bracket 3 (7,692.31..15,384.62): 432.69 + 20% of excess over 7,692.31
        // At 10,000.00: 432.69 + (10000 - 7692.31) * 0.20 = 432.69 + 461.538 = 894.23
        self::assertSame(894.23, $calc->computeWeekly(10000.00));

        // Bracket 4 (15,384.62..38,461.54): 1,971.15 + 25% of excess over 15,384.62
        self::assertSame(3125.00, $calc->computeWeekly(20000.00));
    }

    public function testPasswordStrengthValidationAndNonReuse(): void
    {
        $currentHash = password_hash('TempPass1!', PASSWORD_DEFAULT);

        self::assertSame('Password must be at least 8 characters.', AuthenticationPolicy::validatePasswordStrength('Ab1!'));
        self::assertSame('Password must contain at least one uppercase letter.', AuthenticationPolicy::validatePasswordStrength('lowercase1!'));
        self::assertSame('Password must contain at least one lowercase letter.', AuthenticationPolicy::validatePasswordStrength('UPPERCASE1!'));
        self::assertSame('Password must contain at least one number.', AuthenticationPolicy::validatePasswordStrength('NoDigitsHere!'));
        self::assertSame('Password must contain at least one special character (e.g. !@#$%^&*).', AuthenticationPolicy::validatePasswordStrength('NoSpecial123'));
        self::assertSame(
            'New password cannot be the same as your current or temporary password.',
            AuthenticationPolicy::validatePasswordStrength('TempPass1!', $currentHash),
        );
        self::assertNull(AuthenticationPolicy::validatePasswordStrength('NewStrongPass2@', $currentHash));
    }

    public function testDateFormattingStandardizedOnMmDdYy(): void
    {
        self::assertSame('11/20/26', Formatter::date('2026-11-20'));
        self::assertSame('11/20/26 14:35', Formatter::dateTime('2026-11-20 14:35:00'));
        self::assertSame('11/20/26', PdfRenderer::formatDate('2026-11-20'));
    }

    public function testDeactivatedAndArchivedUsersSurfaceExplicitFailureMessageAfterValidPassword(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE role (role_id INTEGER PRIMARY KEY, role_name TEXT NOT NULL)');
        $pdo->exec('CREATE TABLE users (
            user_id INTEGER PRIMARY KEY,
            username TEXT NOT NULL,
            account_email TEXT NOT NULL,
            password_hash TEXT NOT NULL,
            status TEXT NOT NULL,
            employee_id INTEGER NULL,
            requires_password_change INTEGER NOT NULL DEFAULT 0,
            email_verified_at TEXT NULL,
            email_verification_token_hash TEXT NULL,
            role_id INTEGER NOT NULL
        )');
        $pdo->exec('CREATE TABLE audit_logs (
            audit_id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NULL,
            event_type TEXT NULL,
            action_performed TEXT NULL,
            table_affected TEXT NULL,
            record_id INTEGER NULL,
            attempted_identifier TEXT NULL,
            request_id TEXT NULL,
            ip_address TEXT NULL,
            user_agent TEXT NULL,
            description TEXT NULL,
            action_at TEXT NULL,
            created_at TEXT NULL
        )');

        $hash = password_hash('ValidPass1!', PASSWORD_DEFAULT);
        $pdo->exec("INSERT INTO role VALUES (1, 'HRHead'), (2, 'Employee')");
        $pdo->exec("INSERT INTO users VALUES (10, 'inactive.hr', 'inactive@lde.test', '{$hash}', 'Inactive', 1, 0, '2026-01-01 00:00:00', NULL, 1)");
        $pdo->exec("INSERT INTO users VALUES (11, 'archived.emp', 'archived@lde.test', '{$hash}', 'Archived', 2, 0, '2026-01-01 00:00:00', NULL, 2)");

        $auth = new AuthService(new Connection([], $pdo));

        // Wrong password on inactive user does NOT reveal account status
        self::assertNull($auth->attemptLogin('inactive@lde.test', 'WrongPass1!'));
        self::assertNull($auth->lastFailureMessage());

        // Valid password on inactive user surfaces explicit deactivated message
        self::assertNull($auth->attemptLogin('inactive@lde.test', 'ValidPass1!'));
        self::assertSame(AuthenticationPolicy::INACTIVE_ACCOUNT_MESSAGE, $auth->lastFailureMessage());

        // Valid password on archived user surfaces explicit archived message
        self::assertNull($auth->attemptLogin('archived@lde.test', 'ValidPass1!'));
        self::assertSame(AuthenticationPolicy::ARCHIVED_ACCOUNT_MESSAGE, $auth->lastFailureMessage());
    }

    public function testScheduleDuplicateNameNormalizesWhitespaceAndCase(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE work_schedule (schedule_id INTEGER PRIMARY KEY, schedule_name TEXT NOT NULL)');
        $pdo->exec("INSERT INTO work_schedule VALUES (1, 'Regular Day Shift')");

        $repo = new ScheduleRepository(new Connection([], $pdo));
        self::assertTrue($repo->nameExists('regular   day  shift'));
        self::assertTrue($repo->nameExists('RegularDayShift'));
        self::assertFalse($repo->nameExists('Regular Day Shift', 1));
        self::assertFalse($repo->nameExists('Afternoon Shift'));
    }

    public function testEmployeeValidationEnforcesAgeEffectiveFromMinimumWageAndPositionRules(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE employee (employee_id INTEGER PRIMARY KEY, employee_number TEXT NOT NULL, email TEXT NOT NULL)');
        $pdo->exec('CREATE TABLE employee_biometric_enrollment (enrollment_id INTEGER PRIMARY KEY, employee_id INTEGER NOT NULL, device_id INTEGER NOT NULL, device_employee_code TEXT NOT NULL, status TEXT NOT NULL, effective_to TEXT NULL)');
        $pdo->exec('CREATE TABLE payroll_policy_version (policy_id INTEGER PRIMARY KEY, minimum_daily_wage REAL NOT NULL, status TEXT NOT NULL, effective_from TEXT NOT NULL)');
        $pdo->exec('CREATE TABLE job_position (position_id INTEGER PRIMARY KEY, position_title TEXT NOT NULL, default_daily_rate REAL NULL, requires_regular INTEGER NOT NULL DEFAULT 0)');
        $pdo->exec('CREATE TABLE biometric_device_branch (device_id INTEGER NOT NULL, branch_id INTEGER NOT NULL, status TEXT NOT NULL, effective_from TEXT NOT NULL, effective_to TEXT NULL)');

        $pdo->exec("INSERT INTO payroll_policy_version VALUES (1, 460.00, 'Approved', '2026-01-01')");
        $pdo->exec("INSERT INTO job_position VALUES (1, 'HR Head', 700.00, 1), (2, 'Laborer', 476.00, 0)");
        $pdo->exec("INSERT INTO biometric_device_branch VALUES (1, 1, 'Active', '2020-01-01', NULL)");

        $repo = new EmployeeRepository(new Connection([], $pdo));
        $controller = new EmployeeController();
        $validate = new ReflectionMethod(EmployeeController::class, 'validate');

        $baseData = [
            'employee_number' => 'EMP-2026-099',
            'employee_type' => 'Contractual',
            'first_name' => 'Richie',
            'middle_name' => '',
            'last_name' => 'Tagaduar',
            'email' => 'richie@lde.test',
            'contact_number' => '09171234567',
            'birthdate' => '1800-01-01', // > 100 years old
            'position' => 'HR Head',     // requires_regular = 1
            'hire_date' => '',
            'effective_from' => '2040-02-05', // far future
            'branch_id' => '2',          // device 1 only covers branch 1
            'schedule_id' => '1',
            'device_id' => '1',
            'enrollment_code' => '21',
            'daily_rate' => '350.00',    // below 460 minimum wage
            'philhealth_number' => '',
            'pagibig_number' => '',
            'tin_number' => '',
            'sss_number' => '',
            'status' => 'active',
        ];

        /** @var array<string,string> $errors */
        $errors = $validate->invoke($controller, $baseData, false, $repo, null);

        self::assertArrayHasKey('birthdate', $errors);
        self::assertStringContainsString('100 years', $errors['birthdate']);
        self::assertArrayHasKey('effective_from', $errors);
        self::assertStringContainsString('30 days in the future', $errors['effective_from']);
        self::assertArrayHasKey('daily_rate', $errors);
        self::assertStringContainsString('minimum daily wage', $errors['daily_rate']);
        self::assertArrayHasKey('employee_type', $errors);
        self::assertStringContainsString('requires Regular employment status', $errors['employee_type']);
        self::assertArrayHasKey('device_id', $errors);
        self::assertStringContainsString('not assigned to the employee\'s branch', $errors['device_id']);

        // Valid case with Probationary Laborer on covered branch 1 and rate 476.00
        $validData = array_merge($baseData, [
            'birthdate' => '1998-05-15',
            'position' => 'Laborer',
            'employee_type' => 'Probationary',
            'effective_from' => (new \DateTimeImmutable('today', new \DateTimeZone('Asia/Manila')))->format('Y-m-d'),
            'branch_id' => '1',
            'daily_rate' => '476.00',
        ]);
        /** @var array<string,string> $validErrors */
        $validErrors = $validate->invoke($controller, $validData, false, $repo, null);
        self::assertSame([], $validErrors);
    }
}

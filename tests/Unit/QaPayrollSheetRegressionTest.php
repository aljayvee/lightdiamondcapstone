<?php

declare(strict_types=1);

namespace Wbpms\Tests\Unit;

use PDOException;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Wbpms\Http\Controllers\EmployeeController;
use Wbpms\Http\Controllers\ScheduleController;

/**
 * Regression tests for defects from the "CAPSTONE - PAYROLL" QA sheet that
 * live in controller helpers (exercised through reflection, as the existing
 * AttendanceScheduleWorkdayTest does).
 */
final class QaPayrollSheetRegressionTest extends TestCase
{
    /** @return array<string, string> */
    private function validEmployee(): array
    {
        return [
            'employee_number'   => 'QA-001',
            'employee_type'     => 'Regular',
            'first_name'        => 'Nina',
            'middle_name'       => '',
            'last_name'         => 'Bucio',
            'email'             => 'nina.qa@example.test',
            'contact_number'    => '09171234567',
            'birthdate'         => '2000-01-15',
            'position'          => 'Cashier',
            'hire_date'         => '2026-11-20',
            'effective_from'    => '2026-11-20',
            'branch_id'         => '1',
            'schedule_id'       => '1',
            'device_id'         => '1',
            'enrollment_code'   => '501',
            'daily_rate'        => '500',
            'philhealth_number' => '',
            'pagibig_number'    => '',
            'tin_number'        => '',
            'sss_number'        => '',
            'status'            => 'Active',
        ];
    }

    /**
     * @param array<string, string> $data
     * @return array<string, string>
     */
    private function validateEmployee(array $data, bool $isEdit): array
    {
        $method = new ReflectionMethod(EmployeeController::class, 'validate');
        /** @var array<string, string> $errors */
        $errors = $method->invoke(new EmployeeController(), $data, $isEdit, null, null);
        return $errors;
    }

    // A daily rate of exactly ₱1,000,000.00 is accepted; anything above is rejected.
    public function testDailyRateAcceptsOneMillionButNotMore(): void
    {
        self::assertArrayNotHasKey('daily_rate', $this->validateEmployee(['daily_rate' => '1000000'] + $this->validEmployee(), false));
        self::assertArrayNotHasKey('daily_rate', $this->validateEmployee(['daily_rate' => '1000000.00'] + $this->validEmployee(), true));

        $errors = $this->validateEmployee(['daily_rate' => '1000000.01'] + $this->validEmployee(), false);
        self::assertArrayHasKey('daily_rate', $errors);
        self::assertSame('Daily rate cannot exceed ₱1,000,000.00.', $errors['daily_rate']);
    }

    // An under-age birthdate is reported on the birthdate field with the age and cutoff date.
    public function testUnderAgeBirthdateIsReportedWithAgeAndCutoff(): void
    {
        $today = new \DateTimeImmutable('today', new \DateTimeZone('Asia/Manila'));
        $birthdate = $today->modify('-17 years')->format('Y-m-d');

        $errors = $this->validateEmployee(['birthdate' => $birthdate] + $this->validEmployee(), false);

        self::assertArrayHasKey('birthdate', $errors);
        self::assertStringContainsString('under age (17 years old)', $errors['birthdate']);
        self::assertStringContainsString($today->modify('-18 years')->format('m/d/y'), $errors['birthdate']);

        // Exactly 18 today is allowed.
        $eighteen = $today->modify('-18 years')->format('Y-m-d');
        self::assertArrayNotHasKey('birthdate', $this->validateEmployee(['birthdate' => $eighteen] + $this->validEmployee(), false));
    }

    // QA EMP TC3 — a new employee cannot be saved without biometric enrollment.
    public function testANewEmployeeRequiresBiometricDeviceAndEnrollId(): void
    {
        $data = ['device_id' => '', 'enrollment_code' => ''] + $this->validEmployee();
        $errors = $this->validateEmployee($data, false);

        self::assertArrayHasKey('device_id', $errors);
        self::assertArrayHasKey('enrollment_code', $errors);
        self::assertArrayNotHasKey('device_id', $this->validateEmployee($this->validEmployee(), false));
    }

    // QA EMP TC4 — on edit, a half-filled biometric pair is rejected instead of silently ignored.
    public function testAnEditNeedsBothDeviceAndEnrollId(): void
    {
        $errors = $this->validateEmployee(['enrollment_code' => ''] + $this->validEmployee(), true);
        self::assertArrayHasKey('enrollment_code', $errors);

        $errors = $this->validateEmployee(['device_id' => '', 'enrollment_code' => ''] + $this->validEmployee(), true);
        self::assertArrayNotHasKey('device_id', $errors, 'Leaving both empty keeps the current enrollment.');
    }

    public function testEnrollIdMustBeDigitsAsOnTheDevice(): void
    {
        $errors = $this->validateEmployee(['enrollment_code' => '12a'] + $this->validEmployee(), false);
        self::assertArrayHasKey('enrollment_code', $errors);
        self::assertArrayNotHasKey('enrollment_code', $this->validateEmployee(['enrollment_code' => '00001'] + $this->validEmployee(), false));
    }

    // The employee number is auto-generated (EMP-001, ...), so a create form
    // never reports "Employee number is required". [EMP-020]
    public function testEmployeeNumberIsNotTypedOnCreate(): void
    {
        $errors = $this->validateEmployee(['employee_number' => ''] + $this->validEmployee(), false);
        self::assertArrayNotHasKey('employee_number', $errors);

    }

    // Two employees saved at the same moment: the uq_employee_number clash is
    // shown on the employee number with a "save again" hint. [EMP-020]
    public function testEmployeeNumberClashAsksToSaveAgain(): void
    {
        $method = new ReflectionMethod(EmployeeController::class, 'uniqueConstraintErrors');
        $exception = new PDOException("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'EMP-002' for key 'uq_employee_number'");
        $exception->errorInfo = ['23000', 1062, "Duplicate entry 'EMP-002' for key 'uq_employee_number'"];
        (new \ReflectionProperty(\Exception::class, 'code'))->setValue($exception, '23000');

        /** @var array<string, string> $errors */
        $errors = $method->invoke(new EmployeeController(), $exception);
        self::assertArrayHasKey('employee_number', $errors);
        self::assertStringContainsString('Create Employee again', $errors['employee_number']);
    }

    // Add/Edit Employee must write through the same connection that runs the
    // transaction; otherwise a failed login provisioning left the employee,
    // branch, schedule, salary and Enroll ID rows saved. [EMP-022]
    // (Behaviour was reproduced against MariaDB; this guards the wiring.)
    public function testEmployeeSavesUseTheTransactionConnection(): void
    {
        foreach (['store', 'update'] as $name) {
            $method = new ReflectionMethod(EmployeeController::class, $name);
            $lines  = file((string) $method->getFileName());
            self::assertIsArray($lines);
            $body = implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));

            self::assertStringNotContainsString('$this->makeRepo()', $body, $name);
            self::assertSame(1, substr_count($body, '$this->makeConnection()'), $name);
            self::assertStringContainsString('new EmployeeRepository($connection)', $body, $name);
            self::assertStringContainsString('$connection->transaction(', $body, $name);
        }
    }

    // QA EMP TC2 / TC11 — unique-key clashes become field errors, not an HTTP 500.
    public function testCreateEmployeeConstraintClashesMapToFieldErrors(): void
    {
        $method = new ReflectionMethod(EmployeeController::class, 'uniqueConstraintErrors');
        $cases = [
            'uq_ebe_device_code_from' => 'enrollment_code',
            'uq_users_account_email'  => 'email',
            'uq_employee_email'       => 'email',
            'uq_employee_number'      => 'employee_number',
        ];
        foreach ($cases as $key => $field) {
            $exception = new PDOException("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'x' for key '{$key}'");
            $exception->errorInfo = ['23000', 1062, "Duplicate entry 'x' for key '{$key}'"];
            (new \ReflectionProperty(\Exception::class, 'code'))->setValue($exception, '23000');

            /** @var array<string, string> $errors */
            $errors = $method->invoke(new EmployeeController(), $exception);
            self::assertArrayHasKey($field, $errors, $key);
        }
    }

    // QA SCH TC5 — a name that embeds the shift follows edited times.
    public function testScheduleNameFollowsEditedShiftTimes(): void
    {
        $method = new ReflectionMethod(ScheduleController::class, 'syncShiftTimesInName');
        $controller = new ScheduleController();

        self::assertSame('Day Shift (07:00-16:00)', $method->invoke($controller, 'Day Shift (07:00-16:30)', '07:00', '16:00'));
        self::assertSame('Regular Shift (08:00-17:00)', $method->invoke($controller, 'Regular Shift (07:00 – 16:00)', '08:00', '17:00'));
        self::assertSame('Morning Shift', $method->invoke($controller, 'Morning Shift', '06:00', '15:00'));
    }

    // QA SCH TC4 — the break duration is derived from the break window.
    public function testBreakDurationIsComputedFromTheBreakWindow(): void
    {
        $_POST = [
            'schedule_name' => 'QA Shift', 'time_in' => '07:00', 'time_out' => '16:00',
            'break_minutes' => '999', 'break_start' => '12:00', 'break_end' => '12:45',
            'effective_from' => '2026-01-01',
        ];
        $method = new ReflectionMethod(ScheduleController::class, 'extractScheduleFields');
        /** @var array<string, string> $fields */
        $fields = $method->invoke(new ScheduleController());
        $_POST = [];

        self::assertSame('45', $fields['break_minutes']);
    }

    public function testABreakOutsideTheShiftIsRejected(): void
    {
        $method = new ReflectionMethod(ScheduleController::class, 'validateSchedule');
        /** @var array<string, string> $errors */
        $errors = $method->invoke(new ScheduleController(), [
            'schedule_name' => 'QA Shift', 'work_days' => ['Monday'], 'time_in' => '07:00', 'time_out' => '16:00',
            'break_minutes' => '60', 'break_start' => '16:30', 'break_end' => '17:30',
            'grace_minutes' => '0', 'overtime_allowed' => '1', 'notes' => '',
            'effective_from' => '2026-01-01', 'status' => 'Active',
        ]);
        self::assertArrayHasKey('break_start', $errors);
    }
}

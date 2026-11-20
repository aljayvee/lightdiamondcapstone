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

<?php

declare(strict_types=1);

namespace Wbpms\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Wbpms\Http\Controllers\EmployeeController;

final class EmployeeTypeDropdownTest extends TestCase
{
    public function testAddEditAndRehireViewsOfferOnlyRegularAndContractual(): void
    {
        $root = dirname(__DIR__, 2);

        foreach (['resources/views/hr/employees/edit.php', 'resources/views/hr/employees/rehire.php'] as $rel) {
            $src = file_get_contents($root . '/' . $rel);
            self::assertIsString($src);
            self::assertStringNotContainsString('value="Probationary"', $src, "{$rel} must not offer Probationary.");
            self::assertStringContainsString('value="Regular"', $src);
            self::assertStringContainsString('value="Contractual"', $src);
        }
    }

    public function testValidateRejectsProbationaryOnCreateAndEdit(): void
    {
        $controller = new EmployeeController();
        $validate   = new ReflectionMethod(EmployeeController::class, 'validate');
        $today      = new DateTimeImmutable('today', new DateTimeZone('Asia/Manila'));

        $base = [
            'employee_number'      => 'EMP-099',
            'employee_type'        => 'Probationary',
            'first_name'           => 'Juan',
            'middle_name'          => '',
            'last_name'            => 'Dela Cruz',
            'email'                => 'juan@lde.test',
            'contact_number'       => '09171234567',
            'birthdate'            => '1995-05-15',
            'position'             => 'Laborer',
            'hire_date'            => '',
            'effective_from'       => $today->format('Y-m-d'),
            'contract_review_date' => $today->modify('+6 months')->format('Y-m-d'),
            'branch_id'            => '1',
            'schedule_id'          => '1',
            'device_id'            => '1',
            'enrollment_code'      => '99',
            'daily_rate'           => '500.00',
            'philhealth_number'    => '',
            'pagibig_number'       => '',
            'tin_number'           => '',
            'sss_number'           => '',
            'status'               => 'active',
        ];

        /** @var array<string,string> $createErrors */
        $createErrors = $validate->invoke($controller, $base, false, null, null);
        self::assertArrayHasKey('employee_type', $createErrors);
        self::assertSame('Employee type must be Regular or Contractual.', $createErrors['employee_type']);

        /** @var array<string,string> $regularErrors */
        $regularErrors = $validate->invoke($controller, array_merge($base, ['employee_type' => 'Regular', 'contract_review_date' => '']), false, null, null);
        self::assertArrayNotHasKey('employee_type', $regularErrors);

        /** @var array<string,string> $contractualErrors */
        $contractualErrors = $validate->invoke($controller, array_merge($base, ['employee_type' => 'Contractual']), false, null, null);
        self::assertArrayNotHasKey('employee_type', $contractualErrors);
    }

    public function testValidateRehireRejectsProbationary(): void
    {
        $controller = new EmployeeController();
        $validate   = new ReflectionMethod(EmployeeController::class, 'validateRehire');
        $today      = new DateTimeImmutable('today', new DateTimeZone('Asia/Manila'));

        $base = [
            'rehire_date'          => $today->format('Y-m-d'),
            'branch_id'            => 1,
            'schedule_id'          => 1,
            'device_id'            => 1,
            'enrollment_code'      => '99',
            'daily_rate'           => '500.00',
            'position'             => 'Laborer',
            'employee_type'        => 'Probationary',
            'contract_review_date' => $today->modify('+6 months')->format('Y-m-d'),
            'notes'                => '',
        ];

        /** @var array<string,string> $errors */
        $errors = $validate->invoke($controller, $base, null, null);
        self::assertArrayHasKey('employee_type', $errors);
        self::assertSame('Choose a valid employee type (Regular or Contractual).', $errors['employee_type']);
    }
}

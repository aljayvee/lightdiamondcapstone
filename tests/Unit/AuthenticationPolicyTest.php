<?php

declare(strict_types=1);

namespace Wbpms\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Wbpms\Application\AuthenticationPolicy;

final class AuthenticationPolicyTest extends TestCase
{
    public function testEmployeeWithoutLinkIsRejected(): void
    {
        self::assertTrue(AuthenticationPolicy::isUnlinkedEmployee('Employee', null));
    }

    public function testLinkedEmployeeIsAccepted(): void
    {
        self::assertFalse(AuthenticationPolicy::isUnlinkedEmployee('Employee', 42));
    }

    public function testBusinessOwnerDoesNotRequireEmployeeLink(): void
    {
        self::assertFalse(AuthenticationPolicy::isUnlinkedEmployee('BusinessOwner', null));
    }

    // The owner has no salary: an owner account may not point to an employee profile. [USER-015]
    public function testBusinessOwnerLinkedToEmployeeIsRejected(): void
    {
        self::assertTrue(AuthenticationPolicy::isLinkedOwner('BusinessOwner', 40));
        self::assertFalse(AuthenticationPolicy::isLinkedOwner('BusinessOwner', null));
        self::assertFalse(AuthenticationPolicy::isLinkedOwner('HRHead', 51));
        self::assertFalse(AuthenticationPolicy::isLinkedOwner('Employee', 39));
        self::assertStringContainsString('not on payroll', AuthenticationPolicy::LINKED_OWNER_MESSAGE);
    }

    // Payroll never selects an employee linked to a non-archived owner account. [PAY-032]
    public function testPayrollEligibilityExcludesOwnerLinkedEmployees(): void
    {
        $method = new \ReflectionMethod(\Wbpms\Application\PayrollService::class, 'eligibleEmployees');
        $file   = (string) $method->getFileName();
        $lines  = array_slice((array) file($file), $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1);
        $source = preg_replace('/\s+/', ' ', implode('', $lines)) ?? '';

        self::assertStringContainsString('AND NOT EXISTS ( SELECT 1 FROM users uo JOIN role ro ON ro.role_id = uo.role_id WHERE uo.employee_id = e.employee_id AND ro.role_name = \'BusinessOwner\' AND uo.status <> \'Archived\' )', $source);
    }
}
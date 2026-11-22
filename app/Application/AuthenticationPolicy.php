<?php

declare(strict_types=1);

namespace Wbpms\Application;

/** Central authentication rules shared by login, sessions, and user management. */
final class AuthenticationPolicy
{
    public const UNLINKED_EMPLOYEE_MESSAGE =
        'Your account is not linked to an employee profile. Please contact HR.';

    public const INACTIVE_ACCOUNT_MESSAGE =
        'Your account has been deactivated. Please contact the Business Owner or HR.';

    public const ARCHIVED_ACCOUNT_MESSAGE =
        'Your account has been archived and can no longer be used to sign in.';

    public const UNVERIFIED_EMAIL_MESSAGE =
        'Your email address has not been verified yet. Please check your email for the verification link or ask HR/Business Owner to resend it.';

    public static function isUnlinkedEmployee(string $roleName, ?int $employeeId): bool
    {
        return $roleName === 'Employee' && $employeeId === null;
    }

    public const LINKED_OWNER_MESSAGE =
        'A Business Owner account cannot be linked to an employee profile. The owner is not on payroll and has no salary.';

    /**
     * The Business Owner is not an employee: no employee profile, no salary,
     * never on payroll (users.employee_id is nullable for this reason).
     */
    public static function isLinkedOwner(string $roleName, ?int $employeeId): bool
    {
        return $roleName === 'BusinessOwner' && $employeeId !== null;
    }

    /**
     * Validate password complexity and optional non-reuse against current password hash.
     *
     * Rules:
     *   - At least 8 characters
     *   - At least one uppercase letter (A-Z)
     *   - At least one lowercase letter (a-z)
     *   - At least one digit (0-9)
     *   - At least one special character (non-alphanumeric)
     *   - Must not match $currentPasswordHash when supplied
     *
     * @return string|null Error message if invalid, or null if valid.
     */
    public static function validatePasswordStrength(string $password, ?string $currentPasswordHash = null): ?string
    {
        if (strlen($password) < 8) {
            return 'Password must be at least 8 characters.';
        }
        if (!preg_match('/[A-Z]/', $password)) {
            return 'Password must contain at least one uppercase letter.';
        }
        if (!preg_match('/[a-z]/', $password)) {
            return 'Password must contain at least one lowercase letter.';
        }
        if (!preg_match('/\d/', $password)) {
            return 'Password must contain at least one number.';
        }
        if (!preg_match('/[^A-Za-z0-9]/', $password)) {
            return 'Password must contain at least one special character (e.g. !@#$%^&*).';
        }
        if ($currentPasswordHash !== null && $currentPasswordHash !== '' && password_verify($password, $currentPasswordHash)) {
            return 'New password cannot be the same as your current or temporary password.';
        }

        return null;
    }
}

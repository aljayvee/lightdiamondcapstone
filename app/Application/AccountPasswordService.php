<?php

declare(strict_types=1);

namespace Wbpms\Application;

use Wbpms\Infrastructure\Database\Connection;
use Wbpms\Infrastructure\Persistence\PasswordResetRepository;

/**
 * A signed-in user changes their own password (REQ002).
 *
 * The first-login flow (/change-password) only works while a change is
 * forced; this is the everyday "Change Password" for every role. The current
 * password must be confirmed, and the new one follows the same strength rules
 * as every other password in the system.
 */
final class AccountPasswordService
{
    public function __construct(
        private readonly Connection $connection,
        private readonly PasswordResetRepository $audit,
    ) {
    }

    /**
     * @return array<string, string> field => message; empty when the password was changed
     */
    public function change(
        int $userId,
        string $current,
        string $new,
        string $confirm,
        string $ip,
        string $userAgent,
    ): array {
        $errors = [];
        if ($current === '') {
            $errors['current_password'] = 'Enter your current password.';
        }
        if ($new === '') {
            $errors['password'] = 'Enter a new password.';
        }
        if ($confirm === '') {
            $errors['password_confirm'] = 'Type the new password again.';
        } elseif ($new !== '' && $new !== $confirm) {
            $errors['password_confirm'] = 'The two new passwords do not match.';
        }
        if ($errors !== []) {
            return $errors;
        }

        $stmt = $this->connection->pdo()->prepare('SELECT password_hash FROM users WHERE user_id = :id LIMIT 1');
        $stmt->execute([':id' => $userId]);
        $hash = $stmt->fetchColumn();
        if (!is_string($hash) || !password_verify($current, $hash)) {
            $this->audit->audit($userId, 'password_change_failed', 'Change Password: wrong current password.', $ip, $userAgent);

            return ['current_password' => 'Your current password is not correct.'];
        }

        $strength = AuthenticationPolicy::validatePasswordStrength($new, $hash);
        if ($strength !== null) {
            return ['password' => $strength];
        }

        $this->connection->pdo()->prepare(
            'UPDATE users SET password_hash = :hash, updated_at = UTC_TIMESTAMP() WHERE user_id = :id'
        )->execute([':hash' => password_hash($new, PASSWORD_DEFAULT), ':id' => $userId]);
        $this->audit->audit($userId, 'password_changed', 'Password changed by the user (Change Password).', $ip, $userAgent);

        return [];
    }
}

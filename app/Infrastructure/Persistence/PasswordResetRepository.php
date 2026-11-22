<?php

declare(strict_types=1);

namespace Wbpms\Infrastructure\Persistence;

use PDO;
use Wbpms\Infrastructure\Database\Connection;

/**
 * SQL for the forgot-password code (users.password_reset_* columns,
 * migration 20261121000034). All timestamps are UTC 'Y-m-d H:i:s'.
 */
final class PasswordResetRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * The Active account with this email, with its reset-code state.
     *
     * @return array{user_id:int, username:string, account_email:string,
     *               code_hash:?string, expires_at:?string, attempts:int, sent_at:?string,
     *               send_count:int, window_started_at:?string}|null
     */
    public function findActiveByEmail(string $email): ?array
    {
        $stmt = $this->connection->pdo()->prepare(
            "SELECT user_id, username, account_email,
                    password_reset_code_hash, password_reset_expires_at, password_reset_attempts,
                    password_reset_sent_at, password_reset_send_count, password_reset_window_started_at
               FROM users
              WHERE account_email = :email
                AND status = 'Active'
              LIMIT 1"
        );
        $stmt->execute([':email' => $email]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        $nullable = static fn (mixed $v): ?string => $v !== null ? (string) $v : null;

        return [
            'user_id'           => (int) $row['user_id'],
            'username'          => (string) $row['username'],
            'account_email'     => (string) ($row['account_email'] ?? ''),
            'code_hash'         => $nullable($row['password_reset_code_hash']),
            'expires_at'        => $nullable($row['password_reset_expires_at']),
            'attempts'          => (int) $row['password_reset_attempts'],
            'sent_at'           => $nullable($row['password_reset_sent_at']),
            'send_count'        => (int) $row['password_reset_send_count'],
            'window_started_at' => $nullable($row['password_reset_window_started_at']),
        ];
    }

    public function storeCode(
        int $userId,
        string $hash,
        string $expiresAtUtc,
        string $sentAtUtc,
        int $sendCount,
        string $windowStartedAtUtc
    ): void {
        $this->connection->pdo()->prepare(
            'UPDATE users
                SET password_reset_code_hash         = :hash,
                    password_reset_expires_at        = :expires,
                    password_reset_attempts          = 0,
                    password_reset_sent_at           = :sent,
                    password_reset_send_count        = :cnt,
                    password_reset_window_started_at = :win
              WHERE user_id = :id'
        )->execute([
            ':hash'    => $hash,
            ':expires' => $expiresAtUtc,
            ':sent'    => $sentAtUtc,
            ':cnt'     => $sendCount,
            ':win'     => $windowStartedAtUtc,
            ':id'      => $userId,
        ]);
    }

    /** Drop an undelivered code so the user is not told to use a code that never arrived. */
    public function clearCode(int $userId): void
    {
        $this->connection->pdo()->prepare(
            'UPDATE users
                SET password_reset_code_hash = NULL, password_reset_expires_at = NULL,
                    password_reset_attempts = 0, password_reset_sent_at = NULL
              WHERE user_id = :id'
        )->execute([':id' => $userId]);
    }

    public function incrementAttempts(int $userId): void
    {
        $this->connection->pdo()->prepare(
            'UPDATE users SET password_reset_attempts = password_reset_attempts + 1 WHERE user_id = :id'
        )->execute([':id' => $userId]);
    }

    /**
     * Set the new password and clear the reset code. Entering the emailed code
     * proves the user controls the account email, so it also counts as the
     * first-login email verification (no login OTP is asked afterwards).
     * The daily send count is kept so the cap still holds after a reset.
     */
    public function completeReset(int $userId, string $passwordHash): void
    {
        $this->connection->pdo()->prepare(
            'UPDATE users
                SET password_hash             = :pwd,
                    password_reset_token      = NULL,
                    password_reset_code_hash  = NULL,
                    password_reset_expires_at = NULL,
                    password_reset_attempts   = 0,
                    password_reset_sent_at    = NULL,
                    requires_password_change  = 0,
                    email_verified_at         = COALESCE(email_verified_at, UTC_TIMESTAMP()),
                    login_otp_hash            = NULL,
                    login_otp_expires_at      = NULL,
                    login_otp_attempts        = 0,
                    login_otp_sent_at         = NULL,
                    updated_at                = UTC_TIMESTAMP()
              WHERE user_id = :id'
        )->execute([':pwd' => $passwordHash, ':id' => $userId]);
    }

    public function audit(int $userId, string $eventType, string $description, string $ipAddress, string $userAgent): void
    {
        $this->connection->pdo()->prepare(
            "INSERT INTO audit_logs
                (user_id, event_type, action_performed, table_affected, record_id,
                 ip_address, user_agent, description, action_at, created_at)
             VALUES
                (:uid, :event, :action, 'users', :rid,
                 :ip, :ua, :descr, UTC_TIMESTAMP(), UTC_TIMESTAMP())"
        )->execute([
            ':uid'    => $userId,
            ':event'  => $eventType,
            ':action' => $eventType,
            ':rid'    => $userId,
            ':ip'     => $ipAddress !== '' ? $ipAddress : null,
            ':ua'     => $userAgent !== '' ? mb_substr($userAgent, 0, 500) : null,
            ':descr'  => $description,
        ]);
    }
}

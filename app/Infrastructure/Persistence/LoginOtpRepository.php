<?php

declare(strict_types=1);

namespace Wbpms\Infrastructure\Persistence;

use PDO;
use Wbpms\Infrastructure\Database\Connection;

/**
 * SQL for the first-login email OTP (users.login_otp_* columns, migration
 * 20261121000033). All timestamps are UTC 'Y-m-d H:i:s'.
 */
final class LoginOtpRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return array{user_id:int, username:string, account_email:string, email_verified:bool,
     *               otp_hash:?string, otp_expires_at:?string, otp_attempts:int, otp_sent_at:?string}|null
     */
    public function findState(int $userId): ?array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT user_id, username, account_email, email_verified_at,
                    login_otp_hash, login_otp_expires_at, login_otp_attempts, login_otp_sent_at
               FROM users
              WHERE user_id = :id
              LIMIT 1'
        );
        $stmt->execute([':id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        return [
            'user_id'        => (int) $row['user_id'],
            'username'       => (string) $row['username'],
            'account_email'  => (string) ($row['account_email'] ?? ''),
            'email_verified' => $row['email_verified_at'] !== null,
            'otp_hash'       => $row['login_otp_hash'] !== null ? (string) $row['login_otp_hash'] : null,
            'otp_expires_at' => $row['login_otp_expires_at'] !== null ? (string) $row['login_otp_expires_at'] : null,
            'otp_attempts'   => (int) $row['login_otp_attempts'],
            'otp_sent_at'    => $row['login_otp_sent_at'] !== null ? (string) $row['login_otp_sent_at'] : null,
        ];
    }

    public function storeCode(int $userId, string $hash, string $expiresAtUtc, string $sentAtUtc): void
    {
        $this->connection->pdo()->prepare(
            'UPDATE users
                SET login_otp_hash       = :hash,
                    login_otp_expires_at = :expires,
                    login_otp_attempts   = 0,
                    login_otp_sent_at    = :sent
              WHERE user_id = :id'
        )->execute([':hash' => $hash, ':expires' => $expiresAtUtc, ':sent' => $sentAtUtc, ':id' => $userId]);
    }

    public function clearCode(int $userId): void
    {
        $this->connection->pdo()->prepare(
            'UPDATE users
                SET login_otp_hash = NULL, login_otp_expires_at = NULL,
                    login_otp_attempts = 0, login_otp_sent_at = NULL
              WHERE user_id = :id'
        )->execute([':id' => $userId]);
    }

    public function incrementAttempts(int $userId): void
    {
        $this->connection->pdo()->prepare(
            'UPDATE users SET login_otp_attempts = login_otp_attempts + 1 WHERE user_id = :id'
        )->execute([':id' => $userId]);
    }

    /** Mark the account email verified and clear any OTP / verification-link state. */
    public function markVerified(int $userId): void
    {
        $this->connection->pdo()->prepare(
            'UPDATE users
                SET email_verified_at = UTC_TIMESTAMP(),
                    email_verification_token_hash = NULL,
                    email_verification_expires_at = NULL,
                    login_otp_hash = NULL, login_otp_expires_at = NULL,
                    login_otp_attempts = 0, login_otp_sent_at = NULL,
                    updated_at = UTC_TIMESTAMP()
              WHERE user_id = :id'
        )->execute([':id' => $userId]);
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

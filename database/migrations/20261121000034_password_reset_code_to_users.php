<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Migration: forgot-password by emailed 6-digit code instead of a link.
 *
 *   1. Adds password_reset_code_hash, password_reset_attempts,
 *      password_reset_sent_at, password_reset_send_count and
 *      password_reset_window_started_at to `users` (idempotent).
 *      password_reset_expires_at (migration 20260921000021) is reused as the
 *      code's expiry.
 *   2. Clears password_reset_token / password_reset_expires_at so reset links
 *      emailed before this change stop working. The password_reset_token
 *      column itself is left in place (forward-only; no longer read).
 */
final class PasswordResetCodeToUsers extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasColumn('users', 'password_reset_code_hash')) {
            $this->execute(
                "ALTER TABLE `users`
                    ADD COLUMN password_reset_code_hash VARCHAR(255) NULL DEFAULT NULL
                        COMMENT 'password_hash() of the current forgot-password code'
                        AFTER password_reset_expires_at,
                    ADD COLUMN password_reset_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0
                        COMMENT 'Wrong entries for the current reset code'
                        AFTER password_reset_code_hash,
                    ADD COLUMN password_reset_sent_at DATETIME NULL DEFAULT NULL
                        COMMENT 'UTC time the current reset code was emailed (resend cooldown)'
                        AFTER password_reset_attempts,
                    ADD COLUMN password_reset_send_count TINYINT UNSIGNED NOT NULL DEFAULT 0
                        COMMENT 'Reset codes sent in the current 24-hour window'
                        AFTER password_reset_sent_at,
                    ADD COLUMN password_reset_window_started_at DATETIME NULL DEFAULT NULL
                        COMMENT 'UTC start of the 24-hour reset-code window'
                        AFTER password_reset_send_count"
            );

            $this->execute(
                "UPDATE users
                    SET password_reset_token = NULL,
                        password_reset_expires_at = NULL
                  WHERE password_reset_token IS NOT NULL
                     OR password_reset_expires_at IS NOT NULL"
            );
        }
    }

    public function down(): void
    {
        // Forward-only migration.
    }

    private function hasColumn(string $table, string $column): bool
    {
        $row = $this->fetchRow(
            "SELECT COUNT(*) AS c
               FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = '{$table}'
                AND COLUMN_NAME = '{$column}'"
        );

        return $row !== false && (int) $row['c'] > 0;
    }
}

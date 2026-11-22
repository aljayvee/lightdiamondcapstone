<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Migration: first-login email OTP.
 *
 * After a user replaces their temporary password, WBPMS emails a 6-digit
 * one-time code to the account email. Entering it sets users.email_verified_at.
 * It is asked only once per account: later logins skip it because the email is
 * already verified.
 *
 *   1. Adds login_otp_hash, login_otp_expires_at, login_otp_attempts and
 *      login_otp_sent_at to `users` (idempotent).
 *   2. Migration 20261121000031 back-filled every existing user as verified,
 *      including accounts that never signed in. Accounts still on a temporary
 *      password (requires_password_change = 1) with no pending verification
 *      link are reset to unverified so they get the OTP on their first login.
 *      Accounts that already chose their own password are not touched.
 */
final class AddFirstLoginOtpToUsers extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasColumn('users', 'login_otp_hash')) {
            $this->execute(
                "ALTER TABLE `users`
                    ADD COLUMN login_otp_hash VARCHAR(255) NULL DEFAULT NULL
                        COMMENT 'password_hash() of the current first-login email OTP'
                        AFTER email_verification_expires_at,
                    ADD COLUMN login_otp_expires_at DATETIME NULL DEFAULT NULL
                        COMMENT 'UTC expiry of login_otp_hash'
                        AFTER login_otp_hash,
                    ADD COLUMN login_otp_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0
                        COMMENT 'Wrong entries for the current code'
                        AFTER login_otp_expires_at,
                    ADD COLUMN login_otp_sent_at DATETIME NULL DEFAULT NULL
                        COMMENT 'UTC time the current code was emailed (resend cooldown)'
                        AFTER login_otp_attempts"
            );

            $this->execute(
                "UPDATE users
                    SET email_verified_at = NULL
                  WHERE requires_password_change = 1
                    AND email_verification_token_hash IS NULL"
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

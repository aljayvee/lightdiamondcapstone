<?php

declare(strict_types=1);

namespace Wbpms\Domain\Auth;

use DateTimeImmutable;

/**
 * Forgot-password code rules (pure; no I/O).
 *
 * A 6-digit code is emailed to the account email. It is valid for 15 minutes
 * and for 5 wrong entries; a new code can be requested once a minute, and at
 * most 5 codes per account in 24 hours. The daily cap bounds guessing by
 * re-requesting codes (5 codes x 5 tries = 25 guesses a day out of 1,000,000)
 * and stops anyone flooding a user's inbox.
 *
 * Code format, normalisation and email masking are shared with the
 * first-login OTP (LoginOtpPolicy).
 */
final class PasswordResetCodePolicy
{
    public const TTL_SECONDS             = 900;
    public const MAX_ATTEMPTS            = 5;
    public const RESEND_COOLDOWN_SECONDS = 60;
    public const MAX_SENDS_PER_WINDOW    = 5;
    public const WINDOW_SECONDS          = 86_400;

    public static function expiresAt(DateTimeImmutable $now): DateTimeImmutable
    {
        return $now->modify('+' . self::TTL_SECONDS . ' seconds');
    }

    /** Seconds left before another code may be sent (0 = may send now). */
    public static function secondsUntilResend(?DateTimeImmutable $sentAt, DateTimeImmutable $now): int
    {
        if ($sentAt === null) {
            return 0;
        }

        return max(0, self::RESEND_COOLDOWN_SECONDS - ($now->getTimestamp() - $sentAt->getTimestamp()));
    }

    /** True when the 24-hour window has ended (or never started), so the send count starts over. */
    public static function windowExpired(?DateTimeImmutable $windowStartedAt, DateTimeImmutable $now): bool
    {
        return $windowStartedAt === null
            || $now->getTimestamp() - $windowStartedAt->getTimestamp() >= self::WINDOW_SECONDS;
    }

    /** True when another code may be sent within the daily cap. */
    public static function withinDailyCap(int $sendCount, ?DateTimeImmutable $windowStartedAt, DateTimeImmutable $now): bool
    {
        return self::windowExpired($windowStartedAt, $now) || $sendCount < self::MAX_SENDS_PER_WINDOW;
    }

    public static function check(
        ?string $hash,
        ?DateTimeImmutable $expiresAt,
        int $attempts,
        string $input,
        DateTimeImmutable $now
    ): PasswordResetCodeCheck {
        $code = LoginOtpPolicy::normalize($input);
        if (!LoginOtpPolicy::isWellFormed($code)) {
            return PasswordResetCodeCheck::Malformed;
        }
        if ($hash === null || $hash === '' || $expiresAt === null) {
            return PasswordResetCodeCheck::NoCode;
        }
        if ($attempts >= self::MAX_ATTEMPTS) {
            return PasswordResetCodeCheck::TooManyAttempts;
        }
        if ($now >= $expiresAt) {
            return PasswordResetCodeCheck::Expired;
        }

        return password_verify($code, $hash) ? PasswordResetCodeCheck::Verified : PasswordResetCodeCheck::Wrong;
    }
}

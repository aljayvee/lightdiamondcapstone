<?php

declare(strict_types=1);

namespace Wbpms\Domain\Auth;

use DateTimeImmutable;

/**
 * First-login email OTP rules (pure; no I/O).
 *
 * After a user replaces their temporary password, a 6-digit code is emailed to
 * the account email. It is valid for 10 minutes and for 5 wrong entries; a new
 * code can be requested once a minute.
 */
final class LoginOtpPolicy
{
    public const CODE_LENGTH             = 6;
    public const TTL_SECONDS             = 600;
    public const MAX_ATTEMPTS            = 5;
    public const RESEND_COOLDOWN_SECONDS = 60;

    /** Domain used for system placeholder emails (UserService::provisionForEmployee). */
    private const PLACEHOLDER_DOMAIN = '@lde.local';

    public static function generateCode(): string
    {
        return str_pad((string) random_int(0, 999_999), self::CODE_LENGTH, '0', STR_PAD_LEFT);
    }

    /** Keep digits only, so "123 456" or "123-456" pasted from the email still work. */
    public static function normalize(string $input): string
    {
        return (string) preg_replace('/\D+/', '', $input);
    }

    public static function isWellFormed(string $code): bool
    {
        return preg_match('/^\d{' . self::CODE_LENGTH . '}$/', $code) === 1;
    }

    /** A real address the code can be sent to (not empty, not a system placeholder). */
    public static function isDeliverableEmail(string $email): bool
    {
        $email = trim($email);

        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false
            && !str_ends_with(strtolower($email), self::PLACEHOLDER_DOMAIN);
    }

    /** "aljay@gmail.com" -> "al***@gmail.com" for on-screen hints. */
    public static function maskEmail(string $email): string
    {
        $at = strrpos($email, '@');
        if ($at === false || $at === 0) {
            return '***';
        }
        $local = substr($email, 0, $at);
        $shown = substr($local, 0, min(2, strlen($local)));

        return $shown . str_repeat('*', max(3, strlen($local) - strlen($shown))) . substr($email, $at);
    }

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

    /** True when a code exists that can still be entered (not expired, attempts left). */
    public static function hasUsableCode(
        ?string $hash,
        ?DateTimeImmutable $expiresAt,
        int $attempts,
        DateTimeImmutable $now
    ): bool {
        return $hash !== null && $hash !== ''
            && $expiresAt !== null && $now < $expiresAt
            && $attempts < self::MAX_ATTEMPTS;
    }

    public static function check(
        ?string $hash,
        ?DateTimeImmutable $expiresAt,
        int $attempts,
        string $input,
        DateTimeImmutable $now
    ): LoginOtpCheck {
        $code = self::normalize($input);
        if (!self::isWellFormed($code)) {
            return LoginOtpCheck::Malformed;
        }
        if ($hash === null || $hash === '' || $expiresAt === null) {
            return LoginOtpCheck::NoCode;
        }
        if ($attempts >= self::MAX_ATTEMPTS) {
            return LoginOtpCheck::TooManyAttempts;
        }
        if ($now >= $expiresAt) {
            return LoginOtpCheck::Expired;
        }

        return password_verify($code, $hash) ? LoginOtpCheck::Verified : LoginOtpCheck::Wrong;
    }
}

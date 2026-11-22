<?php

declare(strict_types=1);

namespace Wbpms\Domain\Validation;

/**
 * Server-side field rules shared by forms whose browser checks could be
 * skipped (REQ005, REQ014, REQ024/025, REQ062/063). Each rule returns null
 * when the value is fine, or a message written for the user.
 */
final class FieldRules
{
    public const USERNAME_MAX     = 50;
    public const EMAIL_MAX        = 254;
    public const BANK_DIGITS_MIN  = 10;
    public const BANK_DIGITS_MAX  = 16;

    public static function username(string $value): ?string
    {
        if ($value === '') {
            return 'Username is required.';
        }
        if (mb_strlen($value) < 3 || mb_strlen($value) > self::USERNAME_MAX) {
            return 'Username must be 3 to ' . self::USERNAME_MAX . ' characters.';
        }
        if (preg_match('/^[A-Za-z0-9._-]+$/', $value) !== 1) {
            return 'Username may use letters, numbers, dots, hyphens and underscores only (no spaces).';
        }

        return null;
    }

    public static function email(string $value, string $label = 'Email'): ?string
    {
        if ($value === '') {
            return $label . ' is required.';
        }
        if (mb_strlen($value) > self::EMAIL_MAX) {
            return $label . ' must be ' . self::EMAIL_MAX . ' characters or fewer.';
        }
        if (filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            return 'Enter a valid email address, e.g. juan@example.com.';
        }

        return null;
    }

    /**
     * Philippine bank account numbers are 10–16 digits (BDO 10/12, BPI 10,
     * Metrobank 13 …); hyphens and spaces in between are allowed.
     */
    public static function bankAccountNumber(string $value): ?string
    {
        if ($value === '') {
            return 'Account number is required.';
        }
        if (preg_match('/^[\d\-\s]+$/', $value) !== 1) {
            return 'Account number must contain digits, hyphens, or spaces only.';
        }
        $digits = strlen((string) preg_replace('/\D/', '', $value));
        if ($digits < self::BANK_DIGITS_MIN || $digits > self::BANK_DIGITS_MAX) {
            return 'Account number must have ' . self::BANK_DIGITS_MIN . ' to ' . self::BANK_DIGITS_MAX
                . ' digits (this one has ' . $digits . ').';
        }

        return null;
    }

    public static function maxLength(string $value, int $max, string $label): ?string
    {
        return mb_strlen($value) > $max ? $label . ' must be ' . $max . ' characters or fewer.' : null;
    }

    /** @param list<string> $allowed */
    public static function oneOf(string $value, array $allowed, string $label): ?string
    {
        return in_array($value, $allowed, true) ? null : 'Choose a valid ' . mb_strtolower($label) . '.';
    }

    /** A real calendar date in YYYY-MM-DD (rejects 2026-02-31). */
    public static function isIsoDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }
}

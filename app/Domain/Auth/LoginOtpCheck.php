<?php

declare(strict_types=1);

namespace Wbpms\Domain\Auth;

/** Outcome of checking an entered first-login OTP. */
enum LoginOtpCheck: string
{
    case Verified        = 'verified';
    case Malformed       = 'malformed';
    case NoCode          = 'no_code';
    case Expired         = 'expired';
    case TooManyAttempts = 'too_many_attempts';
    case Wrong           = 'wrong';

    public function message(): string
    {
        return match ($this) {
            self::Verified        => 'Your email address has been verified.',
            self::Malformed       => 'Enter the 6-digit code from the email.',
            self::NoCode          => 'There is no active code. Click "Send a new code".',
            self::Expired         => 'This code has expired. Click "Send a new code".',
            self::TooManyAttempts => 'Too many incorrect codes. Click "Send a new code".',
            self::Wrong           => 'Incorrect code. Please check the email and try again.',
        };
    }
}

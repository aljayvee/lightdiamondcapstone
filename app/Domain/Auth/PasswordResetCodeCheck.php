<?php

declare(strict_types=1);

namespace Wbpms\Domain\Auth;

/** Outcome of checking an entered forgot-password code. */
enum PasswordResetCodeCheck: string
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
            self::Verified        => 'Your password has been reset. You can now log in with your new password.',
            self::Malformed       => 'Enter the 6-digit code from the email.',
            self::NoCode, self::Expired
                                  => 'This code has expired or is no longer valid. Click "Send a new code".',
            self::TooManyAttempts => 'Too many incorrect codes. Click "Send a new code".',
            self::Wrong           => 'Incorrect code. Please check the email and try again.',
        };
    }
}

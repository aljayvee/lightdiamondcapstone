<?php

declare(strict_types=1);

namespace Wbpms\Application;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Wbpms\Domain\Auth\LoginOtpPolicy;
use Wbpms\Domain\Auth\PasswordResetCodeCheck;
use Wbpms\Domain\Auth\PasswordResetCodePolicy;
use Wbpms\Infrastructure\Mail\Mailer;
use Wbpms\Infrastructure\Persistence\PasswordResetRepository;

/**
 * Forgot password by emailed 6-digit code (REQ002; replaced the reset link
 * on 2026-11-21).
 *
 *   1. sendCode($email): if an Active account has that email, email it a new
 *      code (cooldown and daily cap apply). The caller must show the same
 *      page whatever the result, except MAIL_FAILED, so the form does not
 *      reveal which addresses have accounts.
 *   2. resetPassword($email, $code, $newPassword): checks the code and, when
 *      it matches, sets the new password. An unknown email answers Wrong,
 *      exactly like a wrong code for a real account.
 */
final class PasswordResetService
{
    public const SENT          = 'sent';
    public const COOLDOWN      = 'cooldown';
    public const DAILY_LIMIT   = 'daily_limit';
    public const NO_ACCOUNT    = 'no_account';
    public const UNDELIVERABLE = 'undeliverable';
    public const MAIL_FAILED   = 'mail_failed';

    public function __construct(
        private readonly PasswordResetRepository $repository,
        private readonly Mailer $mailer,
    ) {
    }

    /** @return array{status:string, wait:int} */
    public function sendCode(string $email, string $ip = '', string $userAgent = ''): array
    {
        $user = $this->repository->findActiveByEmail($email);
        if ($user === null) {
            return ['status' => self::NO_ACCOUNT, 'wait' => 0];
        }
        if (!LoginOtpPolicy::isDeliverableEmail($user['account_email'])) {
            return ['status' => self::UNDELIVERABLE, 'wait' => 0];
        }

        $now  = $this->now();
        $wait = PasswordResetCodePolicy::secondsUntilResend($this->parse($user['sent_at']), $now);
        if ($wait > 0) {
            return ['status' => self::COOLDOWN, 'wait' => $wait];
        }

        $windowStart = $this->parse($user['window_started_at']);
        if (!PasswordResetCodePolicy::withinDailyCap($user['send_count'], $windowStart, $now)) {
            $this->repository->audit(
                $user['user_id'],
                'password_reset_code_limit',
                'Forgot-password code not sent: daily limit of ' . PasswordResetCodePolicy::MAX_SENDS_PER_WINDOW . ' codes reached.',
                $ip,
                $userAgent
            );

            return ['status' => self::DAILY_LIMIT, 'wait' => 0];
        }

        $newWindow = PasswordResetCodePolicy::windowExpired($windowStart, $now);
        $code      = LoginOtpPolicy::generateCode();
        $this->repository->storeCode(
            $user['user_id'],
            password_hash($code, PASSWORD_DEFAULT),
            PasswordResetCodePolicy::expiresAt($now)->format('Y-m-d H:i:s'),
            $now->format('Y-m-d H:i:s'),
            $newWindow ? 1 : $user['send_count'] + 1,
            ($newWindow ? $now : ($windowStart ?? $now))->format('Y-m-d H:i:s')
        );

        $masked = LoginOtpPolicy::maskEmail($user['account_email']);
        try {
            $this->mailer->send(
                $user['account_email'],
                'WBPMS — Your password reset code',
                $this->buildEmailBody($user['username'], $code)
            );
        } catch (RuntimeException $e) {
            $this->repository->clearCode($user['user_id']);
            error_log('[PasswordResetService] Reset code mail failed for user_id=' . $user['user_id'] . ': ' . $e->getMessage());

            return ['status' => self::MAIL_FAILED, 'wait' => 0];
        }

        $this->repository->audit($user['user_id'], 'password_reset_code_sent', 'Forgot-password code sent to ' . $masked . '.', $ip, $userAgent);

        return ['status' => self::SENT, 'wait' => PasswordResetCodePolicy::RESEND_COOLDOWN_SECONDS];
    }

    /** Caller has already checked that the new password is strong and confirmed. */
    public function resetPassword(
        string $email,
        string $input,
        string $newPassword,
        string $ip = '',
        string $userAgent = ''
    ): PasswordResetCodeCheck {
        $user = $this->repository->findActiveByEmail($email);
        if ($user === null) {
            return LoginOtpPolicy::isWellFormed(LoginOtpPolicy::normalize($input))
                ? PasswordResetCodeCheck::Wrong
                : PasswordResetCodeCheck::Malformed;
        }

        $result = PasswordResetCodePolicy::check(
            $user['code_hash'],
            $this->parse($user['expires_at']),
            $user['attempts'],
            $input,
            $this->now()
        );

        if ($result === PasswordResetCodeCheck::Verified) {
            $this->repository->completeReset($user['user_id'], password_hash($newPassword, PASSWORD_DEFAULT));
            $this->repository->audit($user['user_id'], 'password_reset_completed', 'Password reset with the emailed code.', $ip, $userAgent);
        } elseif ($result === PasswordResetCodeCheck::Wrong) {
            $this->repository->incrementAttempts($user['user_id']);
            $left = PasswordResetCodePolicy::MAX_ATTEMPTS - ($user['attempts'] + 1);
            $this->repository->audit($user['user_id'], 'password_reset_code_failed', "Wrong forgot-password code entered ({$left} attempt(s) left).", $ip, $userAgent);
        }

        return $result;
    }

    private function buildEmailBody(string $username, string $code): string
    {
        $minutes = intdiv(PasswordResetCodePolicy::TTL_SECONDS, 60);

        return <<<TEXT
        Hello {$username},

        A password reset was requested for your WBPMS account.
        Your password reset code is:

            {$code}

        Enter this code on the Reset Password page, together with your new
        password. It expires in {$minutes} minutes.

        If you did not ask to reset your password, ignore this email and do
        not share the code with anyone. Your password stays the same.

        — Light Diamond Enterprises WBPMS
        TEXT;
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    private function parse(?string $utc): ?DateTimeImmutable
    {
        if ($utc === null || $utc === '') {
            return null;
        }
        $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $utc, new DateTimeZone('UTC'));

        return $dt === false ? null : $dt;
    }
}

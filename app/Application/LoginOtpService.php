<?php

declare(strict_types=1);

namespace Wbpms\Application;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Wbpms\Domain\Auth\LoginOtpCheck;
use Wbpms\Domain\Auth\LoginOtpPolicy;
use Wbpms\Infrastructure\Mail\Mailer;
use Wbpms\Infrastructure\Persistence\LoginOtpRepository;

/**
 * First-login email OTP.
 *
 * A user whose account email is not yet verified (users.email_verified_at IS
 * NULL) and who has already replaced their temporary password must enter a
 * 6-digit code emailed to the account email before using the system. Entering
 * it verifies the email, so the step is asked only once per account.
 */
final class LoginOtpService
{
    public const SENT          = 'sent';
    public const ACTIVE        = 'active';
    public const COOLDOWN      = 'cooldown';
    public const UNDELIVERABLE = 'undeliverable';
    public const MAIL_FAILED   = 'mail_failed';
    public const VERIFIED      = 'verified';

    public function __construct(
        private readonly LoginOtpRepository $repository,
        private readonly Mailer $mailer,
    ) {
    }

    /**
     * Send a code unless a usable one already exists (page load / after password change).
     *
     * @return array{status:string, email:string, wait:int}
     */
    public function ensureSent(int $userId, string $ip = '', string $userAgent = ''): array
    {
        return $this->send($userId, false, $ip, $userAgent);
    }

    /**
     * "Send a new code" button: always issues a fresh code, subject to the cooldown.
     *
     * @return array{status:string, email:string, wait:int}
     */
    public function resend(int $userId, string $ip = '', string $userAgent = ''): array
    {
        return $this->send($userId, true, $ip, $userAgent);
    }

    public function verify(int $userId, string $input, string $ip = '', string $userAgent = ''): LoginOtpCheck
    {
        $state = $this->repository->findState($userId);
        if ($state === null) {
            return LoginOtpCheck::NoCode;
        }
        if ($state['email_verified']) {
            return LoginOtpCheck::Verified;
        }

        $result = LoginOtpPolicy::check(
            $state['otp_hash'],
            $this->parse($state['otp_expires_at']),
            $state['otp_attempts'],
            $input,
            $this->now()
        );

        if ($result === LoginOtpCheck::Verified) {
            $this->repository->markVerified($userId);
            $this->repository->audit($userId, 'login_otp_verified', 'First-login email OTP verified; account email marked verified.', $ip, $userAgent);
        } elseif ($result === LoginOtpCheck::Wrong) {
            $this->repository->incrementAttempts($userId);
            $left = LoginOtpPolicy::MAX_ATTEMPTS - ($state['otp_attempts'] + 1);
            $this->repository->audit($userId, 'login_otp_failed', "Wrong first-login OTP entered ({$left} attempt(s) left).", $ip, $userAgent);
        }

        return $result;
    }

    /** Attempts left for the current code (for the on-screen hint). */
    public function attemptsLeft(int $userId): int
    {
        $state = $this->repository->findState($userId);

        return $state === null ? 0 : max(0, LoginOtpPolicy::MAX_ATTEMPTS - $state['otp_attempts']);
    }

    /** @return array{status:string, email:string, wait:int} */
    private function send(int $userId, bool $force, string $ip, string $userAgent): array
    {
        $state = $this->repository->findState($userId);
        if ($state === null) {
            return ['status' => self::UNDELIVERABLE, 'email' => '', 'wait' => 0];
        }

        $masked = LoginOtpPolicy::maskEmail($state['account_email']);
        if ($state['email_verified']) {
            return ['status' => self::VERIFIED, 'email' => $masked, 'wait' => 0];
        }
        if (!LoginOtpPolicy::isDeliverableEmail($state['account_email'])) {
            return ['status' => self::UNDELIVERABLE, 'email' => $masked, 'wait' => 0];
        }

        $now  = $this->now();
        $wait = LoginOtpPolicy::secondsUntilResend($this->parse($state['otp_sent_at']), $now);

        $usable = LoginOtpPolicy::hasUsableCode(
            $state['otp_hash'],
            $this->parse($state['otp_expires_at']),
            $state['otp_attempts'],
            $now
        );
        if (!$force && $usable) {
            return ['status' => self::ACTIVE, 'email' => $masked, 'wait' => $wait];
        }
        if ($wait > 0) {
            return ['status' => self::COOLDOWN, 'email' => $masked, 'wait' => $wait];
        }

        $code = LoginOtpPolicy::generateCode();
        $this->repository->storeCode(
            $userId,
            password_hash($code, PASSWORD_DEFAULT),
            LoginOtpPolicy::expiresAt($now)->format('Y-m-d H:i:s'),
            $now->format('Y-m-d H:i:s')
        );

        try {
            $this->mailer->send(
                $state['account_email'],
                'WBPMS — Your verification code',
                $this->buildEmailBody($state['username'], $code)
            );
        } catch (RuntimeException $e) {
            // Do not leave an undelivered code behind (it would block "Send a new code").
            $this->repository->clearCode($userId);
            error_log('[LoginOtpService] OTP mail failed for user_id=' . $userId . ': ' . $e->getMessage());

            return ['status' => self::MAIL_FAILED, 'email' => $masked, 'wait' => 0];
        }

        $this->repository->audit($userId, 'login_otp_sent', 'First-login email OTP sent to ' . $masked . '.', $ip, $userAgent);

        return ['status' => self::SENT, 'email' => $masked, 'wait' => LoginOtpPolicy::RESEND_COOLDOWN_SECONDS];
    }

    private function buildEmailBody(string $username, string $code): string
    {
        $minutes = intdiv(LoginOtpPolicy::TTL_SECONDS, 60);

        return <<<TEXT
        Hello {$username},

        Your WBPMS verification code is:

            {$code}

        Enter this code on the verification page to finish signing in for the
        first time. It expires in {$minutes} minutes.

        If you did not just sign in to WBPMS, do not share this code and tell
        your HR Head right away.

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

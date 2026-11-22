<?php

declare(strict_types=1);

namespace Wbpms\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Wbpms\Domain\Auth\LoginOtpCheck;
use Wbpms\Domain\Auth\LoginOtpPolicy;

/** First-login email OTP rules. [AUTH-019, AUTH-020] */
final class LoginOtpPolicyTest extends TestCase
{
    private function at(string $utc): DateTimeImmutable
    {
        return new DateTimeImmutable($utc, new DateTimeZone('UTC'));
    }

    #[Test]
    public function generatedCodesAreSixDigitsIncludingLeadingZeros(): void
    {
        for ($i = 0; $i < 200; $i++) {
            $code = LoginOtpPolicy::generateCode();
            self::assertMatchesRegularExpression('/^\d{6}$/', $code);
        }
    }

    #[Test]
    public function pastedCodesWithSpacesOrDashesAreAccepted(): void
    {
        self::assertSame('012345', LoginOtpPolicy::normalize(' 012 345 '));
        self::assertSame('012345', LoginOtpPolicy::normalize('012-345'));
        self::assertTrue(LoginOtpPolicy::isWellFormed('012345'));
        self::assertFalse(LoginOtpPolicy::isWellFormed('12345'));
        self::assertFalse(LoginOtpPolicy::isWellFormed('1234567'));
    }

    #[Test]
    public function correctCodeWithinTenMinutesVerifies(): void
    {
        $sent = $this->at('2026-11-21 02:00:00');
        $hash = password_hash('482913', PASSWORD_DEFAULT);
        $exp  = LoginOtpPolicy::expiresAt($sent);

        self::assertSame(LoginOtpCheck::Verified, LoginOtpPolicy::check($hash, $exp, 0, '482913', $this->at('2026-11-21 02:09:59')));
        self::assertSame(LoginOtpCheck::Verified, LoginOtpPolicy::check($hash, $exp, 4, '482 913', $this->at('2026-11-21 02:01:00')));
    }

    #[Test]
    public function wrongExpiredExhaustedMissingAndMalformedCodesAreRejected(): void
    {
        $sent = $this->at('2026-11-21 02:00:00');
        $hash = password_hash('482913', PASSWORD_DEFAULT);
        $exp  = LoginOtpPolicy::expiresAt($sent);

        self::assertSame(LoginOtpCheck::Wrong, LoginOtpPolicy::check($hash, $exp, 0, '482914', $this->at('2026-11-21 02:01:00')));
        self::assertSame(LoginOtpCheck::Expired, LoginOtpPolicy::check($hash, $exp, 0, '482913', $this->at('2026-11-21 02:10:00')));
        self::assertSame(LoginOtpCheck::TooManyAttempts, LoginOtpPolicy::check($hash, $exp, LoginOtpPolicy::MAX_ATTEMPTS, '482913', $this->at('2026-11-21 02:01:00')));
        self::assertSame(LoginOtpCheck::NoCode, LoginOtpPolicy::check(null, null, 0, '482913', $this->at('2026-11-21 02:01:00')));
        self::assertSame(LoginOtpCheck::Malformed, LoginOtpPolicy::check($hash, $exp, 0, 'abc', $this->at('2026-11-21 02:01:00')));
    }

    #[Test]
    public function resendIsAllowedOnceAMinute(): void
    {
        $sent = $this->at('2026-11-21 02:00:00');

        self::assertSame(0, LoginOtpPolicy::secondsUntilResend(null, $sent));
        self::assertSame(45, LoginOtpPolicy::secondsUntilResend($sent, $this->at('2026-11-21 02:00:15')));
        self::assertSame(0, LoginOtpPolicy::secondsUntilResend($sent, $this->at('2026-11-21 02:01:00')));
    }

    #[Test]
    public function usableCodeNeedsHashTimeAndAttemptsLeft(): void
    {
        $now = $this->at('2026-11-21 02:05:00');
        $exp = $this->at('2026-11-21 02:10:00');

        self::assertTrue(LoginOtpPolicy::hasUsableCode('h', $exp, 4, $now));
        self::assertFalse(LoginOtpPolicy::hasUsableCode('h', $exp, 5, $now));
        self::assertFalse(LoginOtpPolicy::hasUsableCode('h', $now, 0, $now));
        self::assertFalse(LoginOtpPolicy::hasUsableCode(null, $exp, 0, $now));
    }

    #[Test]
    public function placeholderAndInvalidEmailsCannotReceiveCodes(): void
    {
        self::assertTrue(LoginOtpPolicy::isDeliverableEmail('juan.cruz@gmail.com'));
        self::assertFalse(LoginOtpPolicy::isDeliverableEmail('noemail+emp12@lde.local'));
        self::assertFalse(LoginOtpPolicy::isDeliverableEmail(''));
        self::assertFalse(LoginOtpPolicy::isDeliverableEmail('not-an-email'));
    }

    #[Test]
    public function emailIsMaskedForDisplay(): void
    {
        self::assertSame('ju*******@gmail.com', LoginOtpPolicy::maskEmail('juan.cruz@gmail.com'));
        self::assertSame('a***@x.com', LoginOtpPolicy::maskEmail('a@x.com'));
    }
}

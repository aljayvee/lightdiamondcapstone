<?php

declare(strict_types=1);

namespace Wbpms\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Wbpms\Domain\Auth\PasswordResetCodeCheck;
use Wbpms\Domain\Auth\PasswordResetCodePolicy;

/**
 * Forgot password by emailed 6-digit code (replaced the reset link, 2026-11-21).
 */
final class PasswordResetCodePolicyTest extends TestCase
{
    private function at(string $utc): DateTimeImmutable
    {
        return new DateTimeImmutable($utc, new DateTimeZone('UTC'));
    }

    #[Test]
    public function codeIsValidForFifteenMinutes(): void
    {
        $sent    = $this->at('2026-11-21 08:00:00');
        $expires = PasswordResetCodePolicy::expiresAt($sent);
        $hash    = password_hash('042913', PASSWORD_DEFAULT);

        self::assertSame('2026-11-21 08:15:00', $expires->format('Y-m-d H:i:s'));
        self::assertSame(PasswordResetCodeCheck::Verified, PasswordResetCodePolicy::check($hash, $expires, 0, '042913', $this->at('2026-11-21 08:14:59')));
        self::assertSame(PasswordResetCodeCheck::Expired, PasswordResetCodePolicy::check($hash, $expires, 0, '042913', $this->at('2026-11-21 08:15:00')));
    }

    #[Test]
    public function pastedCodesWithSpacesOrDashesAreAccepted(): void
    {
        $hash    = password_hash('123456', PASSWORD_DEFAULT);
        $expires = $this->at('2026-11-21 09:00:00');
        $now     = $this->at('2026-11-21 08:55:00');

        self::assertSame(PasswordResetCodeCheck::Verified, PasswordResetCodePolicy::check($hash, $expires, 0, ' 123 456 ', $now));
        self::assertSame(PasswordResetCodeCheck::Verified, PasswordResetCodePolicy::check($hash, $expires, 0, '123-456', $now));
    }

    #[Test]
    public function wrongExhaustedMissingAndMalformedCodesAreRejected(): void
    {
        $hash    = password_hash('123456', PASSWORD_DEFAULT);
        $expires = $this->at('2026-11-21 09:00:00');
        $now     = $this->at('2026-11-21 08:55:00');

        self::assertSame(PasswordResetCodeCheck::Wrong, PasswordResetCodePolicy::check($hash, $expires, 0, '654321', $now));
        self::assertSame(PasswordResetCodeCheck::Wrong, PasswordResetCodePolicy::check($hash, $expires, 4, '654321', $now));
        self::assertSame(PasswordResetCodeCheck::TooManyAttempts, PasswordResetCodePolicy::check($hash, $expires, 5, '123456', $now));
        self::assertSame(PasswordResetCodeCheck::NoCode, PasswordResetCodePolicy::check(null, null, 0, '123456', $now));
        self::assertSame(PasswordResetCodeCheck::NoCode, PasswordResetCodePolicy::check('', $expires, 0, '123456', $now));
        self::assertSame(PasswordResetCodeCheck::Malformed, PasswordResetCodePolicy::check($hash, $expires, 0, '12345', $now));
        self::assertSame(PasswordResetCodeCheck::Malformed, PasswordResetCodePolicy::check($hash, $expires, 0, '1234567', $now));
        self::assertSame(PasswordResetCodeCheck::Malformed, PasswordResetCodePolicy::check($hash, $expires, 0, 'Array', $now));
        self::assertSame(PasswordResetCodeCheck::Malformed, PasswordResetCodePolicy::check($hash, $expires, 0, str_repeat('9', 4000), $now));
    }

    #[Test]
    public function newCodeOnlyOnceAMinute(): void
    {
        $sent = $this->at('2026-11-21 08:00:00');

        self::assertSame(0, PasswordResetCodePolicy::secondsUntilResend(null, $sent));
        self::assertSame(60, PasswordResetCodePolicy::secondsUntilResend($sent, $sent));
        self::assertSame(1, PasswordResetCodePolicy::secondsUntilResend($sent, $this->at('2026-11-21 08:00:59')));
        self::assertSame(0, PasswordResetCodePolicy::secondsUntilResend($sent, $this->at('2026-11-21 08:01:00')));
    }

    #[Test]
    public function atMostFiveCodesPerTwentyFourHours(): void
    {
        $start = $this->at('2026-11-21 08:00:00');

        self::assertTrue(PasswordResetCodePolicy::withinDailyCap(0, null, $start));
        self::assertTrue(PasswordResetCodePolicy::withinDailyCap(4, $start, $this->at('2026-11-21 20:00:00')));
        self::assertFalse(PasswordResetCodePolicy::withinDailyCap(5, $start, $this->at('2026-11-21 20:00:00')));
        self::assertFalse(PasswordResetCodePolicy::withinDailyCap(5, $start, $this->at('2026-11-22 07:59:59')));
        self::assertTrue(PasswordResetCodePolicy::withinDailyCap(5, $start, $this->at('2026-11-22 08:00:00')));
    }

    #[Test]
    public function windowStartsOverAfterTwentyFourHours(): void
    {
        $start = $this->at('2026-11-21 08:00:00');

        self::assertTrue(PasswordResetCodePolicy::windowExpired(null, $start));
        self::assertFalse(PasswordResetCodePolicy::windowExpired($start, $this->at('2026-11-22 07:59:59')));
        self::assertTrue(PasswordResetCodePolicy::windowExpired($start, $this->at('2026-11-22 08:00:00')));
    }

    #[Test]
    public function expiredAndMissingCodesShareOneMessage(): void
    {
        // An unknown email answers Wrong, so no message may hint that an account exists.
        self::assertSame(PasswordResetCodeCheck::NoCode->message(), PasswordResetCodeCheck::Expired->message());
        self::assertStringContainsString('Send a new code', PasswordResetCodeCheck::TooManyAttempts->message());
    }
}

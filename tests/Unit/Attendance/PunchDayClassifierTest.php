<?php

declare(strict_types=1);

namespace Wbpms\Tests\Unit\Attendance;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Wbpms\Domain\Attendance\PunchDayClassifier;
use Wbpms\Domain\Attendance\PunchDayResult;
use Wbpms\Domain\Attendance\WorkSchedule;

/**
 * Owner logic review C7. Every punch pattern below is taken from the
 * owner's real biometric exports (Jun–Aug 2026, Sep–Dec 2025), where 3+
 * punch days were almost always a second scan at arrival or departure.
 */
final class PunchDayClassifierTest extends TestCase
{
    private function schedule(): WorkSchedule
    {
        return new WorkSchedule(1, '07:00', '16:00', 60, 480, 0, false, '12:00', '13:00');
    }

    /** @param list<string> $times */
    private function classify(array $times): PunchDayResult
    {
        $tz = new DateTimeZone('Asia/Manila');
        return (new PunchDayClassifier())->classify(
            array_map(static fn (string $t): DateTimeImmutable => new DateTimeImmutable("2026-06-10 {$t}", $tz), $times),
            $this->schedule(),
        );
    }

    /** @return array<string, array{list<string>, string, string, string}> */
    public static function realPatterns(): array
    {
        return [
            'two punches'                      => [['07:00', '16:00'], 'Complete', '07:00', '16:00'],
            'second scan at arrival (29 min)'  => [['06:44', '07:13', '16:30'], 'Complete', '06:44', '16:30'],
            'second scan at departure (11 min)' => [['07:14', '16:31', '16:42'], 'Complete', '07:14', '16:42'],
            'triple scan at arrival'           => [['07:14', '07:15', '07:15', '16:57'], 'Complete', '07:14', '16:57'],
            'lunch out and in inside window'   => [['07:00', '12:02', '12:58', '16:00'], 'Complete', '07:00', '16:00'],
            'unexplained mid-morning scan'     => [['07:08', '08:54', '17:07'], 'ReviewRequired', '07:08', '17:07'],
            'three-hour midday gap'            => [['07:02', '11:47', '14:46', '16:32'], 'ReviewRequired', '07:02', '16:32'],
            'lunch out with no lunch in'       => [['07:06', '12:00', '16:37'], 'ReviewRequired', '07:06', '16:37'],
            'scan late in the evening'         => [['07:28', '16:59', '20:43'], 'ReviewRequired', '07:28', '20:43'],
        ];
    }

    /** @param list<string> $times */
    #[DataProvider('realPatterns')]
    public function testRealPunchPatterns(array $times, string $status, string $timeIn, string $timeOut): void
    {
        $result = $this->classify($times);

        self::assertSame($status, $result->status);
        self::assertSame($timeIn, $result->timeIn?->format('H:i'));
        self::assertSame($timeOut, $result->timeOut?->format('H:i'));
    }

    public function testMergedDoubleScanKeepsTheSameMinutesAsAPlainTwoPunchDay(): void
    {
        $merged = $this->classify(['07:14', '16:31', '16:42']);
        $plain  = $this->classify(['07:14', '16:42']);

        self::assertSame([$plain->workedMinutes, $plain->lateMinutes, $plain->undertimeMinutes],
            [$merged->workedMinutes, $merged->lateMinutes, $merged->undertimeMinutes]);
        self::assertContains('REPEATED_SCAN', $merged->flags);
        self::assertNotContains('DUPLICATE_TAP', $merged->flags);
        self::assertNotContains('MULTI_PUNCH_REVIEW', $merged->flags);
    }

    public function testLunchLongerThanTheBreakAllowanceNeedsReview(): void
    {
        $result = $this->classify(['07:00', '12:00', '13:30', '16:00']);

        self::assertSame('ReviewRequired', $result->status);
        self::assertContains('MULTI_PUNCH_REVIEW', $result->flags);
    }

    public function testOutAndInAwayFromTheBreakWindowNeedsReview(): void
    {
        // A 30-minute absence at 09:00 is within the allowance but is not lunch.
        $result = $this->classify(['07:00', '09:00', '09:30', '16:00']);

        self::assertSame('ReviewRequired', $result->status);
    }

    public function testDoubleScanAtArrivalOnlyIsStillIncomplete(): void
    {
        $result = $this->classify(['06:49', '06:59']);

        self::assertSame('Incomplete', $result->status);
        self::assertSame('06:49', $result->timeIn?->format('H:i'));
        self::assertNull($result->timeOut);
        self::assertSame(['INCOMPLETE', 'DUPLICATE_TAP'], $result->flags);
    }

    public function testSinglePunchAfterMidpointIsATimeOut(): void
    {
        $result = $this->classify(['16:57']);

        self::assertSame('Incomplete', $result->status);
        self::assertNull($result->timeIn);
        self::assertSame('16:57', $result->timeOut?->format('H:i'));
        self::assertContains('TIMEOUT_ONLY', $result->flags);
    }

    public function testPunchOrderDoesNotMatter(): void
    {
        $result = $this->classify(['16:30', '06:44', '07:13']);

        self::assertSame('Complete', $result->status);
        self::assertSame('06:44', $result->timeIn?->format('H:i'));
        self::assertSame('16:30', $result->timeOut?->format('H:i'));
    }
}

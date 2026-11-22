<?php

declare(strict_types=1);

namespace Wbpms\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Wbpms\Http\Controllers\ScheduleController;
use Wbpms\Http\View\ViewRenderer;

/**
 * Bugs found in the production error log on 11/21:
 *  - schedule form: `!isset($a, $b)` ran the shift checks with one invalid
 *    time ("Undefined array key 1") and added false errors to valid fields;
 *  - Assign Schedule reported every database error as a date overlap;
 *  - ViewRenderer::render() forced 200 over a 403/404/422 set by the caller.
 */
final class ScheduleFormAndStatusFixesTest extends TestCase
{
    /**
     * @param  array<string, mixed> $overrides
     * @return array<string, string>
     */
    private function validate(array $overrides): array
    {
        $data = $overrides + [
            'schedule_name'  => 'Day Shift',
            'work_days'      => ['Monday', 'Tuesday'],
            'time_in'        => '08:00',
            'time_out'       => '17:00',
            'break_minutes'  => '60',
            'break_start'    => '',
            'break_end'      => '',
            'grace_minutes'  => '0',
            'effective_from' => '2026-11-23',
        ];

        $controller = (new ReflectionClass(ScheduleController::class))->newInstanceWithoutConstructor();
        $method     = new ReflectionMethod(ScheduleController::class, 'validateSchedule');

        $warnings = [];
        set_error_handler(static function (int $no, string $msg) use (&$warnings): bool {
            $warnings[] = $msg;
            return true;
        });
        try {
            /** @var array<string, string> $errors */
            $errors = $method->invoke($controller, $data);
        } finally {
            restore_error_handler();
        }
        self::assertSame([], $warnings, 'validation must not raise PHP warnings');

        return $errors;
    }

    public function testInvalidTimeInWithoutColonFlagsOnlyTimeIn(): void
    {
        $errors = $this->validate(['time_in' => '0800']);

        self::assertSame('Time in must use 24-hour HH:MM format.', $errors['time_in'] ?? null);
        self::assertArrayNotHasKey('time_out', $errors, 'a valid time out must not get "must be after time in"');
        self::assertArrayNotHasKey('break_minutes', $errors, 'break must not be compared with a bogus shift');
    }

    public function testEmptyTimeOutFlagsOnlyTimeOut(): void
    {
        $errors = $this->validate(['time_out' => '']);

        self::assertSame('Time out is required.', $errors['time_out'] ?? null);
        self::assertArrayNotHasKey('time_in', $errors);
        self::assertArrayNotHasKey('break_minutes', $errors);
    }

    public function testValidShiftStillChecksOrderAndBreak(): void
    {
        self::assertSame([], $this->validate([]));

        $reversed = $this->validate(['time_in' => '17:00', 'time_out' => '08:00']);
        self::assertStringStartsWith('Time out must be after time in.', $reversed['time_out'] ?? '');
        self::assertArrayNotHasKey('break_minutes', $reversed, 'no break error on top of the shift error');

        $longBreak = $this->validate(['time_in' => '08:00', 'time_out' => '10:00', 'break_minutes' => '120']);
        self::assertSame('Break duration cannot be equal to or longer than the shift.', $longBreak['break_minutes'] ?? null);
    }

    /** @return array<string, array{int, string}> */
    public static function assignFailures(): array
    {
        return [
            'duplicate start date' => [1062, 'already has a schedule assignment starting on that date'],
            'period check'         => [4025, 'overlaps the employee\'s current schedule period'],
            'foreign key'          => [1452, 'could not be assigned'],
            'anything else'        => [1205, 'could not be assigned'],
        ];
    }

    #[DataProvider('assignFailures')]
    public function testAssignFailureMessageMatchesTheRealCause(int $driverCode, string $expected): void
    {
        $e = new \PDOException('SQLSTATE[23000]: test');
        $e->errorInfo = ['23000', $driverCode, 'test'];

        $method  = new ReflectionMethod(ScheduleController::class, 'assignFailureMessage');
        $message = (string) $method->invoke(null, $e, 'abc123def456');

        self::assertStringContainsString($expected, $message);
        if ($driverCode === 1452 || $driverCode === 1205) {
            self::assertStringNotContainsString('overlap', $message);
            self::assertStringContainsString('abc123def456', $message, 'generic failure gives the log reference');
        }
    }

    /** @return array<string, array{int|bool, int}> */
    public static function statuses(): array
    {
        return [
            'nothing set (CLI)' => [false, 200],
            'default 200'       => [200, 200],
            'forbidden'         => [403, 403],
            'not found'         => [404, 404],
            'validation'        => [422, 422],
            'server error'      => [500, 500],
            'redirect code'     => [302, 200],
        ];
    }

    #[DataProvider('statuses')]
    public function testRenderKeepsAnErrorStatusTheCallerSet(int|bool $current, int $expected): void
    {
        self::assertSame($expected, ViewRenderer::responseStatus($current));
    }
}

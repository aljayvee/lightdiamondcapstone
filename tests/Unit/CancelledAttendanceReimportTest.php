<?php

declare(strict_types=1);

namespace Wbpms\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Wbpms\Domain\Attendance\GeneratedAttendance;
use Wbpms\Infrastructure\Persistence\PdoAttendanceImportGateway;

/**
 * Verifies that re-importing attendance over a previously Cancelled batch
 * updates existing attendance rows in-place and preserves append-only
 * attendance_adjustment audit records instead of triggering FK 1451. [ATT-033]
 */
final class CancelledAttendanceReimportTest extends TestCase
{
    #[Test]
    public function saveGeneratedAttendanceUpdatesCancelledBatchRowInPlaceWithoutDeleting(): void
    {
        $pdo = new RecordingAttendancePdo([
            'attendance_id' => 9807,
            'status'        => 'Cancelled',
            'branch_id'     => 1,
            'batch_status'  => 'Cancelled',
        ]);

        $gateway = new PdoAttendanceImportGateway($pdo);
        $tz = new DateTimeZone('Asia/Manila');
        $generated = new GeneratedAttendance(
            employeeId: 51,
            attendanceDate: '2026-07-29',
            timeIn: new DateTimeImmutable('2026-07-29 07:24:00', $tz),
            timeOut: new DateTimeImmutable('2026-07-29 17:29:00', $tz),
            workedMinutes: 480,
            lateMinutes: 0,
            undertimeMinutes: 0,
            overtimeMinutes: 0,
            punches: [],
            flags: [],
        );

        $gateway->saveGeneratedAttendance(30, $generated);

        $deletes = array_filter(
            $pdo->executed,
            static fn (array $e): bool => str_starts_with(ltrim($e['sql']), 'DELETE'),
        );
        self::assertSame([], $deletes, 'Must never DELETE attendance when replacing a cancelled row.');

        $inserts = array_filter(
            $pdo->executed,
            static fn (array $e): bool => str_starts_with(ltrim($e['sql']), 'INSERT INTO attendance'),
        );
        self::assertSame([], $inserts, 'Must update in-place instead of inserting a duplicate row.');

        $updates = array_values(array_filter(
            $pdo->executed,
            static fn (array $e): bool => str_starts_with(ltrim($e['sql']), 'UPDATE attendance'),
        ));
        self::assertCount(1, $updates);
        self::assertSame(9807, $updates[0]['params'][':id']);
        self::assertSame(30, $updates[0]['params'][':batch_id']);
        self::assertSame(11, $updates[0]['params'][':ba_id']);
        self::assertSame('Complete', $updates[0]['params'][':status']);
        self::assertStringContainsString("source               = 'xls_import'", $updates[0]['sql']);
    }

    #[Test]
    public function saveGeneratedAttendanceReattachesDetachedCancelledRowInPlace(): void
    {
        // Simulates a row detached during same-checksum batch cleanup:
        // import_batch_id IS NULL (so batch_status='manual'), status='Cancelled'.
        $pdo = new RecordingAttendancePdo([
            'attendance_id' => 9807,
            'status'        => 'Cancelled',
            'branch_id'     => 1,
            'batch_status'  => 'manual',
        ]);

        $gateway = new PdoAttendanceImportGateway($pdo);
        $tz = new DateTimeZone('Asia/Manila');
        $generated = new GeneratedAttendance(
            employeeId: 51,
            attendanceDate: '2026-07-29',
            timeIn: new DateTimeImmutable('2026-07-29 07:24:00', $tz),
            timeOut: new DateTimeImmutable('2026-07-29 17:29:00', $tz),
            workedMinutes: 480,
            lateMinutes: 0,
            undertimeMinutes: 0,
            overtimeMinutes: 0,
            punches: [],
            flags: [],
        );

        $gateway->saveGeneratedAttendance(31, $generated);

        $updates = array_values(array_filter(
            $pdo->executed,
            static fn (array $e): bool => str_starts_with(ltrim($e['sql']), 'UPDATE attendance'),
        ));
        self::assertCount(1, $updates);
        self::assertSame(9807, $updates[0]['params'][':id']);
        self::assertSame(31, $updates[0]['params'][':batch_id']);
    }

    #[Test]
    public function createImportBatchGuardsAdjustedRowsAndDetachesBeforeDeletingSupersededBatch(): void
    {
        $pdo = new RecordingAttendancePdo(null);
        $gateway = new PdoAttendanceImportGateway($pdo);

        $checksum = str_repeat('a', 64);
        $batchId = $gateway->createImportBatch(1, 2, 2026, 7, $checksum, 'LDE_XLS_DAILY_LOG_V1');

        self::assertSame(42, $batchId);
        self::assertGreaterThanOrEqual(5, count($pdo->executed));

        // 1) Delete biometric_punch for stale/cancelled batch
        self::assertStringContainsString('DELETE bp FROM biometric_punch bp', $pdo->executed[0]['sql']);
        // 2) Delete only unadjusted attendance rows
        self::assertStringContainsString('DELETE a FROM attendance a', $pdo->executed[1]['sql']);
        self::assertStringContainsString('NOT EXISTS', $pdo->executed[1]['sql']);
        self::assertStringContainsString('attendance_adjustment', $pdo->executed[1]['sql']);
        // 3) Detach remaining adjusted attendance rows before deleting batch row
        self::assertStringContainsString('UPDATE attendance a', $pdo->executed[2]['sql']);
        self::assertStringContainsString("a.status          = 'Cancelled'", $pdo->executed[2]['sql']);
        self::assertStringContainsString('a.import_batch_id = NULL', $pdo->executed[2]['sql']);
        // 4) Delete superseded batch row
        self::assertStringContainsString('DELETE FROM attendance_import_batch', $pdo->executed[3]['sql']);
        // 5) Insert fresh batch
        self::assertStringContainsString('INSERT INTO attendance_import_batch', $pdo->executed[4]['sql']);
    }
}

/**
 * Minimal in-memory PDO double that records executed SQL + bindings and returns
 * canned rows for PdoAttendanceImportGateway queries without needing pdo_sqlite.
 */
final class RecordingAttendancePdo extends PDO
{
    /** @var list<array{sql:string,params:array<string,mixed>}> */
    public array $executed = [];

    /** @var array<string, mixed>|null */
    private ?array $existingAttendanceRow;

    /** @param array<string, mixed>|null $existingAttendanceRow */
    public function __construct(?array $existingAttendanceRow)
    {
        $this->existingAttendanceRow = $existingAttendanceRow;
    }

    /** @param array<mixed> $options */
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new RecordingAttendanceStatement($this, $query, $this->existingAttendanceRow);
    }

    public function lastInsertId(?string $name = null): string|false
    {
        return '42';
    }
}

final class RecordingAttendanceStatement extends PDOStatement
{
    private RecordingAttendancePdo $pdo;
    private string $sql;
    /** @var array<string, mixed>|null */
    private ?array $existingAttendanceRow;

    /** @param array<string, mixed>|null $existingAttendanceRow */
    public function __construct(RecordingAttendancePdo $pdo, string $sql, ?array $existingAttendanceRow)
    {
        $this->pdo = $pdo;
        $this->sql = $sql;
        $this->existingAttendanceRow = $existingAttendanceRow;
    }

    /** @param array<mixed>|null $params */
    public function execute(?array $params = null): bool
    {
        /** @var array<string, mixed> $bound */
        $bound = $params ?? [];
        $this->pdo->executed[] = ['sql' => $this->sql, 'params' => $bound];
        return true;
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        if (str_contains($this->sql, 'FROM employee_branch_assignment')) {
            return ['branch_assignment_id' => 11];
        }
        if (str_contains($this->sql, 'FROM work_schedule')) {
            return [
                'schedule_id'      => 1,
                'work_start_time'  => '07:30:00',
                'work_end_time'    => '16:30:00',
                'break_minutes'    => 60,
                'break_start_time' => '12:00:00',
                'break_end_time'   => '13:00:00',
                'standard_minutes' => 480,
                'grace_minutes'    => 0,
                'overtime_allowed' => 1,
            ];
        }
        if (str_contains($this->sql, 'COALESCE(aib.status')) {
            return $this->existingAttendanceRow ?? false;
        }
        return false;
    }

    public function fetchColumn(int $column = 0): mixed
    {
        if (str_contains($this->sql, 'FROM employee_schedule_assignment')) {
            return 1;
        }
        return false;
    }

    /** @return list<mixed> */
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        return [];
    }
}

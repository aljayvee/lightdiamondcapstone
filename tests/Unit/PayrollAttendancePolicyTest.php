<?php

declare(strict_types=1);

namespace Wbpms\Tests\Unit;

use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Wbpms\Application\PayrollService;
use Wbpms\Infrastructure\Database\Connection;

final class PayrollAttendancePolicyTest extends TestCase
{
    public function testItPaysApprovedLeaveAndRecordsOnlyTrueUnpaidAbsences(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE work_schedule (schedule_id INTEGER PRIMARY KEY, working_days TEXT NOT NULL)');
        $pdo->exec("CREATE TABLE employee_schedule_assignment (employee_id INTEGER NOT NULL, schedule_id INTEGER NOT NULL, effective_from TEXT NOT NULL, effective_to TEXT NULL, status TEXT NOT NULL DEFAULT 'Active')");
        $pdo->exec('CREATE TABLE request_type (request_type_id INTEGER PRIMARY KEY, type_name TEXT NOT NULL)');
        $pdo->exec('CREATE TABLE request (request_id INTEGER PRIMARY KEY, employee_id INTEGER NOT NULL, request_type_id INTEGER NOT NULL, status TEXT NOT NULL)');
        $pdo->exec('CREATE TABLE leave_request_detail (request_id INTEGER PRIMARY KEY, leave_type TEXT NOT NULL, start_date TEXT NOT NULL, end_date TEXT NOT NULL)');
        $pdo->exec('CREATE TABLE holiday_calendar (holiday_date TEXT NOT NULL, status TEXT NOT NULL)');
        $pdo->exec('CREATE TABLE employee_branch_assignment (employee_id INTEGER NOT NULL, branch_id INTEGER NOT NULL, effective_from TEXT NOT NULL, effective_to TEXT NULL)');
        $pdo->exec('CREATE TABLE biometric_device_branch (device_id INTEGER NOT NULL, branch_id INTEGER NOT NULL, effective_from TEXT NOT NULL, effective_to TEXT NULL)');
        $pdo->exec('CREATE TABLE attendance_import_batch (device_id INTEGER NOT NULL, source_year INTEGER NOT NULL, source_month INTEGER NOT NULL, status TEXT NOT NULL)');
        $pdo->exec("INSERT INTO work_schedule VALUES (1, '[\"Monday\",\"Tuesday\",\"Wednesday\",\"Thursday\",\"Friday\"]')");
        $pdo->exec("INSERT INTO employee_schedule_assignment VALUES (61, 1, '2026-01-01', NULL, 'Active')");
        $pdo->exec("INSERT INTO request_type VALUES (1, 'Leave')");
        $pdo->exec("INSERT INTO request VALUES (31, 61, 1, 'Approved')");
        $pdo->exec("INSERT INTO leave_request_detail VALUES (31, 'Sick', '2026-01-07', '2026-01-08')");
        $pdo->exec("INSERT INTO holiday_calendar VALUES ('2026-01-09', 'Active')");
        $pdo->exec("INSERT INTO employee_branch_assignment VALUES (61, 7, '2026-01-01', NULL)");
        $pdo->exec("INSERT INTO biometric_device_branch VALUES (2, 7, '2026-01-01', NULL)");
        $pdo->exec("INSERT INTO attendance_import_batch VALUES (2, 2026, 1, 'Approved')");

        $method = new ReflectionMethod(PayrollService::class, 'attendancePolicyForPeriod');
        $result = $method->invoke(
            new PayrollService(new Connection([])),
            $pdo,
            61,
            '2026-01-05',
            '2026-01-09',
            ['2026-01-06' => true],
        );

        self::assertSame(1, $result['unpaid_absence_days']);
        self::assertSame([
            ['request_id' => 31, 'leave_type' => 'Sick', 'days' => 2],
        ], $result['paid_leave']);
    }

    public function testPartialMonthBatchBoundsAbsenceCoverageToActualPunchDates(): void
    {
        // Mirrors Daily Log - H(20260830_1439)aug.xls which covers 2026-08-01..2026-08-28
        // (punches 2026-08-02..2026-08-28) and omits 2026-08-29..2026-08-31.
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE work_schedule (schedule_id INTEGER PRIMARY KEY, working_days TEXT NOT NULL)');
        $pdo->exec("CREATE TABLE employee_schedule_assignment (employee_id INTEGER NOT NULL, schedule_id INTEGER NOT NULL, effective_from TEXT NOT NULL, effective_to TEXT NULL, status TEXT NOT NULL DEFAULT 'Active')");
        $pdo->exec('CREATE TABLE request_type (request_type_id INTEGER PRIMARY KEY, type_name TEXT NOT NULL)');
        $pdo->exec('CREATE TABLE request (request_id INTEGER PRIMARY KEY, employee_id INTEGER NOT NULL, request_type_id INTEGER NOT NULL, status TEXT NOT NULL)');
        $pdo->exec('CREATE TABLE leave_request_detail (request_id INTEGER PRIMARY KEY, leave_type TEXT NOT NULL, start_date TEXT NOT NULL, end_date TEXT NOT NULL)');
        $pdo->exec('CREATE TABLE holiday_calendar (holiday_date TEXT NOT NULL, status TEXT NOT NULL)');
        $pdo->exec('CREATE TABLE employee_branch_assignment (employee_id INTEGER NOT NULL, branch_id INTEGER NOT NULL, effective_from TEXT NOT NULL, effective_to TEXT NULL)');
        $pdo->exec('CREATE TABLE biometric_device_branch (device_id INTEGER NOT NULL, branch_id INTEGER NOT NULL, effective_from TEXT NOT NULL, effective_to TEXT NULL)');
        $pdo->exec('CREATE TABLE attendance_import_batch (import_batch_id INTEGER PRIMARY KEY, device_id INTEGER NOT NULL, source_year INTEGER NOT NULL, source_month INTEGER NOT NULL, status TEXT NOT NULL)');
        $pdo->exec('CREATE TABLE biometric_punch (punch_id INTEGER PRIMARY KEY AUTOINCREMENT, import_batch_id INTEGER NOT NULL, source_local_at TEXT NOT NULL)');

        $pdo->exec("INSERT INTO work_schedule VALUES (1, '[\"Sunday\",\"Monday\",\"Tuesday\",\"Wednesday\",\"Thursday\",\"Friday\"]')");
        $pdo->exec("INSERT INTO employee_schedule_assignment VALUES (61, 1, '2026-01-01', NULL, 'Active')");
        $pdo->exec("INSERT INTO employee_branch_assignment VALUES (61, 7, '2026-01-01', NULL)");
        $pdo->exec("INSERT INTO biometric_device_branch VALUES (2, 7, '2026-01-01', NULL)");
        $pdo->exec("INSERT INTO attendance_import_batch VALUES (101, 2, 2026, 8, 'Approved')");
        $pdo->exec("INSERT INTO biometric_punch (import_batch_id, source_local_at) VALUES
            (101, '2026-08-02 07:00:00'),
            (101, '2026-08-28 16:00:00')");

        $method = new ReflectionMethod(PayrollService::class, 'attendancePolicyForPeriod');
        // Period 2026-08-28 (Fri) through 2026-09-03 (Thu):
        // 08-28 Fri present, 08-29 Sat rest, 08-30 Sun & 08-31 Mon uncovered after batch max -> 0 absences.
        $res1 = $method->invoke(
            new PayrollService(new Connection([])),
            $pdo,
            61,
            '2026-08-28',
            '2026-09-03',
            ['2026-08-28' => true],
        );
        self::assertSame(0, $res1['unpaid_absence_days']);

        // If employee missed 2026-08-28 (within [08-02, 08-28]), only 08-28 counts as unpaid absence (1), not 08-30/08-31.
        $res2 = $method->invoke(
            new PayrollService(new Connection([])),
            $pdo,
            61,
            '2026-08-28',
            '2026-09-03',
            [],
        );
        self::assertSame(1, $res2['unpaid_absence_days']);

        // Backward compatibility: an approved batch with 0 rows in biometric_punch still covers its full month.
        $pdo->exec("INSERT INTO attendance_import_batch VALUES (102, 2, 2026, 9, 'Approved')");
        $res3 = $method->invoke(
            new PayrollService(new Connection([])),
            $pdo,
            61,
            '2026-09-01',
            '2026-09-03',
            ['2026-09-01' => true],
        );
        self::assertSame(2, $res3['unpaid_absence_days']);
    }
}

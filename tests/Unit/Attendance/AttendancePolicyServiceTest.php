<?php

declare(strict_types=1);

namespace Wbpms\Tests\Unit\Attendance;

use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Wbpms\Application\AttendancePolicyService;
use Wbpms\Infrastructure\Database\Connection;

final class AttendancePolicyServiceTest extends TestCase
{
    public function testItPersistsFlagDetailsAndDoesNotDuplicateARepeatedEvaluation(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->createEvaluationTables($pdo);
        $pdo->exec("INSERT INTO attendance (employee_id, attendance_date, time_in, time_out, late_minutes, status) VALUES
            (42, '2026-08-03', '08:10:00', '17:00:00', 10, 'Complete'),
            (42, '2026-08-04', '08:05:00', '17:00:00', 5, 'Complete'),
            (42, '2026-08-05', '08:15:00', '17:00:00', 15, 'Complete')");

        $service = new AttendancePolicyService(new Connection([], $pdo));
        $created = $service->evaluateEmployee(42, '2026-08-01', '2026-08-10');

        self::assertCount(1, $created);
        self::assertSame('ConsecutiveLate', $created[0]['flag_type']);
        self::assertStringContainsString('HR memorandum required', $created[0]['notes']);
        self::assertSame(
            $created[0]['notes'],
            $pdo->query('SELECT notes FROM attendance_policy_flag')->fetchColumn(),
        );
        self::assertSame([], $service->evaluateEmployee(42, '2026-08-01', '2026-08-10'));
    }

    public function testTheSaturdaySabbathAndHolidaysAreNotCountedAsAbsences(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->createEvaluationTables($pdo);
        $pdo->exec("INSERT INTO work_schedule VALUES (1, '[\"Sunday\",\"Monday\",\"Tuesday\",\"Wednesday\",\"Thursday\",\"Friday\"]')");
        $pdo->exec("INSERT INTO employee_schedule_assignment VALUES (42, 1, '2026-08-01', NULL, 'Active')");
        $pdo->exec("INSERT INTO holiday_calendar VALUES ('2026-08-10', 'Active')");
        // Thu 6 present; Fri 7 absent; Sat 8 rest; Sun 9 absent; Mon 10 holiday; Tue 11 present.
        $pdo->exec("INSERT INTO attendance (employee_id, attendance_date, time_in, time_out, late_minutes, status) VALUES
            (42, '2026-08-06', '07:00:00', '16:00:00', 0, 'Complete'),
            (42, '2026-08-11', '07:00:00', '16:00:00', 0, 'Complete')");

        $created = (new AttendancePolicyService(new Connection([], $pdo)))->evaluateEmployee(42, '2026-08-01', '2026-08-31');

        self::assertSame([], $created, 'Two missed working days around a rest day and a holiday are not three consecutive absences.');
    }

    // QA ATT TC4 — evidence lists exactly the streak, across rest days and holidays.
    public function testLateEvidenceListsExactlyTheStreakDays(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->createEvaluationTables($pdo);
        $pdo->exec("INSERT INTO work_schedule VALUES (1, '[\"Sunday\",\"Monday\",\"Tuesday\",\"Wednesday\",\"Thursday\",\"Friday\"]')");
        $pdo->exec("INSERT INTO employee_schedule_assignment VALUES (42, 1, '2026-08-01', NULL, 'Active')");
        $pdo->exec("INSERT INTO holiday_calendar VALUES ('2026-08-10', 'Active')");
        // Wed 5 late (an older streak), Thu 6 on time, Fri 7 late, Sat 8 rest,
        // Sun 9 late, Mon 10 holiday, Tue 11 late -> streak Fri, Sun, Tue.
        $pdo->exec("INSERT INTO attendance (employee_id, attendance_date, time_in, time_out, late_minutes, status) VALUES
            (42, '2026-08-05', '07:20:00', '16:00:00', 20, 'Complete'),
            (42, '2026-08-06', '06:58:00', '16:00:00', 0, 'Complete'),
            (42, '2026-08-07', '07:05:00', '16:00:00', 5, 'Complete'),
            (42, '2026-08-09', '07:10:00', '16:00:00', 10, 'Complete'),
            (42, '2026-08-11', '07:15:00', '16:00:00', 15, 'Complete')");

        $service = new AttendancePolicyService(new Connection([], $pdo));
        $created = $service->evaluateEmployee(42, '2026-08-01', '2026-08-31');
        self::assertSame('2026-08-11', $created[0]['triggering_date']);

        $method = new ReflectionMethod(AttendancePolicyService::class, 'withLateEvidence');
        $flags = $method->invoke($service, [[
            'flag_type' => 'ConsecutiveLate', 'employee_id' => 42, 'triggering_date' => '2026-08-11',
        ]]);
        self::assertSame(
            '2026-08-07 07:05 (+5 min), 2026-08-09 07:10 (+10 min), 2026-08-11 07:15 (+15 min)',
            $flags[0]['late_dates'],
        );
    }

    public function testEvaluateEmployeeAndLateEvidenceIgnoreCancelledImportBatches(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->createEvaluationTables($pdo);
        $pdo->exec("INSERT INTO attendance_import_batch (import_batch_id, status) VALUES
            (10, 'Cancelled'),
            (11, 'Approved')");
        // Three late rows from a Cancelled batch (even if row status was left 'Complete')
        // must not trigger a ConsecutiveLate flag or leak into late evidence.
        $pdo->exec("INSERT INTO attendance (employee_id, attendance_date, time_in, time_out, late_minutes, status, import_batch_id) VALUES
            (42, '2026-08-03', '07:10:00', '16:00:00', 10, 'Complete', 10),
            (42, '2026-08-04', '07:12:00', '16:00:00', 12, 'Complete', 10),
            (42, '2026-08-05', '07:15:00', '16:00:00', 15, 'Complete', 10)");

        $service = new AttendancePolicyService(new Connection([], $pdo));
        self::assertSame([], $service->evaluateEmployee(42, '2026-08-01', '2026-08-10'));

        $method = new ReflectionMethod(AttendancePolicyService::class, 'withLateEvidence');
        $flags = $method->invoke($service, [[
            'flag_type' => 'ConsecutiveLate',
            'employee_id' => 42,
            'triggering_date' => '2026-08-05',
            'late_dates' => null,
        ]]);
        self::assertNull($flags[0]['late_dates']);

        // Approved batch + manual (NULL batch) rows still evaluate normally.
        $pdo->exec("INSERT INTO attendance (employee_id, attendance_date, time_in, time_out, late_minutes, status, import_batch_id) VALUES
            (42, '2026-08-10', '07:08:00', '16:00:00', 8, 'Complete', 11),
            (42, '2026-08-11', '07:09:00', '16:00:00', 9, 'Complete', 11),
            (42, '2026-08-12', '07:11:00', '16:00:00', 11, 'Approved', NULL)");
        $created = $service->evaluateEmployee(42, '2026-08-01', '2026-08-15');
        self::assertCount(1, $created);
        self::assertSame('2026-08-12', $created[0]['triggering_date']);

        $liveFlags = $method->invoke($service, [[
            'flag_type' => 'ConsecutiveLate',
            'employee_id' => 42,
            'triggering_date' => '2026-08-12',
        ]]);
        self::assertSame(
            '2026-08-10 07:08 (+8 min), 2026-08-11 07:09 (+9 min), 2026-08-12 07:11 (+11 min)',
            $liveFlags[0]['late_dates'],
        );
    }

    /** @return void */
    private function createEvaluationTables(PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE attendance (employee_id INTEGER, attendance_date TEXT, time_in TEXT, time_out TEXT, late_minutes INTEGER, status TEXT, import_batch_id INTEGER NULL)');
        $pdo->exec('CREATE TABLE attendance_import_batch (import_batch_id INTEGER PRIMARY KEY, status TEXT NOT NULL)');
        $pdo->exec('CREATE TABLE attendance_policy_flag (
            flag_id INTEGER PRIMARY KEY AUTOINCREMENT,
            employee_id INTEGER NOT NULL,
            flag_type TEXT NOT NULL,
            triggering_date TEXT NOT NULL,
            status TEXT NOT NULL,
            notes TEXT NULL,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            UNIQUE (employee_id, flag_type, triggering_date)
        )');
        $pdo->exec('CREATE TABLE request (request_id INTEGER PRIMARY KEY, employee_id INTEGER, status TEXT)');
        $pdo->exec('CREATE TABLE leave_request_detail (request_id INTEGER PRIMARY KEY, start_date TEXT, end_date TEXT)');
        $pdo->exec('CREATE TABLE holiday_calendar (holiday_date TEXT, status TEXT)');
        $pdo->exec('CREATE TABLE work_schedule (schedule_id INTEGER PRIMARY KEY, working_days TEXT)');
        $pdo->exec('CREATE TABLE employee_schedule_assignment (employee_id INTEGER, schedule_id INTEGER, effective_from TEXT, effective_to TEXT, status TEXT)');
    }

    public function testItReconstructsEveryAbsenceDateForAwolEvidence(): void
    {
        $method = new ReflectionMethod(AttendancePolicyService::class, 'withAbsenceEvidenceDates');
        $flags = $method->invoke(new AttendancePolicyService(new Connection([])), [[
            'flag_type' => 'ConsecutiveAWOL',
            'triggering_date' => '2026-08-06',
        ], [
            'flag_type' => 'TwoWeekAbsence',
            'triggering_date' => '2026-08-15',
        ]]);

        self::assertSame('2026-08-04, 2026-08-05, 2026-08-06', $flags[0]['absence_dates']);
        self::assertSame(
            '2026-08-02, 2026-08-03, 2026-08-04, 2026-08-05, 2026-08-06, 2026-08-07, 2026-08-08, 2026-08-09, 2026-08-10, 2026-08-11, 2026-08-12, 2026-08-13, 2026-08-14, 2026-08-15',
            $flags[1]['absence_dates'],
        );
    }

    public function testHrCanIssueOneEmployeeNoticeWhenReviewingAFlag(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE attendance_policy_flag (
            flag_id INTEGER PRIMARY KEY,
            employee_id INTEGER NOT NULL,
            status TEXT NOT NULL,
            action_taken TEXT NULL,
            notes TEXT NULL,
            reviewed_by INTEGER NULL,
            reviewed_at TEXT NULL,
            updated_at TEXT NULL
        )');
        $pdo->exec('CREATE TABLE employee_hr_notice (
            notice_id INTEGER PRIMARY KEY AUTOINCREMENT,
            employee_id INTEGER NOT NULL,
            policy_flag_id INTEGER NOT NULL UNIQUE,
            issued_by INTEGER NOT NULL,
            title TEXT NOT NULL,
            body TEXT NOT NULL,
            issued_at TEXT NOT NULL,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )');
        $pdo->exec('CREATE TABLE audit_logs (
            user_id INTEGER,
            event_type TEXT,
            action_performed TEXT,
            table_affected TEXT,
            record_id INTEGER,
            description TEXT,
            action_at TEXT,
            created_at TEXT
        )');
        $pdo->exec("INSERT INTO attendance_policy_flag (flag_id, employee_id, status) VALUES (7, 42, 'Pending')");

        $service = new AttendancePolicyService(new Connection([], $pdo));
        $service->review(7, 9, [
            'status' => 'Reviewed',
            'action_taken' => 'Memorandum issued',
            'notes' => 'Reviewed attendance evidence.',
            'notice_title' => 'Attendance memorandum',
            'notice_body' => 'Please review your attendance record and coordinate with HR.',
        ]);

        self::assertSame('Reviewed', $pdo->query('SELECT status FROM attendance_policy_flag WHERE flag_id = 7')->fetchColumn());
        self::assertSame('Attendance memorandum', $pdo->query('SELECT title FROM employee_hr_notice')->fetchColumn());
        self::assertSame(2, (int) $pdo->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already been issued');
        $service->review(7, 9, [
            'status' => 'Closed',
            'action_taken' => 'Memorandum issued',
            'notes' => '',
            'notice_title' => 'Attendance memorandum',
            'notice_body' => 'Duplicate notice.',
        ]);
    }
}

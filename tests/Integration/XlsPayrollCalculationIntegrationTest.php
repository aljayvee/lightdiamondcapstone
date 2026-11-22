<?php

declare(strict_types=1);

namespace Wbpms\Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use Wbpms\Application\Attendance\AttendancePunchMatch;
use Wbpms\Application\AttendanceAdjustmentService;
use Wbpms\Application\AttendancePolicyService;
use Wbpms\Application\PayrollService;
use Wbpms\Domain\Attendance\Parsing\ParsedPunch;
use Wbpms\Domain\Attendance\TimesheetGenerator;
use Wbpms\Infrastructure\Database\Connection;
use Wbpms\Infrastructure\Persistence\PdoAttendanceImportGateway;

/**
 * End-to-end MariaDB/MySQL integration tests verifying real .xls attendance
 * reconciliation and PayrollService::computeRun() accuracy across historical
 * salaries/schedules, overlapping rows, DOLE unworked Regular Holidays, worked
 * rest-day holidays, holiday OT rounding, and unassigned break-window fallback.
 */
final class XlsPayrollCalculationIntegrationTest extends IntegrationTestCase
{
    public function testComputeRunDeduplicatesOverlappingScheduleAndSalaryHistory(): void
    {
        $b = $this->seedRun('2025-12-19', '2025-12-25', '2025-12-26');
        $this->pdo->exec("INSERT INTO salary (employee_id, daily_rate, effective_from, effective_to, status, created_by) VALUES ({$b['empId']}, 460, '2025-01-01', '2025-12-31', 'Superseded', {$b['userId']})");
        $this->pdo->exec("INSERT INTO salary (employee_id, daily_rate, effective_from, effective_to, status, created_by) VALUES ({$b['empId']}, 500, '2025-12-01', NULL, 'Active', {$b['userId']})");
        $this->pdo->exec("INSERT INTO employee_schedule_assignment (employee_id, schedule_id, effective_from, effective_to, status) VALUES ({$b['empId']}, {$b['schedId']}, '2025-01-01', '2025-12-31', 'Archived')");
        $this->pdo->exec("INSERT INTO employee_schedule_assignment (employee_id, schedule_id, effective_from, effective_to, status) VALUES ({$b['empId']}, {$b['schedId']}, '2025-12-01', NULL, 'Active')");
        $this->insertAttendance($b['empId'], $b['baId'], $b['schedId'], '2025-12-22', '07:00:00', '16:00:00', 480, 0, 0, 0, 'Complete');

        $svc = new PayrollService(new Connection([], $this->pdo));
        $svc->computeRun($b['runId'], $b['userId']);

        $row = $this->pdo->query("SELECT COUNT(*) AS cnt, MAX(gross_pay) AS gross FROM payroll WHERE payroll_run_id = {$b['runId']}")->fetch();
        self::assertSame(1, (int) $row['cnt']);
        self::assertSame(500.00, (float) $row['gross']);
        $this->assertEarningsMatchGrossPay($b['runId']);
    }

    public function testComputeRunIncludesRehiredEmployeeWithArchivedHistoricalSalary(): void
    {
        $b = $this->seedRun('2025-12-19', '2025-12-25', '2025-12-26');
        // Historical 2025 salary closed as Archived during archive/rehire lifecycle.
        $this->pdo->exec("INSERT INTO salary (employee_id, daily_rate, effective_from, effective_to, status, created_by) VALUES ({$b['empId']}, 460, '2025-01-01', '2025-12-31', 'Archived', {$b['userId']})");
        $this->pdo->exec("INSERT INTO salary (employee_id, daily_rate, effective_from, effective_to, status, created_by) VALUES ({$b['empId']}, 520, '2026-03-01', NULL, 'Active', {$b['userId']})");
        $this->pdo->exec("INSERT INTO employee_schedule_assignment (employee_id, schedule_id, effective_from, effective_to, status) VALUES ({$b['empId']}, {$b['schedId']}, '2025-01-01', NULL, 'Active')");
        $this->insertAttendance($b['empId'], $b['baId'], $b['schedId'], '2025-12-22', '07:00:00', '16:00:00', 480, 0, 0, 0, 'Complete');

        $svc = new PayrollService(new Connection([], $this->pdo));
        $svc->computeRun($b['runId'], $b['userId']);

        $row = $this->pdo->query("SELECT daily_rate_snapshot, gross_pay FROM payroll WHERE payroll_run_id = {$b['runId']}")->fetch();
        self::assertNotFalse($row);
        self::assertSame(460.00, (float) $row['daily_rate_snapshot']);
        self::assertSame(460.00, (float) $row['gross_pay']);
        $this->assertEarningsMatchGrossPay($b['runId']);
    }

    public function testComputeRunSkipsUnworkedRegularHolidayWhenIncompleteAttendanceExists(): void
    {
        // Mirrors june.xls 2026-06-12 Independence Day Friday AM-only lone punch.
        $b = $this->seedRun('2026-06-12', '2026-06-18', '2026-06-19');
        $this->pdo->exec("INSERT INTO salary (employee_id, daily_rate, effective_from, effective_to, status, created_by) VALUES ({$b['empId']}, 500, '2025-01-01', NULL, 'Active', {$b['userId']})");
        $this->pdo->exec("INSERT INTO employee_schedule_assignment (employee_id, schedule_id, effective_from, effective_to, status) VALUES ({$b['empId']}, {$b['schedId']}, '2025-01-01', NULL, 'Active')");
        $this->pdo->exec("INSERT INTO holiday_calendar (holiday_date, description, holiday_type, pay_multiplier, status) VALUES ('2026-06-12', 'Independence Day', 'Regular', 2.00, 'Active')");
        $this->insertAttendance($b['empId'], $b['baId'], $b['schedId'], '2026-06-11', '07:00:00', '16:00:00', 480, 0, 0, 0, 'Complete');
        $this->insertAttendance($b['empId'], $b['baId'], $b['schedId'], '2026-06-12', '06:51:00', null, 0, 0, 0, 0, 'Incomplete');
        $this->insertAttendance($b['empId'], $b['baId'], $b['schedId'], '2026-06-14', '07:00:00', '16:00:00', 480, 0, 0, 0, 'Complete');

        $svc = new PayrollService(new Connection([], $this->pdo));
        $svc->computeRun($b['runId'], $b['userId']);

        $gross = (float) $this->pdo->query("SELECT gross_pay FROM payroll WHERE payroll_run_id = {$b['runId']}")->fetchColumn();
        self::assertSame(500.00, $gross);
        $this->assertEarningsMatchGrossPay($b['runId']);
    }

    public function testComputeRunAppliesDolePrecedingWorkdayRuleForChristmasDay(): void
    {
        $b = $this->seedRun('2025-12-19', '2025-12-25', '2025-12-26');
        $u = substr(uniqid(), -6);
        $this->pdo->exec("INSERT INTO employee (employee_number, employee_type, first_name, last_name, email, hire_date, position, status) VALUES ('E2{$u}', 'Regular', 'Eligible', 'Worker', 'e2{$u}@t.test', '2020-01-01', 'Staff', 'Active')");
        $emp2 = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO employee_branch_assignment (employee_id, branch_id, effective_from, effective_to, created_at) VALUES ({$emp2}, {$b['branchId']}, '2020-01-01', NULL, NOW())");
        $ba2 = (int) $this->pdo->lastInsertId();

        foreach ([$b['empId'], $emp2] as $eid) {
            $this->pdo->exec("INSERT INTO salary (employee_id, daily_rate, effective_from, effective_to, status, created_by) VALUES ({$eid}, 500, '2025-01-01', NULL, 'Active', {$b['userId']})");
            $this->pdo->exec("INSERT INTO employee_schedule_assignment (employee_id, schedule_id, effective_from, effective_to, status) VALUES ({$eid}, {$b['schedId']}, '2025-01-01', NULL, 'Active')");
        }
        $this->pdo->exec("INSERT INTO holiday_calendar (holiday_date, description, holiday_type, pay_multiplier, status) VALUES ('2025-12-24', 'Christmas Eve', 'SpecialNonWorking', 1.30, 'Active'), ('2025-12-25', 'Christmas Day', 'Regular', 2.00, 'Active')");

        // Emp 1: worked 12/19 only, absent 12/23 & 12/24 -> NOT eligible for unworked 12/25 (gross = 500).
        $this->insertAttendance($b['empId'], $b['baId'], $b['schedId'], '2025-12-19', '07:00:00', '16:00:00', 480, 0, 0, 0, 'Complete');
        // Emp 2: worked 12/23, took 12/24 SNW off -> ELIGIBLE for unworked 12/25 (gross = 500 + 500 = 1000).
        $this->insertAttendance($emp2, $ba2, $b['schedId'], '2025-12-23', '07:00:00', '16:00:00', 480, 0, 0, 0, 'Complete');

        $svc = new PayrollService(new Connection([], $this->pdo));
        $svc->computeRun($b['runId'], $b['userId']);

        $gross1 = (float) $this->pdo->query("SELECT gross_pay FROM payroll WHERE payroll_run_id = {$b['runId']} AND employee_id = {$b['empId']}")->fetchColumn();
        $gross2 = (float) $this->pdo->query("SELECT gross_pay FROM payroll WHERE payroll_run_id = {$b['runId']} AND employee_id = {$emp2}")->fetchColumn();
        self::assertSame(500.00, $gross1);
        self::assertSame(1000.00, $gross2);
        $this->assertEarningsMatchGrossPay($b['runId']);
    }

    public function testComputeRunDoesNotDoublePayZeroWorkedApprovedRegularHoliday(): void
    {
        $b = $this->seedRun('2025-12-19', '2025-12-25', '2025-12-26');
        $this->pdo->exec("INSERT INTO salary (employee_id, daily_rate, effective_from, effective_to, status, created_by) VALUES ({$b['empId']}, 500, '2025-01-01', NULL, 'Active', {$b['userId']})");
        $this->pdo->exec("INSERT INTO employee_schedule_assignment (employee_id, schedule_id, effective_from, effective_to, status) VALUES ({$b['empId']}, {$b['schedId']}, '2025-01-01', NULL, 'Active')");
        $this->pdo->exec("INSERT INTO holiday_calendar (holiday_date, description, holiday_type, pay_multiplier, status) VALUES ('2025-12-25', 'Christmas Day', 'Regular', 2.00, 'Active')");
        $this->insertAttendance($b['empId'], $b['baId'], $b['schedId'], '2025-12-25', null, null, 0, 0, 0, 0, 'Approved');

        $svc = new PayrollService(new Connection([], $this->pdo));
        $svc->computeRun($b['runId'], $b['userId']);

        $gross = (float) $this->pdo->query("SELECT gross_pay FROM payroll WHERE payroll_run_id = {$b['runId']}")->fetchColumn();
        self::assertSame(500.00, $gross);
        $this->assertEarningsMatchGrossPay($b['runId']);
    }

    public function testComputeRunPaysExactRestDayHolidayAndHolidayOvertimeWithoutCentavoDrift(): void
    {
        $b = $this->seedRun('2025-10-31', '2025-11-06', '2025-11-07');
        $this->pdo->exec("INSERT INTO salary (employee_id, daily_rate, effective_from, effective_to, status, created_by) VALUES ({$b['empId']}, 500, '2025-01-01', NULL, 'Active', {$b['userId']})");
        $this->pdo->exec("INSERT INTO employee_schedule_assignment (employee_id, schedule_id, effective_from, effective_to, status) VALUES ({$b['empId']}, {$b['schedId']}, '2025-01-01', NULL, 'Active')");
        $this->pdo->exec("INSERT INTO holiday_calendar (holiday_date, description, holiday_type, pay_multiplier, status) VALUES ('2025-11-01', 'All Saints Day', 'SpecialNonWorking', 1.30, 'Active')");
        $this->insertAttendance($b['empId'], $b['baId'], $b['schedId'], '2025-11-01', '07:00:00', '17:00:00', 540, 0, 0, 60, 'Complete');
        $this->insertAttendance($b['empId'], $b['baId'], $b['schedId'], '2025-11-03', '07:00:00', '17:00:00', 540, 0, 0, 60, 'Complete');

        $this->pdo->exec("INSERT INTO request_type (type_name) VALUES ('Overtime')");
        $rtId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO request (employee_id, request_type_id, status, reason, submitted_at) VALUES ({$b['empId']}, {$rtId}, 'Approved', 'OT', NOW())");
        $reqId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO overtime_request_detail (request_id, date_from, date_to, start_time, end_time, requested_minutes) VALUES ({$reqId}, '2025-11-01', '2025-11-03', '16:00:00', '17:00:00', 60)");

        $svc = new PayrollService(new Connection([], $this->pdo));
        $svc->computeRun($b['runId'], $b['userId']);

        // 11/01 rest-day SNW: 500*1.5=750.00 + OT (750/8)*1.3=121.88; 11/03 regular: 500.00 + OT (500/8)*1.25=78.13 => 1450.01
        $gross = (float) $this->pdo->query("SELECT gross_pay FROM payroll WHERE payroll_run_id = {$b['runId']}")->fetchColumn();
        self::assertSame(1450.01, $gross);
        $this->assertEarningsMatchGrossPay($b['runId']);
    }

    public function testComputeRunReconcilesEarningsWithGrossPayOnZeroWorkedSpecialAndSpecialNonWorkingHolidays(): void
    {
        $b = $this->seedRun('2025-10-31', '2025-11-06', '2025-11-07');
        $u = substr(uniqid(), -6);
        $this->pdo->exec("INSERT INTO employee (employee_number, employee_type, first_name, last_name, email, hire_date, position, status) VALUES ('ES{$u}', 'Regular', 'Special', 'Only', 'es{$u}@t.test', '2020-01-01', 'Staff', 'Active')");
        $emp2 = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO employee_branch_assignment (employee_id, branch_id, effective_from, effective_to, created_at) VALUES ({$emp2}, {$b['branchId']}, '2020-01-01', NULL, NOW())");
        $ba2 = (int) $this->pdo->lastInsertId();

        foreach ([$b['empId'], $emp2] as $eid) {
            $this->pdo->exec("INSERT INTO salary (employee_id, daily_rate, effective_from, effective_to, status, created_by) VALUES ({$eid}, 500, '2025-01-01', NULL, 'Active', {$b['userId']})");
            $this->pdo->exec("INSERT INTO employee_schedule_assignment (employee_id, schedule_id, effective_from, effective_to, status) VALUES ({$eid}, {$b['schedId']}, '2025-01-01', NULL, 'Active')");
        }
        $this->pdo->exec("INSERT INTO holiday_calendar (holiday_date, description, holiday_type, pay_multiplier, status) VALUES
            ('2025-11-01', 'All Saints Day', 'SpecialNonWorking', 1.30, 'Active'),
            ('2025-11-02', 'Special Working Day', 'Special', 1.00, 'Active')");

        // Emp 1: 1 worked regular day + 1 zero-worked Approved SpecialNonWorking + 1 zero-worked Approved Special -> gross = 500, Basic qty = 1.
        $this->insertAttendance($b['empId'], $b['baId'], $b['schedId'], '2025-11-01', null, null, 0, 0, 0, 0, 'Approved');
        $this->insertAttendance($b['empId'], $b['baId'], $b['schedId'], '2025-11-02', null, null, 0, 0, 0, 0, 'Approved');
        $this->insertAttendance($b['empId'], $b['baId'], $b['schedId'], '2025-11-03', '07:00:00', '16:00:00', 480, 0, 0, 0, 'Complete');

        // Emp 2: ONLY a zero-worked Approved SpecialNonWorking holiday -> gross = 0.00, Basic qty = 0, SUM(earnings) = 0.00.
        $this->insertAttendance($emp2, $ba2, $b['schedId'], '2025-11-01', null, null, 0, 0, 0, 0, 'Approved');

        $svc = new PayrollService(new Connection([], $this->pdo));
        $svc->computeRun($b['runId'], $b['userId']);

        $emp1Payroll = $this->pdo->query("SELECT payroll_id, gross_pay FROM payroll WHERE payroll_run_id = {$b['runId']} AND employee_id = {$b['empId']}")->fetch();
        $emp2Payroll = $this->pdo->query("SELECT payroll_id, gross_pay FROM payroll WHERE payroll_run_id = {$b['runId']} AND employee_id = {$emp2}")->fetch();
        self::assertSame(500.00, (float) $emp1Payroll['gross_pay']);
        self::assertSame(0.00, (float) $emp2Payroll['gross_pay']);

        $emp1Basic = $this->pdo->query("SELECT quantity, amount FROM payroll_earnings WHERE payroll_id = {$emp1Payroll['payroll_id']} AND earning_type = 'Basic'")->fetch();
        $emp2Basic = $this->pdo->query("SELECT quantity, amount FROM payroll_earnings WHERE payroll_id = {$emp2Payroll['payroll_id']} AND earning_type = 'Basic'")->fetch();
        self::assertSame(1.0, (float) $emp1Basic['quantity']);
        self::assertSame(500.00, (float) $emp1Basic['amount']);
        self::assertSame(0.0, (float) $emp2Basic['quantity']);
        self::assertSame(0.00, (float) $emp2Basic['amount']);

        $this->assertEarningsMatchGrossPay($b['runId']);
    }

    public function testComputeRunClearsOvertimeOnZeroWorkedApprovedSpecialNonWorkingHolidayAndExcludesFromNextDayEligibility(): void
    {
        $b = $this->seedRun('2025-12-19', '2025-12-25', '2025-12-26');
        $this->pdo->exec("INSERT INTO salary (employee_id, daily_rate, effective_from, effective_to, status, created_by) VALUES ({$b['empId']}, 500, '2025-01-01', NULL, 'Active', {$b['userId']})");
        $this->pdo->exec("INSERT INTO employee_schedule_assignment (employee_id, schedule_id, effective_from, effective_to, status) VALUES ({$b['empId']}, {$b['schedId']}, '2025-01-01', NULL, 'Active')");
        $this->pdo->exec("INSERT INTO holiday_calendar (holiday_date, description, holiday_type, pay_multiplier, status) VALUES
            ('2025-12-24', 'Christmas Eve', 'SpecialNonWorking', 1.30, 'Active'),
            ('2025-12-25', 'Christmas Day', 'Regular', 2.00, 'Active')");

        // Absent 12/23; zero-worked Approved 12/24 SNW even with time_in/time_out > 8h + Approved OT;
        // ordinary worked day + 1h OT on 12/22. Neither 12/24 pay/OT nor unworked 12/25 Regular Holiday should accrue.
        $this->insertAttendance($b['empId'], $b['baId'], $b['schedId'], '2025-12-22', '07:00:00', '17:00:00', 540, 0, 0, 60, 'Complete');
        $this->insertAttendance($b['empId'], $b['baId'], $b['schedId'], '2025-12-24', '07:00:00', '17:00:00', 0, 0, 0, 60, 'Approved');

        $this->pdo->exec("INSERT INTO request_type (type_name) VALUES ('Overtime')");
        $rtId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO request (employee_id, request_type_id, status, reason, submitted_at) VALUES ({$b['empId']}, {$rtId}, 'Approved', 'OT', NOW())");
        $reqId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO overtime_request_detail (request_id, date_from, date_to, start_time, end_time, requested_minutes) VALUES ({$reqId}, '2025-12-22', '2025-12-24', '16:00:00', '17:00:00', 60)");

        $svc = new PayrollService(new Connection([], $this->pdo));
        $svc->computeRun($b['runId'], $b['userId']);

        // Only 12/22 counts: Basic 500.00 + OT 78.13 = 578.13 (0 from 12/24 SNW, 0 from unworked 12/25 Regular).
        $gross = (float) $this->pdo->query("SELECT gross_pay FROM payroll WHERE payroll_run_id = {$b['runId']}")->fetchColumn();
        self::assertSame(578.13, $gross);
        $this->assertEarningsMatchGrossPay($b['runId']);
    }

    public function testComputeRunHandlesMultiBatchIncrementalUploadsAndAllDuplicateReuploadsWithoutCoverageDrift(): void
    {
        $b = $this->seedRun('2026-08-07', '2026-08-13', '2026-08-14');
        $u = substr(uniqid(), -6);
        $this->pdo->exec("INSERT INTO salary (employee_id, daily_rate, effective_from, effective_to, status, created_by) VALUES ({$b['empId']}, 500, '2025-01-01', NULL, 'Active', {$b['userId']})");
        $this->pdo->exec("INSERT INTO employee_schedule_assignment (employee_id, schedule_id, effective_from, effective_to, status) VALUES ({$b['empId']}, {$b['schedId']}, '2025-01-01', NULL, 'Active')");
        $this->pdo->exec("INSERT INTO attendance_site (site_code, site_name, status) VALUES ('SM{$u}', 'Site M {$u}', 'Active')");
        $siteId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO biometric_device (site_id, device_code, device_name, status) VALUES ({$siteId}, 'DM{$u}', 'Device M {$u}', 'Active')");
        $deviceId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO biometric_device_branch (device_id, branch_id, effective_from, effective_to, status) VALUES ({$deviceId}, {$b['branchId']}, '2025-01-01', NULL, 'Active')");

        $gateway = new PdoAttendanceImportGateway($this->pdo);
        $tz = new DateTimeZone('Asia/Manila');

        // Week 1 batch (08-02..08-07), Week 2 batch (08-10..08-14; 08-08 Sat rest, 08-09 Sun absent),
        // plus Batch 3 all-duplicate re-upload (0 new punches in biometric_punch).
        $b1 = $gateway->createImportBatch($deviceId, $b['userId'], 2026, 8, hash('sha256', 'mb1_' . $u), 'LDE_XLS_DAILY_LOG_V1');
        $this->pdo->exec("UPDATE attendance_import_batch SET status = 'Approved', records_parsed = 2, records_matched = 2 WHERE import_batch_id = {$b1}");
        $gateway->retainMatchedPunch($b1, new ParsedPunch($deviceId, '126', new DateTimeImmutable('2026-08-02 07:00', $tz), 2, 'F', '07:00', 'LDE', '126', 'Emp 1'), $b['empId'], $b['branchId']);
        $gateway->retainMatchedPunch($b1, new ParsedPunch($deviceId, '126', new DateTimeImmutable('2026-08-07 16:00', $tz), 2, 'K', '16:00', 'LDE', '126', 'Emp 1'), $b['empId'], $b['branchId']);

        $b2 = $gateway->createImportBatch($deviceId, $b['userId'], 2026, 8, hash('sha256', 'mb2_' . $u), 'LDE_XLS_DAILY_LOG_V1');
        $this->pdo->exec("UPDATE attendance_import_batch SET status = 'Approved', records_parsed = 4, records_matched = 2, duplicates_skipped = 2 WHERE import_batch_id = {$b2}");
        $gateway->retainMatchedPunch($b2, new ParsedPunch($deviceId, '126', new DateTimeImmutable('2026-08-10 07:00', $tz), 2, 'N', '07:00', 'LDE', '126', 'Emp 1'), $b['empId'], $b['branchId']);
        $gateway->retainMatchedPunch($b2, new ParsedPunch($deviceId, '126', new DateTimeImmutable('2026-08-14 16:00', $tz), 2, 'R', '16:00', 'LDE', '126', 'Emp 1'), $b['empId'], $b['branchId']);

        $b3 = $gateway->createImportBatch($deviceId, $b['userId'], 2026, 8, hash('sha256', 'mb3_' . $u), 'LDE_XLS_DAILY_LOG_V1');
        $this->pdo->exec("UPDATE attendance_import_batch SET status = 'Approved', records_parsed = 4, records_matched = 0, duplicates_skipped = 4 WHERE import_batch_id = {$b3}");

        foreach (['2026-08-07', '2026-08-10', '2026-08-11', '2026-08-12', '2026-08-13'] as $dt) {
            $this->insertAttendance($b['empId'], $b['baId'], $b['schedId'], $dt, '07:00:00', '16:00:00', 480, 0, 0, 0, 'Complete');
        }

        $svc = new PayrollService(new Connection([], $this->pdo));
        $svc->computeRun($b['runId'], $b['userId']);

        // 2026-08-09 falls between Batch 1 and Batch 2 -> must be counted as 1 unpaid absence (not a blind hole).
        $abs1 = $this->pdo->query(
            "SELECT d.quantity FROM deduction d JOIN payroll p ON p.payroll_id = d.payroll_id
              WHERE p.payroll_run_id = {$b['runId']} AND d.deduction_type = 'Absence'"
        )->fetchColumn();
        self::assertSame(1.0, (float) $abs1);
        $this->assertEarningsMatchGrossPay($b['runId']);

        // Verify a second period after 2026-08-14 (2026-08-21..2026-08-27) is NOT falsely covered by all-duplicate Batch 3.
        $this->pdo->exec("INSERT INTO payroll_period (period_start, period_end, pay_date, status, cutoff_pattern) VALUES ('2026-08-21', '2026-08-27', '2026-08-28', 'Open', 'FridayThursday')");
        $period2 = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO payroll_run (payroll_period_id, branch_id, payroll_policy_id, status, gross_pay, total_deductions, net_pay, lock_version, created_at, updated_at) VALUES ({$period2}, {$b['branchId']}, {$b['policyId']}, 'Draft', 0, 0, 0, 0, NOW(), NOW())");
        $run2 = (int) $this->pdo->lastInsertId();
        $svc->computeRun($run2, $b['userId']);
        $abs2 = $this->pdo->query(
            "SELECT d.quantity FROM deduction d JOIN payroll p ON p.payroll_id = d.payroll_id
              WHERE p.payroll_run_id = {$run2} AND d.deduction_type = 'Absence'"
        )->fetchColumn();
        self::assertFalse($abs2);
        $this->assertEarningsMatchGrossPay($run2);
    }

    public function testComputeRunDoesNotRecordUnpaidAbsencesAfterPartialMonthWorkbookEndDate(): void
    {
        // Mirrors Daily Log - H(20260830_1439)aug.xls ending 2026-08-28 Fri.
        $b = $this->seedRun('2026-08-28', '2026-09-03', '2026-09-04');
        $u = substr(uniqid(), -6);
        $this->pdo->exec("INSERT INTO employee (employee_number, employee_type, first_name, last_name, email, hire_date, position, status) VALUES ('EA{$u}', 'Regular', 'Absent', 'On28', 'ea{$u}@t.test', '2020-01-01', 'Staff', 'Active')");
        $emp2 = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO employee_branch_assignment (employee_id, branch_id, effective_from, effective_to, created_at) VALUES ({$emp2}, {$b['branchId']}, '2020-01-01', NULL, NOW())");
        foreach ([$b['empId'], $emp2] as $eid) {
            $this->pdo->exec("INSERT INTO salary (employee_id, daily_rate, effective_from, effective_to, status, created_by) VALUES ({$eid}, 500, '2025-01-01', NULL, 'Active', {$b['userId']})");
            $this->pdo->exec("INSERT INTO employee_schedule_assignment (employee_id, schedule_id, effective_from, effective_to, status) VALUES ({$eid}, {$b['schedId']}, '2025-01-01', NULL, 'Active')");
        }

        $this->pdo->exec("INSERT INTO attendance_site (site_code, site_name, status) VALUES ('SP{$u}', 'Site P {$u}', 'Active')");
        $siteId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO biometric_device (site_id, device_code, device_name, status) VALUES ({$siteId}, 'DP{$u}', 'Device P {$u}', 'Active')");
        $deviceId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO biometric_device_branch (device_id, branch_id, effective_from, effective_to, status) VALUES ({$deviceId}, {$b['branchId']}, '2025-01-01', NULL, 'Active')");

        $gateway = new PdoAttendanceImportGateway($this->pdo);
        $batchId = $gateway->createImportBatch($deviceId, $b['userId'], 2026, 8, hash('sha256', 'aug_partial_' . $u), 'LDE_XLS_DAILY_LOG_V1');
        $this->pdo->exec("UPDATE attendance_import_batch SET status = 'Approved' WHERE import_batch_id = {$batchId}");
        $tz = new DateTimeZone('Asia/Manila');
        $gateway->retainMatchedPunch($batchId, new ParsedPunch($deviceId, '126', new DateTimeImmutable('2026-08-02 07:00', $tz), 2, 'F', '07:00 16:00', 'LDE', '126', 'Emp 1'), $b['empId'], $b['branchId']);
        $gateway->retainMatchedPunch($batchId, new ParsedPunch($deviceId, '126', new DateTimeImmutable('2026-08-28 07:00', $tz), 2, 'AF', '07:00 16:00', 'LDE', '126', 'Emp 1'), $b['empId'], $b['branchId']);
        $gateway->retainMatchedPunch($batchId, new ParsedPunch($deviceId, '126', new DateTimeImmutable('2026-08-28 16:00', $tz), 2, 'AF', '07:00 16:00', 'LDE', '126', 'Emp 1'), $b['empId'], $b['branchId']);

        // Emp 1 worked 2026-08-28; neither employee has attendance on uncovered 2026-08-30 Sun / 2026-08-31 Mon.
        $this->insertAttendance($b['empId'], $b['baId'], $b['schedId'], '2026-08-28', '07:00:00', '16:00:00', 480, 0, 0, 0, 'Complete');

        $svc = new PayrollService(new Connection([], $this->pdo));
        $svc->computeRun($b['runId'], $b['userId']);

        // Emp 1: 0 unpaid absences (2026-08-30/31 are after batch max_punch_date 2026-08-28).
        $emp1Abs = $this->pdo->query(
            "SELECT d.quantity FROM deduction d JOIN payroll p ON p.payroll_id = d.payroll_id
              WHERE p.payroll_run_id = {$b['runId']} AND p.employee_id = {$b['empId']} AND d.deduction_type = 'Absence'"
        )->fetchColumn();
        self::assertFalse($emp1Abs);

        // Emp 2: missed 2026-08-28 (within batch range [08-02, 08-28]) -> exactly 1 unpaid absence, NOT 3.
        $emp2Abs = $this->pdo->query(
            "SELECT d.quantity FROM deduction d JOIN payroll p ON p.payroll_id = d.payroll_id
              WHERE p.payroll_run_id = {$b['runId']} AND p.employee_id = {$emp2} AND d.deduction_type = 'Absence'"
        )->fetchColumn();
        self::assertSame(1.0, (float) $emp2Abs);
        $this->assertEarningsMatchGrossPay($b['runId']);
    }

    public function testAttendancePolicyServiceIgnoresCancelledImportBatchesOnLiveMariaDb(): void
    {
        $b = $this->seedRun('2026-08-07', '2026-08-13', '2026-08-14');
        $u = substr(uniqid(), -6);
        $this->pdo->exec("INSERT INTO employee_schedule_assignment (employee_id, schedule_id, effective_from, effective_to, status) VALUES ({$b['empId']}, {$b['schedId']}, '2025-01-01', NULL, 'Active')");
        $this->pdo->exec("INSERT INTO attendance_site (site_code, site_name, status) VALUES ('SC{$u}', 'Site C {$u}', 'Active')");
        $siteId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO biometric_device (site_id, device_code, device_name, status) VALUES ({$siteId}, 'DC{$u}', 'Device C {$u}', 'Active')");
        $deviceId = (int) $this->pdo->lastInsertId();

        $gateway = new PdoAttendanceImportGateway($this->pdo);
        $cancelledBatch = $gateway->createImportBatch($deviceId, $b['userId'], 2026, 8, hash('sha256', 'can_' . $u), 'V1');
        $this->pdo->exec("UPDATE attendance_import_batch SET status = 'Cancelled' WHERE import_batch_id = {$cancelledBatch}");
        $approvedBatch = $gateway->createImportBatch($deviceId, $b['userId'], 2026, 8, hash('sha256', 'app_' . $u), 'V1');
        $this->pdo->exec("UPDATE attendance_import_batch SET status = 'Approved' WHERE import_batch_id = {$approvedBatch}");

        // Seed 3 late rows linked to the Cancelled batch (row status still Complete) -> must be ignored.
        foreach (['2026-08-02', '2026-08-03', '2026-08-04'] as $dt) {
            $stmt = $this->pdo->prepare(
                "INSERT INTO attendance
                    (employee_id, branch_assignment_id, schedule_id, attendance_date,
                     time_in, time_out, hours_worked_minutes, late_minutes,
                     undertime_minutes, overtime_minutes, status, source, import_batch_id)
                 VALUES (:emp, :ba, :sched, :dt, '07:10:00', '16:00:00', 470, 10, 0, 0, 'Complete', 'xls_import', :batch)"
            );
            $stmt->execute([':emp' => $b['empId'], ':ba' => $b['baId'], ':sched' => $b['schedId'], ':dt' => $dt, ':batch' => $cancelledBatch]);
        }

        $policySvc = new AttendancePolicyService(new Connection([], $this->pdo));
        self::assertSame([], $policySvc->evaluateEmployee($b['empId'], '2026-08-01', '2026-08-15'));

        // Seed 3 late rows linked to the Approved batch -> creates 1 flag, and findFlag/listFlags show only Approved evidence.
        foreach (['2026-08-09', '2026-08-10', '2026-08-11'] as $dt) {
            $stmt = $this->pdo->prepare(
                "INSERT INTO attendance
                    (employee_id, branch_assignment_id, schedule_id, attendance_date,
                     time_in, time_out, hours_worked_minutes, late_minutes,
                     undertime_minutes, overtime_minutes, status, source, import_batch_id)
                 VALUES (:emp, :ba, :sched, :dt, '07:12:00', '16:00:00', 468, 12, 0, 0, 'Complete', 'xls_import', :batch)"
            );
            $stmt->execute([':emp' => $b['empId'], ':ba' => $b['baId'], ':sched' => $b['schedId'], ':dt' => $dt, ':batch' => $approvedBatch]);
        }

        $created = $policySvc->evaluateEmployee($b['empId'], '2026-08-01', '2026-08-15');
        self::assertCount(1, $created);
        self::assertSame('2026-08-11', $created[0]['triggering_date']);

        $flag = $policySvc->findFlag($created[0]['flag_id']);
        self::assertSame(
            '2026-08-09 07:12 (+12 min), 2026-08-10 07:12 (+12 min), 2026-08-11 07:12 (+12 min)',
            $flag['late_dates'],
        );

        // If the approved batch is later cancelled, findFlag/listFlags must no longer surface cancelled-batch late evidence.
        $this->pdo->exec("UPDATE attendance_import_batch SET status = 'Cancelled' WHERE import_batch_id = {$approvedBatch}");
        $reloaded = $policySvc->findFlag($created[0]['flag_id']);
        self::assertNull($reloaded['late_dates']);
    }

    public function testUnassignedAttendanceAdjustmentMatchesImportBreakWindowFallback(): void
    {
        $b = $this->seedRun('2026-06-12', '2026-06-18', '2026-06-19');
        $attId = $this->insertAttendance($b['empId'], $b['baId'], null, '2026-06-19', '07:06:00', null, 0, 0, 0, 0, 'Incomplete');

        $adj = new AttendanceAdjustmentService(new Connection([], $this->pdo));
        $adj->adjust($attId, ['time_in' => '07:06', 'time_out' => '11:59', 'reason' => 'Half day'], $b['userId']);

        $row = $this->pdo->query("SELECT hours_worked_minutes, late_minutes, undertime_minutes FROM attendance WHERE attendance_id = {$attId}")->fetch();
        self::assertSame(293, (int) $row['hours_worked_minutes']);
        self::assertSame(6, (int) $row['late_minutes']);
        self::assertSame(181, (int) $row['undertime_minutes']);
    }

    public function testDoubleTapReconcilesWhenLaterUploadAddsTimeOutAndRebuildsOnCancel(): void
    {
        $b = $this->seedRun('2025-09-05', '2025-09-11', '2025-09-12');
        $u = substr(uniqid(), -6);
        $this->pdo->exec("INSERT INTO employee_schedule_assignment (employee_id, schedule_id, effective_from, effective_to, status) VALUES ({$b['empId']}, {$b['schedId']}, '2025-01-01', NULL, 'Active')");
        $this->pdo->exec("INSERT INTO attendance_site (site_code, site_name, status) VALUES ('SITE{$u}', 'Site {$u}', 'Active')");
        $siteId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO biometric_device (site_id, device_code, device_name, status) VALUES ({$siteId}, 'DEV{$u}', 'Device {$u}', 'Active')");
        $deviceId = (int) $this->pdo->lastInsertId();

        $gateway = new PdoAttendanceImportGateway($this->pdo);
        $gen = new TimesheetGenerator();
        $tz = new DateTimeZone('Asia/Manila');
        $schedule = $gateway->effectiveSchedule($b['schedId'], '2025-09-05');

        // Batch 1: morning double-tap 06:49 + 06:59 -> Incomplete.
        $batch1 = $gateway->createImportBatch($deviceId, $b['userId'], 2025, 9, hash('sha256', 'b1_' . $u), 'V1');
        $this->pdo->exec("UPDATE attendance_import_batch SET status = 'Approved' WHERE import_batch_id = {$batch1}");
        $p1 = new ParsedPunch($deviceId, '24', new DateTimeImmutable('2025-09-05 06:49', $tz), 2, 'E', '06:49 06:59', 'HARDWARE', '24', 'Test');
        $p2 = new ParsedPunch($deviceId, '24', new DateTimeImmutable('2025-09-05 06:59', $tz), 2, 'E', '06:49 06:59', 'HARDWARE', '24', 'Test');
        $gateway->retainMatchedPunch($batch1, $p1, $b['empId'], $b['branchId']);
        $gateway->retainMatchedPunch($batch1, $p2, $b['empId'], $b['branchId']);
        $att1 = $gen->generate($b['empId'], '2025-09-05', [$p1, $p2], $schedule);
        $gateway->saveGeneratedAttendance($batch1, $att1);

        $row1 = $this->pdo->query("SELECT attendance_id, time_in, time_out, hours_worked_minutes, status FROM attendance WHERE employee_id = {$b['empId']} AND attendance_date = '2025-09-05'")->fetch();
        self::assertSame('Incomplete', $row1['status']);
        self::assertSame('06:49:00', $row1['time_in']);
        self::assertNull($row1['time_out']);
        self::assertSame(0, (int) $row1['hours_worked_minutes']);

        // Batch 2: later upload adds time-out 16:50 -> Complete (owner review C7: the
        // 06:49/06:59 pair is one arrival scanned twice; 541 worked min).
        $batch2 = $gateway->createImportBatch($deviceId, $b['userId'], 2025, 9, hash('sha256', 'b2_' . $u), 'V1');
        $p3 = new ParsedPunch($deviceId, '24', new DateTimeImmutable('2025-09-05 16:50', $tz), 2, 'E', '16:50', 'HARDWARE', '24', 'Test');
        $gateway->retainMatchedPunch($batch2, $p3, $b['empId'], $b['branchId']);
        $att2 = $gen->generate($b['empId'], '2025-09-05', [$p3], $schedule);
        $gateway->saveGeneratedAttendance($batch2, $att2);

        $row2 = $this->pdo->query("SELECT time_in, time_out, hours_worked_minutes, status FROM attendance WHERE attendance_id = {$row1['attendance_id']}")->fetch();
        self::assertSame('Complete', $row2['status']);
        self::assertSame('06:49:00', $row2['time_in']);
        self::assertSame('16:50:00', $row2['time_out']);
        self::assertSame(541, (int) $row2['hours_worked_minutes']);

        // Cancel Batch 2: delete Batch 2 punch & rebuild -> returns cleanly to Incomplete double-tap (0 worked).
        $this->pdo->exec("DELETE FROM biometric_punch WHERE import_batch_id = {$batch2}");
        $this->pdo->exec("UPDATE attendance_import_batch SET status = 'Cancelled' WHERE import_batch_id = {$batch2}");
        $rebuilt = $gateway->rebuildFromLivePunches((int) $row1['attendance_id']);
        self::assertTrue($rebuilt);

        $row3 = $this->pdo->query("SELECT time_in, time_out, hours_worked_minutes, late_minutes, undertime_minutes, status FROM attendance WHERE attendance_id = {$row1['attendance_id']}")->fetch();
        self::assertSame('Incomplete', $row3['status']);
        self::assertSame('06:49:00', $row3['time_in']);
        self::assertNull($row3['time_out']);
        self::assertSame(0, (int) $row3['hours_worked_minutes']);
        self::assertSame(0, (int) $row3['late_minutes']);
        self::assertSame(0, (int) $row3['undertime_minutes']);
    }

    public function testBiometricUnmatchedPunchRematchAndManualAttendanceOverride(): void
    {
        $b = $this->seedRun('2026-08-07', '2026-08-13', '2026-08-14');
        $u = substr(uniqid(), -6);
        $this->pdo->exec("INSERT INTO salary (employee_id, daily_rate, effective_from, effective_to, status, created_by) VALUES ({$b['empId']}, 500, '2025-01-01', NULL, 'Active', {$b['userId']})");
        $this->pdo->exec("INSERT INTO employee_schedule_assignment (employee_id, schedule_id, effective_from, effective_to, status) VALUES ({$b['empId']}, {$b['schedId']}, '2025-01-01', NULL, 'Active')");
        $this->pdo->exec("INSERT INTO attendance_site (site_code, site_name, status) VALUES ('SR{$u}', 'Site R {$u}', 'Active')");
        $siteId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO biometric_device (site_id, device_code, device_name, status) VALUES ({$siteId}, 'DR{$u}', 'Device R {$u}', 'Active')");
        $deviceId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO biometric_device_branch (device_id, branch_id, effective_from, effective_to, status) VALUES ({$deviceId}, {$b['branchId']}, '2025-01-01', NULL, 'Active')");

        $gateway = new PdoAttendanceImportGateway($this->pdo);
        $tz = new DateTimeZone('Asia/Manila');

        // 1. Before enrollment, dry-run diagnostics explain unregistered Enroll ID 21
        $diagBefore = $gateway->diagnoseEnrollmentMatch($deviceId, '21', ['2026-08-07']);
        self::assertSame('unmatched', $diagBefore['status']);
        self::assertStringContainsString('Unregistered Enroll ID', $diagBefore['reason']);

        // 2. Import batch stores unmatched punches for Enroll ID 21 ("Richie Tagaduar" scenario)
        $batchId = $gateway->createImportBatch($deviceId, $b['userId'], 2026, 8, hash('sha256', 'richie_' . $u), 'LDE_XLS_DAILY_LOG_V1');
        $pIn  = new ParsedPunch($deviceId, '21', new DateTimeImmutable('2026-08-07 07:00', $tz), 2, 'F', '07:00', 'LDE', '21', 'Richie Tagaduar');
        $pOut = new ParsedPunch($deviceId, '21', new DateTimeImmutable('2026-08-07 16:00', $tz), 2, 'G', '16:00', 'LDE', '21', 'Richie Tagaduar');
        $gateway->retainUnmatchedPunch($batchId, $pIn, 'unmatched');
        $gateway->retainUnmatchedPunch($batchId, $pOut, 'unmatched');
        $this->pdo->exec("UPDATE attendance_import_batch SET status = 'Approved', records_parsed = 2, records_matched = 0, records_unmatched = 2 WHERE import_batch_id = {$batchId}");

        // Unmatched punches must NOT be treated as already-matched duplicates on re-import
        self::assertFalse($gateway->isDuplicatePunch($pIn));

        // 3. Enroll the employee with Enroll ID 21 and trigger rematchUnmatchedPunches()
        $this->pdo->exec("INSERT INTO employee_biometric_enrollment (employee_id, device_id, device_employee_code, effective_from, status) VALUES ({$b['empId']}, {$deviceId}, '21', '2025-01-01', 'Active')");
        $diagAfter = $gateway->diagnoseEnrollmentMatch($deviceId, '21', ['2026-08-07']);
        self::assertSame('matched', $diagAfter['status']);

        $rematchRes = $gateway->rematchUnmatchedPunches($deviceId, '21', $b['userId']);
        self::assertSame(2, $rematchRes['rematched_punches']);
        self::assertSame(1, $rematchRes['days_updated']);
        self::assertSame(0, $rematchRes['still_unmatched']);

        // Attendance row for 2026-08-07 now exists with Complete 480 worked mins
        $attRow = $this->pdo->query("SELECT status, hours_worked_minutes FROM attendance WHERE employee_id = {$b['empId']} AND attendance_date = '2026-08-07'")->fetch();
        self::assertNotFalse($attRow);
        self::assertSame('Complete', $attRow['status']);
        self::assertSame(480, (int) $attRow['hours_worked_minutes']);

        // 4. Record audited manual attendance override for an ABSENT day (2026-08-10)
        $adjSvc = new AttendanceAdjustmentService(new Connection([], $this->pdo));
        $manualAttId = $adjSvc->recordManualAttendance(
            $b['empId'],
            '2026-08-10',
            ['time_in' => '07:00', 'time_out' => '16:00', 'reason' => 'Guard logbook verified entry #42'],
            $b['userId'],
        );
        $manualRow = $this->pdo->query("SELECT status, hours_worked_minutes FROM attendance WHERE attendance_id = {$manualAttId}")->fetch();
        self::assertNotFalse($manualRow);
        self::assertSame('Complete', $manualRow['status']);
        self::assertSame(480, (int) $manualRow['hours_worked_minutes']);

        $adjHistory = $adjSvc->adjustmentHistory($manualAttId);
        self::assertCount(1, $adjHistory);
        self::assertSame('manual_absence_override', $adjHistory[0]['adjustment_type']);
    }

    public function testArchiveEmployeeWithUnapprovedPayrollAndMidPeriodFinalPay(): void
    {
        $b = $this->seedRun('2026-08-07', '2026-08-13', '2026-08-14');
        $u = substr(uniqid(), -6);
        $this->pdo->exec("INSERT INTO salary (employee_id, daily_rate, effective_from, effective_to, status, created_by) VALUES ({$b['empId']}, 500, '2026-08-07', NULL, 'Active', {$b['userId']})");
        $this->pdo->exec("INSERT INTO employee_schedule_assignment (employee_id, schedule_id, effective_from, effective_to, status) VALUES ({$b['empId']}, {$b['schedId']}, '2026-08-07', NULL, 'Active')");
        $this->pdo->exec("INSERT INTO attendance_site (site_code, site_name, status) VALUES ('SA{$u}', 'Site A {$u}', 'Active')");
        $siteId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO biometric_device (site_id, device_code, device_name, status) VALUES ({$siteId}, 'DA{$u}', 'Device A {$u}', 'Active')");
        $deviceId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO biometric_device_branch (device_id, branch_id, effective_from, effective_to, status) VALUES ({$deviceId}, {$b['branchId']}, '2025-01-01', NULL, 'Active')");
        $this->pdo->exec("INSERT INTO employee_biometric_enrollment (employee_id, device_id, device_employee_code, effective_from, status) VALUES ({$b['empId']}, {$deviceId}, '77', '2026-08-07', 'Active')");

        // Employee worked on 2026-08-07 (1-day tenure on salary/schedule starting 2026-08-07!)
        $this->insertAttendance($b['empId'], $b['baId'], $b['schedId'], '2026-08-07', '07:00:00', '16:00:00', 480, 0, 0, 0, 'Complete');
        // Also an attendance row after lastWorkingDate (2026-08-10) that should be excluded once archived after 2026-08-07
        $this->insertAttendance($b['empId'], $b['baId'], $b['schedId'], '2026-08-10', '07:00:00', '16:00:00', 480, 0, 0, 0, 'Complete');

        $payrollSvc = new PayrollService(new Connection([], $this->pdo));
        $payrollSvc->computeRun($b['runId'], $b['userId']); // Run is now Computed (unapproved)

        // Archive employee with lastWorkingDate = 2026-08-07 and reason = 'AWOL'
        // Must succeed even though an unapproved payroll run exists AND salary/schedule started on 2026-08-07!
        $lifecycleSvc = new \Wbpms\Application\EmployeeLifecycleService(new Connection([], $this->pdo));
        $lifecycleSvc->archive($b['empId'], '2026-08-07', 'AWOL', 'Employee went AWOL after 08/07', $b['userId'], 'HRHead');

        $empStatus = (string) $this->pdo->query("SELECT status FROM employee WHERE employee_id = {$b['empId']}")->fetchColumn();
        self::assertSame('Archived', $empStatus);

        // Pre-separation punch on 2026-08-07 still matches after archiving!
        $gateway = new PdoAttendanceImportGateway($this->pdo);
        $tz = new DateTimeZone('Asia/Manila');
        $matchPre = $gateway->match(new ParsedPunch($deviceId, '77', new DateTimeImmutable('2026-08-07 07:05', $tz), 2, 'F', '07:05', 'LDE', '77', 'Emp'));
        self::assertSame($b['empId'], $matchPre->employeeId);

        // Post-separation punch on 2026-08-08 does NOT match
        $matchPost = $gateway->match(new ParsedPunch($deviceId, '77', new DateTimeImmutable('2026-08-08 07:05', $tz), 2, 'G', '07:05', 'LDE', '77', 'Emp'));
        self::assertNull($matchPost->employeeId);

        // Recompute the payroll run: archived employee is STILL paid for 2026-08-07 (₱500.00), while 2026-08-10 is excluded!
        $payrollSvc->computeRun($b['runId'], $b['userId']);
        $gross = (float) $this->pdo->query("SELECT gross_pay FROM payroll WHERE payroll_run_id = {$b['runId']} AND employee_id = {$b['empId']}")->fetchColumn();
        self::assertSame(500.00, $gross);
        $this->assertEarningsMatchGrossPay($b['runId']);
    }

    public function testZeroGrossSkipsDeductionsAndCashAdvanceSettlesOnlyOnApprovalWithIncomeTax(): void
    {
        // Pay date 2026-08-28 is the last Friday of August 2026 -> monthly contribution cutoff!
        $b = $this->seedRun('2026-08-21', '2026-08-27', '2026-08-28');
        $u = substr(uniqid(), -6);

        // Emp 1: ₱0 gross pay (0 worked days) + active Cash Advance of ₱1,000
        $this->pdo->exec("INSERT INTO salary (employee_id, daily_rate, effective_from, effective_to, status, created_by) VALUES ({$b['empId']}, 500, '2025-01-01', NULL, 'Active', {$b['userId']})");
        $this->pdo->exec("INSERT INTO employee_schedule_assignment (employee_id, schedule_id, effective_from, effective_to, status) VALUES ({$b['empId']}, {$b['schedId']}, '2025-01-01', NULL, 'Active')");

        // Emp 2: High earner (₱2,000/day * 5 days = ₱10,000 weekly gross -> triggers BIR TRAIN withholding tax) + active CA of ₱1,200
        $this->pdo->exec("INSERT INTO employee (employee_number, employee_type, first_name, last_name, email, hire_date, position, status) VALUES ('ET{$u}', 'Regular', 'Tax', 'Earner', 'et{$u}@t.test', '2020-01-01', 'Manager', 'Active')");
        $emp2 = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO employee_branch_assignment (employee_id, branch_id, effective_from, effective_to, created_at) VALUES ({$emp2}, {$b['branchId']}, '2020-01-01', NULL, NOW())");
        $ba2 = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO salary (employee_id, daily_rate, effective_from, effective_to, status, created_by) VALUES ({$emp2}, 2000, '2025-01-01', NULL, 'Active', {$b['userId']})");
        $this->pdo->exec("INSERT INTO employee_schedule_assignment (employee_id, schedule_id, effective_from, effective_to, status) VALUES ({$emp2}, {$b['schedId']}, '2025-01-01', NULL, 'Active')");
        foreach (['2026-08-21', '2026-08-23', '2026-08-24', '2026-08-25', '2026-08-26'] as $dt) {
            $this->insertAttendance($emp2, $ba2, $b['schedId'], $dt, '07:00:00', '16:00:00', 480, 0, 0, 0, 'Complete');
        }

        $this->pdo->exec("INSERT INTO request_type (type_name) VALUES ('CashAdvance')");
        $rtCa = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO request (employee_id, request_type_id, status, reason, submitted_at) VALUES ({$b['empId']}, {$rtCa}, 'Approved', 'CA1', NOW()), ({$emp2}, {$rtCa}, 'Approved', 'CA2', NOW())");
        $reqCa1 = (int) $this->pdo->lastInsertId();
        $reqCa2 = $reqCa1 + 1;
        $this->pdo->exec("INSERT INTO cash_advance_history (employee_id, request_id, original_amount, remaining_balance, status, approved_at, created_at, updated_at) VALUES
            ({$b['empId']}, {$reqCa1}, 1000.00, 1000.00, 'Active', NOW(), NOW(), NOW()),
            ({$emp2}, {$reqCa2}, 1200.00, 1200.00, 'Active', NOW(), NOW(), NOW())");
        $this->pdo->exec("INSERT INTO cash_advance_request_detail (request_id, amount, advance_date) VALUES ({$reqCa1}, 1000.00, '2020-01-01'), ({$reqCa2}, 1200.00, '2020-01-01')");

        $payrollSvc = new PayrollService(new Connection([], $this->pdo));
        $payrollSvc->computeRun($b['runId'], $b['userId']);

        // Emp 1 (gross = 0): total_deductions MUST be 0.00 (no SSS/PhilHealth/Pag-IBIG, no CA deduction!)
        $p1 = $this->pdo->query("SELECT gross_pay, total_deductions, net_pay FROM payroll WHERE payroll_run_id = {$b['runId']} AND employee_id = {$b['empId']}")->fetch();
        self::assertSame(0.00, (float) $p1['gross_pay']);
        self::assertSame(0.00, (float) $p1['total_deductions']);
        self::assertSame(0.00, (float) $p1['net_pay']);

        // Emp 2 (gross = 10,000): has IncomeTax and CashAdvance deductions, and CA balance is STILL 1,200.00 before approval!
        $p2 = $this->pdo->query("SELECT payroll_id, gross_pay, total_deductions, net_pay FROM payroll WHERE payroll_run_id = {$b['runId']} AND employee_id = {$emp2}")->fetch();
        self::assertSame(10000.00, (float) $p2['gross_pay']);
        $taxDed = (float) $this->pdo->query("SELECT amount FROM deduction WHERE payroll_id = {$p2['payroll_id']} AND deduction_type = 'IncomeTax'")->fetchColumn();
        self::assertGreaterThan(0.0, $taxDed);
        $caBalBeforeApprove = (float) $this->pdo->query("SELECT remaining_balance FROM cash_advance_history WHERE employee_id = {$emp2}")->fetchColumn();
        self::assertSame(1200.00, $caBalBeforeApprove);

        // Recomputing the run again does NOT double-decrement CA balance
        $payrollSvc->computeRun($b['runId'], $b['userId']);
        self::assertSame(1200.00, (float) $this->pdo->query("SELECT remaining_balance FROM cash_advance_history WHERE employee_id = {$emp2}")->fetchColumn());
        $p2AfterRecompute = $this->pdo->query("SELECT payroll_id FROM payroll WHERE payroll_run_id = {$b['runId']} AND employee_id = {$emp2}")->fetch();

        // Submit and Approve the run -> CA balance decrements by 500.00 to 700.00 and writes cash_advance_repayment!
        $payrollSvc->submitForApproval($b['runId'], $b['userId']);
        $payrollSvc->approve($b['runId'], $b['userId']);

        self::assertSame(700.00, (float) $this->pdo->query("SELECT remaining_balance FROM cash_advance_history WHERE employee_id = {$emp2}")->fetchColumn());
        $repaymentAmt = (float) $this->pdo->query("SELECT amount FROM cash_advance_repayment WHERE payroll_id = {$p2AfterRecompute['payroll_id']}")->fetchColumn();
        self::assertSame(500.00, $repaymentAmt);
    }

    public function testHrHeadOwnRequestRoutesDirectlyToOwnerApproval(): void
    {
        $b = $this->seedRun('2026-08-07', '2026-08-13', '2026-08-14');
        $u = substr(uniqid(), -6);

        // Ensure role 'HRHead' exists and link $b['empId'] to an HRHead user
        $hrRoleRow = $this->pdo->query("SELECT role_id FROM role WHERE role_name = 'HRHead' LIMIT 1")->fetch();
        if ($hrRoleRow === false) {
            $this->pdo->exec("INSERT INTO role (role_name) VALUES ('HRHead')");
            $hrRoleId = (int) $this->pdo->lastInsertId();
        } else {
            $hrRoleId = (int) $hrRoleRow['role_id'];
        }
        $this->pdo->exec("INSERT INTO users (employee_id, role_id, username, account_email, password_hash, status) VALUES ({$b['empId']}, {$hrRoleId}, 'hrself_{$u}', 'hrself_{$u}@t.test', 'x', 'Active')");
        $hrUserId = (int) $this->pdo->lastInsertId();

        $this->pdo->exec("INSERT INTO request_type (type_name) VALUES ('CashAdvance')");
        $rtId = (int) $this->pdo->lastInsertId();

        $reqSvc = new \Wbpms\Application\RequestService(new Connection([], $this->pdo));
        $reqId = $reqSvc->submit($b['empId'], [
            'request_type_id' => $rtId,
            'reason' => 'HR Head emergency cash advance',
            'amount_requested' => '1000.00',
            'advance_date' => date('Y-m-d'),
        ], $hrUserId);

        $reqRow = $reqSvc->findOrFail($reqId);
        self::assertSame('HRApproved', $reqRow['status']);

        // While in HRApproved status, archiveBlockers() must still flag it as an open request
        $lifecycleRepo = new \Wbpms\Infrastructure\Persistence\EmployeeLifecycleRepository(new Connection([], $this->pdo));
        self::assertContains('pending request', $lifecycleRepo->archiveBlockers($b['empId']));

        // And the HRHead employee can cancel their own unapproved self-request before Owner review
        $reqSvc->cancel($reqId, $b['empId'], $hrUserId);
        self::assertSame('Cancelled', $reqSvc->findOrFail($reqId)['status']);
    }

    public function testPartialCashAdvanceCappedDeductionCarriesBalanceAcrossConsecutiveRuns(): void
    {
        // Period 1 (non-month-end pay date 2026-08-14): 1 worked day at ₱480/day with 120 late mins (₱120 late ded)
        // Net before CA = 480 - 120 = ₱360 (< ₱500 standard weekly CA installment)
        $b = $this->seedRun('2026-08-07', '2026-08-13', '2026-08-14');
        $this->pdo->exec("INSERT INTO salary (employee_id, daily_rate, effective_from, effective_to, status, created_by) VALUES ({$b['empId']}, 480, '2025-01-01', NULL, 'Active', {$b['userId']})");
        $this->pdo->exec("INSERT INTO employee_schedule_assignment (employee_id, schedule_id, effective_from, effective_to, status) VALUES ({$b['empId']}, {$b['schedId']}, '2025-01-01', NULL, 'Active')");
        $this->insertAttendance($b['empId'], $b['baId'], $b['schedId'], '2026-08-07', '09:00:00', '16:00:00', 360, 120, 0, 0, 'Complete');

        $this->pdo->exec("INSERT INTO request_type (type_name) VALUES ('CashAdvance')");
        $rtCa = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO request (employee_id, request_type_id, status, reason, submitted_at) VALUES ({$b['empId']}, {$rtCa}, 'Approved', 'CA Partial', NOW())");
        $reqCa = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO cash_advance_history (employee_id, request_id, original_amount, remaining_balance, status, approved_at, created_at, updated_at) VALUES ({$b['empId']}, {$reqCa}, 600.00, 600.00, 'Active', NOW(), NOW(), NOW())");
        $this->pdo->exec("INSERT INTO cash_advance_request_detail (request_id, amount, advance_date) VALUES ({$reqCa}, 600.00, '2020-01-01')");

        $payrollSvc = new PayrollService(new Connection([], $this->pdo));
        $payrollSvc->computeRun($b['runId'], $b['userId']);
        $payrollSvc->submitForApproval($b['runId'], $b['userId']);
        $payrollSvc->approve($b['runId'], $b['userId']);

        $p1 = $this->pdo->query("SELECT gross_pay, total_deductions, net_pay FROM payroll WHERE payroll_run_id = {$b['runId']} AND employee_id = {$b['empId']}")->fetch();
        self::assertSame(480.00, (float) $p1['gross_pay']);
        self::assertSame(480.00, (float) $p1['total_deductions']); // 120 late + 360 capped CA
        self::assertSame(0.00, (float) $p1['net_pay']);
        self::assertSame(240.00, (float) $this->pdo->query("SELECT remaining_balance FROM cash_advance_history WHERE request_id = {$reqCa}")->fetchColumn());

        // Period 2 (2026-08-14..2026-08-20, pay date 2026-08-21): 2 worked days = ₱960 gross -> deducts remaining ₱240 and marks CA Settled
        $this->pdo->exec("INSERT INTO payroll_period (period_start, period_end, pay_date, status, cutoff_pattern) VALUES ('2026-08-14', '2026-08-20', '2026-08-21', 'Open', 'FridayThursday')");
        $period2Id = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO payroll_run (payroll_period_id, branch_id, payroll_policy_id, status, gross_pay, total_deductions, net_pay, lock_version, created_at, updated_at) VALUES ({$period2Id}, {$b['branchId']}, {$b['policyId']}, 'Draft', 0, 0, 0, 0, NOW(), NOW())");
        $run2Id = (int) $this->pdo->lastInsertId();
        $this->insertAttendance($b['empId'], $b['baId'], $b['schedId'], '2026-08-14', '07:00:00', '16:00:00', 480, 0, 0, 0, 'Complete');
        $this->insertAttendance($b['empId'], $b['baId'], $b['schedId'], '2026-08-16', '07:00:00', '16:00:00', 480, 0, 0, 0, 'Complete');

        $payrollSvc->computeRun($run2Id, $b['userId']);
        $payrollSvc->submitForApproval($run2Id, $b['userId']);
        $payrollSvc->approve($run2Id, $b['userId']);

        $caAfter = $this->pdo->query("SELECT remaining_balance, status FROM cash_advance_history WHERE request_id = {$reqCa}")->fetch();
        self::assertSame(0.00, (float) $caAfter['remaining_balance']);
        self::assertSame('Paid', (string) $caAfter['status']);
    }

    /** Owner logic review C6: Compute lists and blocks on days it would leave unpaid. */
    public function testComputeIsBlockedUntilHrConfirmsDaysThatWouldBeUnpaid(): void
    {
        // Fri 02/06 .. Thu 02/12/26; Sun–Fri schedule, Saturday 02/07 is the rest day.
        $b = $this->seedRun('2026-02-06', '2026-02-12', '2026-02-13');
        $this->pdo->exec("INSERT INTO salary (employee_id, daily_rate, effective_from, effective_to, status, created_by) VALUES ({$b['empId']}, 500, '2020-01-01', NULL, 'Active', {$b['userId']})");
        $this->pdo->exec("INSERT INTO employee_schedule_assignment (employee_id, schedule_id, effective_from, effective_to, status) VALUES ({$b['empId']}, {$b['schedId']}, '2020-01-01', NULL, 'Active')");
        foreach (['2026-02-06', '2026-02-08', '2026-02-09'] as $date) {
            $this->insertAttendance($b['empId'], $b['baId'], $b['schedId'], $date, '07:00:00', '16:00:00', 480, 0, 0, 0, 'Complete');
        }
        $this->insertAttendance($b['empId'], $b['baId'], $b['schedId'], '2026-02-10', '07:00:00', null, 0, 0, 0, 0, 'Incomplete');
        $this->insertAttendance($b['empId'], $b['baId'], $b['schedId'], '2026-02-11', '07:08:00', '17:07:00', 539, 8, 0, 0, 'ReviewRequired');
        // 02/12 has no attendance and no upload covers it.

        $svc = new PayrollService(new Connection([], $this->pdo));
        $issues = $svc->readinessIssues($b['runId']);
        self::assertSame(
            [['2026-02-10', 'INCOMPLETE'], ['2026-02-11', 'REVIEW_REQUIRED'], ['2026-02-12', 'NO_UPLOAD']],
            array_map(static fn (array $i): array => [$i['date'], $i['issue']], $issues),
        );

        try {
            $svc->computeRunIfReady($b['runId'], $b['userId'], false);
            self::fail('Compute should be blocked until HR confirms the unpaid days.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('3 day(s) would be left unpaid', $e->getMessage());
        }
        $blocked = $this->pdo->query("SELECT status, (SELECT COUNT(*) FROM payroll WHERE payroll_run_id = {$b['runId']}) AS rows_computed FROM payroll_run WHERE payroll_run_id = {$b['runId']}")->fetch();
        self::assertSame('Draft', $blocked['status']);
        self::assertSame(0, (int) $blocked['rows_computed']);

        self::assertSame(3, $svc->computeRunIfReady($b['runId'], $b['userId'], true));
        $gross = $this->pdo->query("SELECT gross_pay FROM payroll WHERE payroll_run_id = {$b['runId']}")->fetchColumn();
        self::assertSame(1500.00, (float) $gross, 'Only the 3 complete days are paid.');
        $audit = $this->pdo->query("SELECT COUNT(*) FROM audit_logs WHERE record_id = {$b['runId']} AND action_performed = 'compute_with_unresolved_days'")->fetchColumn();
        self::assertSame(1, (int) $audit);
    }

    /** @return array{userId:int,branchId:int,periodId:int,policyId:int,runId:int,schedId:int,empId:int,baId:int} */
    private function seedRun(string $pStart, string $pEnd, string $payDate): array
    {
        $u = substr(uniqid(), -6);
        $this->pdo->exec("INSERT INTO role (role_name) VALUES ('HRHead_{$u}')");
        $roleId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO users (role_id, username, account_email, password_hash, status) VALUES ({$roleId}, 'hr_{$u}', 'hr_{$u}@t.test', 'x', 'Active')");
        $userId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO branch (branch_code, branch_name, status) VALUES ('BR{$u}', 'Branch {$u}', 'Active')");
        $branchId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO payroll_period (period_start, period_end, pay_date, status, cutoff_pattern) VALUES ('{$pStart}', '{$pEnd}', '{$payDate}', 'Open', 'FridayThursday')");
        $periodId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO payroll_policy_version (policy_code, version, effective_from, annual_tax_threshold, late_rate_per_minute, rounding_mode, status) VALUES ('POL{$u}', '1.0', '2020-01-01', 250000.00, 1.00, 'HalfUp', 'Approved')");
        $policyId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO payroll_run (payroll_period_id, branch_id, payroll_policy_id, status, gross_pay, total_deductions, net_pay, lock_version, created_at, updated_at) VALUES ({$periodId}, {$branchId}, {$policyId}, 'Draft', 0, 0, 0, 0, NOW(), NOW())");
        $runId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO work_schedule (schedule_name, working_days, rest_days, break_minutes, grace_minutes, overtime_allowed, break_start_time, break_end_time, work_start_time, work_end_time, standard_minutes, effective_from, status) VALUES ('SunFri_{$u}', '[\"Sunday\",\"Monday\",\"Tuesday\",\"Wednesday\",\"Thursday\",\"Friday\"]', '[\"Saturday\"]', 60, 0, 1, '12:00:00', '13:00:00', '07:00:00', '16:00:00', 480, '2020-01-01', 'Active')");
        $schedId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO employee (employee_number, employee_type, first_name, last_name, email, hire_date, position, status) VALUES ('E{$u}', 'Regular', 'Test', 'Emp', 'e{$u}@t.test', '2020-01-01', 'Staff', 'Active')");
        $empId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO employee_branch_assignment (employee_id, branch_id, effective_from, effective_to, created_at) VALUES ({$empId}, {$branchId}, '2020-01-01', NULL, NOW())");
        $baId = (int) $this->pdo->lastInsertId();
        return compact('userId', 'branchId', 'periodId', 'policyId', 'runId', 'schedId', 'empId', 'baId');
    }

    private function insertAttendance(
        int $empId,
        int $baId,
        ?int $schedId,
        string $date,
        ?string $timeIn,
        ?string $timeOut,
        int $worked,
        int $late,
        int $undertime,
        int $overtime,
        string $status,
    ): int {
        $stmt = $this->pdo->prepare(
            "INSERT INTO attendance
                (employee_id, branch_assignment_id, schedule_id, attendance_date,
                 time_in, time_out, hours_worked_minutes, late_minutes,
                 undertime_minutes, overtime_minutes, status, source)
             VALUES
                (:emp, :ba, :sched, :dt, :in, :out, :w, :l, :u, :ot, :st, 'manual')"
        );
        $stmt->execute([
            ':emp' => $empId,
            ':ba' => $baId,
            ':sched' => $schedId,
            ':dt' => $date,
            ':in' => $timeIn,
            ':out' => $timeOut,
            ':w' => $worked,
            ':l' => $late,
            ':u' => $undertime,
            ':ot' => $overtime,
            ':st' => $status,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    private function assertEarningsMatchGrossPay(int $runId): void
    {
        $rows = $this->pdo->query(
            "SELECT p.payroll_id, p.gross_pay, COALESCE(SUM(pe.amount), 0) AS earnings_sum
               FROM payroll p
               LEFT JOIN payroll_earnings pe ON pe.payroll_id = p.payroll_id
              WHERE p.payroll_run_id = {$runId}
              GROUP BY p.payroll_id, p.gross_pay"
        )->fetchAll();
        self::assertNotEmpty($rows, 'Expected at least one payroll row to verify earnings invariant.');
        foreach ($rows as $row) {
            self::assertSame(
                round((float) $row['gross_pay'], 2),
                round((float) $row['earnings_sum'], 2),
                "SUM(payroll_earnings.amount) must equal payroll.gross_pay for payroll_id={$row['payroll_id']}.",
            );
        }
    }
}

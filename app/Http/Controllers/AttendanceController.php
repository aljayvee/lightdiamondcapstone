<?php

declare(strict_types=1);

namespace Wbpms\Http\Controllers;

use DateTimeZone;
use RuntimeException;
use Wbpms\Application\AttendanceAdjustmentService;
use Wbpms\Application\Attendance\AttendanceImportService;
use Wbpms\Application\Attendance\DuplicateAttendanceFileException;
use Wbpms\Domain\Attendance\Parsing\AttendanceParseException;
use Wbpms\Domain\Attendance\Parsing\LdeXlsDailyLogParser;
use Wbpms\Domain\Attendance\Parsing\ParserContext;
use Wbpms\Domain\Attendance\Parsing\UploadedAttendanceFile;
use Wbpms\Domain\Attendance\TimesheetGenerator;
use Wbpms\Http\Middleware\AuthMiddleware;
use Wbpms\Http\View\ViewRenderer;
use Wbpms\Http\View\UserMessage;
use Wbpms\Infrastructure\Database\Connection;
use Wbpms\Infrastructure\Persistence\PdoAttendanceImportGateway;
use Wbpms\Infrastructure\Logging\ErrorLog;

/**
 * AttendanceController — HR attendance management.
 *
 * Routes (all require HRHead role):
 *   GET  /hr/attendance         → index()  — attendance list / timesheet view
 *   GET  /hr/attendance/import  → import() — upload form with recent batches
 *   POST /hr/attendance/import  → upload() — process XLS upload end-to-end
 *   GET  /hr/attendance/{id}/adjust  → adjustment form
 *   POST /hr/attendance/{id}/adjust → persist an audited correction
 *
 * REQ018–REQ024 (import) and the HR attendance list view.
 *
 * ADR-0003 upload controls applied in upload():
 *   - Extension + OLE signature check before any processing.
 *   - File stored to storage/private/attendance/ with randomized name.
 *   - File removed in finally block after import completes or fails.
 *   - Max size: 10 MiB.
 *   - SHA-256 duplicate detection via AttendanceImportService/gateway.
 */
final class AttendanceController
{
    private const PREVIEW_SESSION_KEY = '_attendance_import_preview';
    private const PREVIEW_TTL_SECONDS = 1800;
    private const PREVIEW_MAX_PUNCHES = 1000;

    // -----------------------------------------------------------------------
    // GET /hr/attendance
    // -----------------------------------------------------------------------

    /** @param array<string, string> $params */
    public function index(array $params = []): void
    {
        $pdo = $this->makeConnection()->pdo();

        // ---------------------------------------------------------------
        // Filter resolution
        //
        // Priority: period_id (cut-off) > date_from/date_to (range) > month > (default: current month)
        // ---------------------------------------------------------------
        $periodId   = isset($_GET['period_id']) && $_GET['period_id'] !== ''
                      ? (int) $_GET['period_id'] : null;
        $monthInput = trim((string) ($_GET['month'] ?? ''));
        $tabHint    = (string) ($_GET['_tab'] ?? '');

        // Date-range inputs (CR-4)
        $dateRangeFrom = trim((string) ($_GET['date_from'] ?? ''));
        $dateRangeTo   = trim((string) ($_GET['date_to']   ?? ''));

        // Load all payroll periods for the cut-off dropdown
        $periods = $pdo->query(
            "SELECT payroll_period_id, period_start, period_end, pay_date, status
               FROM payroll_period
              ORDER BY period_start DESC"
        )->fetchAll();

        // Resolve date range
        $dateFrom  = null;
        $dateTo    = null;
        $activeTab = 'cutoff'; // 'month' | 'cutoff' | 'range'

        // Attendance review follows the payroll cadence by default. HR can
        // still switch to a full calendar month or a custom date range from the filter bar.
        if ($periodId === null && $tabHint !== 'month' && $tabHint !== 'range' && $periods !== []) {
            $periodId = (int) $periods[0]['payroll_period_id'];
        }

        // --- Range tab takes explicit priority when both date fields are supplied ---
        if ($tabHint === 'range' && $dateRangeFrom !== '' && $dateRangeTo !== '') {
            // Validate ISO dates
            $parsedFrom = \DateTimeImmutable::createFromFormat('!Y-m-d', $dateRangeFrom);
            $parsedTo   = \DateTimeImmutable::createFromFormat('!Y-m-d', $dateRangeTo);
            if ($parsedFrom !== false && $parsedTo !== false && $dateRangeFrom <= $dateRangeTo) {
                $dateFrom  = $dateRangeFrom;
                $dateTo    = $dateRangeTo;
                $activeTab = 'range';
                $periodId  = null; // don't activate cut-off
            }
        }

        if ($dateFrom === null && $periodId !== null) {
            // Cut-off filter
            $stmt = $pdo->prepare(
                "SELECT period_start, period_end FROM payroll_period WHERE payroll_period_id = :id"
            );
            $stmt->execute([':id' => $periodId]);
            $period = $stmt->fetch();
            if ($period) {
                $dateFrom  = $period['period_start'];
                $dateTo    = $period['period_end'];
                $activeTab = 'cutoff';
            } else {
                $periodId = null; // invalid id — fall through to month
            }
        }

        if ($dateFrom === null) {
            // Month filter — default to current Manila month
            $manilaNow = new \DateTimeImmutable('now', new \DateTimeZone('Asia/Manila'));
            if ($monthInput !== '' && preg_match('/^\d{4}-(?:0[1-9]|1[0-2])$/', $monthInput)) {
                [$yr, $mo] = array_map('intval', explode('-', $monthInput));
            } else {
                $yr = (int) $manilaNow->format('Y');
                $mo = (int) $manilaNow->format('m');
                $monthInput = $manilaNow->format('Y-m');
            }
            $dateFrom  = sprintf('%04d-%02d-01', $yr, $mo);
            $dateTo    = (new \DateTimeImmutable($dateFrom))->modify('last day of this month')->format('Y-m-d');
            // Stay on cutoff tab if that's what the user selected, even with no period chosen
            $activeTab = $tabHint === 'cutoff' ? 'cutoff' : 'month';
        }

        // ---------------------------------------------------------------
        // Summary counts — scoped to the same date range
        // ---------------------------------------------------------------
        $countStmt = $pdo->prepare(
            "SELECT
                COUNT(*)                                                           AS total,
                SUM(CASE WHEN a.status IN ('Complete','Approved')   THEN 1 ELSE 0 END) AS complete,
                SUM(CASE WHEN a.status IN ('Incomplete','ReviewRequired') THEN 1 ELSE 0 END) AS incomplete
               FROM attendance a
               LEFT JOIN attendance_import_batch aib ON aib.import_batch_id = a.import_batch_id
              WHERE a.attendance_date BETWEEN :from AND :to
                AND a.status <> 'Cancelled'
                AND (aib.status IS NULL OR aib.status <> 'Cancelled')"
        );
        $countStmt->execute([':from' => $dateFrom, ':to' => $dateTo]);
        $counts     = $countStmt->fetch();
        $total      = (int) ($counts['total']      ?? 0);
        $complete   = (int) ($counts['complete']   ?? 0);
        $incomplete = (int) ($counts['incomplete'] ?? 0);

        // Unmatched punches within the same window (filter by source_local_at date part)
        $umStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM biometric_punch bp
               LEFT JOIN attendance_import_batch aib ON aib.import_batch_id = bp.import_batch_id
              WHERE bp.match_status IN ('unmatched','coverage_exception')
                AND (aib.status IS NULL OR aib.status <> 'Cancelled')
                AND DATE(bp.source_local_at) BETWEEN :from AND :to"
        );
        $umStmt->execute([':from' => $dateFrom, ':to' => $dateTo]);
        $unmatched = (int) $umStmt->fetchColumn();

        $umListStmt = $pdo->prepare(
            "SELECT bp.device_id,
                    COALESCE(bd.device_name, CONCAT('Device #', bp.device_id)) AS device_name,
                    bp.device_employee_code,
                    MAX(bp.source_employee_name) AS source_employee_name,
                    bp.match_status,
                    COUNT(*) AS punch_count,
                    MIN(DATE(bp.source_local_at)) AS first_punch_date,
                    MAX(DATE(bp.source_local_at)) AS last_punch_date
               FROM biometric_punch bp
               LEFT JOIN biometric_device bd ON bd.device_id = bp.device_id
               LEFT JOIN attendance_import_batch aib ON aib.import_batch_id = bp.import_batch_id
              WHERE bp.match_status IN ('unmatched','coverage_exception')
                AND (aib.status IS NULL OR aib.status <> 'Cancelled')
                AND DATE(bp.source_local_at) BETWEEN :from AND :to
              GROUP BY bp.device_id, bd.device_name, bp.device_employee_code, bp.match_status
              ORDER BY last_punch_date DESC, bp.device_employee_code ASC
              LIMIT 50"
        );
        $umListStmt->execute([':from' => $dateFrom, ':to' => $dateTo]);
        $unmatchedPunches = $umListStmt->fetchAll(\PDO::FETCH_ASSOC);

        $lockedStmt = $pdo->prepare(
            "SELECT pp.period_start, pp.period_end, pr.branch_id
               FROM payroll_run pr
               JOIN payroll_period pp ON pp.payroll_period_id = pr.payroll_period_id
              WHERE pr.status = 'Approved'
                AND pp.period_start <= :to
                AND pp.period_end >= :from"
        );
        $lockedStmt->execute([':from' => $dateFrom, ':to' => $dateTo]);
        $lockedPayrollPeriods = $lockedStmt->fetchAll(\PDO::FETCH_ASSOC);

        // ---------------------------------------------------------------
        // Main row query — filtered + branch filter + employee search
        // ---------------------------------------------------------------
        $search   = trim((string) ($_GET['search']   ?? ''));
        $branchId = isset($_GET['branch_id']) && $_GET['branch_id'] !== ''
                    ? (int) $_GET['branch_id'] : null;

        // Cancelled days (a cancelled row or a row of a cancelled import) are
        // voided: they must not appear as gray cards or add to the Hours, Late
        // and UT totals. The day then shows as ABSENT / NOT UPLOADED like any
        // other day without usable attendance. [QA ATT TC5]
        $where  = [
            'a.attendance_date BETWEEN :from AND :to',
            "a.status <> 'Cancelled'",
            "(aib.status IS NULL OR aib.status <> 'Cancelled')",
        ];
        $bind   = [':from' => $dateFrom, ':to' => $dateTo];

        if ($search !== '') {
            // Every word in any order: first, middle, last name or number (REQ012).
            [$nameSql, $nameParams] = \Wbpms\Infrastructure\Persistence\EmployeeNameSearch::condition($search, 'e', 'search_q');
            if ($nameSql !== '') {
                $where[] = $nameSql;
                $bind    = array_merge($bind, $nameParams);
            }
        }
        if ($branchId !== null) {
            $where[]           = 'eba.branch_id = :branch_id';
            $bind[':branch_id'] = $branchId;
        }

        $whereClause = 'WHERE ' . implode(' AND ', $where);

        // The cap must cover the matrix: up to 200 employees (see the employee
        // query below) x 31 days. The old cap of 500 cut off a full month for
        // ~17+ employees, so names late in the alphabet showed as ABSENT.
        $rows = $pdo->prepare(
            "SELECT a.attendance_id,
                    a.employee_id,
                    e.employee_number,
                    CONCAT(e.last_name, ', ', e.first_name) AS employee_name,
                    b.branch_name,
                    a.attendance_date,
                    a.time_in,
                    a.time_out,
                    a.hours_worked_minutes AS worked_minutes,
                    a.late_minutes,
                    a.undertime_minutes,
                    a.overtime_minutes,
                    CASE
                        WHEN aib.status = 'Draft' THEN 'Draft'
                        WHEN aib.status = 'Cancelled' THEN 'Cancelled'
                        ELSE a.status
                    END AS status,
                    CASE WHEN a.status IN ('Incomplete','ReviewRequired') THEN 1 ELSE 0 END AS is_incomplete
               FROM attendance a
               JOIN employee e ON e.employee_id = a.employee_id
               JOIN employee_branch_assignment eba
                     ON eba.branch_assignment_id = a.branch_assignment_id
               LEFT JOIN branch b ON b.branch_id = eba.branch_id
               LEFT JOIN attendance_import_batch aib ON aib.import_batch_id = a.import_batch_id
               {$whereClause}
              ORDER BY e.last_name ASC, e.first_name ASC, a.attendance_date ASC
              LIMIT 6200"
        );
        $rows->execute($bind);
        $rows = $rows->fetchAll();

        // Matrix rows include active employees with no attendance yet, keeping
        // the employee list stable while HR scans a month or payroll cut-off.
        $employeeWhere = ["e.status = 'Active'"];
        $employeeBind = [];
        if ($search !== '') {
            [$nameSql, $nameParams] = \Wbpms\Infrastructure\Persistence\EmployeeNameSearch::condition($search, 'e', 'search_q');
            if ($nameSql !== '') {
                $employeeWhere[] = $nameSql;
                $employeeBind    = array_merge($employeeBind, $nameParams);
            }
        }
        if ($branchId !== null) {
            $employeeWhere[] = 'eba.branch_id = :branch_id';
            $employeeBind[':branch_id'] = $branchId;
        }
        $employeeClause = 'WHERE ' . implode(' AND ', $employeeWhere);
        $employees = $pdo->prepare(
            "SELECT e.employee_id, e.employee_number, e.hire_date,
                    CONCAT(e.last_name, ', ', e.first_name) AS employee_name,
                    eba.branch_id,
                    b.branch_name
               FROM employee e
               LEFT JOIN employee_branch_assignment eba
                      ON eba.employee_id = e.employee_id AND eba.effective_to IS NULL
               LEFT JOIN branch b ON b.branch_id = eba.branch_id
              {$employeeClause}
              ORDER BY e.last_name ASC, e.first_name ASC
              LIMIT 200"
        );
        $employees->execute($employeeBind);
        $employees = $employees->fetchAll();

        $dates = [];
        for ($day = new \DateTimeImmutable($dateFrom); $day <= new \DateTimeImmutable($dateTo); $day = $day->modify('+1 day')) {
            $dates[] = $day->format('Y-m-d');
        }

        // Determine expected workdays from each employee's effective schedule.
        // A missing or malformed schedule deliberately does not create an
        // absence: HR must first assign a valid schedule before a no-show can
        // be classified as an absence.
        $scheduledWorkingDays = $this->scheduledWorkingDays($pdo, $employees, $dates, $dateFrom, $dateTo);
        // An employee can only be absent after an approved device import
        // covers that employee's branch for the source month. Missing or draft
        // uploads are data-availability states, not employee absences.
        $attendanceUploadCoverage = $this->attendanceUploadCoverage($pdo, $employees, $dates);

        // Approved leave is a display state in the attendance matrix. It takes
        // precedence over a derived absence and links back to the source request.
        $leaveStmt = $pdo->prepare(
            "SELECT r.request_id, r.employee_id, lrd.leave_type, lrd.start_date, lrd.end_date
               FROM request r
               JOIN request_type rt ON rt.request_type_id = r.request_type_id AND rt.type_name = 'Leave'
               JOIN leave_request_detail lrd ON lrd.request_id = r.request_id
              WHERE r.status = 'Approved'
                AND lrd.start_date <= :to_date
                AND lrd.end_date >= :from_date"
        );
        $leaveStmt->execute([':from_date' => $dateFrom, ':to_date' => $dateTo]);
        $approvedLeaves = [];
        foreach ($leaveStmt->fetchAll() as $leave) {
            $start = max((string) $leave['start_date'], $dateFrom);
            $end = min((string) $leave['end_date'], $dateTo);
            for ($day = new \DateTimeImmutable($start); $day <= new \DateTimeImmutable($end); $day = $day->modify('+1 day')) {
                $approvedLeaves[(int) $leave['employee_id']][$day->format('Y-m-d')] = [
                    'request_id' => (int) $leave['request_id'],
                    'leave_type' => (string) $leave['leave_type'],
                ];
            }
        }
        $holidayStmt = $pdo->prepare(
            "SELECT holiday_date, description FROM holiday_calendar
              WHERE status = 'Active' AND holiday_date BETWEEN :from_date AND :to_date"
        );
        $holidayStmt->execute([':from_date' => $dateFrom, ':to_date' => $dateTo]);
        $holidayDates = [];
        foreach ($holidayStmt->fetchAll() as $holiday) {
            $holidayDates[(string) $holiday['holiday_date']] = (string) $holiday['description'];
        }
        $today = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Manila')))->format('Y-m-d');

        // Branch list for filter dropdown
        $branches = $pdo->query(
            "SELECT branch_id, branch_name FROM branch WHERE status = 'Active' ORDER BY branch_name"
        )->fetchAll();

        ViewRenderer::render('hr/attendance/index', [
            'rows'        => $rows,
            'employees'   => $employees,
            'dates'       => $dates,
            'scheduledWorkingDays' => $scheduledWorkingDays,
            'attendanceUploadCoverage' => $attendanceUploadCoverage,
            'approvedLeaves' => $approvedLeaves,
            'holidayDates' => $holidayDates,
            'today'       => $today,
            'total'       => $total,
            'complete'    => $complete,
            'incomplete'  => $incomplete,
            'unmatched'   => $unmatched,
            'unmatchedPunches' => $unmatchedPunches,
            'lockedPayrollPeriods' => $lockedPayrollPeriods,
            // filter state
            'activeTab'    => $activeTab,
            'monthInput'   => $monthInput,
            'periodId'     => $periodId,
            'dateFrom'     => $dateFrom,
            'dateTo'       => $dateTo,
            'dateRangeFrom' => $dateRangeFrom,
            'dateRangeTo'   => $dateRangeTo,
            'periods'      => $periods,
            'branches'     => $branches,
            'branchId'     => $branchId,
            'search'       => $search,
        ], 'Attendance Management');
    }

    // -----------------------------------------------------------------------
    // GET /hr/attendance/frequency-report
    // -----------------------------------------------------------------------

    /**
     * Frequency view for HR: counts late arrivals and verified absence days.
     * An absence is only counted after a completed scheduled workday where an
     * approved attendance import covers the employee's branch. This keeps a
     * missing upload, a holiday, and approved leave out of the report.
     *
     * @param array<string, string> $params
     */
    public function frequencyReport(array $params = []): void
    {
        $pdo = $this->makeConnection()->pdo();
        $manilaNow = new \DateTimeImmutable('now', new \DateTimeZone('Asia/Manila'));
        $defaultFrom = $manilaNow->modify('first day of this month')->format('Y-m-d');
        $defaultTo = $manilaNow->format('Y-m-d');

        $dateFrom = trim((string) ($_GET['date_from'] ?? $defaultFrom));
        $dateTo = trim((string) ($_GET['date_to'] ?? $defaultTo));
        if (!$this->isIsoDate($dateFrom) || !$this->isIsoDate($dateTo) || $dateFrom > $dateTo) {
            $dateFrom = $defaultFrom;
            $dateTo = $defaultTo;
        }
        // A bounded range keeps the matrix-equivalent absence calculation
        // responsive while still allowing a full rolling year.
        if ((new \DateTimeImmutable($dateFrom))->diff(new \DateTimeImmutable($dateTo))->days > 366) {
            $dateFrom = (new \DateTimeImmutable($dateTo))->modify('-366 days')->format('Y-m-d');
        }

        $branchValue = filter_var($_GET['branch_id'] ?? null, FILTER_VALIDATE_INT);
        $branchId = $branchValue !== false && $branchValue !== null && $branchValue > 0 ? $branchValue : null;
        $search = trim((string) ($_GET['search'] ?? ''));

        $employeeWhere = ["e.status = 'Active'"];
        $employeeBind = [];
        if ($branchId !== null) {
            $employeeWhere[] = 'eba.branch_id = :branch_id';
            $employeeBind[':branch_id'] = $branchId;
        }
        if ($search !== '') {
            [$nameSql, $nameParams] = \Wbpms\Infrastructure\Persistence\EmployeeNameSearch::condition($search, 'e', 'search_q');
            if ($nameSql !== '') {
                $employeeWhere[] = $nameSql;
                $employeeBind    = array_merge($employeeBind, $nameParams);
            }
        }
        $employeeStmt = $pdo->prepare(
            "SELECT e.employee_id, e.employee_number, e.hire_date,
                    CONCAT(e.last_name, ', ', e.first_name) AS employee_name,
                    b.branch_name
               FROM employee e
               LEFT JOIN employee_branch_assignment eba
                      ON eba.employee_id = e.employee_id AND eba.effective_to IS NULL
               LEFT JOIN branch b ON b.branch_id = eba.branch_id
              WHERE " . implode(' AND ', $employeeWhere) . "
              ORDER BY e.last_name ASC, e.first_name ASC
              LIMIT 200"
        );
        $employeeStmt->execute($employeeBind);
        $employees = $employeeStmt->fetchAll();

        $dates = [];
        for ($day = new \DateTimeImmutable($dateFrom); $day <= new \DateTimeImmutable($dateTo); $day = $day->modify('+1 day')) {
            $dates[] = $day->format('Y-m-d');
        }
        $scheduledWorkingDays = $this->scheduledWorkingDays($pdo, $employees, $dates, $dateFrom, $dateTo);
        $attendanceUploadCoverage = $this->attendanceUploadCoverage($pdo, $employees, $dates);

        $employeeIds = array_map(static fn(array $employee): int => (int) $employee['employee_id'], $employees);
        $attendanceByEmployeeDate = [];
        $tardiness = [];
        if ($employeeIds !== []) {
            $placeholders = [];
            $attendanceBind = [':report_from' => $dateFrom, ':report_to' => $dateTo];
            foreach ($employeeIds as $index => $employeeId) {
                $placeholder = ':report_employee_' . $index;
                $placeholders[] = $placeholder;
                $attendanceBind[$placeholder] = $employeeId;
            }
            $attendanceStmt = $pdo->prepare(
                "SELECT a.employee_id, a.attendance_date, a.late_minutes
                   FROM attendance a
                   LEFT JOIN attendance_import_batch aib ON aib.import_batch_id = a.import_batch_id
                  WHERE a.employee_id IN (" . implode(', ', $placeholders) . ")
                    AND a.attendance_date BETWEEN :report_from AND :report_to
                    AND a.status <> 'Cancelled'
                    AND (aib.status IS NULL OR aib.status <> 'Cancelled')"
            );
            $attendanceStmt->execute($attendanceBind);
            foreach ($attendanceStmt->fetchAll() as $attendance) {
                $employeeId = (int) $attendance['employee_id'];
                $attendanceDate = (string) $attendance['attendance_date'];
                $attendanceByEmployeeDate[$employeeId][$attendanceDate] = true;
                $lateMinutes = (int) $attendance['late_minutes'];
                if ($lateMinutes > 0) {
                    $tardiness[$employeeId]['days'] = ($tardiness[$employeeId]['days'] ?? 0) + 1;
                    $tardiness[$employeeId]['minutes'] = ($tardiness[$employeeId]['minutes'] ?? 0) + $lateMinutes;
                }
            }
        }

        $approvedLeaves = [];
        $leaveStmt = $pdo->prepare(
            "SELECT r.employee_id, lrd.start_date, lrd.end_date
               FROM request r
               JOIN request_type rt ON rt.request_type_id = r.request_type_id AND rt.type_name = 'Leave'
               JOIN leave_request_detail lrd ON lrd.request_id = r.request_id
              WHERE r.status = 'Approved'
                AND lrd.start_date <= :report_to AND lrd.end_date >= :report_from"
        );
        $leaveStmt->execute([':report_from' => $dateFrom, ':report_to' => $dateTo]);
        $selectedEmployeeIds = array_fill_keys($employeeIds, true);
        foreach ($leaveStmt->fetchAll() as $leave) {
            $employeeId = (int) $leave['employee_id'];
            if (!isset($selectedEmployeeIds[$employeeId])) {
                continue;
            }
            $start = max((string) $leave['start_date'], $dateFrom);
            $end = min((string) $leave['end_date'], $dateTo);
            for ($day = new \DateTimeImmutable($start); $day <= new \DateTimeImmutable($end); $day = $day->modify('+1 day')) {
                $approvedLeaves[$employeeId][$day->format('Y-m-d')] = true;
            }
        }

        $holidayStmt = $pdo->prepare(
            "SELECT holiday_date FROM holiday_calendar
              WHERE status = 'Active' AND holiday_date BETWEEN :report_from AND :report_to"
        );
        $holidayStmt->execute([':report_from' => $dateFrom, ':report_to' => $dateTo]);
        $holidayDates = array_fill_keys(array_map(
            static fn(array $holiday): string => (string) $holiday['holiday_date'],
            $holidayStmt->fetchAll()
        ), true);
        $today = $manilaNow->format('Y-m-d');

        $reportRows = [];
        $summary = ['tardy_days' => 0, 'late_minutes' => 0, 'absence_days' => 0, 'employees_affected' => 0];
        foreach ($employees as $employee) {
            $employeeId = (int) $employee['employee_id'];
            $absenceDays = 0;
            foreach ($dates as $date) {
                $isWorkingDay = ($scheduledWorkingDays[$employeeId][$date] ?? false) && !isset($holidayDates[$date]);
                if (!isset($attendanceByEmployeeDate[$employeeId][$date])
                    && !isset($approvedLeaves[$employeeId][$date])
                    && $date < $today
                    && $date >= (string) $employee['hire_date']
                    && $isWorkingDay
                    && ($attendanceUploadCoverage[$employeeId][$date] ?? null) === 'Covered') {
                    $absenceDays++;
                }
            }
            $tardyDays = (int) ($tardiness[$employeeId]['days'] ?? 0);
            $lateMinutes = (int) ($tardiness[$employeeId]['minutes'] ?? 0);
            $frequency = $tardyDays + $absenceDays;
            $reportRows[] = [
                'employee_number' => (string) $employee['employee_number'],
                'employee_name' => (string) $employee['employee_name'],
                'branch_name' => (string) ($employee['branch_name'] ?? '—'),
                'tardy_days' => $tardyDays,
                'late_minutes' => $lateMinutes,
                'absence_days' => $absenceDays,
                'frequency' => $frequency,
            ];
            $summary['tardy_days'] += $tardyDays;
            $summary['late_minutes'] += $lateMinutes;
            $summary['absence_days'] += $absenceDays;
            $summary['employees_affected'] += $frequency > 0 ? 1 : 0;
        }
        usort($reportRows, static fn(array $left, array $right): int =>
            $right['frequency'] <=> $left['frequency'] ?: strcmp($left['employee_name'], $right['employee_name'])
        );

        $isOwner = (AuthMiddleware::identity()['role_name'] ?? '') === 'BusinessOwner';
        ViewRenderer::render('hr/attendance/frequency-report', [
            'rows' => $reportRows,
            'summary' => $summary,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'branches' => $pdo->query("SELECT branch_id, branch_name FROM branch WHERE status = 'Active' ORDER BY branch_name")->fetchAll(),
            'branchId' => $branchId,
            'search' => $search,
            'activePage' => $isOwner ? 'reports' : 'attendance',
            'backPath' => $isOwner ? '/hr/reports' : '/hr/attendance',
            'backLabel' => $isOwner ? 'Back to Reports' : 'Back to Attendance',
        ], 'Attendance Frequency Report');
    }

    /**
     * @param list<array<string, mixed>> $employees
     * @param list<string> $dates
     * @return array<int, array<string, bool>> indexed by employee ID and date
     */
    private function scheduledWorkingDays(\PDO $pdo, array $employees, array $dates, string $dateFrom, string $dateTo): array
    {
        if ($employees === [] || $dates === []) {
            return [];
        }

        $params = [':date_from' => $dateFrom, ':date_to' => $dateTo];
        $placeholders = [];
        foreach ($employees as $index => $employee) {
            $parameter = ':employee_' . $index;
            $placeholders[] = $parameter;
            $params[$parameter] = (int) $employee['employee_id'];
        }

        $stmt = $pdo->prepare(
            "SELECT esa.employee_id, esa.effective_from, esa.effective_to, ws.working_days
               FROM employee_schedule_assignment esa
               JOIN work_schedule ws ON ws.schedule_id = esa.schedule_id
              WHERE esa.employee_id IN (" . implode(', ', $placeholders) . ")
                AND esa.effective_from <= :date_to
                AND (esa.effective_to IS NULL OR esa.effective_to >= :date_from)
                AND (esa.status = 'Active' OR esa.effective_to IS NOT NULL)
              ORDER BY esa.employee_id, esa.effective_from DESC"
        );
        $stmt->execute($params);

        $assignments = [];
        foreach ($stmt->fetchAll() as $row) {
            $workingDays = json_decode((string) $row['working_days'], true);
            if (!is_array($workingDays)) {
                continue;
            }
            $assignments[(int) $row['employee_id']][] = [
                'effective_from' => (string) $row['effective_from'],
                'effective_to' => $row['effective_to'] !== null ? (string) $row['effective_to'] : null,
                'working_days' => array_fill_keys(array_filter($workingDays, 'is_string'), true),
            ];
        }

        $result = [];
        foreach ($employees as $employee) {
            $employeeId = (int) $employee['employee_id'];
            foreach ($dates as $date) {
                foreach ($assignments[$employeeId] ?? [] as $assignment) {
                    if ($assignment['effective_from'] <= $date
                        && ($assignment['effective_to'] === null || $assignment['effective_to'] >= $date)) {
                        $dayName = (new \DateTimeImmutable($date))->format('l');
                        $result[$employeeId][$date] = isset($assignment['working_days'][$dayName]);
                        break;
                    }
                }
            }
        }

        return $result;
    }

    /**
     * @param list<array<string, mixed>> $employees
     * @param list<string> $dates
     * @return array<int, array<string, 'Covered'|'Draft'>> indexed by employee ID and date
     */
    private function attendanceUploadCoverage(\PDO $pdo, array $employees, array $dates): array
    {
        if ($employees === [] || $dates === []) {
            return [];
        }

        $monthPairs = [];
        foreach ($dates as $date) {
            $monthPairs[substr($date, 0, 7)] = true;
        }

        $params = [];
        $employeePlaceholders = [];
        foreach ($employees as $index => $employee) {
            $placeholder = ':coverage_employee_' . $index;
            $employeePlaceholders[] = $placeholder;
            $params[$placeholder] = (int) $employee['employee_id'];
        }
        $monthConditions = [];
        foreach (array_keys($monthPairs) as $index => $yearMonth) {
            $year = (int) substr($yearMonth, 0, 4);
            $month = (int) substr($yearMonth, 5, 2);
            $yearPlaceholder = ':coverage_year_' . $index;
            $monthPlaceholder = ':coverage_month_' . $index;
            $monthConditions[] = "(aib.source_year = {$yearPlaceholder} AND aib.source_month = {$monthPlaceholder})";
            $params[$yearPlaceholder] = $year;
            $params[$monthPlaceholder] = $month;
        }

        if ($this->hasBatchPunchRangeSchema($pdo)) {
            $sql = "SELECT eba.employee_id, eba.effective_from AS assignment_from, eba.effective_to AS assignment_to,
                           dbb.effective_from AS device_from, dbb.effective_to AS device_to,
                           aib.source_year, aib.source_month,
                           MAX(CASE WHEN aib.status IN ('Approved', 'Completed') THEN 1 ELSE 0 END) AS has_approved,
                           MIN(CASE WHEN aib.status IN ('Approved', 'Completed') THEN DATE(bp.source_local_at) ELSE NULL END) AS approved_min,
                           MAX(CASE WHEN aib.status IN ('Approved', 'Completed') THEN DATE(bp.source_local_at) ELSE NULL END) AS approved_max,
                           MAX(CASE WHEN aib.status IN ('Draft', 'Processing') THEN 1 ELSE 0 END) AS has_draft,
                           MIN(DATE(bp.source_local_at)) AS all_min,
                           MAX(DATE(bp.source_local_at)) AS all_max
                      FROM attendance_import_batch aib
                      JOIN biometric_device_branch dbb ON dbb.device_id = aib.device_id
                      JOIN employee_branch_assignment eba ON eba.branch_id = dbb.branch_id
                      LEFT JOIN biometric_punch bp ON bp.import_batch_id = aib.import_batch_id
                     WHERE eba.employee_id IN (" . implode(', ', $employeePlaceholders) . ")
                       AND aib.status IN ('Draft', 'Processing', 'Approved', 'Completed')
                       AND (" . implode(' OR ', $monthConditions) . ")
                     GROUP BY eba.employee_id, eba.effective_from, eba.effective_to,
                              dbb.effective_from, dbb.effective_to,
                              aib.device_id, aib.source_year, aib.source_month";
        } else {
            $sql = "SELECT eba.employee_id, eba.effective_from AS assignment_from, eba.effective_to AS assignment_to,
                           dbb.effective_from AS device_from, dbb.effective_to AS device_to,
                           aib.source_year, aib.source_month,
                           MAX(CASE WHEN aib.status IN ('Approved', 'Completed') THEN 1 ELSE 0 END) AS has_approved,
                           NULL AS approved_min,
                           NULL AS approved_max,
                           MAX(CASE WHEN aib.status IN ('Draft', 'Processing') THEN 1 ELSE 0 END) AS has_draft,
                           NULL AS all_min,
                           NULL AS all_max
                      FROM attendance_import_batch aib
                      JOIN biometric_device_branch dbb ON dbb.device_id = aib.device_id
                      JOIN employee_branch_assignment eba ON eba.branch_id = dbb.branch_id
                     WHERE eba.employee_id IN (" . implode(', ', $employeePlaceholders) . ")
                       AND aib.status IN ('Draft', 'Processing', 'Approved', 'Completed')
                       AND (" . implode(' OR ', $monthConditions) . ")
                     GROUP BY eba.employee_id, eba.effective_from, eba.effective_to,
                              dbb.effective_from, dbb.effective_to,
                              aib.device_id, aib.source_year, aib.source_month";
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $coverage = [];
        $priority = ['Draft' => 1, 'Covered' => 2];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $hasApproved = (int) $row['has_approved'] > 0;
            $hasDraft    = (int) $row['has_draft'] > 0;
            $allMin      = $row['all_min'] !== null ? (string) $row['all_min'] : null;
            $allMax      = $row['all_max'] !== null ? (string) $row['all_max'] : null;
            $approvedMin = $row['approved_min'] !== null ? (string) $row['approved_min'] : $allMin;
            $approvedMax = $row['approved_max'] !== null ? (string) $row['approved_max'] : $allMax;

            /** @var list<array{0:'Covered'|'Draft', 1:?string, 2:?string}> $ranges */
            $ranges = [];
            if ($hasApproved) {
                $ranges[] = ['Covered', $approvedMin, $approvedMax];
            }
            if ($hasDraft) {
                $ranges[] = ['Draft', $allMin, $allMax];
            }

            foreach ($dates as $date) {
                if ((int) substr($date, 0, 4) !== (int) $row['source_year']
                    || (int) substr($date, 5, 2) !== (int) $row['source_month']
                    || (string) $row['assignment_from'] > $date
                    || ($row['assignment_to'] !== null && (string) $row['assignment_to'] < $date)
                    || (string) $row['device_from'] > $date
                    || ($row['device_to'] !== null && (string) $row['device_to'] < $date)) {
                    continue;
                }
                $employeeId = (int) $row['employee_id'];
                foreach ($ranges as [$state, $rangeMin, $rangeMax]) {
                    if (($rangeMin !== null && $date < $rangeMin)
                        || ($rangeMax !== null && $date > $rangeMax)) {
                        continue;
                    }
                    $current = $coverage[$employeeId][$date] ?? null;
                    if ($current === null || $priority[$state] > $priority[$current]) {
                        $coverage[$employeeId][$date] = $state;
                    }
                }
            }
        }

        return $coverage;
    }

    private function hasBatchPunchRangeSchema(\PDO $pdo): bool
    {
        if ($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
            return true;
        }
        $tableStmt = $pdo->query(
            "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'biometric_punch' LIMIT 1"
        );
        if ($tableStmt === false || !(bool) $tableStmt->fetchColumn()) {
            return false;
        }
        $colStmt = $pdo->query('PRAGMA table_info(attendance_import_batch)');
        if ($colStmt === false) {
            return false;
        }
        foreach ($colStmt->fetchAll(\PDO::FETCH_ASSOC) as $col) {
            if (($col['name'] ?? '') === 'import_batch_id') {
                return true;
            }
        }
        return false;
    }

    // -----------------------------------------------------------------------
    // GET /hr/attendance/import
    // -----------------------------------------------------------------------

    /** @param array<string, string> $params */
    public function import(array $params = []): void
    {
        // Visiting the upload form abandons any unconfirmed preview.
        $this->clearStagedImport();
        $this->renderImport([], null);
    }

    // -----------------------------------------------------------------------
    // POST /hr/attendance/import
    // -----------------------------------------------------------------------

    /** @param array<string, string> $params */
    public function upload(array $params = []): void
    {
        $errors     = [];
        $storagePath = null;

        // Replacing a preview must never retain an older workbook in the session.
        $this->clearStagedImport();

        // --- 1. Basic upload presence check ---
        $uploadError = $_FILES['attendance_file']['error'] ?? UPLOAD_ERR_NO_FILE;
        if (empty($_FILES['attendance_file']) || $uploadError !== UPLOAD_ERR_OK) {
            $errors[] = $this->uploadErrorMessage((int) $uploadError);
        }

        if ($errors === []) {
            $file     = $_FILES['attendance_file'];
            $tmpPath  = (string) $file['tmp_name'];
            $origName = (string) $file['name'];
            $size     = (int) $file['size'];

            // --- 2. Size limit: 10 MiB (ADR-0003) ---
            if ($size > 10 * 1024 * 1024) {
                $errors[] = 'File exceeds the 10 MiB size limit.';
            }

            // --- 3. Extension check ---
            if (strtolower(pathinfo($origName, PATHINFO_EXTENSION)) !== 'xls') {
                $errors[] = 'Only .xls files exported from the biometric device are accepted.';
            }

            // --- 4. OLE signature check (ADR-0003) ---
            if ($errors === []) {
                $handle = fopen($tmpPath, 'rb');
                $sig    = $handle !== false ? fread($handle, 8) : '';
                if ($handle !== false) {
                    fclose($handle);
                }
                if ($sig !== "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1") {
                    $errors[] = 'File does not appear to be a valid BIFF8 .xls workbook (OLE signature mismatch).';
                }
            }

            // --- 5. Read device_id, source_year, source_month from POST ---
            $deviceId    = (int) ($_POST['device_id']    ?? 0);
            $sourceYear  = (int) ($_POST['source_year']  ?? 0);
            $sourceMonth = (int) ($_POST['source_month'] ?? 0);

            if ($deviceId < 1) {
                $errors[] = 'Please select the biometric device this file was exported from.';
            }
            if ($sourceYear < 2000 || $sourceYear > 2100) {
                $errors[] = 'Invalid source year.';
            }
            if ($sourceMonth < 1 || $sourceMonth > 12) {
                $errors[] = 'Invalid source month.';
            }

            // --- 6. Move to private storage with randomized name (ADR-0003) ---
            if ($errors === []) {
                $storageDir = APP_ROOT . '/storage/private/attendance/';
                if (!is_dir($storageDir)) {
                    mkdir($storageDir, 0750, true);
                }
                $storagePath = $storageDir . bin2hex(random_bytes(16)) . '.xls';

                if (!move_uploaded_file($tmpPath, $storagePath)) {
                    $errors[] = 'Failed to store uploaded file. Please try again.';
                    $storagePath = null;
                }
            }

            // --- 7. Parse and stage for review. This path has no database writes. ---
            if ($errors === [] && $storagePath !== null) {
                try {
                    $sha256 = (string) hash_file('sha256', $storagePath);

                    $uploadedFile = new UploadedAttendanceFile(
                        $storagePath,
                        $origName,
                        $size,
                        $sha256,
                    );

                    $context = new ParserContext(
                        deviceId:    $deviceId,
                        sourceYear:  $sourceYear,
                        sourceMonth: $sourceMonth,
                        timezone:    new DateTimeZone('Asia/Manila'),
                    );

                    $pdo     = $this->makeConnection()->pdo();
                    $gateway = new PdoAttendanceImportGateway($pdo);

                    if ($gateway->hasCompletedChecksum($sha256)) {
                        throw new DuplicateAttendanceFileException();
                    }

                    $parsed = (new LdeXlsDailyLogParser())->parse($uploadedFile, $context);
                    $preview = $this->buildPreview($parsed->punches, $gateway, $deviceId);
                    $preview['fileName'] = $origName;
                    $preview['deviceId'] = $deviceId;
                    $preview['sourceYear'] = $sourceYear;
                    $preview['sourceMonth'] = $sourceMonth;
                    $this->stageImport($storagePath, [
                        'original_filename' => $origName,
                        'size' => $size,
                        'sha256' => $sha256,
                        'device_id' => $deviceId,
                        'source_year' => $sourceYear,
                        'source_month' => $sourceMonth,
                    ]);
                    $storagePath = null;
                    $preview['token'] = (string) $this->stagedImport()['token'];
                    $this->renderImport([], $preview);
                    return;

                    /*
                    $summary = $service->import($uploadedFile, $context, 0);

                    // Update file_name now that we have the batch_id
                    // (the gateway createImportBatch stored an empty string;
                    //  fetch the row we just committed and patch it)
                    $this->patchFileName($pdo, $sha256, $origName);

                    ViewRenderer::flash(sprintf(
                        "Import complete — %d punches parsed, %d matched, %d unmatched, %d duplicates skipped, %d incomplete days.",
                        $summary->parsedTokens,
                        $summary->matchedPunches,
                        $summary->unmatchedPunches,
                        $summary->duplicatePunches,
                        $summary->incompleteDays,
                    ));
                    $this->redirect('/hr/attendance/import');
                    return;
                    */

                } catch (DuplicateAttendanceFileException) {
                    $errors[] = 'This file has already been imported (duplicate checksum detected).';

                } catch (AttendanceParseException $e) {
                    foreach ($e->errors as $err) {
                        $loc = '';
                        if ($err->row !== null)    { $loc .= " row {$err->row}"; }
                        if ($err->column !== null) { $loc .= " col {$err->column}"; }
                        $errors[] = "[{$err->code}]{$loc}: {$err->message}";
                    }

                } catch (\Throwable $e) {
                    ErrorLog::exception($e, 'attendance import upload');
                    $errors[] = UserMessage::prefixed('Import failed', $e);
                } finally {
                    // Always remove the temporary file (ADR-0003)
                    if ($storagePath !== null && file_exists($storagePath)) {
                        @unlink($storagePath);
                    }
                }
            } elseif ($storagePath !== null && file_exists($storagePath)) {
                @unlink($storagePath);
            }
        }

        // Re-render import form with errors
        $this->renderImport($errors, null);
    }

    /**
     * POST /hr/attendance/import/confirm.
     *
     * The reviewed workbook is parsed again and saved only through the
     * import service's single database transaction.
     *
     * @param array<string, string> $params
     */
    public function confirmImport(array $params = []): void
    {
        $staged = $this->stagedImport();
        $submittedToken = (string) ($_POST['preview_token'] ?? '');
        if ($staged === null || !hash_equals((string) $staged['token'], $submittedToken)) {
            ViewRenderer::flashError('This import preview has expired. Upload the workbook again.');
            $this->redirect('/hr/attendance/import');
            return;
        }

        $storagePath = (string) $staged['path'];
        if (!is_file($storagePath) || hash_file('sha256', $storagePath) !== $staged['sha256']) {
            $this->clearStagedImport();
            ViewRenderer::flashError('The staged workbook is no longer available. Upload it again.');
            $this->redirect('/hr/attendance/import');
            return;
        }

        try {
            $identity = AuthMiddleware::identity();
            $file = new UploadedAttendanceFile(
                $storagePath,
                (string) $staged['original_filename'],
                (int) $staged['size'],
                (string) $staged['sha256'],
            );
            $context = new ParserContext(
                deviceId: (int) $staged['device_id'],
                sourceYear: (int) $staged['source_year'],
                sourceMonth: (int) $staged['source_month'],
                timezone: new DateTimeZone('Asia/Manila'),
            );
            $pdo = $this->makeConnection()->pdo();
            $service = new AttendanceImportService(
                new LdeXlsDailyLogParser(),
                new PdoAttendanceImportGateway($pdo),
                new TimesheetGenerator(),
            );
            $summary = $service->import($file, $context, (int) ($identity['user_id'] ?? 0));
            $this->patchFileName($pdo, (string) $staged['sha256'], (string) $staged['original_filename']);
            $this->clearStagedImport();
            ViewRenderer::flash(sprintf(
                'Draft import saved - %d punches parsed, %d matched, %d unmatched, %d duplicates skipped, %d incomplete days. Approve it before it is used by payroll.',
                $summary->parsedTokens,
                $summary->matchedPunches,
                $summary->unmatchedPunches,
                $summary->duplicatePunches,
                $summary->incompleteDays,
            ));
            $this->redirect('/hr/attendance/import');
            return;
        } catch (DuplicateAttendanceFileException) {
            $errors = ['This file has already been imported (duplicate checksum detected).'];
        } catch (AttendanceParseException $e) {
            $errors = $this->formatParseErrors($e);
        } catch (\Throwable $e) {
            ErrorLog::exception($e, 'attendance import confirm');
            $errors = [UserMessage::prefixed('Import failed. No attendance records were saved', $e)];
        }

        $this->clearStagedImport();
        $this->renderImport($errors);
    }

    /** @param array<string, string> $params */
    public function approveImport(array $params = []): void
    {
        $batchId = (int) ($params['id'] ?? 0);
        $actorId = (int) (AuthMiddleware::identity()['user_id'] ?? 0);

        try {
            $sourceYear = 0;
            $sourceMonth = 0;
            $this->makeConnection()->transaction(function (\PDO $pdo) use ($batchId, $actorId, &$sourceYear, &$sourceMonth): void {
                $batch = $pdo->prepare(
                    'SELECT status, source_year, source_month FROM attendance_import_batch WHERE import_batch_id = :id FOR UPDATE'
                );
                $batch->execute([':id' => $batchId]);
                $row = $batch->fetch(\PDO::FETCH_ASSOC);
                if (!is_array($row) || ($row['status'] ?? '') !== 'Draft') {
                    throw new RuntimeException('Only a draft attendance import can be approved.');
                }
                $sourceYear = (int) ($row['source_year'] ?? 0);
                $sourceMonth = (int) ($row['source_month'] ?? 0);
                $pdo->prepare(
                    "UPDATE attendance_import_batch
                        SET status = 'Approved', approved_by = :user_id, approved_at = UTC_TIMESTAMP()
                      WHERE import_batch_id = :id"
                )->execute([':id' => $batchId, ':user_id' => $actorId]);
            });

            $periodsCreated = 0;
            if ($sourceYear >= 2000 && $sourceMonth >= 1 && $sourceMonth <= 12) {
                try {
                    $payrollService = new \Wbpms\Application\PayrollService($this->makeConnection());
                    $periodsResult = $payrollService->createPeriodsForMonth(sprintf('%04d-%02d', $sourceYear, $sourceMonth));
                    $periodsCreated = (int) ($periodsResult['created'] ?? 0);
                } catch (\Throwable) {
                    // Best-effort auto-generation of Friday-Thursday payroll periods
                }
            }

            $extraMsg = $periodsCreated > 0
                ? sprintf(' %d Friday–Thursday payroll period(s) were automatically created for %04d-%02d.', $periodsCreated, $sourceYear, $sourceMonth)
                : '';
            ViewRenderer::flash('Attendance import approved. Its timesheets are now available to payroll.' . $extraMsg);
        } catch (\Throwable $e) {
            ErrorLog::exception($e, 'attendance import approve');
            ViewRenderer::flashError(UserMessage::prefixed('The import could not be approved', $e));
        }

        $this->redirect('/hr/attendance/import');
    }

    /** @param array<string, string> $params */
    public function cancelImport(array $params = []): void
    {
        $batchId = (int) ($params['id'] ?? 0);
        $actorId = (int) (AuthMiddleware::identity()['user_id'] ?? 0);

        try {
            $this->makeConnection()->transaction(function (\PDO $pdo) use ($batchId, $actorId): void {
                $batch = $pdo->prepare(
                    'SELECT status FROM attendance_import_batch WHERE import_batch_id = :id FOR UPDATE'
                );
                $batch->execute([':id' => $batchId]);
                $status = $batch->fetchColumn();
                if (!in_array($status, ['Draft', 'Approved', 'Completed'], true)) {
                    throw new RuntimeException('Only a saved attendance import can be cancelled.');
                }

                // Payroll stores an employee and branch-assignment snapshot rather
                // than a direct batch reference. Any payroll period containing an
                // attendance day from this batch means it has been used and is
                // intentionally immutable from this workflow.
                $used = $pdo->prepare(
                    "SELECT COUNT(*)
                       FROM attendance a
                       JOIN payroll p ON p.employee_id = a.employee_id
                       JOIN payroll_period pp ON pp.payroll_period_id = p.payroll_period_id
                      WHERE a.import_batch_id = :id
                        AND a.attendance_date BETWEEN pp.period_start AND pp.period_end"
                );
                $used->execute([':id' => $batchId]);
                if ((int) $used->fetchColumn() > 0) {
                    throw new RuntimeException('This import has already been used by payroll and cannot be cancelled. Reverse or recompute the payroll first.');
                }

                // A punch is stored once (uq_punch_device_code_local_at). When a
                // later upload of the same device and month contains punches this
                // batch already holds, it skips them as duplicates and relies on
                // this batch for them. Cancelling this batch first would delete
                // that evidence and mark those days Cancelled even though the
                // later (often approved) workbook contains them.
                $dependents = $pdo->prepare(
                    "SELECT later.import_batch_id
                       FROM attendance_import_batch cur
                       JOIN attendance_import_batch later
                         ON later.device_id     = cur.device_id
                        AND later.source_year   = cur.source_year
                        AND later.source_month  = cur.source_month
                        AND later.import_batch_id > cur.import_batch_id
                      WHERE cur.import_batch_id = :id
                        AND later.status IN ('Draft', 'Approved', 'Completed')
                        AND later.duplicates_skipped > 0
                      ORDER BY later.import_batch_id DESC"
                );
                $dependents->execute([':id' => $batchId]);
                $laterBatches = array_map('intval', $dependents->fetchAll(\PDO::FETCH_COLUMN));
                if ($laterBatches !== []) {
                    throw new RuntimeException(
                        'A later upload for the same device and month (batch #' . implode(', #', $laterBatches)
                        . ') reused punches stored by this import. Cancel the later upload(s) first, newest first, '
                        . 'then cancel this one and re-upload the latest workbook.'
                    );
                }

                $owned = $pdo->prepare('SELECT attendance_id FROM attendance WHERE import_batch_id = :id');
                $owned->execute([':id' => $batchId]);
                $ownedAttendanceIds = array_map('intval', $owned->fetchAll(\PDO::FETCH_COLUMN));

                $pdo->prepare(
                    "UPDATE attendance SET status = 'Cancelled' WHERE import_batch_id = :id"
                )->execute([':id' => $batchId]);

                // Delete biometric_punch rows so the same file can be cleanly
                // re-imported after cancellation — isDuplicatePunch checks
                // biometric_punch and would otherwise skip every punch as a
                // duplicate, producing an empty re-import.
                $pdo->prepare(
                    'DELETE FROM biometric_punch WHERE import_batch_id = :id'
                )->execute([':id' => $batchId]);

                // A day owned by this batch can still have punches from an earlier
                // live upload (e.g. this file only added the missing time-out).
                // Rebuild those days from the remaining evidence instead of
                // leaving them Cancelled under an approved workbook.
                $gateway = new PdoAttendanceImportGateway($pdo);
                foreach ($ownedAttendanceIds as $attendanceId) {
                    $gateway->rebuildFromLivePunches($attendanceId);
                }

                $pdo->prepare(
                    "UPDATE attendance_import_batch
                        SET status = 'Cancelled', cancelled_by = :user_id, cancelled_at = UTC_TIMESTAMP(),
                            cancellation_reason = 'Cancelled by HR before payroll use.'
                      WHERE import_batch_id = :id"
                )->execute([':id' => $batchId, ':user_id' => $actorId]);
            });
            ViewRenderer::flash('Attendance import cancelled. Its timesheets are excluded from payroll.');
        } catch (\Throwable $e) {
            ErrorLog::exception($e, 'attendance import cancel');
            ViewRenderer::flashError(UserMessage::prefixed('The import could not be cancelled', $e));
        }

        $this->redirect('/hr/attendance/import');
    }

    /** @param list<string> $errors */
    private function renderImport(array $errors, ?array $preview = null): void
    {
        $pdo = $this->makeConnection()->pdo();
        $batches = $pdo->query(
            "SELECT aib.import_batch_id, aib.file_name, aib.file_checksum, aib.status,
                    aib.source_year, aib.source_month,
                    u.username AS uploaded_by, aib.uploaded_at, aib.records_parsed,
                    aib.records_matched, aib.records_unmatched, aib.duplicates_skipped,
                    aib.incomplete_days, aib.multi_punch_days, aib.completed_at
               FROM attendance_import_batch aib
               LEFT JOIN users u ON u.user_id = aib.uploaded_by
              ORDER BY aib.uploaded_at DESC LIMIT 20"
        )->fetchAll();

        ViewRenderer::render('hr/attendance/import', [
            'batches' => $batches,
            'errors' => $errors,
            'devices' => $this->loadDevices($pdo),
            'preview' => $preview,
        ], 'Import Attendance');
    }

    /** @param list<\Wbpms\Domain\Attendance\Parsing\ParsedPunch> $punches */
    private function buildPreview(array $punches, ?PdoAttendanceImportGateway $gateway = null, int $deviceId = 0): array
    {
        $sample = [];
        $dates = [];
        $enrollments = [];
        $enrollmentSummary = [];
        foreach ($punches as $punch) {
            $dateTime = $punch->localTimestamp->format('Y-m-d H:i');
            $dateStr = $punch->localTimestamp->format('Y-m-d');
            $dates[] = $dateStr;
            $code = $punch->enrollmentCode;
            $enrollments[$code] = true;
            if (!isset($enrollmentSummary[$code])) {
                $enrollmentSummary[$code] = [
                    'code' => $code,
                    'source_name' => $punch->sourceName ?? '',
                    'department' => $punch->department ?? '',
                    'punch_count' => 0,
                    'dates' => [],
                ];
            }
            $enrollmentSummary[$code]['punch_count']++;
            $enrollmentSummary[$code]['dates'][$dateStr] = true;
            if ($enrollmentSummary[$code]['source_name'] === '' && ($punch->sourceName ?? '') !== '') {
                $enrollmentSummary[$code]['source_name'] = (string) $punch->sourceName;
            }
            if (count($sample) < self::PREVIEW_MAX_PUNCHES) {
                $sample[] = [
                    'row' => $punch->sourceRow,
                    'dateTime' => $dateTime,
                    'enrollment' => $code,
                    'name' => $punch->sourceName ?? '',
                    'department' => $punch->department ?? '',
                ];
            }
        }
        sort($dates);

        $diagnostics = [];
        $matchedEnrollmentCount = 0;
        $unmatchedEnrollmentCount = 0;
        if ($gateway !== null && $deviceId > 0) {
            ksort($enrollmentSummary, SORT_NATURAL);
            foreach ($enrollmentSummary as $code => $info) {
                $diag = $gateway->diagnoseEnrollmentMatch(
                    $deviceId,
                    (string) $code,
                    array_keys($info['dates']),
                );
                if (($diag['status'] ?? '') === 'matched') {
                    $matchedEnrollmentCount++;
                } else {
                    $unmatchedEnrollmentCount++;
                }
                $diagnostics[] = [
                    'code' => (string) $code,
                    'source_name' => (string) $info['source_name'],
                    'department' => (string) $info['department'],
                    'punch_count' => (int) $info['punch_count'],
                    'status' => (string) ($diag['status'] ?? 'unmatched'),
                    'reason' => (string) ($diag['reason'] ?? ''),
                    'employee_id' => $diag['employee_id'] ?? null,
                    'employee_number' => $diag['employee_number'] ?? null,
                    'employee_name' => $diag['employee_name'] ?? null,
                    'branch_name' => $diag['branch_name'] ?? null,
                ];
            }
        }

        return [
            'total' => count($punches),
            'employeeCount' => count($enrollments),
            'matchedEnrollmentCount' => $matchedEnrollmentCount,
            'unmatchedEnrollmentCount' => $unmatchedEnrollmentCount,
            'diagnostics' => $diagnostics,
            'dateFrom' => $dates[0] ?? null,
            'dateTo' => $dates[array_key_last($dates)] ?? null,
            'sample' => $sample,
        ];
    }

    /** @param array<string, int|string> $metadata */
    private function stageImport(string $path, array $metadata): void
    {
        $_SESSION[self::PREVIEW_SESSION_KEY] = $metadata + [
            'token' => bin2hex(random_bytes(24)),
            'path' => $path,
            'created_at' => time(),
        ];
    }

    /** @return array<string, int|string>|null */
    private function stagedImport(): ?array
    {
        $staged = $_SESSION[self::PREVIEW_SESSION_KEY] ?? null;
        if (!is_array($staged)
            || !isset($staged['token'], $staged['path'], $staged['sha256'], $staged['created_at'])
            || (int) $staged['created_at'] + self::PREVIEW_TTL_SECONDS < time()) {
            $this->clearStagedImport();
            return null;
        }
        return $staged;
    }

    private function clearStagedImport(): void
    {
        $staged = $_SESSION[self::PREVIEW_SESSION_KEY] ?? null;
        unset($_SESSION[self::PREVIEW_SESSION_KEY]);
        if (!is_array($staged) || !isset($staged['path'])) {
            return;
        }

        $directory = realpath(APP_ROOT . '/storage/private/attendance');
        $path = realpath((string) $staged['path']);
        if ($directory !== false && $path !== false && str_starts_with($path, $directory . DIRECTORY_SEPARATOR)) {
            @unlink($path);
        }
    }

    /** @return list<string> */
    private function formatParseErrors(AttendanceParseException $exception): array
    {
        $errors = [];
        foreach ($exception->errors as $err) {
            $location = '';
            if ($err->row !== null) { $location .= " row {$err->row}"; }
            if ($err->column !== null) { $location .= " col {$err->column}"; }
            $errors[] = "[{$err->code}]{$location}: {$err->message}";
        }
        return $errors;
    }

    // -----------------------------------------------------------------------
    // POST /hr/attendance/rematch
    // -----------------------------------------------------------------------

    /** @param array<string, string> $params */
    public function rematchUnmatched(array $params = []): void
    {
        $actorId = (int) (AuthMiddleware::identity()['user_id'] ?? 0);
        $deviceId = isset($_POST['device_id']) && (int) $_POST['device_id'] > 0
            ? (int) $_POST['device_id'] : null;
        $enrollmentCode = isset($_POST['enrollment_code']) && trim((string) $_POST['enrollment_code']) !== ''
            ? trim((string) $_POST['enrollment_code']) : null;
        $returnTo = trim((string) ($_POST['return_to'] ?? ''));
        if (!in_array($returnTo, ['/hr/attendance', '/hr/attendance/import'], true)) {
            $returnTo = '/hr/attendance';
        }

        try {
            $result = $this->makeConnection()->transaction(
                function (\PDO $pdo) use ($deviceId, $enrollmentCode, $actorId): array {
                    $gateway = new PdoAttendanceImportGateway($pdo);
                    $res = $gateway->rematchUnmatchedPunches($deviceId, $enrollmentCode, $actorId);
                    if ($actorId > 0) {
                        // Column names must match the audit_logs table; a wrong
                        // column fails the INSERT and rolls back the whole re-match.
                        $now = gmdate('Y-m-d H:i:s');
                        $pdo->prepare(
                            "INSERT INTO audit_logs
                                (user_id, event_type, action_performed, table_affected, record_id,
                                 ip_address, description, action_at, created_at)
                             VALUES
                                (:user_id, 'attendance_rematched', 'rematch_unmatched_punches', 'biometric_punch', NULL,
                                 :ip, :description, :action_at, :created_at)"
                        )->execute([
                            ':user_id' => $actorId,
                            ':action_at' => $now,
                            ':created_at' => $now,
                            ':description' => json_encode([
                                'device_id' => $deviceId,
                                'enrollment_code' => $enrollmentCode,
                                'rematched_punches' => $res['rematched_punches'],
                                'days_updated' => $res['days_updated'],
                                'still_unmatched' => $res['still_unmatched'],
                            ], JSON_THROW_ON_ERROR),
                            ':ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                        ]);
                    }
                    return $res;
                }
            );

            if ($result['rematched_punches'] > 0) {
                ViewRenderer::flash(sprintf(
                    'Re-matched %d previously unmatched punch(es) across %d attendance day(s). %d punch(es) remain unmatched.',
                    $result['rematched_punches'],
                    $result['days_updated'],
                    $result['still_unmatched'],
                ));
            } else {
                ViewRenderer::flash(sprintf(
                    'No unmatched punches could be matched yet (%d punch(es) still unmatched — verify employee Biometric Enroll ID, Hire/Effective Date, and Device Branch coverage).',
                    $result['still_unmatched'],
                ));
            }
        } catch (\Throwable $e) {
            ErrorLog::exception($e, 'attendance re-match');
            ViewRenderer::flashError(UserMessage::prefixed('Failed to re-match unmatched punches', $e));
        }

        $this->redirect($returnTo);
    }

    // -----------------------------------------------------------------------
    // GET /hr/attendance/manual
    // -----------------------------------------------------------------------

    /** @param array<string, string> $params */
    public function manualForm(array $params = []): void
    {
        $employeeId = (int) ($_GET['employee_id'] ?? 0);
        $date = trim((string) ($_GET['date'] ?? ''));

        if ($employeeId <= 0 || !$this->isIsoDate($date)) {
            ViewRenderer::flashError('Please select a valid employee and date for manual attendance entry.');
            $this->redirect('/hr/attendance');
            return;
        }

        try {
            $context = $this->adjustmentService()->findForManualEntry($employeeId, $date);
        } catch (RuntimeException $e) {
            ViewRenderer::flashError(UserMessage::from($e));
            $this->redirect('/hr/attendance');
            return;
        }

        ViewRenderer::render('hr/attendance/manual', [
            'context' => $context,
            'errors' => [],
            'old' => [],
        ], 'Record Manual Attendance');
    }

    // -----------------------------------------------------------------------
    // POST /hr/attendance/manual
    // -----------------------------------------------------------------------

    /** @param array<string, string> $params */
    public function storeManual(array $params = []): void
    {
        $employeeId = (int) ($_POST['employee_id'] ?? 0);
        $date = trim((string) ($_POST['attendance_date'] ?? ''));
        $input = [
            'time_in' => trim((string) ($_POST['time_in'] ?? '')),
            'time_out' => trim((string) ($_POST['time_out'] ?? '')),
            'reason' => trim((string) ($_POST['reason'] ?? '')),
        ];

        try {
            $identity = AuthMiddleware::identity();
            $this->adjustmentService()->recordManualAttendance(
                $employeeId,
                $date,
                $input,
                (int) ($identity['user_id'] ?? 0),
            );
            ViewRenderer::flash('Manual attendance recorded and logged in the audit trail.');
            $monthParam = $this->isIsoDate($date) ? '?_tab=cutoff&month=' . substr($date, 0, 7) : '';
            $this->redirect('/hr/attendance' . $monthParam);
            return;
        } catch (RuntimeException $e) {
            try {
                $context = $this->adjustmentService()->findForManualEntry($employeeId, $date);
            } catch (RuntimeException $inner) {
                ViewRenderer::flashError(UserMessage::from($inner));
                $this->redirect('/hr/attendance');
                return;
            }
            ViewRenderer::render('hr/attendance/manual', [
                'context' => $context,
                'errors' => [UserMessage::from($e)],
                'old' => $input,
            ], 'Record Manual Attendance');
        }
    }

    // -----------------------------------------------------------------------
    // GET /hr/attendance/{id}/adjust
    // -----------------------------------------------------------------------

    /** @param array<string, string> $params */
    /**
     * GET /hr/attendance/preview-minutes — read-only JSON for the live
     * preview on the Adjust and Manual forms (REQ019). Either attendance_id,
     * or employee_id + date, plus time_in and time_out (HH:MM).
     *
     * @param array<string, string> $params
     */
    public function previewMinutes(array $params = []): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        $service = $this->adjustmentService();
        try {
            $attendanceId = (int) ($_GET['attendance_id'] ?? 0);
            if ($attendanceId > 0) {
                $context = $service->findForAdjustment($attendanceId);
            } else {
                $employeeId = (int) ($_GET['employee_id'] ?? 0);
                $date       = trim((string) ($_GET['date'] ?? ''));
                if ($employeeId <= 0 || !$this->isIsoDate($date)) {
                    throw new RuntimeException('Missing employee or date.');
                }
                $context = $service->findForManualEntry($employeeId, $date);
            }
            $minutes = $service->previewMinutes(
                $context,
                (string) ($_GET['time_in'] ?? ''),
                (string) ($_GET['time_out'] ?? '')
            );
            echo json_encode(['ok' => true] + $minutes + [
                'worked_label'    => \Wbpms\Http\View\Formatter::hoursMinutes($minutes['worked']),
                'late_label'      => \Wbpms\Http\View\Formatter::hoursMinutes($minutes['late']),
                'undertime_label' => \Wbpms\Http\View\Formatter::hoursMinutes($minutes['undertime']),
            ]);
        } catch (RuntimeException $e) {
            echo json_encode(['ok' => false, 'message' => UserMessage::from($e)]);
        }
    }

    public function adjustForm(array $params = []): void
    {
        $attendanceId = (int) ($params['id'] ?? 0);
        try {
            $service = $this->adjustmentService();
            $attendance = $service->findForAdjustment($attendanceId);
            $history = $service->adjustmentHistory($attendanceId);
        } catch (RuntimeException $e) {
            ViewRenderer::flashError(UserMessage::from($e));
            $this->redirect('/hr/attendance');
            return;
        }

        ViewRenderer::render('hr/attendance/adjust', [
            'attendance' => $attendance,
            'history' => $history,
            'errors' => [],
            'old' => [],
        ], 'Adjust Attendance');
    }

    // -----------------------------------------------------------------------
    // POST /hr/attendance/{id}/adjust
    // -----------------------------------------------------------------------

    /** @param array<string, string> $params */
    public function adjust(array $params = []): void
    {
        $attendanceId = (int) ($params['id'] ?? 0);
        $input = [
            'time_in' => trim((string) ($_POST['time_in'] ?? '')),
            'time_out' => trim((string) ($_POST['time_out'] ?? '')),
            'reason' => trim((string) ($_POST['reason'] ?? '')),
        ];

        try {
            $identity = AuthMiddleware::identity();
            // Load the record first so we can redirect back to the correct month
            $attendance = $this->adjustmentService()->findForAdjustment($attendanceId);
            $attendanceDate = (string) ($attendance['attendance_date'] ?? '');
            $this->adjustmentService()->adjust($attendanceId, $input, (int) ($identity['user_id'] ?? 0));
            ViewRenderer::flash('Attendance adjustment saved. Recompute any unapproved payroll run for this period before submitting it.');
            // Return to the cut-off tab filtered to the same month so HR can
            // see the updated record without having to re-navigate.
            $monthParam = $attendanceDate !== '' ? '?_tab=cutoff&month=' . substr($attendanceDate, 0, 7) : '';
            $this->redirect('/hr/attendance' . $monthParam);
            return;
        } catch (RuntimeException $e) {
            try {
                $service = $this->adjustmentService();
                $attendance = $service->findForAdjustment($attendanceId);
                $history = $service->adjustmentHistory($attendanceId);
            } catch (RuntimeException) {
                ViewRenderer::flashError('Attendance record not found.');
                $this->redirect('/hr/attendance');
                return;
            }
            ViewRenderer::render('hr/attendance/adjust', [
                'attendance' => $attendance,
                'history' => $history,
                'errors' => [UserMessage::from($e)],
                'old' => $input,
            ], 'Adjust Attendance');
        }
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    /** Load active biometric devices for the upload form device selector. */
    private function loadDevices(\PDO $pdo): array
    {
        return $pdo->query(
            "SELECT device_id, device_code, device_name
               FROM biometric_device
              WHERE status = 'Active'
              ORDER BY device_name"
        )->fetchAll();
    }

    /** Patch file_name on the batch row after a successful import. */
    private function patchFileName(\PDO $pdo, string $sha256, string $origName): void
    {
        $stmt = $pdo->prepare(
            "UPDATE attendance_import_batch
                SET file_name = :fname
              WHERE file_checksum = :checksum"
        );
        $stmt->execute([':fname' => $origName, ':checksum' => $sha256]);
    }

    private function uploadErrorMessage(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File is too large.',
            UPLOAD_ERR_PARTIAL                        => 'File was only partially uploaded.',
            UPLOAD_ERR_NO_FILE                        => 'No file was selected.',
            UPLOAD_ERR_NO_TMP_DIR                     => 'Missing temporary directory.',
            UPLOAD_ERR_CANT_WRITE                     => 'Failed to write file to disk.',
            UPLOAD_ERR_EXTENSION                      => 'Upload blocked by PHP extension.',
            default                                   => 'Unknown upload error.',
        };
    }

    private function makeConnection(): Connection
    {
        return new Connection(require APP_ROOT . '/config/database.php');
    }

    private function isIsoDate(string $value): bool
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $parsed !== false && $parsed->format('Y-m-d') === $value;
    }

    private function adjustmentService(): AttendanceAdjustmentService
    {
        return new AttendanceAdjustmentService($this->makeConnection());
    }

    private function redirect(string $path): void
    {
        $base = rtrim((string) ($_ENV['APP_BASE_URL'] ?? ''), '/');
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        header('Location: ' . $base . $path, true, 302);
        exit;
    }
}

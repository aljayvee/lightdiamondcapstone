<?php

declare(strict_types=1);

namespace Wbpms\Application;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;
use Wbpms\Domain\CashAdvance\CashAdvanceDate;
use Wbpms\Domain\Payroll\HolidayPayCalculator;
use Wbpms\Domain\Payroll\HolidayRecord;
use Wbpms\Domain\Payroll\HolidayType;
use Wbpms\Domain\Payroll\PayrollPeriodClosure;
use Wbpms\Domain\Payroll\PayrollReadinessEvaluator;
use Wbpms\Domain\Payroll\ApprovedOvertimeCalculator;
use Wbpms\Domain\Payroll\SssContributionSchedule;
use Wbpms\Domain\Payroll\WithholdingTaxCalculator;
use Wbpms\Http\View\Formatter;
use Wbpms\Infrastructure\Database\Connection;
use Wbpms\Infrastructure\Persistence\WorkCalendarRepository;

/**
 * PayrollService — orchestrates the full payroll lifecycle.
 *
 * Responsibilities (ADR-0001):
 *   - Period management (create Friday→Thursday periods).
 *   - Draft payroll run creation.
 *   - Computation: gross from attendance and approved paid leave; deductions
 *     include late/undertime/contributions/cash-advance; unpaid absences are
 *     retained as zero-value policy records because they are excluded from
 *     basic pay before deductions are calculated.
 *   - Submission to Owner (Draft/Computed → PendingOwnerApproval).
 *   - Owner approve / return for revision.
 *   - 13th-month pay computation.
 *
 * All SQL lives here (or in helper methods on this class).
 * Money is always handled as integer centavos internally, stored as DECIMAL(12,2).
 *
 * ADR-0001 payroll cycle:
 *   period_start = Friday, period_end = Thursday, pay_date = the following Friday.
 *   EEMR = (daily_rate × eemr_days_per_year) / 12.
 *   Late deduction = late_minutes × late_rate_per_minute (default ₱1.00/min).
 *   Undertime deduction = undertime_minutes × (daily_rate / standard_minutes).
 *   Overtime earning = approved, worked overtime_minutes × (daily_rate / standard_minutes) × multiplier.
 */
final class PayrollService
{
    private Connection $connection;
    private HolidayPayCalculator $holidayCalc;
    private ApprovedOvertimeCalculator $approvedOvertimeCalc;
    private WithholdingTaxCalculator $taxCalc;

    public function __construct(Connection $connection)
    {
        $this->connection  = $connection;
        $this->holidayCalc = new HolidayPayCalculator();
        $this->approvedOvertimeCalc = new ApprovedOvertimeCalculator();
        $this->taxCalc     = new WithholdingTaxCalculator();
    }

    // ===================================================================
    // Payroll Period management
    // ===================================================================

    /**
     * Create one Friday→Thursday payroll period.
     * Validates that the supplied date is a Friday.
     */
    public function createPeriod(string $periodStart): int
    {
        $start = DateTimeImmutable::createFromFormat('Y-m-d', $periodStart, new DateTimeZone('Asia/Manila'));
        if ($start === false) {
            throw new RuntimeException('Invalid period start date. Use YYYY-MM-DD format.');
        }
        if ((int) $start->format('N') !== 5) { // 5 = Friday
            throw new RuntimeException('Period start must be a Friday.');
        }

        $end     = $start->modify('+6 days');  // Thursday
        $payDate = $end->modify('+1 day');     // Salary is released Friday

        $pdo = $this->connection->pdo();

        // Check for duplicate
        $chk = $pdo->prepare(
            "SELECT COUNT(*)
               FROM payroll_period
              WHERE period_start = :start OR pay_date = :pay_date"
        );
        $chk->execute([
            ':start'    => $start->format('Y-m-d'),
            ':pay_date' => $payDate->format('Y-m-d'),
        ]);
        if ((int) $chk->fetchColumn() > 0) {
            throw new RuntimeException('A payroll period with that start date or pay date already exists.');
        }

        $stmt = $pdo->prepare(
            "INSERT INTO payroll_period
                (period_start, period_end, pay_date, cutoff_pattern, status, created_at, updated_at)
             VALUES
                (:start, :end, :pay, 'FridayThursday', 'Open', :created_at, :updated_at)"
        );
        $now = $this->utcNow();
        $stmt->execute([
            ':start'      => $start->format('Y-m-d'),
            ':end'        => $end->format('Y-m-d'),
            ':pay'        => $payDate->format('Y-m-d'),
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
        return (int) $pdo->lastInsertId();
    }

    /**
     * Generate every Friday–Thursday cutoff that overlaps a calendar month.
     * A cutoff may begin in the preceding month or end in the following one.
     *
     * @return array{created:int,existing:int}
     */
    public function createPeriodsForMonth(string $month): array
    {
        if (!preg_match('/^\d{4}-(?:0[1-9]|1[0-2])$/', $month)) {
            throw new RuntimeException('Select a valid payroll month.');
        }

        $timezone = new DateTimeZone('Asia/Manila');
        $firstDay = new DateTimeImmutable($month . '-01', $timezone);
        $lastDay = $firstDay->modify('last day of this month');
        // DateTime::format('w') is Sunday=0 through Saturday=6. This gives
        // the Friday on or immediately before the first day of the month.
        $daysSinceFriday = ((int) $firstDay->format('w') + 2) % 7;
        $firstFriday = $firstDay->modify('-' . $daysSinceFriday . ' days');
        $created = 0;
        $existing = 0;

        for ($start = $firstFriday; $start <= $lastDay; $start = $start->modify('+7 days')) {
            try {
                $this->createPeriod($start->format('Y-m-d'));
                $created++;
            } catch (RuntimeException $e) {
                if (!str_contains($e->getMessage(), 'already exists')) {
                    throw $e;
                }
                $existing++;
            }
        }

        return ['created' => $created, 'existing' => $existing];
    }

    /** @return list<array<string,mixed>> */
    public function listPeriods(): array
    {
        $rows = $this->connection->pdo()->query(
            "SELECT pp.payroll_period_id,
                    pp.period_start,
                    pp.period_end,
                    pp.pay_date,
                    pp.cutoff_pattern,
                    pp.status,
                    pp.created_at,
                    COUNT(pr.payroll_run_id) AS run_count
               FROM payroll_period pp
               LEFT JOIN payroll_run pr ON pr.payroll_period_id = pp.payroll_period_id
              GROUP BY pp.payroll_period_id, pp.period_start, pp.period_end, pp.pay_date,
                       pp.cutoff_pattern, pp.status, pp.created_at
              ORDER BY pp.period_start DESC"
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['is_month_end_contribution_cutoff'] = $this->isMonthlyContributionCutoff((string) $row['pay_date']);
        }
        return $rows;
    }

    /** @return list<array<string,mixed>> Periods formatted for select dropdowns. */
    public function periods(): array
    {
        $rows = $this->listPeriods();
        foreach ($rows as &$r) {
            $r['label'] = $r['period_start'] . ' – ' . $r['period_end'];
        }
        return $rows;
    }

    // ===================================================================
    // Payroll Run lifecycle
    // ===================================================================

    /**
     * Create a Draft payroll run for a given period + branch.
     * Enforces the unique constraint (one run per period per branch).
     *
     * @param array<string,mixed> $data  Keys: payroll_period_id, branch_id
     */
    public function createRun(array $data, int $createdByUserId): int
    {
        $periodId = (int) ($data['payroll_period_id'] ?? 0);
        $branchId = (int) ($data['branch_id'] ?? 0);

        if ($periodId < 1 || $branchId < 1) {
            throw new RuntimeException('Period and branch are required.');
        }

        $pdo = $this->connection->pdo();

        // Validate period exists and is open
        $stmt = $pdo->prepare("SELECT status FROM payroll_period WHERE payroll_period_id = :id");
        $stmt->execute([':id' => $periodId]);
        $period = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$period) {
            throw new RuntimeException('Payroll period not found.');
        }

        // Resolve active payroll policy
        $policy = $this->resolveActivePolicy($pdo);
        $policyId = (int) $policy['policy_id'];

        $now  = $this->utcNow();
        $stmt = $pdo->prepare(
            "INSERT INTO payroll_run
                (payroll_period_id, branch_id, payroll_policy_id, status,
                 lock_version, created_at, updated_at)
             VALUES
                (:period_id, :branch_id, :policy_id, 'Draft',
                 0, :created_at, :updated_at)"
        );
        try {
            $stmt->execute([
                ':period_id'  => $periodId,
                ':branch_id'  => $branchId,
                ':policy_id'  => $policyId,
                ':created_at' => $now,
                ':updated_at' => $now,
            ]);
        } catch (\PDOException $e) {
            if (str_contains($e->getMessage(), 'Duplicate') || str_contains($e->getMessage(), '1062')) {
                throw new RuntimeException('A payroll run already exists for this period and branch.');
            }
            throw $e;
        }
        return (int) $pdo->lastInsertId();
    }

    /**
     * Find a run or throw RuntimeException with a safe 404-friendly message.
     *
     * @return array<string,mixed>
     */
    public function findRunOrFail(int $runId): array
    {
        $pdo  = $this->connection->pdo();
        $stmt = $pdo->prepare(
            "SELECT pr.payroll_run_id,
                    pr.payroll_period_id,
                    pr.status,
                    pr.return_reason,
                    pr.cancellation_reason,
                    pr.cancelled_at,
                    pr.submitted_at,
                    pr.reviewed_at,
                    pr.created_at,
                    pr.gross_pay,
                    pr.net_pay,
                    pr.total_deductions,
                    b.branch_name,
                    CONCAT(DATE_FORMAT(pp.period_start, '%m/%d/%y'), ' – ', DATE_FORMAT(pp.period_end, '%m/%d/%y')) AS period_label,
                    pp.period_start,
                    pp.period_end,
                    pp.pay_date
               FROM payroll_run pr
               JOIN branch b         ON b.branch_id          = pr.branch_id
               JOIN payroll_period pp ON pp.payroll_period_id = pr.payroll_period_id
              WHERE pr.payroll_run_id = :id"
        );
        $stmt->execute([':id' => $runId]);
        $run = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$run) {
            throw new RuntimeException('Payroll run not found.');
        }
        return $run;
    }

    /**
     * Employee payroll rows for a run.
     *
     * @return list<array<string,mixed>>
     */
    public function runDetails(int $runId): array
    {
        $stmt = $this->connection->pdo()->prepare(
            "SELECT p.payroll_id,
                    CONCAT(e.last_name, ', ', e.first_name) AS employee_name,
                    e.employee_number,
                    p.daily_rate_snapshot,
                    p.gross_pay,
                    p.total_deductions,
                    p.net_pay,
                    ps.payslip_id
               FROM payroll p
               JOIN employee e   ON e.employee_id = p.employee_id
               LEFT JOIN payslip ps ON ps.payroll_id = p.payroll_id
              WHERE p.payroll_run_id = :id
              ORDER BY e.last_name, e.first_name"
        );
        $stmt->execute([':id' => $runId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Summarise the deductions posted to a payroll run for the HR review page.
     *
     * @return list<array{deduction_type:string,record_count:int,total_amount:float}>
     */
    public function runDeductionSummary(int $runId): array
    {
        $stmt = $this->connection->pdo()->prepare(
            "SELECT d.deduction_type,
                    COUNT(*) AS record_count,
                    SUM(d.amount) AS total_amount
               FROM deduction d
               JOIN payroll p ON p.payroll_id = d.payroll_id
              WHERE p.payroll_run_id = :id
              GROUP BY d.deduction_type
              ORDER BY total_amount DESC, d.deduction_type"
        );
        $stmt->execute([':id' => $runId]);

        return array_map(static fn (array $row): array => [
            'deduction_type' => (string) $row['deduction_type'],
            'record_count' => (int) $row['record_count'],
            'total_amount' => (float) $row['total_amount'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Summarise basic pay, overtime, holiday pay, and other posted earnings.
     *
     * @return list<array{earning_type:string,record_count:int,total_amount:float}>
     */
    public function runEarningSummary(int $runId): array
    {
        $stmt = $this->connection->pdo()->prepare(
            "SELECT pe.earning_type,
                    COUNT(*) AS record_count,
                    SUM(pe.amount) AS total_amount
               FROM payroll_earnings pe
               JOIN payroll p ON p.payroll_id = pe.payroll_id
              WHERE p.payroll_run_id = :id
              GROUP BY pe.earning_type
              ORDER BY total_amount DESC, pe.earning_type"
        );
        $stmt->execute([':id' => $runId]);

        return array_map(static fn (array $row): array => [
            'earning_type' => (string) $row['earning_type'],
            'record_count' => (int) $row['record_count'],
            'total_amount' => (float) $row['total_amount'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @return list<array{payroll_id:int,earning_type:string,description:string,quantity:float,unit_rate:float,multiplier:float,amount:float}>
     */
    public function runEarnings(int $runId): array
    {
        $stmt = $this->connection->pdo()->prepare(
            "SELECT pe.payroll_id, pe.earning_type, pe.description,
                    pe.quantity, pe.unit_rate, pe.multiplier, pe.amount
               FROM payroll_earnings pe
               JOIN payroll p ON p.payroll_id = pe.payroll_id
              WHERE p.payroll_run_id = :id
              ORDER BY pe.payroll_id, pe.earning_type, pe.earning_id"
        );
        $stmt->execute([':id' => $runId]);

        return array_map(static fn (array $row): array => [
            'payroll_id' => (int) $row['payroll_id'],
            'earning_type' => (string) $row['earning_type'],
            'description' => (string) $row['description'],
            'quantity' => (float) $row['quantity'],
            'unit_rate' => (float) $row['unit_rate'],
            'multiplier' => (float) $row['multiplier'],
            'amount' => (float) $row['amount'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @return list<array{payroll_id:int,deduction_type:string,description:string,quantity:float,unit_rate:float,amount:float}>
     */
    public function runDeductions(int $runId): array
    {
        $stmt = $this->connection->pdo()->prepare(
            "SELECT d.payroll_id, d.deduction_type, d.description,
                    d.quantity, d.unit_rate, d.amount
               FROM deduction d
               JOIN payroll p ON p.payroll_id = d.payroll_id
              WHERE p.payroll_run_id = :id
              ORDER BY d.payroll_id, d.deduction_type, d.deduction_id"
        );
        $stmt->execute([':id' => $runId]);

        return array_map(static fn (array $row): array => [
            'payroll_id' => (int) $row['payroll_id'],
            'deduction_type' => (string) $row['deduction_type'],
            'description' => (string) $row['description'],
            'quantity' => (float) $row['quantity'],
            'unit_rate' => (float) $row['unit_rate'],
            'amount' => (float) $row['amount'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    // ===================================================================
    // Payroll Computation (Wire #2 + Wire #3)
    // ===================================================================

    /**
     * Compute gross/deductions/net for every eligible employee in the run.
     * Clears any prior computed rows for this run first (re-compute is safe).
     *
     * Flow per employee:
     *   1. Fetch effective daily_rate for period_start.
     *   2. Sum attendance days worked / late / undertime; pay OT only where an approved request exists.
     *   3. Basic pay = days_worked_equivalent × daily_rate.
     *   4. Overtime earning (regular hours overtime, not holiday).
     *   5. Government contributions (SSS, PhilHealth, Pag-IBIG).
     *   6. Late deduction = late_minutes × policy.late_rate_per_minute.
     *   7. Undertime deduction = undertime_minutes × (daily_rate / standard_minutes).
     *   8. Outstanding cash advance deduction (advances dated on or before period end).
     *   9. Net = Gross − Total Deductions.
     */
    public function computeRun(int $runId, int $computedByUserId): void
    {
        $pdo = $this->connection->pdo();

        $run = $this->findRunOrFail($runId);
        if (!in_array($run['status'], ['Draft', 'Computed', 'Returned'], true)) {
            throw new RuntimeException('Only Draft, Computed, or Returned payroll runs can be computed.');
        }

        $adjustmentCheck = $pdo->prepare(
            "SELECT COUNT(*)
               FROM payroll_adjustment
              WHERE payroll_run_id = :run_id"
        );
        $adjustmentCheck->execute([':run_id' => $runId]);
        if ((int) $adjustmentCheck->fetchColumn() > 0) {
            throw new RuntimeException(
                'This payroll run contains manual adjustments and cannot be recomputed. '
                . 'Review the adjusted amounts before submitting it for approval.'
            );
        }

        $policy        = $this->resolveRunPolicy($pdo, $runId);
        $contribPolicy = $this->resolveContribPolicy($pdo);

        // SSS brackets, PhilHealth rate, Pag-IBIG rate
        $sssBrackets  = $this->loadSssBrackets($pdo, (int) $contribPolicy['contribution_policy_id']);
        $philRate     = $this->loadPhilhealthRate($pdo, (int) $contribPolicy['contribution_policy_id']);
        $pagibigRate  = $this->loadPagibigRate($pdo, (int) $contribPolicy['contribution_policy_id']);

        $lateRatePerMin     = (float) ($policy['late_rate_per_minute'] ?? 1.00);
        $eemrDaysPerYear    = (int)   ($policy['eemr_days_per_year']   ?? 313);
        $annualTaxThreshold = (float) ($policy['annual_tax_threshold'] ?? 250000.00);
        $periodStart        = $run['period_start'];
        $periodEnd          = $run['period_end'];
        $payDate            = $run['pay_date'];
        $applyContributions = $this->isMonthlyContributionCutoff((string) $payDate);

        $employees = $this->eligibleEmployees($pdo, $runId, (string) $periodStart, (string) $periodEnd);

        $this->assertEmployeesAreNotAlreadyPaidInAnotherRun(
            $pdo,
            $runId,
            (int) $run['payroll_period_id'],
            $employees,
        );

        $this->connection->transaction(function () use (
            $pdo, $runId, $employees, $periodStart, $periodEnd,
            $contribPolicy, $sssBrackets, $philRate, $pagibigRate,
            $lateRatePerMin, $eemrDaysPerYear, $annualTaxThreshold, $computedByUserId,
            $payDate, $applyContributions
        ): void {
            // Lock and re-check after the pre-computation reads. This prevents
            // a cancellation from being overwritten by a concurrently running
            // calculation.
            $statusStatement = $pdo->prepare(
                'SELECT status FROM payroll_run WHERE payroll_run_id = :id FOR UPDATE'
            );
            $statusStatement->execute([':id' => $runId]);
            $lockedRun = $statusStatement->fetch(PDO::FETCH_ASSOC);
            if ($lockedRun === false || !in_array($lockedRun['status'], ['Draft', 'Computed', 'Returned'], true)) {
                throw new RuntimeException('This payroll run is no longer available for computation.');
            }

            // Clear prior rows for this run
            $pdo->prepare("DELETE FROM cash_advance_repayment WHERE payroll_id IN (SELECT payroll_id FROM payroll WHERE payroll_run_id = :id)")->execute([':id' => $runId]);
            $pdo->prepare("DELETE FROM contribution_record WHERE payroll_id IN (SELECT payroll_id FROM payroll WHERE payroll_run_id = :id)")->execute([':id' => $runId]);
            $pdo->prepare("DELETE FROM deduction WHERE payroll_id IN (SELECT payroll_id FROM payroll WHERE payroll_run_id = :id)")->execute([':id' => $runId]);
            $pdo->prepare("DELETE FROM payroll_earnings WHERE payroll_id IN (SELECT payroll_id FROM payroll WHERE payroll_run_id = :id)")->execute([':id' => $runId]);
            $pdo->prepare("DELETE FROM payslip WHERE payroll_id IN (SELECT payroll_id FROM payroll WHERE payroll_run_id = :id)")->execute([':id' => $runId]);
            $pdo->prepare("DELETE FROM payroll WHERE payroll_run_id = :id")->execute([':id' => $runId]);

            $runGross = 0.0;
            $runDed   = 0.0;
            $runNet   = 0.0;

            foreach ($employees as $emp) {
                $employeeId      = (int) $emp['employee_id'];
                $baId            = (int) $emp['branch_assignment_id'];
                $salaryId        = (int) $emp['salary_id'];
                $dailyRate       = (float) $emp['daily_rate'];
                $standardMinutes = (int) ($emp['standard_minutes'] ?? 480); // 8h default
                [$empStart, $empEnd] = $this->employeeWindow($emp, (string) $periodStart, (string) $periodEnd);

                // --- Attendance summary for employee's active window in period ---
                $stmt = $pdo->prepare(
                    "SELECT a.attendance_date, a.late_minutes, a.undertime_minutes,
                            ws.standard_minutes AS row_standard_minutes
                       FROM attendance a
                       LEFT JOIN attendance_import_batch aib ON aib.import_batch_id = a.import_batch_id
                       LEFT JOIN work_schedule ws ON ws.schedule_id = a.schedule_id
                      WHERE a.employee_id = :emp_id
                        AND a.attendance_date BETWEEN :start AND :end
                        AND a.status IN ('Complete','Approved')
                        AND (a.import_batch_id IS NULL OR aib.status IN ('Approved','Completed'))"
                );
                $stmt->execute([':emp_id' => $employeeId, ':start' => $empStart, ':end' => $empEnd]);
                $attendanceRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $daysCount = count($attendanceRows);
                $totalLate = array_sum(array_map(static fn (array $row): int => (int) $row['late_minutes'], $attendanceRows));
                $totalUndertime = array_sum(array_map(static fn (array $row): int => (int) $row['undertime_minutes'], $attendanceRows));
                $undertimeDedRaw = 0.0;
                $standardMinutesByDate = [];
                foreach ($attendanceRows as $row) {
                    $rowStd = (int) ($row['row_standard_minutes'] ?? $standardMinutes);
                    if ($rowStd <= 0) {
                        $rowStd = $standardMinutes > 0 ? $standardMinutes : 480;
                    }
                    $standardMinutesByDate[(string) $row['attendance_date']] = $rowStd;
                    $rowUt = (int) $row['undertime_minutes'];
                    if ($rowUt > 0) {
                        $undertimeDedRaw += $rowUt * ($dailyRate / $rowStd);
                    }
                }
                $attendanceDates = array_fill_keys(
                    array_map(static fn (array $row): string => (string) $row['attendance_date'], $attendanceRows),
                    true,
                );
                $scheduledDates = $this->scheduledDatesForPeriod($pdo, $employeeId, $empStart, $empEnd);
                $attendancePolicy = $this->attendancePolicyForPeriod(
                    $pdo, $employeeId, $empStart, $empEnd, $attendanceDates, $scheduledDates
                );
                $paidLeaveRows = $attendancePolicy['paid_leave'];
                $paidLeaveDays = array_sum(array_column($paidLeaveRows, 'days'));
                $paidLeavePay = round($paidLeaveDays * $dailyRate, 2);
                $approvedOtByDate = $this->approvedOvertimeRequestsByDate(
                    $pdo, $employeeId, $empStart, $empEnd
                );
                $paidOtByDate = $this->payableOvertimeByDate(
                    $pdo, $employeeId, $empStart, $empEnd, $approvedOtByDate
                );
                $totalOvertime = array_sum($paidOtByDate);

                // Basic pay = days present × daily_rate
                // (full days: worked_minutes >= standard_minutes - 30min tolerance)
                $basicDays = $daysCount;
                $basicPay  = round($basicDays * $dailyRate, 2);

                // Overtime is paid only when attendance and an approved OT request agree.
                // Round per date so holiday OT swaps match regular-day contributions to the centavo.
                $minuteRate     = $standardMinutes > 0 ? $dailyRate / $standardMinutes : 0.0;
                $regularOtByDate = [];
                foreach ($paidOtByDate as $otDate => $otMins) {
                    $dayStd  = $standardMinutesByDate[$otDate] ?? $standardMinutes;
                    $dayRate = $dayStd > 0 ? $dailyRate / $dayStd : 0.0;
                    $regularOtByDate[$otDate] = round($otMins * $dayRate * 1.25, 2);
                }
                $overtimePay = round(array_sum($regularOtByDate), 2);

                // --- Holiday pay (REQ047, ADR-0001 §Holiday Pay) ---
                // Fetch attendance rows that overlap an active holiday in this period.
                // For each: compute holiday bundle (including rest-day multiplier), then
                // apply an adjustment against the regular-day amounts already counted in
                // $basicPay / $overtimePay. Also pay covered employees 100% daily rate
                // for unworked Regular Holidays per DOLE Rule IV Sec. 6.
                $holidayDayAdj = 0.0;
                $holidayOtAdj  = 0.0;
                $holidayEarningRows = [];
                $handledHolidayDates = [];

                $hStmt = $pdo->prepare(
                    "SELECT a.attendance_id,
                            a.attendance_date,
                            a.hours_worked_minutes,
                            hc.holiday_type,
                            hc.description AS holiday_description
                       FROM attendance a
                       LEFT JOIN attendance_import_batch aib ON aib.import_batch_id = a.import_batch_id
                       JOIN holiday_calendar hc
                         ON hc.holiday_date = a.attendance_date
                        AND hc.status = 'Active'
                      WHERE a.employee_id = :emp_id
                        AND a.attendance_date BETWEEN :start AND :end
                         AND a.status IN ('Complete','Approved')
                         AND (a.import_batch_id IS NULL OR aib.status IN ('Approved','Completed'))"
                );
                $hStmt->execute([
                    ':emp_id' => $employeeId,
                    ':start'  => $empStart,
                    ':end'    => $empEnd,
                ]);
                $holidayAttRows = $hStmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($holidayAttRows as $hRow) {
                    $hDate      = (string) $hRow['attendance_date'];
                    $handledHolidayDates[$hDate] = true;
                    $hType      = HolidayType::from($hRow['holiday_type']);
                    $workedMins = (int) $hRow['hours_worked_minutes'];
                    $hOtMins    = $paidOtByDate[$hDate] ?? 0;
                    $worked     = $workedMins > 0;
                    $isRestDay  = array_key_exists($hDate, $scheduledDates) && !$scheduledDates[$hDate];
                    $dayStd     = $standardMinutesByDate[$hDate] ?? $standardMinutes;
                    $dayMinRate = $dayStd > 0 ? $dailyRate / $dayStd : 0.0;

                    $bundle = $this->holidayCalc->computeBundle(
                        dailyRate:       $dailyRate,
                        type:            $hType,
                        worked:          $worked,
                        isRestDay:       $isRestDay,
                        overtimeMinutes: $hOtMins,
                        standardMinutes: $dayStd,
                    );

                    // Every row in $holidayAttRows was already counted once in
                    // $daysCount / $basicPay (1.00 × dailyRate). Reconcile that
                    // exact regular-day amount with the holiday day_pay so a
                    // zero-minute Approved row can never be double-paid and
                    // SUM(payroll_earnings.amount) always equals gross_pay.
                    $regularDayAmt     = $dailyRate;
                    $holidayDayPremium = round($bundle['day_pay'] - $regularDayAmt, 2);
                    if ($holidayDayPremium < 0.0 && $bundle['day_pay'] === 0.0) {
                        // Zero-worked Approved row on a no-work-no-pay Special or
                        // SpecialNonWorking holiday: exclude the unpaid day from
                        // Basic Pay instead of applying a negative holiday adjustment
                        // without a matching earning row.
                        $basicDays = max(0, $basicDays - 1);
                        $basicPay  = round($basicDays * $dailyRate, 2);
                    } else {
                        $holidayDayAdj += $holidayDayPremium;
                        if ($holidayDayPremium > 0.0) {
                            $holidayEarningRows[] = [
                                'type'        => $bundle['earning_type'],
                                'description' => $hRow['holiday_description'] . ' premium above Basic Pay',
                                'qty'         => 1,
                                'unit_rate'   => $dailyRate,
                                'multiplier'  => max(0.0, $bundle['multiplier_used'] - 1.0),
                                'amount'      => $holidayDayPremium,
                            ];
                        }
                    }

                    // Swap regular OT for holiday OT on this date
                    $regularOtAmt     = $regularOtByDate[$hDate] ?? 0.0;
                    $holidayOtPremium = round($bundle['overtime_pay'] - $regularOtAmt, 2);
                    if ($holidayOtPremium < 0.0 && $bundle['overtime_pay'] === 0.0 && $regularOtAmt > 0.0) {
                        // Zero-worked holiday row cannot earn regular OT; remove that
                        // date from regular OT so Overtime earnings match gross_pay.
                        unset($regularOtByDate[$hDate], $paidOtByDate[$hDate]);
                        $totalOvertime = array_sum($paidOtByDate);
                        $overtimePay   = round(array_sum($regularOtByDate), 2);
                    } else {
                        $holidayOtAdj += $holidayOtPremium;
                        if ($holidayOtPremium > 0.0 && $hOtMins > 0 && $dayMinRate > 0.0) {
                            $holidayEarningRows[] = [
                                'type'        => $bundle['earning_type'],
                                'description' => $hRow['holiday_description'] . ' OT premium above regular OT',
                                'qty'         => $hOtMins,
                                'unit_rate'   => $dayMinRate,
                                'multiplier'  => round(($bundle['overtime_pay'] / ($hOtMins * $dayMinRate)) - 1.25, 4),
                                'amount'      => $holidayOtPremium,
                            ];
                        }
                    }
                }

                // Unworked Regular Holidays (DOLE Labor Code Art. 94 / Rule IV Sec. 6):
                // Employees with no attendance record on a Regular Holiday (excluding
                // unresolved Incomplete/ReviewRequired days) receive 100% daily rate
                // only when they were present or on approved paid leave on the
                // scheduled working day immediately preceding the holiday.
                $unworkedHStmt = $pdo->prepare(
                    "SELECT holiday_date, holiday_type, description
                       FROM holiday_calendar
                      WHERE status = 'Active'
                        AND holiday_type = 'Regular'
                        AND holiday_date BETWEEN :start AND :end
                      ORDER BY holiday_date ASC"
                );
                $unworkedHStmt->execute([':start' => $empStart, ':end' => $empEnd]);
                $regularHolidayRows = $unworkedHStmt->fetchAll(PDO::FETCH_ASSOC);

                if ($regularHolidayRows !== []) {
                    $anyAttStmt = $pdo->prepare(
                        "SELECT a.attendance_date
                           FROM attendance a
                           LEFT JOIN attendance_import_batch aib ON aib.import_batch_id = a.import_batch_id
                          WHERE a.employee_id = :emp_id
                            AND a.attendance_date BETWEEN :start AND :end
                            AND a.status <> 'Cancelled'
                            AND (a.import_batch_id IS NULL OR aib.status <> 'Cancelled')"
                    );
                    $anyAttStmt->execute([
                        ':emp_id' => $employeeId,
                        ':start'  => $empStart,
                        ':end'    => $empEnd,
                    ]);
                    $anyAttendanceDates = array_fill_keys(
                        array_map('strval', $anyAttStmt->fetchAll(PDO::FETCH_COLUMN)),
                        true,
                    );

                    foreach ($regularHolidayRows as $uhRow) {
                        $uhDate = (string) $uhRow['holiday_date'];
                        if (isset($handledHolidayDates[$uhDate])
                            || isset($attendanceDates[$uhDate])
                            || isset($anyAttendanceDates[$uhDate])) {
                            continue;
                        }
                        if (!$this->isEligibleForUnworkedRegularHoliday($pdo, $employeeId, $uhDate, $basicDays, $paidLeaveDays)) {
                            continue;
                        }
                        $bundle = $this->holidayCalc->computeBundle(
                            dailyRate: $dailyRate,
                            type:      HolidayType::Regular,
                            worked:    false,
                        );
                        if ($bundle['day_pay'] > 0.0) {
                            $holidayDayAdj += $bundle['day_pay'];
                            $holidayEarningRows[] = [
                                'type'        => $bundle['earning_type'],
                                'description' => (string) $uhRow['description'] . ' (unworked regular holiday)',
                                'qty'         => 1,
                                'unit_rate'   => $dailyRate,
                                'multiplier'  => $bundle['multiplier_used'],
                                'amount'      => $bundle['day_pay'],
                            ];
                        }
                    }
                }

                $grossPay = round($basicPay + $paidLeavePay + $overtimePay + $holidayDayAdj + $holidayOtAdj, 2);

                // EEMR = (daily_rate × eemr_days_per_year) / 12
                $eemr = round(($dailyRate * $eemrDaysPerYear) / 12, 2);

                // --- Late deduction ---
                $lateDed = round($totalLate * $lateRatePerMin, 2);

                // --- Undertime deduction ---
                $undertimeDed = round($undertimeDedRaw, 2);

                // --- Contributions, Withholding Tax & Cash Advance ---
                // When gross pay is ₱0.00 (no worked days, no paid leave, no holiday pay),
                // skip statutory employee contributions, withholding tax, and cash advance repayment.
                $caHistoryId = null;
                if ($grossPay <= 0.0) {
                    $sssEmployee  = 0.0;
                    $sssEmployer  = 0.0;
                    $philEmployee = 0.0;
                    $philEmployer = 0.0;
                    $pagEmployee  = 0.0;
                    $pagEmployer  = 0.0;
                    $incomeTax    = 0.0;
                    $caDed        = 0.0;
                } else {
                    $sssEmployee  = $applyContributions ? $this->computeSss($eemr, $sssBrackets) : 0.0;
                    $sssEmployer  = $applyContributions ? $this->computeSssEmployer($eemr, $sssBrackets) : 0.0;
                    $philEmployee = $applyContributions ? $this->computePhilhealth($eemr, $philRate, true) : 0.0;
                    $philEmployer = $applyContributions ? $this->computePhilhealth($eemr, $philRate, false) : 0.0;
                    $pagEmployee  = $applyContributions ? $this->computePagibig($eemr, $pagibigRate, true) : 0.0;
                    $pagEmployer  = $applyContributions ? $this->computePagibig($eemr, $pagibigRate, false) : 0.0;

                    $taxableIncome = max(
                        0.0,
                        round($grossPay - ($sssEmployee + $philEmployee + $pagEmployee + $lateDed + $undertimeDed), 2)
                    );
                    $incomeTax = $this->taxCalc->computeWeekly($taxableIncome, $annualTaxThreshold);

                    $availableForCa = max(
                        0.0,
                        round($grossPay - ($sssEmployee + $philEmployee + $pagEmployee + $lateDed + $undertimeDed + $incomeTax), 2)
                    );
                    $caInfo       = $this->activeCashAdvanceInfo($pdo, $employeeId, (string) $periodEnd, $runId);
                    $caHistoryId  = $caInfo['history_id'];
                    $caDed        = round(min($caInfo['repayment'], $availableForCa), 2);
                }

                // --- Totals ---
                $totalDed = round($sssEmployee + $philEmployee + $pagEmployee + $lateDed + $undertimeDed + $incomeTax + $caDed, 2);
                $netPay   = round($grossPay - $totalDed, 2);
                if ($netPay < 0) {
                    $netPay = 0.0;
                }

                $now = $this->utcNow();

                // Insert payroll row
                $stmt = $pdo->prepare(
                    "INSERT INTO payroll
                        (payroll_run_id, payroll_period_id, employee_id,
                         branch_assignment_id, salary_id, daily_rate_snapshot,
                         gross_pay, total_deductions, net_pay,
                         created_at)
                     VALUES
                        (:run_id, (SELECT payroll_period_id FROM payroll_run WHERE payroll_run_id = :run_id2),
                         :emp_id, :ba_id, :sal_id, :rate,
                         :gross, :ded, :net,
                         :now)"
                );
                $stmt->execute([
                    ':run_id'  => $runId,
                    ':run_id2' => $runId,
                    ':emp_id'  => $employeeId,
                    ':ba_id'   => $baId,
                    ':sal_id'  => $salaryId,
                    ':rate'    => $dailyRate,
                    ':gross'   => $grossPay,
                    ':ded'     => $totalDed,
                    ':net'     => $netPay,
                    ':now'     => $now,
                ]);
                $payrollId = (int) $pdo->lastInsertId();

                // --- Earnings rows ---
                $this->insertEarning($pdo, $payrollId, 'Basic', 'Basic Pay', $basicDays, $dailyRate, 1.0, $basicPay, $now);
                foreach ($paidLeaveRows as $leave) {
                    $leaveAmount = round((float) $leave['days'] * $dailyRate, 2);
                    $this->insertEarning(
                        $pdo,
                        $payrollId,
                        'Basic',
                        'Approved ' . $leave['leave_type'] . ' Leave',
                        (float) $leave['days'],
                        $dailyRate,
                        1.0,
                        $leaveAmount,
                        $now,
                        (int) $leave['request_id'],
                    );
                }
                if ($overtimePay > 0) {
                    $otUnitRate = $totalOvertime > 0 ? ($overtimePay / ($totalOvertime * 1.25)) : $minuteRate;
                    $this->insertEarning($pdo, $payrollId, 'Overtime', 'Approved overtime pay', $totalOvertime, $otUnitRate, 1.25, $overtimePay, $now);
                }
                // --- Holiday earnings rows (REQ047) ---
                foreach ($holidayEarningRows as $hr) {
                    $this->insertEarning(
                        $pdo, $payrollId,
                        $hr['type'], $hr['description'],
                        $hr['qty'], $hr['unit_rate'], $hr['multiplier'], $hr['amount'],
                        $now
                    );
                }

                // --- Deduction rows ---
                if ($lateDed > 0) {
                    $this->insertDeduction($pdo, $payrollId, 'Late', 'Late deduction', $totalLate, $lateRatePerMin, $lateDed, $now);
                }
                if ($undertimeDed > 0) {
                    $utUnitRate = $totalUndertime > 0 ? ($undertimeDedRaw / $totalUndertime) : $minuteRate;
                    $this->insertDeduction($pdo, $payrollId, 'Undertime', 'Undertime deduction', $totalUndertime, $utUnitRate, $undertimeDed, $now);
                }
                if ($attendancePolicy['unpaid_absence_days'] > 0) {
                    $this->insertDeduction(
                        $pdo,
                        $payrollId,
                        'Absence',
                        'Unpaid absence (already excluded from Basic Pay)',
                        (float) $attendancePolicy['unpaid_absence_days'],
                        $dailyRate,
                        0.0,
                        $now,
                    );
                }
                if ($incomeTax > 0) {
                    $this->insertDeduction(
                        $pdo,
                        $payrollId,
                        'IncomeTax',
                        'BIR withholding tax (TRAIN weekly table)',
                        1,
                        $incomeTax,
                        $incomeTax,
                        $now,
                    );
                }
                if ($caDed > 0) {
                    $this->insertDeduction(
                        $pdo,
                        $payrollId,
                        'CashAdvance',
                        'Cash advance repayment',
                        1,
                        $caDed,
                        $caDed,
                        $now,
                        false,
                        $caHistoryId !== null ? ['history_id' => $caHistoryId] : [],
                    );
                }

                // --- Government contribution deduction rows + contribution_record ---
                $deductionDate = $payDate;
                foreach ([
                    ['SSS',       $sssEmployee,  $sssEmployer],
                    ['PhilHealth', $philEmployee, $philEmployer],
                    ['PagIBIG',   $pagEmployee,  $pagEmployer],
                ] as [$type, $empShare, $emplrShare]) {
                    if ($empShare <= 0) {
                        continue;
                    }
                    $dedId = $this->insertDeduction($pdo, $payrollId, $type, $type . ' contribution', 1, $empShare, $empShare, $now, true);

                    $pdo->prepare(
                        "INSERT INTO contribution_record
                            (payroll_id, deduction_id, contribution_policy_id,
                             contribution_type, eemr_basis,
                             employee_share, employer_share, deduction_date,
                             calculation_details, status, created_at)
                         VALUES
                            (:pid, :did, :cpid,
                             :type, :eemr,
                             :emp, :emplr, :ddate,
                             :details, 'Computed', :now)"
                    )->execute([
                        ':pid'     => $payrollId,
                        ':did'     => $dedId,
                        ':cpid'    => $contribPolicy['contribution_policy_id'],
                        ':type'    => $type,
                        ':eemr'    => $eemr,
                        ':emp'     => $empShare,
                        ':emplr'   => $emplrShare,
                        ':ddate'   => $deductionDate,
                        ':details' => json_encode($type === 'SSS'
                            ? [
                                'eemr' => $eemr,
                                'type' => $type,
                                'msc'  => SssContributionSchedule::mscFor($eemr),
                                // Employer share already includes this EC premium.
                                'ec'   => SssContributionSchedule::ecFor(SssContributionSchedule::mscFor($eemr)),
                            ]
                            : ['eemr' => $eemr, 'type' => $type]),
                        ':now'     => $now,
                    ]);
                }

                $runGross += $grossPay;
                $runDed   += $totalDed;
                $runNet   += $netPay;
            }

            // Update run totals and status
            $computedNow = $this->utcNow();
            $pdo->prepare(
                "UPDATE payroll_run SET
                    status       = 'Computed',
                    gross_pay    = :gross,
                    total_deductions = :ded,
                    net_pay      = :net,
                    computed_by  = :by,
                    computed_at  = :computed_at,
                    updated_at   = :updated_at
                  WHERE payroll_run_id = :id"
            )->execute([
                ':gross'       => round($runGross, 2),
                ':ded'         => round($runDed, 2),
                ':net'         => round($runNet, 2),
                ':by'          => $computedByUserId,
                ':computed_at' => $computedNow,
                ':updated_at'  => $computedNow,
                ':id'          => $runId,
            ]);
        });
    }

    // ===================================================================
    // Submission and Approval
    // ===================================================================

    public function submitForApproval(int $runId, int $submittedByUserId): void
    {
        $pdo = $this->connection->pdo();
        $run = $this->findRunOrFail($runId);

        // REQ010.11: approved runs are immutable
        if ($run['status'] === 'Approved') {
            throw new RuntimeException('Approved payroll runs are immutable and cannot be resubmitted.');
        }

        if (!in_array($run['status'], ['Computed', 'Returned'], true)) {
            throw new RuntimeException('Only Computed or Returned runs can be submitted for approval.');
        }

        (new PayrollPeriodClosure())->assertEnded((string) $run['period_end'], $this->manilaToday(), 'submitted for approval');

        $submittedNow = $this->utcNow();
        $updated = $pdo->prepare(
            "UPDATE payroll_run SET
                status       = 'PendingOwnerApproval',
                submitted_by = :by,
                submitted_at = :submitted_at,
                updated_at   = :updated_at
              WHERE payroll_run_id = :id
                AND status IN ('Computed', 'Returned')"
        );
        $updated->execute([
            ':by'           => $submittedByUserId,
            ':submitted_at' => $submittedNow,
            ':updated_at'   => $submittedNow,
            ':id'           => $runId,
        ]);
        if ($updated->rowCount() !== 1) {
            throw new RuntimeException('This payroll run changed status and can no longer be submitted.');
        }

        $pdo->prepare(
            "INSERT INTO audit_logs
                (user_id, event_type, action_performed, table_affected, record_id,
                 description, action_at, created_at)
             VALUES
                (:user_id, 'payroll_submitted', 'submit_payroll_run', 'payroll_run', :record_id,
                 :description, :action_at, :created_at)"
        )->execute([
            ':user_id'     => $submittedByUserId,
            ':record_id'   => $runId,
            ':description' => "Payroll run {$runId} submitted for Owner approval.",
            ':action_at'   => $submittedNow,
            ':created_at'  => $submittedNow,
        ]);
    }

    public function approve(int $runId, int $reviewedByUserId): void
    {
        $pdo = $this->connection->pdo();
        $run = $this->findRunOrFail($runId);

        if ($run['status'] !== 'PendingOwnerApproval') {
            throw new RuntimeException('Only runs pending approval can be approved.');
        }

        (new PayrollPeriodClosure())->assertEnded((string) $run['period_end'], $this->manilaToday(), 'approved');

        $now = $this->utcNow();

        $this->connection->transaction(function () use ($pdo, $runId, $reviewedByUserId, $now): void {
            // 1. Mark run as Approved
            $updated = $pdo->prepare(
                "UPDATE payroll_run SET
                    status      = 'Approved',
                    reviewed_by = :by,
                    reviewed_at = :reviewed_at,
                    updated_at  = :updated_at
                  WHERE payroll_run_id = :id
                    AND status = 'PendingOwnerApproval'"
            );
            $updated->execute([':by' => $reviewedByUserId, ':reviewed_at' => $now, ':updated_at' => $now, ':id' => $runId]);
            if ($updated->rowCount() !== 1) {
                throw new RuntimeException('This payroll run changed status and can no longer be approved.');
            }

            // 2. Payroll approval finalizes all of its generated contribution records.
            // Individual records cannot be independently locked or reopened.
            $pdo->prepare(
                "UPDATE contribution_record cr
                   JOIN payroll p ON p.payroll_id = cr.payroll_id
                    SET cr.status    = 'Locked',
                        cr.locked_at = :locked_at,
                        cr.locked_by = :locked_by
                  WHERE p.payroll_run_id = :run_id"
            )->execute([
                ':locked_at' => $now,
                ':locked_by' => $reviewedByUserId,
                ':run_id'    => $runId,
            ]);

            // 2b. Settle Cash Advance repayments upon Owner approval (REQ046 / ADR-0001).
            // Decrement cash_advance_history.remaining_balance and record an audit row
            // in cash_advance_repayment only when the payroll run is finalized.
            $caStmt = $pdo->prepare(
                "SELECT d.deduction_id, d.payroll_id, d.amount, d.calculation_details, p.employee_id
                   FROM deduction d
                   JOIN payroll p ON p.payroll_id = d.payroll_id
                  WHERE p.payroll_run_id = :run_id
                    AND d.deduction_type = 'CashAdvance'
                    AND d.amount > 0"
            );
            $caStmt->execute([':run_id' => $runId]);
            foreach ($caStmt->fetchAll(PDO::FETCH_ASSOC) as $caDedRow) {
                $details = json_decode((string) ($caDedRow['calculation_details'] ?? '{}'), true);
                $historyId = is_array($details) && isset($details['history_id']) && (int) $details['history_id'] > 0
                    ? (int) $details['history_id'] : null;

                if ($historyId === null) {
                    $hLookup = $pdo->prepare(
                        "SELECT history_id
                           FROM cash_advance_history
                          WHERE employee_id = :emp_id AND status = 'Active'
                          ORDER BY approved_at DESC, history_id DESC
                          LIMIT 1"
                    );
                    $hLookup->execute([':emp_id' => (int) $caDedRow['employee_id']]);
                    $foundH = $hLookup->fetchColumn();
                    $historyId = $foundH !== false ? (int) $foundH : null;
                }

                if ($historyId !== null) {
                    $dedAmount = (float) $caDedRow['amount'];
                    $pdo->prepare(
                        "UPDATE cash_advance_history
                            SET status = CASE
                                    WHEN remaining_balance <= :ded1
                                    THEN 'Paid'
                                    ELSE status
                                END,
                                remaining_balance = GREATEST(0, remaining_balance - :ded2),
                                updated_at = :now
                          WHERE history_id = :hid"
                    )->execute([
                        ':ded1' => $dedAmount,
                        ':ded2' => $dedAmount,
                        ':now'  => $now,
                        ':hid'  => $historyId,
                    ]);

                    $pdo->prepare(
                        "INSERT INTO cash_advance_repayment
                            (history_id, payroll_id, deduction_id, amount, created_at)
                         VALUES
                            (:hid, :pid, :did, :amt, :now)"
                    )->execute([
                        ':hid' => $historyId,
                        ':pid' => (int) $caDedRow['payroll_id'],
                        ':did' => (int) $caDedRow['deduction_id'],
                        ':amt' => $dedAmount,
                        ':now' => $now,
                    ]);
                }
            }

            // 3. Generate one payslip row per employee payroll row (REQ048)
            // Skip employees that already have a payslip for this run
            $stmt = $pdo->prepare(
                "SELECT p.payroll_id
                   FROM payroll p
                   LEFT JOIN payslip ps ON ps.payroll_id = p.payroll_id
                  WHERE p.payroll_run_id = :run_id
                    AND ps.payslip_id IS NULL"
            );
            $stmt->execute([':run_id' => $runId]);
            $payrollIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

            $insert = $pdo->prepare(
                "INSERT INTO payslip
                    (payroll_id, issue_date, generated_by, generated_at)
                 VALUES
                    (:pid, :issue, :by, :generated_at)"
            );
            $issueDate = (new DateTimeImmutable($now))->format('Y-m-d');
            foreach ($payrollIds as $pid) {
                $insert->execute([
                    ':pid'          => $pid,
                    ':issue'        => $issueDate,
                    ':by'           => $reviewedByUserId,
                    ':generated_at' => $now,
                ]);
            }

            // 4. Audit log
            $pdo->prepare(
                "INSERT INTO audit_logs
                    (user_id, event_type, action_performed, table_affected, record_id,
                     description, action_at, created_at)
                 VALUES
                    (:user_id, 'payroll_approved', 'approve_payroll_run', 'payroll_run', :record_id,
                     :description, :action_at, :created_at)"
            )->execute([
                ':user_id'     => $reviewedByUserId,
                ':record_id'   => $runId,
                ':description' => "Payroll run {$runId} approved by Owner. {$this->payslipCountMsg(count($payrollIds))}",
                ':action_at'   => $now,
                ':created_at'  => $now,
            ]);
        });
    }

    public function returnForRevision(int $runId, int $reviewedByUserId, string $reason): void
    {
        $pdo = $this->connection->pdo();
        $run = $this->findRunOrFail($runId);

        // REQ010.11: approved runs are immutable — cannot be returned after approval
        if ($run['status'] === 'Approved') {
            throw new RuntimeException('Approved payroll runs are immutable and cannot be returned.');
        }

        if ($run['status'] !== 'PendingOwnerApproval') {
            throw new RuntimeException('Only runs pending approval can be returned.');
        }
        if (trim($reason) === '') {
            throw new RuntimeException('A return reason is required.');
        }

        $now = $this->utcNow();
        $updated = $pdo->prepare(
            "UPDATE payroll_run SET
                status        = 'Returned',
                return_reason = :reason,
                reviewed_by   = :by,
                reviewed_at   = :reviewed_at,
                updated_at    = :updated_at
              WHERE payroll_run_id = :id
                AND status = 'PendingOwnerApproval'"
        );
        $updated->execute([':reason' => $reason, ':by' => $reviewedByUserId, ':reviewed_at' => $now, ':updated_at' => $now, ':id' => $runId]);
        if ($updated->rowCount() !== 1) {
            throw new RuntimeException('This payroll run changed status and can no longer be returned.');
        }

        $pdo->prepare(
            "INSERT INTO audit_logs
                (user_id, event_type, action_performed, table_affected, record_id,
                 description, action_at, created_at)
             VALUES
                (:user_id, 'payroll_returned', 'return_payroll_run', 'payroll_run', :record_id,
                 :description, :action_at, :created_at)"
        )->execute([
            ':user_id'     => $reviewedByUserId,
            ':record_id'   => $runId,
            ':description' => "Payroll run {$runId} returned to HR for revision. Reason: {$reason}",
            ':action_at'   => $now,
            ':created_at'  => $now,
        ]);
    }

    /**
     * Cancel an unapproved payroll run without deleting its calculation history.
     * A cancelled run no longer occupies its period/branch, so HR can create a
     * corrected replacement run.
     */
    public function cancelRun(int $runId, int $cancelledByUserId, string $reason): void
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new RuntimeException('A cancellation reason is required.');
        }
        if (mb_strlen($reason) > 1000) {
            throw new RuntimeException('The cancellation reason must be 1,000 characters or fewer.');
        }

        $this->connection->transaction(function (PDO $pdo) use ($runId, $cancelledByUserId, $reason): void {
            $statement = $pdo->prepare(
                'SELECT status FROM payroll_run WHERE payroll_run_id = :id FOR UPDATE'
            );
            $statement->execute([':id' => $runId]);
            $run = $statement->fetch(PDO::FETCH_ASSOC);

            if ($run === false) {
                throw new RuntimeException('Payroll run not found.');
            }

            $previousStatus = (string) $run['status'];
            if ($previousStatus === 'Approved') {
                throw new RuntimeException('Approved payroll runs are immutable and cannot be cancelled.');
            }
            if ($previousStatus === 'Cancelled') {
                throw new RuntimeException('This payroll run has already been cancelled.');
            }

            // Remove all payroll detail rows for this run so they do not
            // block a replacement run for the same payroll_period_id + employee_id
            // combination (enforced by uq_payroll_period_employee). The cancellation
            // marks the run as historical; keeping stale child rows would cause a
            // duplicate-key error on every subsequent recompute attempt.
            $pdo->prepare("DELETE FROM cash_advance_repayment WHERE payroll_id IN (SELECT payroll_id FROM payroll WHERE payroll_run_id = :id)")->execute([':id' => $runId]);
            $pdo->prepare("DELETE FROM contribution_record WHERE payroll_id IN (SELECT payroll_id FROM payroll WHERE payroll_run_id = :id)")->execute([':id' => $runId]);
            $pdo->prepare("DELETE FROM deduction         WHERE payroll_id IN (SELECT payroll_id FROM payroll WHERE payroll_run_id = :id)")->execute([':id' => $runId]);
            $pdo->prepare("DELETE FROM payroll_earnings  WHERE payroll_id IN (SELECT payroll_id FROM payroll WHERE payroll_run_id = :id)")->execute([':id' => $runId]);
            $pdo->prepare("DELETE FROM payslip           WHERE payroll_id IN (SELECT payroll_id FROM payroll WHERE payroll_run_id = :id)")->execute([':id' => $runId]);
            $pdo->prepare("DELETE FROM payroll           WHERE payroll_run_id = :id")->execute([':id' => $runId]);

            $now = $this->utcNow();
            $pdo->prepare(
                "UPDATE payroll_run
                    SET status              = 'Cancelled',
                        active_run_marker   = NULL,
                        cancellation_reason = :reason,
                        cancelled_by        = :cancelled_by,
                        cancelled_at        = :cancelled_at,
                        updated_at          = :updated_at
                  WHERE payroll_run_id = :id"
            )->execute([
                ':reason'       => $reason,
                ':cancelled_by' => $cancelledByUserId,
                ':cancelled_at' => $now,
                ':updated_at'   => $now,
                ':id'           => $runId,
            ]);

            $description = json_encode([
                'previous_status' => $previousStatus,
                'reason'          => $reason,
            ], JSON_UNESCAPED_SLASHES);
            $pdo->prepare(
                "INSERT INTO audit_logs
                    (user_id, event_type, action_performed, table_affected, record_id,
                     description, action_at, created_at)
                 VALUES
                    (:user_id, 'payroll_cancelled', 'cancel_payroll_run', 'payroll_run', :record_id,
                     :description, :action_at, :created_at)"
            )->execute([
                ':user_id'     => $cancelledByUserId,
                ':record_id'   => $runId,
                ':description' => $description,
                ':action_at'   => $now,
                ':created_at'  => $now,
            ]);
        });
    }

    // ===================================================================
    // Payslip detail data (Wire #4)
    // ===================================================================

    /**
     * Return full itemized payslip data for a given payslip_id.
     * Used by EmployeePortalController::payslipDetail() and the download renderer.
     *
     * @return array<string,mixed>
     */
    public function payslipData(int $payslipId): array
    {
        $pdo  = $this->connection->pdo();

        // Header
        $stmt = $pdo->prepare(
            "SELECT ps.payslip_id,
                    ps.issue_date,
                    p.employee_id,
                    p.payroll_id,
                    p.daily_rate_snapshot,
                    p.gross_pay,
                    p.total_deductions,
                    p.net_pay,
                    CONCAT(e.first_name, ' ', e.last_name)        AS employee_name,
                    e.employee_number,
                    e.position,
                    b.branch_name,
                    CONCAT(pp.period_start, ' – ', pp.period_end) AS period,
                    pp.period_start,
                    pp.period_end,
                    pp.pay_date
               FROM payslip ps
               JOIN payroll p         ON p.payroll_id          = ps.payroll_id
               JOIN payroll_run pr    ON pr.payroll_run_id      = p.payroll_run_id
               JOIN payroll_period pp ON pp.payroll_period_id   = pr.payroll_period_id
               JOIN employee e        ON e.employee_id          = p.employee_id
               JOIN branch b          ON b.branch_id            = pr.branch_id
              WHERE ps.payslip_id = :id"
        );
        $stmt->execute([':id' => $payslipId]);
        $header = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$header) {
            throw new RuntimeException('Payslip not found.');
        }

        $payrollId = (int) $header['payroll_id'];

        // Earnings
        $stmt = $pdo->prepare(
            "SELECT earning_type, description, quantity, unit_rate, multiplier, amount
               FROM payroll_earnings
              WHERE payroll_id = :id
              ORDER BY earning_type"
        );
        $stmt->execute([':id' => $payrollId]);
        $earnings = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Deductions
        $stmt = $pdo->prepare(
            "SELECT deduction_type, description, quantity, unit_rate, amount
               FROM deduction
              WHERE payroll_id = :id
              ORDER BY deduction_type"
        );
        $stmt->execute([':id' => $payrollId]);
        $deductions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'payslip_id'        => (int) $header['payslip_id'],
            'issue_date'        => $header['issue_date'],
            'employee_id'       => (int) $header['employee_id'],
            'payroll_id'        => (int) $header['payroll_id'],
            'employee_name'     => $header['employee_name'],
            'employee_number'   => $header['employee_number'],
            'position'          => $header['position'] ?? '',
            'branch_name'       => $header['branch_name'],
            'period'            => Formatter::date((string) $header['period_start']) . ' – ' . Formatter::date((string) $header['period_end']),
            'period_start'      => $header['period_start'],
            'period_end'        => $header['period_end'],
            'pay_date'          => $header['pay_date'],
            'daily_rate'        => (float) $header['daily_rate_snapshot'],
            'gross_pay'         => (float) $header['gross_pay'],
            'total_deductions'  => (float) $header['total_deductions'],
            'net_pay'           => (float) $header['net_pay'],
            'earnings'          => $earnings,
            'deductions'        => $deductions,
        ];
    }

    // ===================================================================
    // 13th Month Pay (Wire #5)
    // ===================================================================

    /**
     * Compute 13th-month pay for employees with approved payroll in a given
     * calendar year, optionally limited by the historical branch assignment
     * captured on the payroll row or by employee.
     *
     * 13th month = total basic pay earned in the year / 12.
     * Total basic pay = SUM of payroll_earnings.amount WHERE earning_type = 'Basic'
     * across all Approved payroll runs in the year.
     *
     * @param array{branch_id?:int,employee_id?:int} $filters
     * @return list<array{employee_id:int,employee_name:string,employee_number:string,basic_total:float,thirteenth_month:float}>
     */
    public function compute13thMonth(int $year, array $filters = []): array
    {
        $where = [
            "pe.earning_type = 'Basic'",
            "pr.status = 'Approved'",
            'YEAR(pp.period_start) = :year',
        ];
        $parameters = [':year' => $year];

        if (($filters['branch_id'] ?? 0) > 0) {
            $where[] = 'eba.branch_id = :branch_id';
            $parameters[':branch_id'] = $filters['branch_id'];
        }

        if (($filters['employee_id'] ?? 0) > 0) {
            $where[] = 'p.employee_id = :employee_id';
            $parameters[':employee_id'] = $filters['employee_id'];
        }

        $sql = "SELECT e.employee_id,
                    CONCAT(e.last_name, ', ', e.first_name) AS employee_name,
                    e.employee_number,
                    SUM(pe.amount) AS basic_total
               FROM payroll_earnings pe
               JOIN payroll p         ON p.payroll_id         = pe.payroll_id
               JOIN payroll_run pr    ON pr.payroll_run_id    = p.payroll_run_id
               JOIN payroll_period pp ON pp.payroll_period_id = pr.payroll_period_id
               JOIN employee e        ON e.employee_id        = p.employee_id
                JOIN employee_branch_assignment eba
                  ON eba.branch_assignment_id = p.branch_assignment_id
               WHERE " . implode("\n                 AND ", $where) . "
              GROUP BY e.employee_id, e.last_name, e.first_name, e.employee_number
               ORDER BY e.last_name, e.first_name";

        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute($parameters);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $result = [];
        foreach ($rows as $row) {
            $basicTotal      = (float) $row['basic_total'];
            $thirteenthMonth = round($basicTotal / 12, 2);
            $result[] = [
                'employee_id'      => (int) $row['employee_id'],
                'employee_name'    => $row['employee_name'],
                'employee_number'  => $row['employee_number'],
                'basic_total'      => $basicTotal,
                'thirteenth_month' => $thirteenthMonth,
            ];
        }
        return $result;
    }

    // ===================================================================
    // Private — Contribution calculations (Wire #3)
    // ===================================================================

    /**
     * Eligible employees: branch assignment overlapping [period_start, period_end]
     * (supports mid-period hires and employees archived/separated mid-period or
     * before payroll computation so they are still paid for worked days in
     * [eba.effective_from, eba.effective_to)).
     *
     * Shared by computeRun() and readinessIssues() so the pre-compute check
     * looks at exactly the employees that Compute pays.
     *
     * @return list<array<string, mixed>>
     */
    private function eligibleEmployees(PDO $pdo, int $runId, string $periodStart, string $periodEnd): array
    {
        $stmt = $pdo->prepare(
            "SELECT e.employee_id, e.employee_number, e.first_name, e.last_name,
                    eba.branch_assignment_id,
                    eba.effective_from AS eba_from,
                    eba.effective_to   AS eba_to,
                    s.salary_id, s.daily_rate,
                    ws.standard_minutes
               FROM employee_branch_assignment eba
               JOIN employee e ON e.employee_id = eba.employee_id
               JOIN salary s
                 ON s.salary_id = (
                     SELECT s2.salary_id
                       FROM salary s2
                      WHERE s2.employee_id    = e.employee_id
                        AND s2.effective_from <= :pe1
                        AND (s2.effective_to IS NULL OR s2.effective_to >= :ps2)
                        AND (
                            s2.status = 'Active'
                            OR (s2.status IN ('Superseded', 'Archived') AND s2.effective_to IS NOT NULL)
                        )
                      ORDER BY (s2.effective_from <= :ps1_pref) DESC, s2.effective_from DESC, s2.salary_id DESC
                      LIMIT 1
                 )
               LEFT JOIN employee_schedule_assignment esa
                 ON esa.assignment_id = (
                     SELECT esa2.assignment_id
                       FROM employee_schedule_assignment esa2
                      WHERE esa2.employee_id    = e.employee_id
                        AND esa2.effective_from <= :pe3
                        AND (esa2.effective_to IS NULL OR esa2.effective_to >= :ps4)
                        AND (esa2.status = 'Active' OR esa2.effective_to IS NOT NULL)
                      ORDER BY (esa2.effective_from <= :ps3_pref) DESC, esa2.effective_from DESC, esa2.assignment_id DESC
                      LIMIT 1
                 )
               LEFT JOIN work_schedule ws
                 ON ws.schedule_id = esa.schedule_id
              WHERE eba.branch_assignment_id = (
                  SELECT eba2.branch_assignment_id
                    FROM employee_branch_assignment eba2
                   WHERE eba2.employee_id = e.employee_id
                     AND eba2.branch_id   = (SELECT branch_id FROM payroll_run WHERE payroll_run_id = :run_id)
                     AND eba2.effective_from <= :pe5
                     AND (eba2.effective_to IS NULL OR eba2.effective_to > :ps6)
                   ORDER BY eba2.effective_from DESC, eba2.branch_assignment_id DESC
                   LIMIT 1
              )
                -- The Business Owner has no salary and is never paid, even if
                -- an owner account was linked to an employee profile.
                AND NOT EXISTS (
                  SELECT 1
                    FROM users uo
                    JOIN role ro ON ro.role_id = uo.role_id
                   WHERE uo.employee_id = e.employee_id
                     AND ro.role_name   = 'BusinessOwner'
                     AND uo.status     <> 'Archived'
              )"
        );
        $stmt->execute([
            ':pe1'      => $periodEnd,
            ':ps2'      => $periodStart,
            ':ps1_pref' => $periodStart,
            ':pe3'      => $periodEnd,
            ':ps4'      => $periodStart,
            ':ps3_pref' => $periodStart,
            ':pe5'      => $periodEnd,
            ':ps6'      => $periodStart,
            ':run_id'   => $runId,
        ]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @param list<array<string,mixed>> $brackets */
    private function computeSss(float $eemr, array $brackets): float
    {
        foreach ($brackets as $b) {
            $from = (float) $b['salary_from'];
            $to   = $b['salary_to'] !== null ? (float) $b['salary_to'] : PHP_FLOAT_MAX;
            if ($eemr >= $from && $eemr <= $to) {
                return (float) $b['employee_share'];
            }
        }
        // Use last bracket if above ceiling
        if (!empty($brackets)) {
            return (float) end($brackets)['employee_share'];
        }
        return 0.0;
    }

    /** @param list<array<string,mixed>> $brackets */
    private function computeSssEmployer(float $eemr, array $brackets): float
    {
        foreach ($brackets as $b) {
            $from = (float) $b['salary_from'];
            $to   = $b['salary_to'] !== null ? (float) $b['salary_to'] : PHP_FLOAT_MAX;
            if ($eemr >= $from && $eemr <= $to) {
                return (float) $b['employer_share'];
            }
        }
        if (!empty($brackets)) {
            return (float) end($brackets)['employer_share'];
        }
        return 0.0;
    }

    /** @param array<string,mixed> $rate */
    private function computePhilhealth(float $eemr, array $rate, bool $employeeShare): float
    {
        $floor   = $rate['basis_floor']   !== null ? (float) $rate['basis_floor']   : 0.0;
        $ceiling = $rate['basis_ceiling'] !== null ? (float) $rate['basis_ceiling'] : PHP_FLOAT_MAX;
        $basis   = max($floor, min($ceiling, $eemr));

        $shareRate = $employeeShare
            ? (float) $rate['employee_share_decimal']
            : ((float) $rate['rate_decimal'] - (float) $rate['employee_share_decimal']);

        return round($basis * $shareRate, 2);
    }

    /** @param array<string,mixed> $rate */
    private function computePagibig(float $eemr, array $rate, bool $employeeShare): float
    {
        // Fixed amount model (demo fixture)
        if ($rate['employee_fixed_amount'] !== null) {
            return $employeeShare
                ? (float) $rate['employee_fixed_amount']
                : (float) ($rate['employer_fixed_amount'] ?? 0.0);
        }

        // Rate model
        $ceiling = $rate['basis_ceiling'] !== null ? (float) $rate['basis_ceiling'] : $eemr;
        $basis   = min($ceiling, $eemr);
        $r       = (float) ($rate['rate_decimal'] ?? 0.02);
        return round($basis * $r, 2);
    }

    /**
     * The weekly ₱500 repayment (ADR-0001) this run takes, and from which advance.
     *
     * Only advances dated on or before the run's period end are due, so a run
     * for a past week never takes an advance given later. [PAY-031] Oldest
     * advance first. The balance left is what is still owed after the
     * repayments already taken in other payroll runs that are computed but
     * not yet approved: computing June and November together cannot take the
     * same last ₱500 twice (approval is what lowers remaining_balance).
     *
     * @return array{history_id:?int,repayment:float}
     */
    private function activeCashAdvanceInfo(PDO $pdo, int $employeeId, string $periodEnd, int $runId): array
    {
        $stmt = $pdo->prepare(
            "SELECT cah.history_id, cah.remaining_balance, card.advance_date
               FROM cash_advance_history cah
               JOIN cash_advance_request_detail card ON card.request_id = cah.request_id
              WHERE cah.employee_id = :id
                AND cah.status = 'Active'
                AND cah.remaining_balance > 0
                AND card.advance_date <= :period_end
              ORDER BY card.advance_date ASC, cah.history_id ASC"
        );
        $stmt->execute([':id' => $employeeId, ':period_end' => $periodEnd]);
        $advances = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($advances === []) {
            return ['history_id' => null, 'repayment' => 0.0];
        }

        $pendingByHistory = $this->cashAdvanceRepaymentsInOpenRuns($pdo, $employeeId, $runId);
        foreach ($advances as $row) {
            $historyId = (int) $row['history_id'];
            $owed = round((float) $row['remaining_balance'] - ($pendingByHistory[$historyId] ?? 0.0), 2);
            if ($owed > 0.0 && CashAdvanceDate::isDueBy((string) $row['advance_date'], $periodEnd)) {
                return [
                    'history_id' => $historyId,
                    'repayment'  => min(500.0, $owed), // ₱500/week (ADR-0001)
                ];
            }
        }

        return ['history_id' => null, 'repayment' => 0.0];
    }

    /**
     * Cash advance repayments already taken for this employee in other runs
     * that are computed but not yet approved (not yet settled).
     *
     * @return array<int,float> history_id => amount
     */
    private function cashAdvanceRepaymentsInOpenRuns(PDO $pdo, int $employeeId, int $runId): array
    {
        $stmt = $pdo->prepare(
            "SELECT d.amount, d.calculation_details
               FROM deduction d
               JOIN payroll p      ON p.payroll_id      = d.payroll_id
               JOIN payroll_run pr ON pr.payroll_run_id = p.payroll_run_id
              WHERE p.employee_id = :employee_id
                AND p.payroll_run_id <> :run_id
                AND pr.status IN ('Computed', 'PendingOwnerApproval', 'Returned')
                AND d.deduction_type = 'CashAdvance'
                AND d.amount > 0"
        );
        $stmt->execute([':employee_id' => $employeeId, ':run_id' => $runId]);

        $pending = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $details = json_decode((string) ($row['calculation_details'] ?? ''), true);
            $historyId = is_array($details) ? (int) ($details['history_id'] ?? 0) : 0;
            if ($historyId > 0) {
                $pending[$historyId] = round(($pending[$historyId] ?? 0.0) + (float) $row['amount'], 2);
            }
        }

        return $pending;
    }

    /**
     * @return array<string,list<array{start_time:string,end_time:string}>> Approved OT request windows, keyed by date.
     */
    private function approvedOvertimeRequestsByDate(PDO $pdo, int $employeeId, string $periodStart, string $periodEnd): array
    {
        // Fetch all approved OT requests whose date range overlaps the payroll period.
        $stmt = $pdo->prepare(
            "SELECT ord.date_from, ord.date_to, ord.start_time, ord.end_time
               FROM request r
               JOIN request_type rt ON rt.request_type_id = r.request_type_id
               JOIN overtime_request_detail ord ON ord.request_id = r.request_id
              WHERE r.employee_id = :emp_id
                AND rt.type_name = 'Overtime'
                AND r.status = 'Approved'
                AND ord.date_from <= :end
                AND ord.date_to   >= :start
              ORDER BY ord.date_from, ord.start_time"
        );
        $stmt->execute([':emp_id' => $employeeId, ':start' => $periodStart, ':end' => $periodEnd]);

        // Expand each range row into individual per-date entries so payableOvertimeByDate()
        // can look up approved windows by a single attendance date key.
        $approved = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $current = new \DateTimeImmutable((string) $row['date_from']);
            $last    = new \DateTimeImmutable((string) $row['date_to']);
            while ($current <= $last) {
                $date = $current->format('Y-m-d');
                // Only include dates that fall within the payroll period.
                if ($date >= $periodStart && $date <= $periodEnd) {
                    $approved[$date][] = [
                        'start_time' => (string) $row['start_time'],
                        'end_time'   => (string) $row['end_time'],
                    ];
                }
                $current = $current->modify('+1 day');
            }
        }
        return $approved;
    }

    /**
     * Match approved requests with actual attendance. ReviewRequired attendance is
     * deliberately excluded from OT pay until HR resolves the biometric exception.
     *
     * @param array<string,list<array{start_time:string,end_time:string}>> $approvedOtByDate
     * @return array<string,int> Payable OT minutes, keyed by attendance date.
     */
    private function payableOvertimeByDate(
        PDO $pdo,
        int $employeeId,
        string $periodStart,
        string $periodEnd,
        array $approvedOtByDate,
    ): array {
        if ($approvedOtByDate === []) {
            return [];
        }

        $stmt = $pdo->prepare(
            "SELECT a.attendance_date, a.time_in, a.time_out,
                    COALESCE(ws.work_start_time, '07:00:00') AS work_start_time,
                    COALESCE(ws.work_end_time, '16:00:00') AS work_end_time,
                    COALESCE(ws.break_minutes, 60) AS break_minutes,
                    CASE WHEN ws.schedule_id IS NULL THEN '12:00:00' ELSE ws.break_start_time END AS break_start_time,
                    CASE WHEN ws.schedule_id IS NULL THEN '13:00:00' ELSE ws.break_end_time END AS break_end_time,
                    COALESCE(ws.standard_minutes, 480) AS standard_minutes,
                    COALESCE(ws.overtime_allowed, 0) AS overtime_allowed
               FROM attendance a
               LEFT JOIN attendance_import_batch aib ON aib.import_batch_id = a.import_batch_id
               LEFT JOIN work_schedule ws ON ws.schedule_id = a.schedule_id
              WHERE a.employee_id = :emp_id
                AND a.attendance_date BETWEEN :start AND :end
                AND a.status IN ('Complete', 'Approved')
                AND (a.import_batch_id IS NULL OR aib.status IN ('Approved','Completed'))
                AND a.time_in IS NOT NULL
                AND a.time_out IS NOT NULL"
        );
        $stmt->execute([':emp_id' => $employeeId, ':start' => $periodStart, ':end' => $periodEnd]);

        $payable = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!(bool) $row['overtime_allowed']) {
                continue;
            }
            $date = (string) $row['attendance_date'];
            $minutes = $this->approvedOvertimeCalc->payableMinutesForAttendance(
                $row['time_in'] !== null ? (string) $row['time_in'] : null,
                $row['time_out'] !== null ? (string) $row['time_out'] : null,
                (string) $row['work_start_time'],
                (string) $row['work_end_time'],
                (int) $row['break_minutes'],
                (int) $row['standard_minutes'],
                $approvedOtByDate[$date] ?? [],
                $row['break_start_time'] !== null ? (string) $row['break_start_time'] : null,
                $row['break_end_time'] !== null ? (string) $row['break_end_time'] : null,
            );
            if ($minutes > 0) {
                $payable[$date] = $minutes;
            }
        }
        return $payable;
    }

    // ===================================================================
    // Private — DB helpers
    // ===================================================================

    /** @return array<string,mixed> */
    private function resolveActivePolicy(PDO $pdo): array
    {
        $stmt = $pdo->query(
            "SELECT policy_id, policy_code, eemr_days_per_year,
                    annual_tax_threshold, late_rate_per_minute, rounding_mode
               FROM payroll_policy_version
              WHERE status = 'Approved'
              ORDER BY effective_from DESC
              LIMIT 1"
        );
        $policy = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
        if (!$policy) {
            // No approved policy exists — insert the ADR-0001 default so the
            // FK on payroll_run.payroll_policy_id is always satisfied.
            $now = $this->utcNow();
            $ins = $pdo->prepare(
                "INSERT INTO payroll_policy_version
                    (policy_code, version, effective_from, effective_to,
                     eemr_days_per_year, annual_tax_threshold, late_rate_per_minute,
                     rounding_mode, demo_only, status,
                     approved_by, approved_at, created_at, updated_at)
                 VALUES
                    ('DEFAULT', 'V1', '2024-01-01', NULL,
                     313, 250000.00, 1.00,
                     'HalfUp', TRUE, 'Approved',
                     NULL, :approved_at, :created_at, :updated_at)"
            );
            $ins->execute([
                ':approved_at' => $now,
                ':created_at'  => $now,
                ':updated_at'  => $now,
            ]);
            $newId = (int) $pdo->lastInsertId();

            return [
                'policy_id'            => $newId,
                'policy_code'          => 'DEFAULT',
                'eemr_days_per_year'   => 313,
                'annual_tax_threshold' => 250000.00,
                'late_rate_per_minute' => 1.00,
                'rounding_mode'        => 'HalfUp',
            ];
        }
        return $policy;
    }

    /**
     * Guards against computing a run when any of its employees already have a
     * finalised payroll row for the same payroll period in a *different*, still
     * active (non-Cancelled) run.
     *
     * This prevents the `uq_payroll_period_employee` unique-key violation that
     * occurs when a cancelled run's payroll rows were not cleaned up (legacy
     * data) or when two concurrent compute attempts race on the same period.
     *
     * @param list<array<string,mixed>> $employees
     */
    private function assertEmployeesAreNotAlreadyPaidInAnotherRun(
        PDO $pdo,
        int $currentRunId,
        int $periodId,
        array $employees,
    ): void {
        if (empty($employees)) {
            return;
        }

        $employeeIds = array_map(static fn(array $e): int => (int) $e['employee_id'], $employees);
        $placeholders = implode(',', array_fill(0, count($employeeIds), '?'));

        $stmt = $pdo->prepare(
            "SELECT p.employee_id
               FROM payroll p
               JOIN payroll_run pr ON pr.payroll_run_id = p.payroll_run_id
              WHERE p.payroll_period_id  = ?
                AND p.payroll_run_id    != ?
                AND pr.status           != 'Cancelled'
                AND p.employee_id IN ($placeholders)
              LIMIT 1"
        );

        $bindings = [$periodId, $currentRunId, ...$employeeIds];
        $stmt->execute($bindings);
        $conflict = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($conflict !== false) {
            throw new RuntimeException(
                'One or more employees in this run have already been paid under a different active '
                . 'payroll run for the same period. Cancel or resolve that run before computing this one.'
            );
        }
    }

    /** @return array<string,mixed> */
    private function resolveRunPolicy(PDO $pdo, int $runId): array
    {
        $stmt = $pdo->prepare(
            "SELECT ppv.policy_id, ppv.eemr_days_per_year,
                    ppv.annual_tax_threshold, ppv.late_rate_per_minute, ppv.rounding_mode
               FROM payroll_run pr
               JOIN payroll_policy_version ppv ON ppv.policy_id = pr.payroll_policy_id
              WHERE pr.payroll_run_id = :id"
        );
        $stmt->execute([':id' => $runId]);
        $policy = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$policy) {
            return $this->resolveActivePolicy($pdo);
        }
        return $policy;
    }

    /** @return array<string,mixed> */
    private function resolveContribPolicy(PDO $pdo): array
    {
        $stmt = $pdo->query(
            "SELECT contribution_policy_id
               FROM contribution_policy_version
              WHERE status = 'Approved'
              ORDER BY effective_from DESC
              LIMIT 1"
        );
        $policy = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
        if (!$policy) {
            // No approved contribution policy exists — insert the canonical
            // PH-STATUTORY-2026 policy (SSS Circular 2024-006 15%, PhilHealth 5%,
            // Pag-IBIG 2% up to ₱10,000 MFS) so payroll computation is statutory-accurate.
            $now = $this->utcNow();

            $ins = $pdo->prepare(
                "INSERT INTO contribution_policy_version
                    (policy_code, version, effective_from, effective_to,
                     demo_only, status, approved_by, approved_at, created_at, updated_at)
                 VALUES
                    ('PH-STATUTORY-2026', 'V1', '2026-01-01', NULL,
                     FALSE, 'Approved', NULL, :approved_at, :created_at, :updated_at)"
            );
            $ins->execute([
                ':approved_at' => $now,
                ':created_at'  => $now,
                ':updated_at'  => $now,
            ]);
            $cpvId = (int) $pdo->lastInsertId();

            $bracketIns = $pdo->prepare(
                "INSERT INTO sss_bracket
                    (contribution_policy_id, salary_from, salary_to,
                     employee_share, employer_share,
                     effective_from, effective_to, created_at, updated_at)
                 VALUES
                    (:cpv, :from, :to, :ee, :er, '2026-01-01', NULL, :ca, :ua)"
            );
            foreach (SssContributionSchedule::brackets() as $b) {
                $bracketIns->execute([
                    ':cpv'  => $cpvId,
                    ':from' => $b['salary_from'],
                    ':to'   => $b['salary_to'],
                    ':ee'   => $b['employee_share'],
                    ':er'   => $b['employer_share'],
                    ':ca'   => $now,
                    ':ua'   => $now,
                ]);
            }

            // PhilHealth — 5% total, 2.5% employee share, floor ₱10k / ceiling ₱100k
            $pdo->prepare(
                "INSERT INTO philhealth_rate
                    (contribution_policy_id, rate_decimal, basis_floor, basis_ceiling,
                     employee_share_decimal, created_at, updated_at)
                 VALUES
                    (:cpv, 0.050000, 10000.00, 100000.00, 0.025000, :ca, :ua)"
            )->execute([':cpv' => $cpvId, ':ca' => $now, ':ua' => $now]);

            // Pag-IBIG — 2% rate, MFS ceiling ₱10,000
            $pdo->prepare(
                "INSERT INTO pagibig_rate
                    (contribution_policy_id, rate_decimal, basis_ceiling,
                     employee_fixed_amount, employer_fixed_amount, created_at, updated_at)
                 VALUES
                    (:cpv, 0.020000, 10000.00, NULL, NULL, :ca, :ua)"
            )->execute([':cpv' => $cpvId, ':ca' => $now, ':ua' => $now]);

            return ['contribution_policy_id' => $cpvId];
        }
        return $policy;
    }

    /** @return list<array<string,mixed>> */
    private function loadSssBrackets(PDO $pdo, int $policyId): array
    {
        $stmt = $pdo->prepare(
            "SELECT salary_from, salary_to, employee_share, employer_share
               FROM sss_bracket
              WHERE contribution_policy_id = :id
              ORDER BY salary_from ASC"
        );
        $stmt->execute([':id' => $policyId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string,mixed> */
    private function loadPhilhealthRate(PDO $pdo, int $policyId): array
    {
        $stmt = $pdo->prepare(
            "SELECT rate_decimal, basis_floor, basis_ceiling, employee_share_decimal
               FROM philhealth_rate
              WHERE contribution_policy_id = :id
              LIMIT 1"
        );
        $stmt->execute([':id' => $policyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return ['rate_decimal' => 0.05, 'basis_floor' => 10000, 'basis_ceiling' => 100000, 'employee_share_decimal' => 0.025];
        }
        return $row;
    }

    /** @return array<string,mixed> */
    private function loadPagibigRate(PDO $pdo, int $policyId): array
    {
        $stmt = $pdo->prepare(
            "SELECT rate_decimal, basis_ceiling, employee_fixed_amount, employer_fixed_amount
               FROM pagibig_rate
              WHERE contribution_policy_id = :id
              LIMIT 1"
        );
        $stmt->execute([':id' => $policyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return ['rate_decimal' => 0.02, 'basis_ceiling' => 10000, 'employee_fixed_amount' => null, 'employer_fixed_amount' => null];
        }
        return $row;
    }

    /**
     * Map each date in [$periodStart, $periodEnd] covered by an effective
     * schedule assignment to whether that date is a scheduled working day
     * (true) or a scheduled rest day (false).
     *
     * @return array<string, bool>
     */
    private function scheduledDatesForPeriod(
        PDO $pdo,
        int $employeeId,
        string $periodStart,
        string $periodEnd,
    ): array {
        // Shared with RequestService so a leave request is charged exactly the
        // days payroll will pay.
        return (new WorkCalendarRepository(new Connection([], $pdo)))
            ->scheduledDates($employeeId, $periodStart, $periodEnd);
    }

    /**
     * DOLE Labor Code Art. 94 / Omnibus Rules Book III, Rule IV, Sec. 6 & 10:
     * A covered employee who did not work on a Regular Holiday is entitled to
     * 100% daily rate when they were present (Complete/Approved attendance) or
     * on approved paid leave on the scheduled working day immediately preceding
     * the holiday. Rest days and preceding non-working holidays (e.g. Dec 24
     * Christmas Eve or successive regular holidays) are skipped when looking
     * back for that preceding working day; working on such a day also qualifies.
     */
    private function isEligibleForUnworkedRegularHoliday(
        PDO $pdo,
        int $employeeId,
        string $holidayDate,
        int $periodWorkedDays,
        int $periodPaidLeaveDays,
    ): bool {
        $timezone = new DateTimeZone('Asia/Manila');
        $holiday = new DateTimeImmutable($holidayDate, $timezone);
        $lookbackEnd = $holiday->modify('-1 day')->format('Y-m-d');
        $lookbackStart = $holiday->modify('-14 days')->format('Y-m-d');

        $prevScheduled = $this->scheduledDatesForPeriod($pdo, $employeeId, $lookbackStart, $lookbackEnd);
        if ($prevScheduled === []) {
            return $periodWorkedDays > 0 || $periodPaidLeaveDays > 0;
        }

        $nonWorkingStmt = $pdo->prepare(
            "SELECT holiday_date
               FROM holiday_calendar
              WHERE status = 'Active'
                AND holiday_type IN ('Regular', 'SpecialNonWorking')
                AND holiday_date BETWEEN :start AND :end"
        );
        $nonWorkingStmt->execute([':start' => $lookbackStart, ':end' => $lookbackEnd]);
        $nonWorkingHolidays = array_fill_keys(
            array_map('strval', $nonWorkingStmt->fetchAll(PDO::FETCH_COLUMN)),
            true,
        );

        $workedStmt = $pdo->prepare(
            "SELECT a.attendance_date
               FROM attendance a
               LEFT JOIN attendance_import_batch aib ON aib.import_batch_id = a.import_batch_id
              WHERE a.employee_id = :emp_id
                AND a.attendance_date BETWEEN :start AND :end
                AND a.status IN ('Complete', 'Approved')
                AND a.hours_worked_minutes > 0
                AND (a.import_batch_id IS NULL OR aib.status IN ('Approved', 'Completed'))"
        );
        $workedStmt->execute([
            ':emp_id' => $employeeId,
            ':start'  => $lookbackStart,
            ':end'    => $lookbackEnd,
        ]);
        $workedDates = array_fill_keys(
            array_map('strval', $workedStmt->fetchAll(PDO::FETCH_COLUMN)),
            true,
        );

        $leaveStmt = $pdo->prepare(
            "SELECT lrd.start_date, lrd.end_date
               FROM request r
               JOIN request_type rt ON rt.request_type_id = r.request_type_id
               JOIN leave_request_detail lrd ON lrd.request_id = r.request_id
              WHERE r.employee_id = :emp_id
                AND r.status = 'Approved'
                AND rt.type_name = 'Leave'
                AND lrd.start_date <= :end
                AND lrd.end_date >= :start"
        );
        $leaveStmt->execute([
            ':emp_id' => $employeeId,
            ':start'  => $lookbackStart,
            ':end'    => $lookbackEnd,
        ]);
        $leaveDates = [];
        foreach ($leaveStmt->fetchAll(PDO::FETCH_ASSOC) as $leave) {
            $start = max((string) $leave['start_date'], $lookbackStart);
            $end   = min((string) $leave['end_date'], $lookbackEnd);
            for ($d = new DateTimeImmutable($start, $timezone); $d <= new DateTimeImmutable($end, $timezone); $d = $d->modify('+1 day')) {
                $leaveDates[$d->format('Y-m-d')] = true;
            }
        }

        for ($day = $holiday->modify('-1 day'); $day >= new DateTimeImmutable($lookbackStart, $timezone); $day = $day->modify('-1 day')) {
            $date = $day->format('Y-m-d');
            if (isset($workedDates[$date]) || isset($leaveDates[$date])) {
                return true;
            }
            if ((array_key_exists($date, $prevScheduled) && !$prevScheduled[$date])
                || isset($nonWorkingHolidays[$date])) {
                continue;
            }
            if (array_key_exists($date, $prevScheduled) && $prevScheduled[$date]) {
                return false;
            }
        }

        return false;
    }

    /**
     * Resolve payroll-relevant leave and absence days from the employee's
     * historical work schedule. A day with qualifying attendance wins over a
     * leave request; approved leave wins over an absence; holidays are neither
     * absence nor leave-pay days here because holiday pay has its own policy.
     *
     * @param array<string, bool> $attendanceDates
     * @param array<string, bool>|null $scheduledDates
     * @return array{
     *   paid_leave:list<array{request_id:int,leave_type:string,days:int}>,
     *   unpaid_absence_days:int
     * }
     */
    private function attendancePolicyForPeriod(
        PDO $pdo,
        int $employeeId,
        string $periodStart,
        string $periodEnd,
        array $attendanceDates,
        ?array $scheduledDates = null,
    ): array {
        $scheduledDates ??= $this->scheduledDatesForPeriod($pdo, $employeeId, $periodStart, $periodEnd);
        $timezone = new DateTimeZone('Asia/Manila');
        $approvedUploadDates = $this->approvedAttendanceUploadDates(
            $pdo, $employeeId, $periodStart, $periodEnd
        );
        $leaveByDate  = $this->approvedLeaveByDate($pdo, $employeeId, $periodStart, $periodEnd, $scheduledDates);
        $holidayDates = $this->activeHolidayDates($pdo, $periodStart, $periodEnd);

        $paidLeave = [];
        $unpaidAbsenceDays = 0;
        $today = (new DateTimeImmutable('now', $timezone))->format('Y-m-d');
        foreach ($scheduledDates as $date => $isScheduled) {
            if (!$isScheduled || isset($holidayDates[$date]) || isset($attendanceDates[$date])) {
                continue;
            }
            if (isset($leaveByDate[$date])) {
                $leave = $leaveByDate[$date];
                $key = $leave['request_id'];
                $paidLeave[$key] ??= [
                    'request_id' => $leave['request_id'],
                    'leave_type' => $leave['leave_type'],
                    'days' => 0,
                ];
                $paidLeave[$key]['days']++;
            } elseif ($date < $today && isset($approvedUploadDates[$date])) {
                $unpaidAbsenceDays++;
            }
        }

        return [
            'paid_leave' => array_values($paidLeave),
            'unpaid_absence_days' => $unpaidAbsenceDays,
        ];
    }

    /**
     * Approved leave on scheduled working days, keyed by Y-m-d.
     *
     * @param array<string, bool> $scheduledDates
     * @return array<string, array{request_id:int, leave_type:string}>
     */
    private function approvedLeaveByDate(
        PDO $pdo,
        int $employeeId,
        string $periodStart,
        string $periodEnd,
        array $scheduledDates,
    ): array {
        $timezone = new DateTimeZone('Asia/Manila');
        $leaveStmt = $pdo->prepare(
            "SELECT r.request_id, lrd.leave_type, lrd.start_date, lrd.end_date
               FROM request r
               JOIN request_type rt ON rt.request_type_id = r.request_type_id
               JOIN leave_request_detail lrd ON lrd.request_id = r.request_id
              WHERE r.employee_id = :employee_id
                AND r.status = 'Approved'
                AND rt.type_name = 'Leave'
                AND lrd.start_date <= :period_end
                AND lrd.end_date >= :period_start
              ORDER BY r.request_id"
        );
        $leaveStmt->execute([
            ':employee_id' => $employeeId,
            ':period_start' => $periodStart,
            ':period_end' => $periodEnd,
        ]);

        $leaveByDate = [];
        foreach ($leaveStmt->fetchAll(PDO::FETCH_ASSOC) as $leave) {
            $start = max((string) $leave['start_date'], $periodStart);
            $end = min((string) $leave['end_date'], $periodEnd);
            for ($day = new DateTimeImmutable($start, $timezone); $day <= new DateTimeImmutable($end, $timezone); $day = $day->modify('+1 day')) {
                $date = $day->format('Y-m-d');
                if (($scheduledDates[$date] ?? false) && !isset($leaveByDate[$date])) {
                    $leaveByDate[$date] = [
                        'request_id' => (int) $leave['request_id'],
                        'leave_type' => (string) $leave['leave_type'],
                    ];
                }
            }
        }

        return $leaveByDate;
    }

    /** @return array<string, bool> Active holiday dates (Y-m-d) in the range. */
    private function activeHolidayDates(PDO $pdo, string $periodStart, string $periodEnd): array
    {
        return (new WorkCalendarRepository(new Connection([], $pdo)))
            ->activeHolidayDates($periodStart, $periodEnd);
    }

    /**
     * Days in a payroll run's period that Compute would leave unpaid without
     * any message (owner logic review C6): unresolved attendance, uploads not
     * approved yet, working days with no upload, and dates with no schedule.
     *
     * @return list<array{employee_id:int, employee_number:string, employee_name:string, date:string, issue:string, label:string}>
     */
    public function readinessIssues(int $runId): array
    {
        $pdo = $this->connection->pdo();
        $run = $this->findRunOrFail($runId);
        $periodStart = (string) $run['period_start'];
        $periodEnd   = (string) $run['period_end'];

        $evaluator    = new PayrollReadinessEvaluator();
        $holidayDates = $this->activeHolidayDates($pdo, $periodStart, $periodEnd);
        $attendanceStmt = $pdo->prepare(
            "SELECT a.attendance_date, a.status,
                    CASE WHEN a.import_batch_id IS NULL
                           OR aib.status IN ('Approved', 'Completed') THEN 1 ELSE 0 END AS upload_approved
               FROM attendance a
               LEFT JOIN attendance_import_batch aib ON aib.import_batch_id = a.import_batch_id
              WHERE a.employee_id = :emp_id
                AND a.attendance_date BETWEEN :start AND :end
                AND a.status <> 'Cancelled'
                AND (a.import_batch_id IS NULL OR aib.status <> 'Cancelled')"
        );

        $issues = [];
        foreach ($this->eligibleEmployees($pdo, $runId, $periodStart, $periodEnd) as $emp) {
            $employeeId = (int) $emp['employee_id'];
            [$empStart, $empEnd] = $this->employeeWindow($emp, $periodStart, $periodEnd);
            if ($empStart > $empEnd) {
                continue;
            }

            $windowDates = [];
            for ($day = new DateTimeImmutable($empStart); $day <= new DateTimeImmutable($empEnd); $day = $day->modify('+1 day')) {
                $windowDates[] = $day->format('Y-m-d');
            }

            $attendanceStmt->execute([':emp_id' => $employeeId, ':start' => $empStart, ':end' => $empEnd]);
            $attendance = [];
            foreach ($attendanceStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $attendance[(string) $row['attendance_date']] = [
                    'status'          => (string) $row['status'],
                    'upload_approved' => (int) $row['upload_approved'] === 1,
                ];
            }

            $scheduledDates = $this->scheduledDatesForPeriod($pdo, $employeeId, $empStart, $empEnd);
            $leaveDates     = array_map(
                static fn (): bool => true,
                $this->approvedLeaveByDate($pdo, $employeeId, $empStart, $empEnd, $scheduledDates),
            );
            $uploadedDates  = $this->approvedAttendanceUploadDates($pdo, $employeeId, $empStart, $empEnd);

            $name = trim((string) ($emp['last_name'] ?? '') . ', ' . (string) ($emp['first_name'] ?? ''), ', ');
            foreach ($evaluator->evaluate($windowDates, $scheduledDates, $attendance, $holidayDates, $leaveDates, $uploadedDates) as $issue) {
                $issues[] = [
                    'employee_id'     => $employeeId,
                    'employee_number' => (string) $emp['employee_number'],
                    'employee_name'   => $name,
                    'date'            => $issue['date'],
                    'issue'           => $issue['issue'],
                    'label'           => PayrollReadinessEvaluator::label($issue['issue']),
                ];
            }
        }

        return $issues;
    }

    /**
     * Compute only after HR has seen the days that would go unpaid.
     *
     * With unresolved days and no confirmation, nothing is computed and the
     * run is left as it was. With confirmation, the run is computed and the
     * confirmation is written to the audit log.
     *
     * @return int Number of unresolved days HR confirmed (0 when none).
     */
    public function computeRunIfReady(int $runId, int $computedByUserId, bool $confirmedUnresolvedDays): int
    {
        $issues = $this->readinessIssues($runId);
        if ($issues !== [] && !$confirmedUnresolvedDays) {
            $count = count($issues);
            throw new RuntimeException(
                "Payroll was not computed: {$count} day(s) would be left unpaid. "
                . 'Resolve them in Attendance, or tick the confirmation box to compute without paying them.'
            );
        }

        $this->computeRun($runId, $computedByUserId);

        if ($issues !== []) {
            $now   = $this->utcNow();
            $count = count($issues);
            $this->connection->pdo()->prepare(
                "INSERT INTO audit_logs
                    (user_id, event_type, action_performed, table_affected, record_id,
                     description, action_at, created_at)
                 VALUES
                    (:user_id, 'payroll_computed', 'compute_with_unresolved_days', 'payroll_run', :record_id,
                     :description, :action_at, :created_at)"
            )->execute([
                ':user_id'     => $computedByUserId,
                ':record_id'   => $runId,
                ':description' => "Payroll run {$runId} computed with {$count} unresolved day(s) confirmed as unpaid by HR.",
                ':action_at'   => $now,
                ':created_at'  => $now,
            ]);
        }

        return count($issues);
    }

    /**
     * The dates one employee is paid for in a run: the period, narrowed to the
     * branch assignment (effective_to is the first day no longer assigned).
     *
     * @param array<string, mixed> $emp Row from eligibleEmployees().
     * @return array{0:string, 1:string} [first date, last date]
     */
    private function employeeWindow(array $emp, string $periodStart, string $periodEnd): array
    {
        $empStart = max($periodStart, (string) ($emp['eba_from'] ?? $periodStart));
        $empEnd   = ($emp['eba_to'] ?? null) !== null
            ? min($periodEnd, (new DateTimeImmutable((string) $emp['eba_to']))->modify('-1 day')->format('Y-m-d'))
            : $periodEnd;

        return [$empStart, $empEnd];
    }

    /**
     * @return array<string, bool> Scheduled dates covered by an approved or
     *                              completed device import for this employee.
     *                              When biometric punches exist for a batch,
     *                              coverage is bounded to that batch's actual
     *                              punch date range so partial-month uploads
     *                              (e.g. aug.xls ending 08/28) do not mark
     *                              uncovered trailing days as unpaid absences.
     */
    private function approvedAttendanceUploadDates(PDO $pdo, int $employeeId, string $periodStart, string $periodEnd): array
    {
        if ($this->hasBatchPunchRangeSchema($pdo)) {
            $sql = "SELECT eba.effective_from AS assignment_from, eba.effective_to AS assignment_to,
                           dbb.effective_from AS device_from, dbb.effective_to AS device_to,
                           aib.source_year, aib.source_month,
                           MIN(DATE(bp.source_local_at)) AS min_punch_date,
                           MAX(DATE(bp.source_local_at)) AS max_punch_date
                      FROM attendance_import_batch aib
                      JOIN biometric_device_branch dbb ON dbb.device_id = aib.device_id
                      JOIN employee_branch_assignment eba ON eba.branch_id = dbb.branch_id
                      LEFT JOIN biometric_punch bp ON bp.import_batch_id = aib.import_batch_id
                     WHERE eba.employee_id = :employee_id
                       AND aib.status IN ('Approved', 'Completed')
                     GROUP BY eba.effective_from, eba.effective_to,
                              dbb.effective_from, dbb.effective_to,
                              aib.device_id, aib.source_year, aib.source_month";
        } else {
            $sql = "SELECT eba.effective_from AS assignment_from, eba.effective_to AS assignment_to,
                           dbb.effective_from AS device_from, dbb.effective_to AS device_to,
                           aib.source_year, aib.source_month,
                           NULL AS min_punch_date, NULL AS max_punch_date
                      FROM attendance_import_batch aib
                      JOIN biometric_device_branch dbb ON dbb.device_id = aib.device_id
                      JOIN employee_branch_assignment eba ON eba.branch_id = dbb.branch_id
                     WHERE eba.employee_id = :employee_id
                       AND aib.status IN ('Approved', 'Completed')";
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':employee_id' => $employeeId]);

        $covered = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $batchMin = $row['min_punch_date'] !== null ? (string) $row['min_punch_date'] : null;
            $batchMax = $row['max_punch_date'] !== null ? (string) $row['max_punch_date'] : null;
            for ($day = new DateTimeImmutable($periodStart); $day <= new DateTimeImmutable($periodEnd); $day = $day->modify('+1 day')) {
                $date = $day->format('Y-m-d');
                if ((int) $day->format('Y') !== (int) $row['source_year']
                    || (int) $day->format('n') !== (int) $row['source_month']
                    || ($batchMin !== null && $date < $batchMin)
                    || ($batchMax !== null && $date > $batchMax)
                    || (string) $row['assignment_from'] > $date
                    || ($row['assignment_to'] !== null && (string) $row['assignment_to'] < $date)
                    || (string) $row['device_from'] > $date
                    || ($row['device_to'] !== null && (string) $row['device_to'] < $date)) {
                    continue;
                }
                $covered[$date] = true;
            }
        }

        return $covered;
    }

    /**
     * Always true on MySQL/MariaDB. On in-memory SQLite unit tests, checks
     * whether the minimal test schema defines `biometric_punch` and
     * `attendance_import_batch.import_batch_id` before joining punch ranges.
     */
    private function hasBatchPunchRangeSchema(PDO $pdo): bool
    {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
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
        foreach ($colStmt->fetchAll(PDO::FETCH_ASSOC) as $col) {
            if (($col['name'] ?? '') === 'import_batch_id') {
                return true;
            }
        }
        return false;
    }

    private function insertEarning(
        PDO $pdo, int $payrollId, string $type, string $desc,
        float $qty, float $unitRate, float $multiplier, float $amount, string $now,
        ?int $sourceRequestId = null,
    ): int {
        $stmt = $pdo->prepare(
            "INSERT INTO payroll_earnings
                (payroll_id, earning_type, description, source_request_id,
                 quantity, unit_rate, multiplier, amount,
                 calculation_details, created_at)
             VALUES
                (:pid, :type, :desc, :source_request_id,
                 :qty, :rate, :mult, :amount,
                 :details, :now)"
        );
        $stmt->execute([
            ':pid'     => $payrollId,
            ':type'    => $type,
            ':desc'    => $desc,
            ':source_request_id' => $sourceRequestId,
            ':qty'     => $qty,
            ':rate'    => $unitRate,
            ':mult'    => $multiplier,
            ':amount'  => $amount,
            ':details' => json_encode([
                'qty' => $qty,
                'unit_rate' => $unitRate,
                'multiplier' => $multiplier,
                'source_request_id' => $sourceRequestId,
            ]),
            ':now'     => $now,
        ]);
        return (int) $pdo->lastInsertId();
    }

    /**
     * Insert a deduction row and return its ID.
     *
     * @param array<string,mixed> $extraDetails
     */
    private function insertDeduction(
        PDO $pdo, int $payrollId, string $type, string $desc,
        float $qty, float $unitRate, float $amount, string $now,
        bool $returnId = false, array $extraDetails = []
    ): int {
        $stmt = $pdo->prepare(
            "INSERT INTO deduction
                (payroll_id, deduction_type, description,
                 quantity, unit_rate, amount,
                 calculation_details, created_at)
             VALUES
                (:pid, :type, :desc,
                 :qty, :rate, :amount,
                 :details, :now)"
        );
        $stmt->execute([
            ':pid'     => $payrollId,
            ':type'    => $type,
            ':desc'    => $desc,
            ':qty'     => $qty,
            ':rate'    => $unitRate,
            ':amount'  => $amount,
            ':details' => json_encode(['qty' => $qty, 'unit_rate' => $unitRate] + $extraDetails),
            ':now'     => $now,
        ]);
        return (int) $pdo->lastInsertId();
    }

    /** True only for the Friday payday that is last in its calendar month. */
    private function isMonthlyContributionCutoff(string $payDate): bool
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $payDate, new DateTimeZone('Asia/Manila'));
        if ($date === false || (int) $date->format('N') !== 5) {
            return false;
        }
        return $date->format('Y-m-d') === $date->modify('last friday of this month')->format('Y-m-d');
    }

    private function payslipCountMsg(int $count): string
    {
        return $count === 0
            ? 'No new payslips generated (all already existed).'
            : "{$count} payslip(s) generated.";
    }

    private function utcNow(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }

    private function manilaToday(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d');
    }
}

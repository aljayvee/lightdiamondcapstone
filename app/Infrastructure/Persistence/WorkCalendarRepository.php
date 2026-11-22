<?php

declare(strict_types=1);

namespace Wbpms\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * Read-only lookups shared by payroll and requests, so both use the same
 * working-day, holiday and "already paid" rules.
 */
final class WorkCalendarRepository extends AbstractRepository
{
    /**
     * The employee's working days from their historical schedule assignments.
     * Dates with no assignment are left out (payroll treats them as unscheduled).
     *
     * @return array<string,bool> Y-m-d => true for a working day, false for a rest day.
     */
    public function scheduledDates(int $employeeId, string $periodStart, string $periodEnd): array
    {
        $scheduleStmt = $this->pdo()->prepare(
            "SELECT esa.effective_from, esa.effective_to, ws.working_days
               FROM employee_schedule_assignment esa
               JOIN work_schedule ws ON ws.schedule_id = esa.schedule_id
              WHERE esa.employee_id = :employee_id
                AND esa.effective_from <= :period_end
                AND (esa.effective_to IS NULL OR esa.effective_to >= :period_start)
                AND (esa.status = 'Active' OR esa.effective_to IS NOT NULL)
              ORDER BY esa.effective_from DESC"
        );
        $scheduleStmt->execute([
            ':employee_id' => $employeeId,
            ':period_start' => $periodStart,
            ':period_end' => $periodEnd,
        ]);

        $assignments = [];
        foreach ($scheduleStmt->fetchAll(PDO::FETCH_ASSOC) as $assignment) {
            $workingDays = json_decode((string) $assignment['working_days'], true);
            if (!is_array($workingDays)) {
                continue;
            }
            $assignments[] = [
                'effective_from' => (string) $assignment['effective_from'],
                'effective_to' => $assignment['effective_to'] !== null ? (string) $assignment['effective_to'] : null,
                'working_days' => array_fill_keys(array_filter($workingDays, 'is_string'), true),
            ];
        }

        $scheduledDates = [];
        $timezone = new DateTimeZone('Asia/Manila');
        for ($day = new DateTimeImmutable($periodStart, $timezone); $day <= new DateTimeImmutable($periodEnd, $timezone); $day = $day->modify('+1 day')) {
            $date = $day->format('Y-m-d');
            foreach ($assignments as $assignment) {
                if ($assignment['effective_from'] <= $date
                    && ($assignment['effective_to'] === null || $assignment['effective_to'] >= $date)) {
                    $scheduledDates[$date] = isset($assignment['working_days'][$day->format('l')]);
                    break;
                }
            }
        }

        return $scheduledDates;
    }

    /**
     * @return array<string,true> Y-m-d keys of active holidays in the range.
     */
    public function activeHolidayDates(string $periodStart, string $periodEnd): array
    {
        $holidayStmt = $this->pdo()->prepare(
            "SELECT holiday_date FROM holiday_calendar
              WHERE status = 'Active' AND holiday_date BETWEEN :period_start AND :period_end"
        );
        $holidayStmt->execute([':period_start' => $periodStart, ':period_end' => $periodEnd]);

        return array_fill_keys(array_map('strval', $holidayStmt->fetchAll(PDO::FETCH_COLUMN)), true);
    }

    /**
     * Years between $fromYear and $toYear that have at least one active holiday.
     *
     * @return list<int>
     */
    public function yearsWithActiveHolidays(int $fromYear, int $toYear): array
    {
        $stmt = $this->pdo()->prepare(
            "SELECT DISTINCT YEAR(holiday_date)
               FROM holiday_calendar
              WHERE status = 'Active'
                AND holiday_date BETWEEN :from_date AND :to_date"
        );
        $stmt->execute([
            ':from_date' => sprintf('%04d-01-01', $fromYear),
            ':to_date'   => sprintf('%04d-12-31', $toYear),
        ]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Dates with attendance that payroll pays as worked days (same filter as
     * PayrollService::computeRun's attendance summary).
     *
     * @return array<string,true>
     */
    public function workedDates(int $employeeId, string $periodStart, string $periodEnd): array
    {
        $stmt = $this->pdo()->prepare(
            "SELECT a.attendance_date
               FROM attendance a
               LEFT JOIN attendance_import_batch aib ON aib.import_batch_id = a.import_batch_id
              WHERE a.employee_id = :emp_id
                AND a.attendance_date BETWEEN :start AND :end
                AND a.status IN ('Complete','Approved')
                AND (a.import_batch_id IS NULL OR aib.status IN ('Approved','Completed'))"
        );
        $stmt->execute([':emp_id' => $employeeId, ':start' => $periodStart, ':end' => $periodEnd]);

        return array_fill_keys(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN)), true);
    }

    /**
     * Dates in the range already covered by one of the employee's rows in an
     * Approved payroll run (period and that row's branch assignment window).
     * Approved runs are immutable, so leave or overtime on these dates can
     * no longer be paid.
     *
     * @return array<string,int> Y-m-d => payroll_run_id
     */
    public function datesInApprovedPayroll(int $employeeId, string $firstDate, string $lastDate): array
    {
        $stmt = $this->pdo()->prepare(
            "SELECT pr.payroll_run_id, pp.period_start, pp.period_end,
                    eba.effective_from AS eba_from, eba.effective_to AS eba_to
               FROM payroll p
               JOIN payroll_run pr    ON pr.payroll_run_id    = p.payroll_run_id
               JOIN payroll_period pp ON pp.payroll_period_id = p.payroll_period_id
               LEFT JOIN employee_branch_assignment eba ON eba.branch_assignment_id = p.branch_assignment_id
              WHERE p.employee_id = :employee_id
                AND pr.status = 'Approved'
                AND pp.period_start <= :last_date
                AND pp.period_end   >= :first_date"
        );
        $stmt->execute([
            ':employee_id' => $employeeId,
            ':first_date'  => $firstDate,
            ':last_date'   => $lastDate,
        ]);

        $covered = [];
        $timezone = new DateTimeZone('Asia/Manila');
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            // Same window as PayrollService::employeeWindow(): effective_to is exclusive.
            $from = max($firstDate, (string) $row['period_start'], (string) ($row['eba_from'] ?? $row['period_start']));
            $to   = min($lastDate, (string) $row['period_end']);
            if ($row['eba_to'] !== null) {
                $to = min($to, (new DateTimeImmutable((string) $row['eba_to'], $timezone))->modify('-1 day')->format('Y-m-d'));
            }
            for ($day = new DateTimeImmutable($from, $timezone); $day <= new DateTimeImmutable($to, $timezone); $day = $day->modify('+1 day')) {
                $covered[$day->format('Y-m-d')] ??= (int) $row['payroll_run_id'];
            }
        }
        ksort($covered);

        return $covered;
    }

    /**
     * Friday pay_dates in [$firstPayDate, $lastPayDate] where the employee
     * already has a row in an Approved payroll run (immutable). [REQ-026]
     *
     * @return list<string> ascending Y-m-d pay_date values
     */
    public function approvedPayDatesInRange(
        int $employeeId,
        string $firstPayDate,
        string $lastPayDate,
        ?PDO $pdo = null,
    ): array {
        $db = $pdo ?? $this->pdo();
        $stmt = $db->prepare(
            "SELECT DISTINCT pp.pay_date
               FROM payroll p
               JOIN payroll_run pr    ON pr.payroll_run_id    = p.payroll_run_id
               JOIN payroll_period pp ON pp.payroll_period_id = p.payroll_period_id
              WHERE p.employee_id = :employee_id
                AND pr.status = 'Approved'
                AND pp.pay_date BETWEEN :first_pay_date AND :last_pay_date
              ORDER BY pp.pay_date ASC"
        );
        $stmt->execute([
            ':employee_id'    => $employeeId,
            ':first_pay_date' => $firstPayDate,
            ':last_pay_date'  => $lastPayDate,
        ]);

        return array_values(array_map(
            static fn ($d): string => substr((string) $d, 0, 10),
            $stmt->fetchAll(PDO::FETCH_COLUMN),
        ));
    }
}

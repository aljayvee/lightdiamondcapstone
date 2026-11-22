<?php

declare(strict_types=1);

namespace Wbpms\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Wbpms\Http\View\Formatter;

/**
 * Earning Details: Basic Pay shows days × rate and the attendance behind it
 * (days, hours worked, late, undertime) on the HR run page and the owner
 * review page. [PAY-026]
 */
final class PayrollBasicPayAttendanceTest extends TestCase
{
    #[Test]
    public function minutesAreShownAsHoursAndMinutes(): void
    {
        self::assertSame('47h 30m', Formatter::hoursMinutes(2850));
        self::assertSame('8h 00m', Formatter::hoursMinutes(480));
        self::assertSame('45m', Formatter::hoursMinutes(45));
        self::assertSame('0m', Formatter::hoursMinutes(0));
        self::assertSame('0m', Formatter::hoursMinutes(-5));
    }

    #[Test]
    public function quantitiesDropTrailingZeros(): void
    {
        self::assertSame('6', Formatter::quantity(6.0));
        self::assertSame('10', Formatter::quantity(10.0));
        self::assertSame('5.5', Formatter::quantity(5.5));
        self::assertSame('0.25', Formatter::quantity(0.25));
        self::assertSame('0', Formatter::quantity(0.0));
    }

    #[Test]
    public function hrRunPageShowsDaysRateAndHoursOnBasicPay(): void
    {
        $html = self::render('hr/payroll/detail', 'Computed');

        self::assertStringContainsString('6 days × ₱460.22', $html);
        self::assertStringContainsString('Attendance: 6 days · 47h 30m worked · late 15m · undertime 30m', $html);
        // Leave rows are Basic too: days × rate, but no attendance line.
        self::assertStringContainsString('1 day × ₱460.22', $html);
        self::assertSame(1, substr_count($html, 'Attendance: '));
    }

    #[Test]
    public function ownerReviewPageShowsTheSameLines(): void
    {
        $html = self::render('owner/payroll/review', 'PendingOwnerApproval');

        self::assertStringContainsString('6 days × ₱460.22', $html);
        self::assertStringContainsString('Attendance: 6 days · 47h 30m worked · late 15m · undertime 30m', $html);
    }

    #[Test]
    public function changedAttendanceIsFlaggedOnlyWhenDaysDiffer(): void
    {
        self::assertStringNotContainsString('basic-attendance-differs', self::render('hr/payroll/detail', 'Computed'));

        $changed = [142 => ['days' => 7, 'worked_minutes' => 3330, 'late_minutes' => 0, 'undertime_minutes' => 0]];
        self::assertStringContainsString('basic-attendance-differs', self::render('hr/payroll/detail', 'Computed', $changed));
        self::assertStringContainsString('basic-attendance-differs', self::render('owner/payroll/review', 'PendingOwnerApproval', $changed));
    }

    #[Test]
    public function noAttendanceSummaryMeansNoAttendanceLine(): void
    {
        $html = self::render('hr/payroll/detail', 'Computed', []);

        self::assertStringContainsString('6 days × ₱460.22', $html);
        self::assertStringNotContainsString('Attendance: ', $html);
    }

    /** @param array<int, array{days:int, worked_minutes:int, late_minutes:int, undertime_minutes:int}>|null $attendanceByPayroll */
    private static function render(string $view, string $status, ?array $attendanceByPayroll = null): string
    {
        $base = '';
        $csrf = 'token';
        $run = [
            'payroll_run_id' => 11, 'payroll_period_id' => 21, 'branch_name' => 'Banga Construction Supplies',
            'period_label' => 'Jul 24 – Jul 30, 2026', 'status' => $status, 'return_reason' => null,
            'cancellation_reason' => null, 'created_at' => '2026-11-21', 'submitted_at' => null, 'reviewed_at' => null,
        ];
        $details = [[
            'payroll_id' => 142, 'employee_id' => 7, 'employee_name' => 'Algan, Ryan', 'employee_number' => '136',
            'daily_rate_snapshot' => 460.22, 'gross_pay' => 3221.54, 'total_deductions' => 1100.10,
            'net_pay' => 2121.44, 'payslip_id' => null,
        ]];
        $earnings = [
            ['payroll_id' => 142, 'earning_type' => 'Basic', 'description' => 'Basic Pay', 'quantity' => 6.0, 'unit_rate' => 460.22, 'multiplier' => 1.0, 'amount' => 2761.32],
            ['payroll_id' => 142, 'earning_type' => 'Basic', 'description' => 'Approved Sick Leave', 'quantity' => 1.0, 'unit_rate' => 460.22, 'multiplier' => 1.0, 'amount' => 460.22],
        ];
        $attendanceByPayroll ??= [142 => ['days' => 6, 'worked_minutes' => 2850, 'late_minutes' => 15, 'undertime_minutes' => 30]];
        $earningSummary = $deductionSummary = $deductions = $readinessIssues = $otherBranches = $errors = [];

        ob_start();
        require dirname(__DIR__, 2) . '/resources/views/' . $view . '.php';

        return (string) ob_get_clean();
    }
}

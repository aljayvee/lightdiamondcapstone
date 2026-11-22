<?php

declare(strict_types=1);

namespace Wbpms\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Payroll run page: note for other branches with attendance in the same period,
 * and the Create Run form pre-selected from its link. [PAY-025]
 */
final class PayrollOtherBranchNoteTest extends TestCase
{
    #[Test]
    public function aBranchWithoutARunIsListedWithACreateLink(): void
    {
        $html = self::renderDetail('Computed', [
            ['branch_id' => 2, 'branch_name' => 'Banga Auto Supplies', 'employees' => 22, 'attendance_days' => 126, 'run_id' => null, 'run_status' => null],
        ]);

        self::assertStringContainsString('Other branches are not in this run.', $html);
        self::assertStringContainsString('22 employee(s) in other branches have attendance', $html);
        self::assertStringContainsString('/hr/payroll/create?period_id=21&amp;branch_id=2', $html);
    }

    #[Test]
    public function aCancelledRunShowsTheNoteWithoutACreateLink(): void
    {
        $html = self::renderDetail('Cancelled', [
            ['branch_id' => 2, 'branch_name' => 'Banga Auto Supplies', 'employees' => 22, 'attendance_days' => 126, 'run_id' => null, 'run_status' => null],
        ]);

        self::assertStringContainsString('Other branches are not in this run.', $html);
        self::assertStringNotContainsString('/hr/payroll/create?', $html);
    }

    #[Test]
    public function aBranchThatHasItsOwnRunIsLinkedNotWarned(): void
    {
        $html = self::renderDetail('Computed', [
            ['branch_id' => 3, 'branch_name' => 'Surallah <b>', 'employees' => 5, 'attendance_days' => 20, 'run_id' => 99, 'run_status' => 'Computed'],
        ]);

        self::assertStringNotContainsString('Other branches are not in this run.', $html);
        self::assertStringContainsString('href="/hr/payroll/99"', $html);
        self::assertStringContainsString('Surallah &lt;b&gt;', $html);
    }

    #[Test]
    public function noOtherBranchesMeansNoNote(): void
    {
        $html = self::renderDetail('Computed', []);

        self::assertStringNotContainsString('other-branch-note', $html);
        self::assertStringNotContainsString('other-branch-runs', $html);
    }

    #[Test]
    public function theCreateRunFormPreSelectsPeriodAndBranch(): void
    {
        $periods = [
            ['payroll_period_id' => 20, 'period_start' => '2026-07-17', 'label' => 'Jul 17 – Jul 23, 2026', 'status' => 'Open'],
            ['payroll_period_id' => 21, 'period_start' => '2026-07-24', 'label' => 'Jul 24 – Jul 30, 2026', 'status' => 'Open'],
        ];
        $branches = [
            ['branch_id' => 1, 'branch_name' => 'Banga Construction Supplies'],
            ['branch_id' => 2, 'branch_name' => 'Banga Auto Supplies'],
        ];

        $html = self::renderRun($periods, $branches, 21, 2);
        self::assertMatchesRegularExpression('/<option value="2026-07" selected>/', $html);
        self::assertMatchesRegularExpression('/<option value="21"\s+data-ym="2026-07" selected>/', $html);
        self::assertMatchesRegularExpression('/<option value="20"\s+data-ym="2026-07">/', $html);
        self::assertStringContainsString('<option value="2" selected>Banga Auto Supplies</option>', $html);

        $plain = self::renderRun($periods, $branches, 0, 0);
        self::assertStringNotContainsString(' selected', $plain);
    }

    /** @param list<array<string,mixed>> $otherBranches */
    private static function renderDetail(string $status, array $otherBranches): string
    {
        $base = '';
        $csrf = 'token';
        $run = [
            'payroll_run_id' => 10, 'payroll_period_id' => 21, 'branch_name' => 'Banga Construction Supplies',
            'period_label' => 'Jul 24 – Jul 30, 2026', 'status' => $status, 'return_reason' => null,
            'cancellation_reason' => null, 'created_at' => '2026-11-21', 'submitted_at' => null, 'reviewed_at' => null,
        ];
        $details = $earningSummary = $earnings = $deductionSummary = $deductions = $readinessIssues = $errors = [];

        ob_start();
        require dirname(__DIR__, 2) . '/resources/views/hr/payroll/detail.php';

        return (string) ob_get_clean();
    }

    /**
     * @param list<array<string,mixed>> $periods
     * @param list<array<string,mixed>> $branches
     */
    private static function renderRun(array $periods, array $branches, int $selectedPeriodId, int $selectedBranchId): string
    {
        $base = '';
        $csrf = 'token';
        $errors = [];

        ob_start();
        require dirname(__DIR__, 2) . '/resources/views/hr/payroll/run.php';

        return (string) ob_get_clean();
    }
}

<?php
/**
 * View: hr/payroll/detail  (GET /hr/payroll/{id})
 * Variables: $run, $details, $earningSummary, $earnings, $deductionSummary, $deductions,
 *            $readinessIssues (days Compute would leave unpaid, owner review C6), $errors,
 *            $attendanceByPayroll (days/hours behind Basic Pay, keyed by payroll_id)
 */
use Wbpms\Http\View\Formatter;

$errors  ??= [];
$readinessIssues ??= [];
$details ??= [];
$earningSummary ??= [];
$earnings ??= [];
$deductionSummary ??= [];
$deductions ??= [];
$attendanceByPayroll ??= [];

$statusColors = [
    'Draft'                => '#6b7280',
    'Computed'             => '#3b82f6',
    'PendingOwnerApproval' => '#f59e0b',
    'Approved'             => '#10b981',
    'Returned'             => '#ef4444',
    'Cancelled'            => '#6b7280',
];
$sc = $statusColors[$run['status']] ?? '#6b7280';

$grossTotal = array_sum(array_column($details, 'gross_pay'));
$dedTotal   = array_sum(array_column($details, 'total_deductions'));
$netTotal   = array_sum(array_column($details, 'net_pay'));
$earningsByPayroll = [];
foreach ($earnings as $earning) {
    $earningsByPayroll[(int) $earning['payroll_id']][] = $earning;
}
$deductionsByPayroll = [];
foreach ($deductions as $deduction) {
    $deductionsByPayroll[(int) $deduction['payroll_id']][] = $deduction;
}
?>
<div class="page-head">
    <div>
        <h1>Payroll Run — <?= htmlspecialchars($run['branch_name']) ?></h1>
        <p><?= htmlspecialchars($run['period_label']) ?></p>
    </div>
    <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;justify-content:flex-end">
        <a href="<?= $base ?>/hr/payroll" class="btn btn-secondary"><?= \Wbpms\Http\View\Icon::svg('chevron-left') ?> Back to Payroll</a>
        <a href="<?= $base ?>/payroll/<?= (int) $run['payroll_run_id'] ?>/timesheet" class="btn btn-secondary" style="font-size:.8rem">Cut-off Timesheet</a>
        <a href="<?= $base ?>/payroll/<?= (int) $run['payroll_run_id'] ?>/disbursement-summary" class="btn btn-secondary" style="font-size:.8rem">Disbursement Summary</a>
        <span style="background:<?= $sc ?>;color:#fff;padding:4px 14px;border-radius:9999px;font-size:.875rem"><?= htmlspecialchars($run['status']) ?></span>
    </div>
</div>

<?php if (!empty($run['return_reason'])): ?>
<div style="background:#fef2f2;border:1px solid #fca5a5;border-radius:6px;padding:.75rem 1rem;margin-bottom:1rem;color:#991b1b">
    <strong>Returned by Owner:</strong> <?= htmlspecialchars((string)$run['return_reason']) ?>
</div>
<?php endif; ?>

<?php if (!empty($run['cancellation_reason'])): ?>
<div style="background:#f9fafb;border:1px solid #d1d5db;border-radius:6px;padding:.75rem 1rem;margin-bottom:1rem;color:#374151">
    <strong>Cancelled by HR:</strong> <?= htmlspecialchars((string)$run['cancellation_reason']) ?>
</div>
<?php endif; ?>

<?php
// Other branches with attendance in this period [PAY-025]. A run pays one branch;
// the same uploaded workbook can hold several branches' attendance.
$otherBranches ??= [];
$otherWithoutRun = array_values(array_filter($otherBranches, static fn (array $o): bool => $o['run_id'] === null));
$otherWithRun    = array_values(array_filter($otherBranches, static fn (array $o): bool => $o['run_id'] !== null));
$missingEmployees = array_sum(array_column($otherWithoutRun, 'employees'));
?>
<?php if ($otherWithoutRun !== []): ?>
<div id="other-branch-note" style="background:#fffbeb;border:1px solid #fcd34d;border-radius:6px;padding:.75rem 1rem;margin-bottom:1rem;color:#92400e">
    <strong>Other branches are not in this run.</strong>
    This run pays <?= Formatter::escape((string) $run['branch_name']) ?> only.
    <?= (int) $missingEmployees ?> employee(s) in other branches have attendance in this period but no payroll run yet:
    <ul style="margin:.4rem 0 0 1.1rem;padding:0">
        <?php foreach ($otherWithoutRun as $o): ?>
        <li style="margin:.2rem 0">
            <strong><?= Formatter::escape($o['branch_name']) ?></strong>:
            <?= (int) $o['employees'] ?> employee(s), <?= (int) $o['attendance_days'] ?> attendance day(s)
            <?php if ($run['status'] !== 'Cancelled'): ?>
            — <a href="<?= $base ?>/hr/payroll/create?period_id=<?= (int) $run['payroll_period_id'] ?>&amp;branch_id=<?= (int) $o['branch_id'] ?>">Create run for this period</a>
            <?php endif; ?>
        </li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>
<?php if ($otherWithRun !== []): ?>
<p id="other-branch-runs" style="margin:0 0 1rem;font-size:.85rem;color:#6b7280">
    Same period, other branches:
    <?php foreach ($otherWithRun as $i => $o): ?><?= $i > 0 ? '; ' : '' ?><a href="<?= $base ?>/hr/payroll/<?= (int) $o['run_id'] ?>"><?= Formatter::escape($o['branch_name']) ?></a> (<?= Formatter::escape((string) $o['run_status']) ?>, <?= (int) $o['employees'] ?> with attendance)<?php endforeach; ?>
</p>
<?php endif; ?>

<!-- Action buttons: at the top so HR does not scroll past every table (REQ056) -->
<div class="run-actions" style="margin-bottom:1.25rem">
<?php $runId = (int)$run['payroll_run_id']; ?>
<?php if (!in_array($run['status'], ['PendingOwnerApproval', 'Approved', 'Cancelled'], true)): ?>
<?php
$issuesByEmployee = [];
foreach ($readinessIssues as $issue) {
    $issuesByEmployee[(int) $issue['employee_id']][] = $issue;
}
?>
<?php if ($issuesByEmployee !== []): ?>
<!-- Pre-compute check (owner logic review C6) -->
<div class="card" id="payroll-check" style="padding:0;overflow:hidden;margin-bottom:1.25rem;border:1px solid #fcd34d">
    <div style="padding:1rem 1.25rem;background:#fffbeb;border-bottom:1px solid #fde68a">
        <h2 style="margin:0;font-size:1.05rem;color:#92400e">
            Check before computing: <?= count($readinessIssues) ?> day(s) would not be paid
        </h2>
        <p style="margin:.35rem 0 0;color:#92400e;font-size:.875rem">
            Payroll pays only complete attendance from approved uploads. Resolve these days in Attendance first,
            or confirm below that they should stay unpaid for this run.
        </p>
    </div>
    <div style="overflow-x:auto">
    <table class="data-table">
        <thead>
            <tr>
                <th>Employee</th>
                <th>Date</th>
                <th>Why It Would Not Be Paid</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($issuesByEmployee as $employeeIssues):
            $firstIssue = $employeeIssues[0];
            $attendanceLink = $base . '/hr/attendance?' . http_build_query([
                'date_from' => (string) $run['period_start'],
                'date_to'   => (string) $run['period_end'],
                'search'    => (string) $firstIssue['employee_number'],
            ]);
            foreach ($employeeIssues as $i => $issue): ?>
            <tr>
                <?php if ($i === 0): ?>
                <td rowspan="<?= count($employeeIssues) ?>" style="vertical-align:top">
                    <strong><?= Formatter::escape((string) $issue['employee_name']) ?></strong><br>
                    <small style="color:#6b7280"><?= Formatter::escape((string) $issue['employee_number']) ?></small><br>
                    <a href="<?= Formatter::escape($attendanceLink) ?>" style="font-size:.8rem">Open attendance <?= \Wbpms\Http\View\Icon::svg('chevron-right') ?></a>
                </td>
                <?php endif; ?>
                <td><?= Formatter::escape(Formatter::date((string) $issue['date'])) ?></td>
                <td><?= Formatter::escape((string) $issue['label']) ?></td>
            </tr>
            <?php endforeach; ?>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
<?php endif; ?>
<div style="display:flex;gap:.75rem;flex-wrap:wrap;align-items:center">
    <!-- Compute -->
    <form method="post" action="<?= $base ?>/hr/payroll/<?= $runId ?>/compute" style="display:flex;gap:.75rem;flex-wrap:wrap;align-items:center">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
        <?php if ($issuesByEmployee !== []): ?>
        <label style="display:flex;gap:.4rem;align-items:center;margin:0;font-size:.875rem;font-weight:500;color:#92400e;text-transform:none;letter-spacing:normal">
            <input type="checkbox" name="confirm_unresolved_days" value="1" required>
            I checked the <?= count($readinessIssues) ?> day(s) above; compute without paying them
        </label>
        <?php endif; ?>
        <button type="submit" class="btn btn-secondary"
                data-confirm="Run payroll computation for this period/branch?">
            <?= \Wbpms\Http\View\Icon::svg('refresh') ?> Compute Payroll
        </button>
    </form>

    <!-- Submit for approval (only when Computed or Returned) -->
    <?php if (in_array($run['status'], ['Computed','Returned'], true)): ?>
    <form method="post" action="<?= $base ?>/hr/payroll/<?= $runId ?>/submit">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
        <button type="submit" class="btn btn-primary"
                data-confirm="Submit this payroll run to the Business Owner for approval?">
            <?= \Wbpms\Http\View\Icon::svg('send') ?> Submit for Approval
        </button>
    </form>
    <?php endif; ?>

    <form method="post" action="<?= $base ?>/hr/payroll/<?= $runId ?>/cancel" style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
        <input type="text" name="cancellation_reason" required maxlength="1000"
               aria-label="Cancellation reason" placeholder="Reason for cancellation">
        <button type="submit" class="btn btn-danger"
                data-confirm="Cancel this payroll run? It cannot be restored, but its history will be retained.">
            Cancel Payroll Run
        </button>
    </form>
</div>
<?php elseif ($run['status'] === 'Approved'): ?>
<p style="color:#10b981;font-weight:600"><?= \Wbpms\Http\View\Icon::svg('check') ?> This payroll run has been approved and is read-only.</p>
<?php elseif ($run['status'] === 'Cancelled'): ?>
<p style="color:#6b7280;font-weight:600"><?= \Wbpms\Http\View\Icon::svg('circle-x') ?> This payroll run is cancelled and read-only. Create a new run when the correction is ready.</p>
<?php else: ?>
<div style="display:flex;gap:.75rem;flex-wrap:wrap;align-items:center">
    <p style="color:#f59e0b;font-weight:600;margin:0"><?= \Wbpms\Http\View\Icon::svg('hourglass') ?> Awaiting Business Owner approval.</p>
    <form method="post" action="<?= $base ?>/hr/payroll/<?= $runId ?>/cancel" style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
        <input type="text" name="cancellation_reason" required maxlength="1000"
               aria-label="Cancellation reason" placeholder="Reason for cancellation">
        <button type="submit" class="btn btn-danger"
                data-confirm="Cancel this pending payroll run? It cannot be restored, but its history will be retained.">
            Cancel Payroll Run
        </button>
    </form>
</div>
<?php endif; ?>
</div>

<!-- Meta strip -->
<div style="display:flex;flex-wrap:wrap;gap:2rem;background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:1rem 1.5rem;margin-bottom:1.25rem">
    <?php
    $meta = [
        'Branch'    => $run['branch_name'],
        'Period'    => $run['period_label'],
        // Run timestamps are stored in UTC (PayrollService::utcNow()).
        'Created'   => \Wbpms\Http\View\Formatter::utcDateTime($run['created_at'] ?? null),
        'Submitted' => \Wbpms\Http\View\Formatter::utcDateTime($run['submitted_at'] ?? null),
        'Reviewed'  => \Wbpms\Http\View\Formatter::utcDateTime($run['reviewed_at'] ?? null),
    ];
    foreach ($meta as $label => $val):
    ?>
    <div>
        <div style="font-size:.7rem;color:#6b7280;text-transform:uppercase"><?= htmlspecialchars($label) ?></div>
        <div style="font-weight:600;margin-top:.15rem"><?= htmlspecialchars((string)$val) ?></div>
    </div>
    <?php endforeach; ?>
</div>

<?php if ($earningSummary !== []): ?>
<!-- Earnings summary -->
<div class="card" style="padding:0;overflow-x:auto;margin-bottom:1.25rem">
    <div style="padding:1rem 1.25rem;border-bottom:1px solid var(--line)">
        <h2 style="margin:0;font-size:1.05rem">Earnings Breakdown</h2>
        <p style="margin:.25rem 0 0;color:#6b7280;font-size:.875rem">Basic pay, approved overtime matched to actual attendance, holiday premiums above Basic Pay, and other earnings included in gross pay.</p>
    </div>
    <table class="data-table">
        <thead><tr><th>Earning Type</th><th style="text-align:right">Employees / Records</th><th style="text-align:right">Amount</th></tr></thead>
        <tbody>
        <?php foreach ($earningSummary as $summary): ?>
            <tr>
                <td><?= htmlspecialchars($summary['earning_type']) ?></td>
                <td style="text-align:right"><?= (int) $summary['record_count'] ?></td>
                <td style="text-align:right;color:#047857;font-weight:600">₱<?= number_format((float) $summary['total_amount'], 2) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr style="background:#f9fafb;font-weight:700"><td colspan="2" style="padding:.6rem .85rem">Total Gross Pay</td><td style="text-align:right;padding:.6rem .85rem;color:#047857">₱<?= number_format($grossTotal, 2) ?></td></tr></tfoot>
    </table>
</div>
<?php endif; ?>

<?php if ($deductionSummary !== []): ?>
<!-- Deduction summary -->
<div class="card" style="padding:0;overflow:hidden;margin-bottom:1.25rem">
    <div style="padding:1rem 1.25rem;border-bottom:1px solid var(--line)">
        <h2 style="margin:0;font-size:1.05rem">Deduction Breakdown</h2>
        <p style="margin:.25rem 0 0;color:#6b7280;font-size:.875rem">Totals posted by the computed payroll run.</p>
    </div>
    <table class="data-table">
        <thead><tr><th>Deduction Type</th><th style="text-align:right">Employees / Records</th><th style="text-align:right">Amount</th></tr></thead>
        <tbody>
        <?php foreach ($deductionSummary as $summary): ?>
            <tr>
                <td><?= htmlspecialchars($summary['deduction_type']) ?></td>
                <td style="text-align:right"><?= (int) $summary['record_count'] ?></td>
                <td style="text-align:right;color:#ef4444;font-weight:600">₱<?= number_format((float) $summary['total_amount'], 2) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr style="background:#f9fafb;font-weight:700"><td colspan="2" style="padding:.6rem .85rem">Total Deductions</td><td style="text-align:right;padding:.6rem .85rem;color:#ef4444">₱<?= number_format($dedTotal, 2) ?></td></tr></tfoot>
    </table>
</div>
<?php endif; ?>

<!-- Employee rows -->
<div class="card" style="padding:0;overflow-x:auto;margin-bottom:1.25rem">
    <table class="data-table">
        <thead>
            <tr>
                <th>Employee</th>
                <th style="text-align:right">Daily Rate</th>
                <th>Earning Details</th>
                <th style="text-align:right">Gross Pay</th>
                <th style="text-align:right">Deductions</th>
                <th>Deduction Details</th>
                <th style="text-align:right;font-weight:700">Net Pay</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php if ($details === []): ?>
            <tr><td colspan="8" style="text-align:center;color:#6b7280;padding:2rem">
                No employees computed yet. Use Compute below.
            </td></tr>
        <?php else: ?>
            <?php foreach ($details as $d): ?>
            <tr>
                <td>
                    <strong><?= htmlspecialchars($d['employee_name']) ?></strong><br>
                    <small style="color:#6b7280"><?= htmlspecialchars($d['employee_number']) ?></small>
                </td>
                <td style="text-align:right">₱<?= number_format((float)$d['daily_rate_snapshot'], 2) ?></td>
                <td>
                    <?php $employeeEarnings = $earningsByPayroll[(int) $d['payroll_id']] ?? []; ?>
                    <?php if ($employeeEarnings === []): ?>
                        <span style="color:#6b7280">—</span>
                    <?php else: ?>
                        <details>
                            <summary style="cursor:pointer;color:#047857">View <?= count($employeeEarnings) ?> item<?= count($employeeEarnings) === 1 ? '' : 's' ?></summary>
                            <ul style="margin:.5rem 0 0;padding-left:1rem;font-size:.82rem;min-width:180px">
                            <?php foreach ($employeeEarnings as $item): ?>
                                <li><?= htmlspecialchars($item['earning_type']) ?> — <strong>₱<?= number_format((float) $item['amount'], 2) ?></strong><br><small style="color:#6b7280"><?= htmlspecialchars($item['description']) ?></small>
                                <?php if ($item['earning_type'] === 'Basic' && (float) $item['quantity'] > 0): ?>
                                    <br><small style="color:#374151"><?= Formatter::escape(Formatter::quantity((float) $item['quantity'])) ?> day<?= (float) $item['quantity'] === 1.0 ? '' : 's' ?> × ₱<?= number_format((float) $item['unit_rate'], 2) ?></small>
                                <?php endif; ?>
                                <?php $basicAttendance = $attendanceByPayroll[(int) $d['payroll_id']] ?? null; ?>
                                <?php if ($item['earning_type'] === 'Basic' && $item['description'] === 'Basic Pay' && $basicAttendance !== null): ?>
                                    <br><small style="color:#374151" title="Complete/Approved attendance in this period, as counted by Compute">Attendance: <?= (int) $basicAttendance['days'] ?> day<?= (int) $basicAttendance['days'] === 1 ? '' : 's' ?> · <?= Formatter::escape(Formatter::hoursMinutes((int) $basicAttendance['worked_minutes'])) ?> worked<?php if ((int) $basicAttendance['late_minutes'] > 0): ?> · late <?= Formatter::escape(Formatter::hoursMinutes((int) $basicAttendance['late_minutes'])) ?><?php endif; ?><?php if ((int) $basicAttendance['undertime_minutes'] > 0): ?> · undertime <?= Formatter::escape(Formatter::hoursMinutes((int) $basicAttendance['undertime_minutes'])) ?><?php endif; ?></small>
                                    <?php if ((int) $basicAttendance['days'] !== (int) round((float) $item['quantity'])): ?>
                                        <br><small class="basic-attendance-differs" style="color:#b45309">Attendance days differ from Basic Pay days. If attendance changed after Compute, compute again.</small>
                                    <?php endif; ?>
                                <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                            </ul>
                        </details>
                    <?php endif; ?>
                </td>
                <td style="text-align:right">₱<?= number_format((float)$d['gross_pay'], 2) ?></td>
                <td style="text-align:right;color:#ef4444">₱<?= number_format((float)$d['total_deductions'], 2) ?></td>
                <td>
                    <?php $employeeDeductions = $deductionsByPayroll[(int) $d['payroll_id']] ?? []; ?>
                    <?php if ($employeeDeductions === []): ?>
                        <span style="color:#6b7280">—</span>
                    <?php else: ?>
                        <details>
                            <summary style="cursor:pointer;color:#1d4ed8">View <?= count($employeeDeductions) ?> item<?= count($employeeDeductions) === 1 ? '' : 's' ?></summary>
                            <ul style="margin:.5rem 0 0;padding-left:1rem;font-size:.82rem;min-width:180px">
                            <?php foreach ($employeeDeductions as $item): ?>
                                <li><?= htmlspecialchars($item['deduction_type']) ?> — <strong>₱<?= number_format((float) $item['amount'], 2) ?></strong><br><small style="color:#6b7280"><?= htmlspecialchars($item['description']) ?></small></li>
                            <?php endforeach; ?>
                            </ul>
                        </details>
                    <?php endif; ?>
                </td>
                <td style="text-align:right;font-weight:700">₱<?= number_format((float)$d['net_pay'], 2) ?></td>
                <td style="text-align:right">
                    <?php if (in_array($run['status'], ['Computed', 'Returned'], true)): ?>
                    <a href="<?= $base ?>/hr/payroll/<?= (int) $run['payroll_run_id'] ?>/employees/<?= (int) $d['payroll_id'] ?>/adjust"
                       class="btn btn-secondary" style="padding:.3rem .65rem;font-size:.8rem">Adjust</a>
                    <?php elseif ($run['status'] === 'Approved' && !empty($d['payslip_id'])): ?>
                    <div style="display:flex;flex-direction:column;gap:.3rem;align-items:flex-end">
                        <a href="<?= $base ?>/hr/payroll/<?= (int) $run['payroll_run_id'] ?>/payslips/<?= (int) $d['payslip_id'] ?>/print?view=1"
                           class="btn btn-primary" style="padding:.3rem .65rem;font-size:.8rem"><?= \Wbpms\Http\View\Icon::svg('file-text') ?> View</a>
                        <a href="<?= $base ?>/hr/payroll/<?= (int) $run['payroll_run_id'] ?>/payslips/<?= (int) $d['payslip_id'] ?>/print"
                           target="_blank" rel="noopener"
                           class="btn btn-secondary" style="padding:.3rem .65rem;font-size:.8rem"><?= \Wbpms\Http\View\Icon::svg('printer') ?> Print</a>
                        <a href="<?= $base ?>/hr/payroll/<?= (int) $run['payroll_run_id'] ?>/payslips/<?= (int) $d['payslip_id'] ?>/download"
                           class="btn btn-secondary" style="padding:.3rem .65rem;font-size:.8rem"><?= \Wbpms\Http\View\Icon::svg('file-down') ?> PDF</a>
                    </div>
                    <?php else: ?>
                    <span style="color:#6b7280">—</span>
                    <?php endif; ?>
                </td>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
        <?php if ($details !== []): ?>
        <tfoot>
            <tr style="background:#f9fafb;font-weight:700">
                <td style="padding:.6rem .85rem" colspan="2">Totals</td>
                <td></td>
                <td style="text-align:right;padding:.6rem .85rem">₱<?= number_format($grossTotal, 2) ?></td>
                <td style="text-align:right;padding:.6rem .85rem;color:#ef4444">₱<?= number_format($dedTotal, 2) ?></td>
                <td></td>
                <td style="text-align:right;padding:.6rem .85rem">₱<?= number_format($netTotal, 2) ?></td>
                <td></td>
            </tr>
        </tfoot>
        <?php endif; ?>
    </table>
</div>

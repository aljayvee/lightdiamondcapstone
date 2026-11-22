<?php

declare(strict_types=1);

use Wbpms\Http\View\Formatter;

/**
 * View: hr/reports/index  (GET /hr/reports)
 * Variables: $totalEmployees, $approvedPayroll, $approvedGrossPay, $approvedTotalDeductions, $approvedNetPay, $totalRequests, $payrollSummary
 */
$approvedGrossPay        ??= 0.0;
$approvedTotalDeductions ??= 0.0;
?>
<div class="page-head">
    <div>
        <h1>Reports Management</h1>
        <p>Payroll, attendance, request, and contribution reports.</p>
    </div>
    <a href="<?= $base ?>/hr/reports/export?type=payroll" class="btn btn-secondary">Export / Print</a>
</div>

<!-- Summary -->
<div class="stats-grid" style="display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:1rem;margin-bottom:1.5rem">
    <div class="stat-card"><span class="stat-value"><?= (int)$totalEmployees ?></span><span class="stat-label">Active Employees</span></div>
    <div class="stat-card"><span class="stat-value"><?= (int)$approvedPayroll ?></span><span class="stat-label">Approved Payroll Runs</span></div>
    <div class="stat-card"><span class="stat-value">₱<?= number_format((float)$approvedGrossPay, 2) ?></span><span class="stat-label">Total Gross Approved</span></div>
    <div class="stat-card"><span class="stat-value" style="color:#dc2626">₱<?= number_format((float)$approvedTotalDeductions, 2) ?></span><span class="stat-label">Total Deductions Approved</span></div>
    <div class="stat-card"><span class="stat-value" style="color:#15803d">₱<?= number_format((float)$approvedNetPay, 2) ?></span><span class="stat-label">Total Net Pay Approved</span></div>
</div>

<!-- Quick reports -->
<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:1rem;margin-bottom:1.5rem">
    <?php
    $reportTypes = [
        ['label'=>'Payroll Summary',      'type'=>'payroll',       'icon'=>'banknote'],
        ['label'=>'Attendance Report',    'type'=>'attendance',    'icon'=>'clock'],
        ['label'=>'Attendance Frequency', 'type'=>'frequency',     'icon'=>'activity'],
        ['label'=>'Leave/Request Report', 'type'=>'requests',      'icon'=>'inbox'],
        ['label'=>'Contributions Report', 'type'=>'contributions', 'icon'=>'shield-plus'],
        ['label'=>'13th Month Pay',       'type'=>'13th_month',    'icon'=>'gift'],
        ['label'=>'Transaction Slips',    'type'=>'transaction_slips', 'icon'=>'landmark'],
        ['label'=>'Employee List',        'type'=>'employees',     'icon'=>'users'],
        ['label'=>'Employee Payslip Report', 'type'=>'payslips',   'icon'=>'receipt'],
    ];
    foreach ($reportTypes as $rt): ?>
    <a href="<?= $rt['type'] === '13th_month'
        ? $base . '/hr/reports/13th-month'
        : ($rt['type'] === 'frequency'
            ? $base . '/hr/attendance/frequency-report'
            : ($rt['type'] === 'transaction_slips'
                ? $base . '/hr/reports/transaction-slips'
                : $base . '/hr/reports/' . htmlspecialchars($rt['type']))) ?>"
       class="card" style="text-decoration:none;display:flex;align-items:center;gap:.75rem;padding:1rem">
        <span class="icon-tile"><?= \Wbpms\Http\View\Icon::svg($rt['icon'], 20) ?></span>
        <span style="font-weight:500"><?= htmlspecialchars($rt['label']) ?></span>
    </a>
    <?php endforeach; ?>
    <!-- Audit Trail — accessible to HRHead and BusinessOwner -->
    <a href="<?= $base ?>/hr/audit"
       class="card" style="text-decoration:none;display:flex;align-items:center;gap:.75rem;padding:1rem">
        <span class="icon-tile"><?= \Wbpms\Http\View\Icon::svg('search', 20) ?></span>
        <span style="font-weight:500">Audit Trail</span>
    </a>
</div>

<!-- Approved payroll summary -->
<div class="card">
    <h3 style="margin:0 0 1rem">Approved Payroll Runs</h3>
    <?php if ($payrollSummary === []): ?>
    <p style="color:#6b7280">No approved payroll runs yet.</p>
    <?php else: ?>
    <table class="data-table">
        <thead>
            <tr>
                <th>Branch</th>
                <th>Period</th>
                <th style="text-align:center">Employees</th>
                <th style="text-align:right">Gross</th>
                <th style="text-align:right">Deductions</th>
                <th style="text-align:right">Net</th>
                <th>Approved At</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($payrollSummary as $r): ?>
        <tr>
            <td><?= Formatter::escape((string) $r['branch_name']) ?></td>
            <td><?= Formatter::date((string) $r['period_start']) . ' – ' . Formatter::date((string) $r['period_end']) ?></td>
            <td style="text-align:center"><?= (int)$r['emp_count'] ?></td>
            <td style="text-align:right">₱<?= number_format((float)($r['gross'] ?? 0), 2) ?></td>
            <td style="text-align:right;color:#dc2626">₱<?= number_format((float)($r['deductions'] ?? 0), 2) ?></td>
            <td style="text-align:right;font-weight:600">₱<?= number_format((float)($r['net'] ?? 0), 2) ?></td>
            <td style="font-size:.8rem"><?= Formatter::date($r['approved_at']) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<?php


use Wbpms\Http\View\Formatter;

/**
 * HR Head dashboard.
 *
 * @var int    $totalEmployees        Total active employees
 * @var int    $pendingRequests       Requests awaiting HR action
 * @var int    $incompleteAttendance  Employees with incomplete attendance this period
 * @var int    $payrollDrafts         Payroll runs in Draft/Computed state
 * @var list<array{id:int,employee_name:string,type:string,submitted_at:string,status:string}> $recentRequests
 * @var list<array{id:int,branch_name:string,period:string,status:string,employee_count:int}>  $payrollRuns
 */

$totalEmployees       ??= 0;
$pendingRequests      ??= 0;
$incompleteAttendance ??= 0;
$payrollDrafts        ??= 0;
$payrollReturned      ??= 0;
$recentRequests       ??= [];
$payrollRuns          ??= [];
$incompleteAlerts     ??= [];

$statusBadge = static function (string $status): string {
    $map = [
        'Draft'                 => 'badge-gray',
        'Computed'              => 'badge-blue',
        'PendingOwnerApproval'  => 'badge-yellow',
        'Approved'              => 'badge-green',
        'Returned'              => 'badge-red',
        'Pending'               => 'badge-yellow',
        'HRApproved'            => 'badge-blue',
        'Cancelled'             => 'badge-gray',
        'Approved_req'          => 'badge-green',
        'Rejected'              => 'badge-red',
    ];
    $label = match ($status) {
        'PendingOwnerApproval' => 'Pending Approval',
        'HRApproved'           => 'Waiting for Owner',
        'Approved_req'         => 'Approved',
        default                => Formatter::label($status),
    };
    $cls = $map[$status] ?? 'badge-gray';
    return '<span class="badge ' . $cls . '">' . htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>';
};
?>

<div class="page-head">
    <div>
        <h1>HR Dashboard</h1>
        <p>Overview of employees, requests, attendance and payroll as of <?= Formatter::escape(Formatter::date(date('Y-m-d'))) ?>.</p>
    </div>
</div>

<!-- Stat cards -->
<div class="stat-grid">
    <div class="stat-card">
        <div class="stat-label">Active Employees</div>
        <div class="stat-value"><?= $totalEmployees ?></div>
        <div class="stat-sub"><a href="<?= $base ?>/hr/employees">View all <?= \Wbpms\Http\View\Icon::svg('chevron-right') ?></a></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Requests to Review</div>
        <div class="stat-value" style="<?= $pendingRequests > 0 ? 'color:#b45309' : '' ?>"><?= $pendingRequests ?></div>
        <div class="stat-sub"><a href="<?= $base ?>/hr/requests">Review <?= \Wbpms\Http\View\Icon::svg('chevron-right') ?></a></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Incomplete Attendance</div>
        <div class="stat-value" style="<?= $incompleteAttendance > 0 ? 'color:#dc2626' : '' ?>"><?= $incompleteAttendance ?></div>
        <div class="stat-sub"><a href="<?= $base ?>/hr/attendance">Review <?= \Wbpms\Http\View\Icon::svg('chevron-right') ?></a></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Payroll Runs to Finish</div>
        <div class="stat-value"><?= $payrollDrafts ?></div>
        <div class="stat-sub">
            <?php if ($payrollReturned > 0): ?><span style="color:#b91c1c"><?= $payrollReturned ?> returned by Owner</span> · <?php endif; ?>
            <a href="<?= $base ?>/hr/payroll">View <?= \Wbpms\Http\View\Icon::svg('chevron-right') ?></a>
        </div>
    </div>
</div>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(340px,1fr));gap:1.5rem">

    <!-- Recent Requests -->
    <div class="card" style="padding:0;overflow:hidden;">
        <div style="padding:.85rem 1.25rem;border-bottom:1px solid #e5e7eb;display:flex;justify-content:space-between;align-items:center">
            <strong style="font-size:.95rem">Recent Requests</strong>
            <a href="<?= $base ?>/hr/requests" class="btn btn-secondary btn-sm">All requests</a>
        </div>
        <?php if (empty($recentRequests)): ?>
        <p style="padding:1.25rem;color:#6b7280;font-size:.875rem;margin:0">No requests yet.</p>
        <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Employee</th>
                    <th>Type</th>
                    <th>Submitted</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($recentRequests as $req): ?>
            <tr>
                <td><?= Formatter::escape($req['employee_name']) ?></td>
                <td><?= Formatter::escape(Formatter::label($req['type'])) ?></td>
                <td><?= Formatter::date($req['submitted_at']) ?></td>
                <td><?= $statusBadge($req['status'] === 'Approved' ? 'Approved_req' : $req['status']) ?></td>
                <td><a href="<?= $base ?>/hr/requests/<?= (int) $req['id'] ?>" class="btn btn-secondary btn-sm">View</a></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

    <!-- Payroll Runs -->
    <div class="card" style="padding:0;overflow:hidden;">
        <div style="padding:.85rem 1.25rem;border-bottom:1px solid #e5e7eb;display:flex;justify-content:space-between;align-items:center">
            <strong style="font-size:.95rem">Payroll Runs</strong>
            <a href="<?= $base ?>/hr/payroll/create" class="btn btn-primary btn-sm">New run</a>
        </div>
        <?php if (empty($payrollRuns)): ?>
        <p style="padding:1.25rem;color:#6b7280;font-size:.875rem;margin:0">No payroll runs yet.</p>
        <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Branch</th>
                    <th>Period</th>
                    <th>Employees</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($payrollRuns as $run): ?>
            <tr>
                <td><?= Formatter::escape($run['branch_name']) ?></td>
                <td><?= Formatter::escape($run['period']) ?></td>
                <td style="text-align:center"><?= (int) $run['employee_count'] ?></td>
                <td><?= $statusBadge($run['status']) ?></td>
                <td><a href="<?= $base ?>/hr/payroll/<?= (int) $run['id'] ?>" class="btn btn-secondary btn-sm">View</a></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

</div>

<!-- Quick links -->
<div class="card" style="margin-top:1.5rem">
    <strong style="font-size:.9rem;display:block;margin-bottom:.75rem;color:#374151">Quick Actions</strong>
    <div style="display:flex;flex-wrap:wrap;gap:.65rem">
        <a href="<?= $base ?>/hr/employees/new"    class="btn btn-secondary">Add Employee</a>
        <a href="<?= $base ?>/hr/attendance/import" class="btn btn-secondary">Import Attendance</a>
        <a href="<?= $base ?>/hr/schedules"         class="btn btn-secondary">Manage Schedules</a>
        <a href="<?= $base ?>/hr/payroll/create"       class="btn btn-primary">Generate Payroll</a>
    </div>
</div>

<?php if (!empty($incompleteAlerts)): ?>
<!-- Attendance Alert Widget (Task 12.5) -->
<div class="card" style="margin-top:1.5rem;padding:0;overflow:hidden;border-left:4px solid #dc2626">
    <div style="padding:.85rem 1.25rem;border-bottom:1px solid #e5e7eb;display:flex;justify-content:space-between;align-items:center">
        <strong style="font-size:.95rem;color:#dc2626"><?= \Wbpms\Http\View\Icon::svg('alert') ?> Attendance Review Alerts</strong>
        <a href="<?= $base ?>/hr/attendance" class="btn btn-secondary btn-sm">View All</a>
    </div>
    <table class="data-table">
        <thead>
            <tr>
                <th>Employee</th>
                <th>Date</th>
                <th>Time In</th>
                <th>Time Out</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($incompleteAlerts as $alert): ?>
        <tr>
            <td>
                <strong><?= Formatter::escape((string) $alert['employee_name']) ?></strong><br>
                <small style="color:#6b7280"><?= Formatter::escape((string) $alert['employee_number']) ?></small>
            </td>
            <td style="white-space:nowrap"><?= Formatter::date($alert['attendance_date']) ?></td>
            <td style="white-space:nowrap"><?= Formatter::escape((string) ($alert['time_in'] ?? '—')) ?></td>
            <td style="white-space:nowrap;color:#dc2626"><?= $alert['time_out'] ? Formatter::escape((string) $alert['time_out']) : '<em>missing</em>' ?></td>
            <td><span class="badge badge-red"><?= Formatter::escape((string) $alert['status']) ?></span></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p style="padding:.6rem 1.25rem;margin:0;color:#6b7280;font-size:.8rem">Showing up to 20 records with missing punches or multiple-punch review requirements. Adjust attendance records to resolve.</p>
</div>
<?php endif; ?>

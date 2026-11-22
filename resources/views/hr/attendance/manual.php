<?php

declare(strict_types=1);

/** @var array<string,mixed> $context */
/** @var list<string> $errors */
/** @var array<string,string> $old */

use Wbpms\Http\View\Formatter;

$value = static function (string $key, string $fallback = '') use ($old): string {
    return (string) ($old[$key] ?? $fallback);
};
$timeValue = static fn (?string $time): string => $time === null ? '' : substr($time, 0, 5);
$defaultTimeIn = $timeValue($context['existing_time_in'] ?? $context['work_start_time'] ?? '08:00:00');
$defaultTimeOut = $timeValue($context['existing_time_out'] ?? $context['work_end_time'] ?? '17:00:00');
?>

<div class="page-header">
    <div>
        <h1>Record Manual Attendance</h1>
        <p>Audited manual entry or absence override for verified field work, guard logbook entry, or device downtime.</p>
    </div>
    <a href="<?= $base ?>/hr/attendance" class="btn btn-secondary"><?= \Wbpms\Http\View\Icon::svg('chevron-left') ?> Back to Attendance</a>
</div>

<?php if ($errors !== []): ?>
<div class="alert alert-error">
    <?php foreach ($errors as $error): ?>
        <div><?= Formatter::escape($error) ?></div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="card" style="margin-bottom:1rem">
    <div style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:1rem">
        <div>
            <small>Employee</small><br>
            <strong><?= Formatter::escape((string) $context['employee_name']) ?></strong><br>
            <small><?= Formatter::escape((string) $context['employee_number']) ?></small>
        </div>
        <div>
            <small>Date</small><br>
            <strong><?= Formatter::date((string) $context['attendance_date']) ?></strong><br>
            <small>Schedule: <?= Formatter::escape((string) $context['schedule_name']) ?></small>
        </div>
        <div>
            <small>Scheduled Shift</small><br>
            <strong><?= Formatter::escape($timeValue((string) ($context['work_start_time'] ?? '')) ?: '—') ?> – <?= Formatter::escape($timeValue((string) ($context['work_end_time'] ?? '')) ?: '—') ?></strong><br>
            <small>Current status: <?= Formatter::escape((string) ($context['existing_status'] ?? 'ABSENT / No Record')) ?></small>
        </div>
    </div>
</div>

<div class="card" style="max-width:760px">
    <form method="POST" action="<?= $base ?>/hr/attendance/manual">
        <input type="hidden" name="_csrf" value="<?= Formatter::escape($csrf) ?>">
        <input type="hidden" name="employee_id" value="<?= (int) $context['employee_id'] ?>">
        <input type="hidden" name="attendance_date" value="<?= Formatter::escape((string) $context['attendance_date']) ?>">

        <p style="margin-top:0;color:#6b7280;font-size:.9rem">
            Enter both 24-hour times to record verified attendance. Worked minutes, late minutes, and undertime are calculated from the employee's assigned schedule, and an immutable audit entry is recorded in <code>attendance_adjustment</code> and <code>audit_logs</code>.
        </p>

        <div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:1rem">
            <div>
                <label for="time_in">Time In <span style="color:var(--bad)">*</span></label>
                <input id="time_in" name="time_in" type="text" pattern="(?:[01][0-9]|2[0-3]):[0-5][0-9]" required value="<?= Formatter::escape($value('time_in', $defaultTimeIn)) ?>" placeholder="HH:MM">
            </div>
            <div>
                <label for="time_out">Time Out <span style="color:var(--bad)">*</span></label>
                <input id="time_out" name="time_out" type="text" pattern="(?:[01][0-9]|2[0-3]):[0-5][0-9]" required value="<?= Formatter::escape($value('time_out', $defaultTimeOut)) ?>" placeholder="HH:MM">
            </div>
            <p class="muted" style="margin:.5rem 0 0;font-size:.8rem">Use 24-hour time (HH:MM), for example 08:00 and 17:00.</p>
        </div>
        <div class="minutes-preview" data-minutes-preview
             data-url="<?= $base ?>/hr/attendance/preview-minutes?employee_id=<?= (int) $context['employee_id'] ?>&amp;date=<?= Formatter::escape((string) $context['attendance_date']) ?>" aria-live="polite">
            <span class="minutes-preview-title">Will be recorded</span>
            <span><b data-preview="worked">—</b> worked</span>
            <span><b data-preview="late">—</b> late</span>
            <span><b data-preview="undertime">—</b> undertime</span>
            <span class="minutes-preview-note" data-preview="note">Updates as you type the times.</span>
        </div>

        <div style="margin-top:1rem">
            <label for="reason">Reason and Supporting Evidence <span style="color:var(--bad)">*</span></label>
            <textarea id="reason" name="reason" required maxlength="1000" rows="4" placeholder="e.g. Guard logbook page #14 signed by supervisor, or off-site delivery assignment."><?= Formatter::escape($value('reason')) ?></textarea>
        </div>

        <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:6px;padding:.75rem 1rem;margin:1rem 0;color:#92400e;font-size:.875rem">
            Manual overrides are blocked once the payroll run for this branch and period is approved. If an unapproved payroll run exists, recompute it after saving.
        </div>

        <button type="submit" class="btn btn-primary">Save Audited Manual Attendance</button>
    </form>
</div>

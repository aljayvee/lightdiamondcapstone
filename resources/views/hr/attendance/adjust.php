<?php

declare(strict_types=1);

/** @var array<string,mixed> $attendance */
/** @var list<array<string,mixed>> $history */
/** @var list<array<string,mixed>> $rawPunches */
/** @var ?string $reviewNote */
/** @var list<string> $errors */
/** @var array<string,string> $old */

use Wbpms\Http\View\Formatter;

$history    ??= [];
$rawPunches ??= [];
$reviewNote ??= null;

$value = static function (string $key, string $fallback = '') use ($old): string {
    return (string) ($old[$key] ?? $fallback);
};
$timeValue = static fn (?string $time): string => $time === null ? '' : substr($time, 0, 5);
$isReviewRequired = (string) ($attendance['status'] ?? '') === 'ReviewRequired';
?>

<div class="page-header">
    <div>
        <h1>Adjust Attendance</h1>
        <p>Correct a biometric exception. The original punches remain unchanged.</p>
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
        <div><small>Employee</small><br><strong><?= Formatter::escape((string) $attendance['employee_name']) ?></strong><br><small><?= Formatter::escape((string) $attendance['employee_number']) ?></small></div>
        <div><small>Date / Schedule</small><br><strong><?= Formatter::date((string) $attendance['attendance_date']) ?></strong><br><small><?= Formatter::escape((string) $attendance['schedule_name']) ?></small></div>
        <div><small>Current status</small><br><strong><?= Formatter::escape((string) $attendance['status']) ?></strong><br><small>Worked <?= (int) $attendance['hours_worked_minutes'] ?> min · OT <?= (int) $attendance['overtime_minutes'] ?> min</small></div>
    </div>
</div>

<?php if ($rawPunches !== []):
    $firstPunch = $rawPunches[0];
    $deviceName = trim((string) ($firstPunch['device_name'] ?? ''));
    $srcRow     = isset($firstPunch['source_workbook_row']) && $firstPunch['source_workbook_row'] !== null ? (int) $firstPunch['source_workbook_row'] : null;
    $srcCol     = trim((string) ($firstPunch['source_date_column'] ?? ''));
    $sourceMeta = [];
    if ($deviceName !== '') {
        $sourceMeta[] = $deviceName;
    }
    if ($srcRow !== null && $srcCol !== '') {
        $sourceMeta[] = "Workbook row {$srcRow}, col {$srcCol}";
    }
?>
<div class="card" style="margin-bottom:1rem;max-width:760px">
    <div style="display:flex;justify-content:space-between;align-items:baseline;gap:.75rem;flex-wrap:wrap;margin-bottom:.5rem">
        <strong style="font-size:.9rem">Raw Biometric Punches (<?= count($rawPunches) ?>)</strong>
        <?php if ($sourceMeta !== []): ?>
            <small style="color:#6b7280"><?= Formatter::escape(implode(' · ', $sourceMeta)) ?></small>
        <?php endif; ?>
    </div>
    <div style="display:flex;flex-wrap:wrap;gap:.45rem;margin-bottom:.5rem">
        <?php foreach ($rawPunches as $rp):
            $localAt = (string) ($rp['source_local_at'] ?? '');
            $hm = strlen($localAt) >= 16 ? substr($localAt, 11, 5) : $localAt;
        ?>
            <span style="display:inline-block;padding:.25rem .6rem;border-radius:9999px;background:#f1f5f9;border:1px solid #cbd5e1;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:.85rem;font-weight:600;color:#0f172a"><?= Formatter::escape($hm) ?></span>
        <?php endforeach; ?>
    </div>
    <?php if ($reviewNote !== null): ?>
        <div style="margin-top:.5rem;padding:.65rem .85rem;border-radius:6px;font-size:.85rem;<?= $isReviewRequired ? 'background:#fff1f2;border:1px solid #fecdd3;color:#9f1239' : 'background:#fffbeb;border:1px solid #fde68a;color:#92400e' ?>">
            <?= Formatter::escape($reviewNote) ?>
        </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="card" style="max-width:760px">
    <form method="POST" action="<?= $base ?>/hr/attendance/<?= (int) $attendance['attendance_id'] ?>/adjust">
        <input type="hidden" name="_csrf" value="<?= Formatter::escape($csrf) ?>">

        <?php if ($isReviewRequired): ?>
        <p style="margin-top:0;color:#6b7280;font-size:.9rem">This day requires HR review before it can be included in payroll. You may confirm the pre-filled times as-is with a reason to mark it Complete, or enter corrected times. Overtime is recorded only when an approved overtime request overlaps the actual overtime window.</p>
        <?php else: ?>
        <p style="margin-top:0;color:#6b7280;font-size:.9rem">Enter both times to resolve an incomplete record. Worked, late, and undertime are recalculated from the assigned schedule. Overtime is recorded only when an approved overtime request overlaps the actual overtime window.</p>
        <?php endif; ?>
        <div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:1rem">
            <div>
                <label for="time_in">Time In</label>
                <input id="time_in" name="time_in" type="text" pattern="(?:[01][0-9]|2[0-3]):[0-5][0-9]" required value="<?= Formatter::escape($value('time_in', $timeValue($attendance['time_in']))) ?>" placeholder="HH:MM">
            </div>
            <div>
                <label for="time_out">Time Out</label>
                <input id="time_out" name="time_out" type="text" pattern="(?:[01][0-9]|2[0-3]):[0-5][0-9]" required value="<?= Formatter::escape($value('time_out', $timeValue($attendance['time_out']))) ?>" placeholder="HH:MM">
            </div>
            <p class="muted" style="margin:.5rem 0 0;font-size:.8rem">Use 24-hour time (HH:MM), for example 13:30.</p>
        </div>
        <div class="minutes-preview" data-minutes-preview
             data-url="<?= $base ?>/hr/attendance/preview-minutes?attendance_id=<?= (int) $attendance['attendance_id'] ?>" aria-live="polite">
            <span class="minutes-preview-title">Will be recorded</span>
            <span><b data-preview="worked">—</b> worked</span>
            <span><b data-preview="late">—</b> late</span>
            <span><b data-preview="undertime">—</b> undertime</span>
            <span class="minutes-preview-note" data-preview="note">Updates as you type the times.</span>
        </div>

        <div style="margin-top:1rem">
            <label for="reason">Reason and Supporting Evidence</label>
            <textarea id="reason" name="reason" required maxlength="1000" rows="4" placeholder="e.g. Gate log reference or missed biometric punch."><?= Formatter::escape($value('reason')) ?></textarea>
        </div>

        <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:6px;padding:.75rem 1rem;margin:1rem 0;color:#92400e;font-size:.875rem">
            Changes are blocked if the related payroll is approved. If a payroll is only computed or pending, recompute it after saving this correction.
        </div>
        <button type="submit" class="btn btn-primary"><?= $isReviewRequired ? 'Confirm & save audited adjustment' : 'Save audited adjustment' ?></button>
    </form>
</div>

<?php if ($history !== []): ?>
<div class="card" style="margin-top:1.25rem;max-width:960px">
    <h3 style="margin:0 0 .75rem;font-size:15px">Audited Adjustment History</h3>
    <div class="table-wrap">
        <table style="width:100%;font-size:12px;border-collapse:collapse">
            <thead>
                <tr>
                    <th style="text-align:left;padding:8px;border-bottom:1px solid var(--line)">Adjusted At</th>
                    <th style="text-align:left;padding:8px;border-bottom:1px solid var(--line)">By</th>
                    <th style="text-align:left;padding:8px;border-bottom:1px solid var(--line)">Type</th>
                    <th style="text-align:left;padding:8px;border-bottom:1px solid var(--line)">Before → After</th>
                    <th style="text-align:left;padding:8px;border-bottom:1px solid var(--line)">Reason / Evidence</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($history as $adj): ?>
                <tr>
                    <td style="padding:8px;border-bottom:1px solid var(--line);white-space:nowrap"><?= Formatter::dateTime((string) ($adj['adjustment_at'] ?? '')) ?></td>
                    <td style="padding:8px;border-bottom:1px solid var(--line)"><?= Formatter::escape((string) ($adj['adjusted_by_name'] ?? 'HR')) ?></td>
                    <td style="padding:8px;border-bottom:1px solid var(--line)"><code><?= Formatter::escape((string) ($adj['adjustment_type'] ?? '')) ?></code></td>
                    <td style="padding:8px;border-bottom:1px solid var(--line);white-space:nowrap">
                        <?= Formatter::escape((string) ($adj['old_time_in'] ?? '—')) ?>–<?= Formatter::escape((string) ($adj['old_time_out'] ?? '—')) ?>
                        →
                        <strong><?= Formatter::escape((string) ($adj['new_time_in'] ?? '—')) ?>–<?= Formatter::escape((string) ($adj['new_time_out'] ?? '—')) ?></strong>
                    </td>
                    <td style="padding:8px;border-bottom:1px solid var(--line)"><?= Formatter::escape((string) ($adj['reason'] ?? '')) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

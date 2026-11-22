<?php
use Wbpms\Http\View\Formatter;
/**
 * View: hr/salary/form
 * Variables: $salary (array|null), $employees, $history (array|null),
 *            $errors, $formTitle, $formAction, $old (array|null), $minimumDailyWage (float|null)
 */
$old              = $old ?? ($salary ?? []);
$history          = $history ?? [];
$isEdit           = $salary !== null;
$minimumDailyWage = (float) ($minimumDailyWage ?? \Wbpms\Domain\Validation\DailyRateRule::FALLBACK_MINIMUM);
$activeStarts     = $activeStarts ?? [];
$today            = date('Y-m-d');
// A new rate must start after the current one (REQ062/063). On Edit the
// field used to be pre-filled with the current record's own start date,
// which is always refused.
$currentStart = $isEdit
    ? (string) ($activeStarts[(int) $salary['employee_id']] ?? $salary['effective_from'])
    : ($activeStarts[(int) ($old['employee_id'] ?? 0)] ?? null);
$earliest     = \Wbpms\Domain\Employee\EffectiveDateRule::earliestAfter($currentStart);
$dateValue    = isset($_POST['effective_from'])
    ? (string) $_POST['effective_from']
    : \Wbpms\Domain\Employee\EffectiveDateRule::defaultDate($currentStart, $today);
?>
<div class="page-head">
    <div>
        <h1><?= htmlspecialchars($formTitle) ?></h1>
    </div>
    <a href="<?= $base ?>/hr/salary" class="btn btn-secondary"><?= \Wbpms\Http\View\Icon::svg('chevron-left') ?> Back to Salary Management</a>
</div>

<?php if ($errors !== []): ?>
<div class="alert alert-error" style="margin-bottom:1rem;padding:.75rem 1rem;background:#fef2f2;border:1px solid #fca5a5;border-radius:6px;color:#991b1b">
    <?php foreach ($errors as $e): ?><p style="margin:0"><?= htmlspecialchars($e) ?></p><?php endforeach; ?>
</div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:<?= $isEdit ? '1fr 1fr' : '1fr' ?>;gap:1.5rem">

    <div class="card">
        <form method="post" action="<?= $base . htmlspecialchars($formAction) ?>">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">

            <?php if (!$isEdit): ?>
            <div class="form-group" style="margin-bottom:1rem">
                <label for="employee_id" style="display:block;font-weight:500;margin-bottom:.25rem">
                    Employee <span style="color:#ef4444">*</span>
                </label>
                <select id="employee_id" name="employee_id" class="form-control" required>
                    <option value="">— Select Employee —</option>
                    <?php foreach ($employees as $e): ?>
                    <?php $empStart = $activeStarts[(int) $e['employee_id']] ?? null; ?>
                    <option value="<?= (int)$e['employee_id'] ?>"
                        <?php if ($empStart !== null): ?>data-current="<?= htmlspecialchars($empStart) ?>" data-current-label="<?= htmlspecialchars(Formatter::date($empStart)) ?>" data-earliest="<?= htmlspecialchars((string) \Wbpms\Domain\Employee\EffectiveDateRule::earliestAfter($empStart)) ?>" data-earliest-label="<?= htmlspecialchars(Formatter::date((string) \Wbpms\Domain\Employee\EffectiveDateRule::earliestAfter($empStart))) ?>"<?php endif; ?>
                        <?= (string)($old['employee_id'] ?? '') === (string)$e['employee_id'] ? ' selected' : '' ?>>
                        <?= htmlspecialchars($e['employee_name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php else: ?>
            <div class="form-group" style="margin-bottom:1rem">
                <label style="display:block;font-weight:500;margin-bottom:.25rem;color:#6b7280">Employee</label>
                <p style="padding:.5rem 0;font-weight:600"><?= htmlspecialchars($salary['employee_name']) ?></p>
            </div>
            <?php endif; ?>

            <div class="form-group" style="margin-bottom:1rem">
                <label for="daily_rate" style="display:block;font-weight:500;margin-bottom:.25rem">
                    Daily Rate (₱) <span style="color:#ef4444">*</span>
                </label>
                <input type="number" id="daily_rate" name="daily_rate" class="form-control"
                       step="0.01" min="<?= htmlspecialchars(number_format($minimumDailyWage, 2, '.', '')) ?>" max="<?= htmlspecialchars(number_format(\Wbpms\Domain\Validation\DailyRateRule::MAX, 2, '.', '')) ?>" required
                       data-msg-max="<?= htmlspecialchars(\Wbpms\Domain\Validation\DailyRateRule::maxMessage()) ?>"
                       value="<?= htmlspecialchars((string)($old['daily_rate'] ?? '')) ?>">
                <small style="color:#6b7280">DOLE Region XII minimum daily wage floor: ₱<?= htmlspecialchars(number_format($minimumDailyWage, 2)) ?></small>
            </div>

            <div class="form-group" style="margin-bottom:1.25rem">
                <label for="effective_from" style="display:block;font-weight:500;margin-bottom:.25rem">
                    Effective From <span style="color:#ef4444">*</span>
                </label>
                <input type="date" id="effective_from" name="effective_from" class="form-control" required
                       value="<?= htmlspecialchars($dateValue) ?>"
                       <?= $earliest !== null ? 'min="' . htmlspecialchars($earliest) . '"' : '' ?>>
                <small id="effective_from_hint" style="color:#6b7280;display:block">
                    <?php if ($isEdit): ?>A new record will be created; the current record will be closed the day before this date.<?php endif; ?>
                    <span data-earliest-hint><?php if ($earliest !== null): ?>Earliest date: <strong><?= htmlspecialchars(Formatter::date($earliest)) ?></strong> (the current salary starts <?= htmlspecialchars(Formatter::date((string) $currentStart)) ?>).<?php endif; ?></span>
                </small>
            </div>

            <div style="display:flex;gap:.75rem">
                <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Save New Rate' : 'Create Record' ?></button>
                <a href="<?= $base ?>/hr/salary" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
        <?php if (!$isEdit): ?>
        <script>
        // Earliest Effective From follows the chosen employee's current salary (REQ062).
        (function syncSalaryDateMin() {
            var select = document.getElementById('employee_id');
            var date   = document.getElementById('effective_from');
            var hint   = document.querySelector('[data-earliest-hint]');
            if (!select || !date || !hint) return;
            function today() {
                try { return new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Manila' }).format(new Date()); }
                catch (e) { return new Date().toISOString().slice(0, 10); }
            }
            function apply() {
                var opt = select.options[select.selectedIndex];
                var earliest = opt ? opt.getAttribute('data-earliest') : null;
                if (earliest) {
                    date.min = earliest;
                    if (!date.value || date.value < earliest) date.value = earliest > today() ? earliest : today();
                    if (date.value < earliest) date.value = earliest;
                    hint.innerHTML = 'Earliest date: <strong>' + opt.getAttribute('data-earliest-label')
                        + '</strong> (the current salary starts ' + opt.getAttribute('data-current-label') + ').';
                } else {
                    date.removeAttribute('min');
                    hint.textContent = '';
                }
            }
            select.addEventListener('change', apply);
            apply();
        })();
        </script>
        <?php endif; ?>
    </div>

    <?php if ($isEdit && $history !== []): ?>
    <div class="card">
        <h3 style="margin:0 0 1rem">Rate History</h3>
        <table class="data-table" style="font-size:.875rem">
            <thead>
                <tr>
                    <th>Daily Rate</th>
                    <th>From</th>
                    <th>To</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($history as $h): ?>
            <tr<?= (int)$h['salary_id'] === (int)$salary['salary_id'] ? ' style="background:#f0fdf4"' : '' ?>>
                <td>₱<?= number_format((float)$h['daily_rate'], 2) ?></td>
                <td><?= Formatter::date($h['effective_from'] ?? null) ?></td>
                <td><?= !empty($h['effective_to']) ? Formatter::date($h['effective_to']) : '—' ?></td>
                <td>
                    <?php $c=['Active'=>'#10b981','Superseded'=>'#f59e0b','Archived'=>'#6b7280'][$h['status']]??'#6b7280'; ?>
                    <span style="background:<?=$c?>;color:#fff;padding:1px 7px;border-radius:9999px;font-size:.7rem"><?= htmlspecialchars($h['status']) ?></span>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

</div>

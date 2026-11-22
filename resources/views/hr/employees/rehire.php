<?php

declare(strict_types=1);

use Wbpms\Http\View\Formatter;

/** @var array<string,mixed> $employee */
/** @var list<array{id:int,name:string}> $branches */
/** @var list<array{id:int,name:string}> $schedules */
/** @var list<array{id:int,name:string}> $devices */
/** @var list<array<string,mixed>> $positions */
/** @var float $minimumDailyWage */
/** @var array<string,string> $errors */
$errors ??= [];
$minimumDailyWage ??= \Wbpms\Domain\Validation\DailyRateRule::FALLBACK_MINIMUM;
$value = static fn(string $key, string $fallback = ''): string => Formatter::escape((string) ($_POST[$key] ?? $fallback));
$fieldError = static fn(string $key): string => isset($errors[$key])
    ? '<span class="field-error">' . Formatter::escape($errors[$key]) . '</span>' : '';
?>

<div class="page-header">
    <h1>Rehire Employee</h1>
    <a href="<?= $base ?>/hr/employees?status=archived" class="btn btn-secondary">&larr; Archived employees</a>
</div>

<div class="card" style="max-width:720px">
    <p><strong><?= Formatter::escape($employee['last_name']) ?>, <?= Formatter::escape($employee['first_name']) ?></strong>
        <span class="muted">(<?= Formatter::escape($employee['employee_number']) ?>)</span></p>
    <p class="muted">This creates a new employment episode using the same employee record. Historical attendance and payroll are retained unchanged.</p>
    <?php if (isset($errors['form'])): ?>
    <div class="flash flash-error" role="alert"><?= Formatter::escape($errors['form']) ?></div>
    <?php endif; ?>

    <form method="POST" action="<?= $base ?>/hr/employees/<?= (int) $employee['id'] ?>/rehire">
        <input type="hidden" name="_csrf" value="<?= Formatter::escape($csrf) ?>">
        <div class="form-row">
            <div class="form-group">
                <label for="rehire_date">Rehire Effective Date <span style="color:#dc2626">*</span></label>
                <input type="date" id="rehire_date" name="rehire_date" value="<?= $value('rehire_date') ?>" required onchange="syncRehireReview()" class="<?= isset($errors['rehire_date']) ? 'is-invalid' : '' ?>">
                <?= $fieldError('rehire_date') ?>
            </div>
            <div class="form-group">
                <label for="employee_type">Employee Type <span style="color:#dc2626">*</span></label>
                <select id="employee_type" name="employee_type" required onchange="syncRehireReview()" class="<?= isset($errors['employee_type']) ? 'is-invalid' : '' ?>">
                    <?php $employeeType = (string) ($_POST['employee_type'] ?? ($employee['employee_type'] ?? 'Regular')); ?>
                    <option value="Regular" <?= $employeeType === 'Regular' ? 'selected' : '' ?>>Regular</option>
                    <option value="Probationary" <?= $employeeType === 'Probationary' ? 'selected' : '' ?>>Probationary</option>
                    <option value="Contractual" <?= $employeeType === 'Contractual' ? 'selected' : '' ?>>Contractual</option>
                </select>
                <?= $fieldError('employee_type') ?>
            </div>
        </div>
        <?php
        // Contract review date for the new episode (Contractual / Probationary).
        // The previous episode's date is not reused. [EMP-024]
        $needsReview = \Wbpms\Domain\Employee\ContractReviewPolicy::needsReview($employeeType);
        ?>
        <div class="form-row" id="contract_review_row" style="<?= $needsReview ? '' : 'display:none' ?>">
            <div class="form-group">
                <label for="contract_review_date">Contract Review Date <span style="color:#dc2626">*</span></label>
                <input type="date" id="contract_review_date" name="contract_review_date"
                    value="<?= $value('contract_review_date') ?>"
                    <?= $needsReview ? 'required' : 'disabled' ?>
                    class="<?= isset($errors['contract_review_date']) ? 'is-invalid' : '' ?>">
                <small class="muted">The date HR will review this employee's contract for renewal or regularisation.
                    The HR Head is notified <?= \Wbpms\Domain\Employee\ContractReviewPolicy::DUE_SOON_DAYS ?> days before.</small>
                <?= $fieldError('contract_review_date') ?>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label for="position">Position <span style="color:#dc2626">*</span></label>
                <select id="position" name="position" required onchange="onRehirePositionChange(this)" class="<?= isset($errors['position']) ? 'is-invalid' : '' ?>">
                    <?php $currentPosition = (string) ($_POST['position'] ?? ($employee['position'] ?? '')); ?>
                    <?php foreach ($positions as $position):
                        $reqReg = (int) ($position['requires_regular'] ?? 0);
                        $defRate = ($position['default_daily_rate'] ?? null) !== null
                            ? number_format((float) $position['default_daily_rate'], 2, '.', '') : '';
                    ?>
                    <option value="<?= Formatter::escape((string) $position['name']) ?>"
                            data-requires-regular="<?= $reqReg ?>"
                            data-default-rate="<?= Formatter::escape($defRate) ?>"
                            <?= $position['name'] === $currentPosition ? 'selected' : '' ?>><?= Formatter::escape((string) $position['name']) ?></option>
                    <?php endforeach; ?>
                    <?php if ($currentPosition !== '' && !in_array($currentPosition, array_column($positions, 'name'), true)): ?>
                    <option value="<?= Formatter::escape($currentPosition) ?>" selected><?= Formatter::escape($currentPosition) ?></option>
                    <?php endif; ?>
                </select>
                <?= $fieldError('position') ?>
            </div>
            <div class="form-group">
                <label for="daily_rate">Daily Rate (₱)<span style="color:#dc2626">*</span></label>
                <input type="number" id="daily_rate" name="daily_rate"
                       min="<?= Formatter::escape(number_format((float) $minimumDailyWage, 2, '.', '')) ?>"
                       max="<?= Formatter::escape(number_format(\Wbpms\Http\Controllers\EmployeeController::MAX_DAILY_RATE, 2, '.', '')) ?>"
                       step="0.01" required
                       data-msg-max="<?= Formatter::escape(\Wbpms\Domain\Validation\DailyRateRule::maxMessage()) ?>"
                       value="<?= $value('daily_rate', (string) ($employee['daily_rate'] ?? '')) ?>"
                       class="<?= isset($errors['daily_rate']) ? 'is-invalid' : '' ?>">
                <small class="muted" id="daily_rate_hint" style="display:none"></small>
                <small class="muted">Picking a position fills in its default rate; change it if this employee's rate is different. DOLE Region XII minimum daily wage: ₱<?= Formatter::escape(number_format((float) $minimumDailyWage, 2)) ?></small>
                <?= $fieldError('daily_rate') ?>
                <?php require __DIR__ . '/_default_rate_script.php'; ?>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label for="branch_id">Branch <span style="color:#dc2626">*</span></label>
                <?php $selectedBranchId = (string) ($_POST['branch_id'] ?? ($employee['branch_id'] ?? '')); ?>
                <select id="branch_id" name="branch_id" required class="<?= isset($errors['branch_id']) ? 'is-invalid' : '' ?>">
                    <option value="">&mdash; select &mdash;</option>
                    <?php foreach ($branches as $branch): ?><option value="<?= (int) $branch['id'] ?>" <?= (string) $branch['id'] === $selectedBranchId ? 'selected' : '' ?>><?= Formatter::escape($branch['name']) ?></option><?php endforeach; ?>
                </select>
                <?= $fieldError('branch_id') ?>
            </div>
            <div class="form-group">
                <label for="schedule_id">Work Schedule <span style="color:#dc2626">*</span></label>
                <?php $selectedScheduleId = (string) ($_POST['schedule_id'] ?? ($employee['schedule_id'] ?? '')); ?>
                <select id="schedule_id" name="schedule_id" required class="<?= isset($errors['schedule_id']) ? 'is-invalid' : '' ?>">
                    <option value="">&mdash; select &mdash;</option>
                    <?php foreach ($schedules as $schedule): ?><option value="<?= (int) $schedule['id'] ?>" <?= (string) $schedule['id'] === $selectedScheduleId ? 'selected' : '' ?>><?= Formatter::escape($schedule['name']) ?></option><?php endforeach; ?>
                </select>
                <?= $fieldError('schedule_id') ?>
            </div>
        </div>
        <h2>Biometric Enrollment</h2>
        <div class="form-row">
            <div class="form-group">
                <label for="device_id">Device <span style="color:#dc2626">*</span></label>
                <?php $selectedDeviceId = (string) ($_POST['device_id'] ?? ($employee['device_id'] ?? '')); ?>
                <select id="device_id" name="device_id" required class="<?= isset($errors['device_id']) ? 'is-invalid' : '' ?>">
                    <option value="">&mdash; select &mdash;</option>
                    <?php foreach ($devices as $device): ?><option value="<?= (int) $device['id'] ?>" <?= (string) $device['id'] === $selectedDeviceId ? 'selected' : '' ?>><?= Formatter::escape($device['name']) ?></option><?php endforeach; ?>
                </select>
                <?= $fieldError('device_id') ?>
            </div>
            <div class="form-group">
                <label for="enrollment_code">Biometric Enroll ID <span style="color:#dc2626">*</span></label>
                <input type="text" id="enrollment_code" name="enrollment_code" inputmode="numeric" pattern="\d{1,20}" required value="<?= $value('enrollment_code', (string) ($employee['enrollment_code'] ?? '')) ?>" class="<?= isset($errors['enrollment_code']) ? 'is-invalid' : '' ?>">
                <small class="muted">Digits-only Enroll ID shown on the biometric scanner / .xls export.</small>
                <?= $fieldError('enrollment_code') ?>
            </div>
        </div>
        <script>
        function onRehirePositionChange(sel) {
            if (!sel || sel.selectedIndex < 0) return;
            var opt = sel.options[sel.selectedIndex];
            if (typeof wbFillDefaultRate === 'function') { wbFillDefaultRate(opt); }
            var reqReg = opt ? opt.getAttribute('data-requires-regular') : '0';
            var typeSel = document.getElementById('employee_type');
            if (typeSel && reqReg === '1') {
                typeSel.value = 'Regular';
                syncRehireReview();
            }
        }
        function syncRehireReview() {
            var type  = document.getElementById('employee_type');
            var row   = document.getElementById('contract_review_row');
            var input = document.getElementById('contract_review_date');
            if (!type || !row || !input) { return; }
            var needs = type.value === 'Contractual' || type.value === 'Probationary';
            row.style.display = needs ? '' : 'none';
            input.disabled = !needs;
            input.required = needs;
            var start = (document.getElementById('rehire_date') || {}).value || '';
            if (needs && input.value === '' && /^\d{4}-\d{2}-\d{2}$/.test(start)) {
                // Pre-fill rehire date + 6 months; HR can change it.
                var p = start.split('-');
                input.value = new Date(Date.UTC(+p[0], +p[1] - 1 + 6, +p[2])).toISOString().slice(0, 10);
            }
        }
        </script>
        <div class="form-group">
            <label><input type="checkbox" name="reactivate_account" value="1" <?= isset($_POST['reactivate_account']) || $_POST === [] ? 'checked' : '' ?>> Reactivate the linked employee login, if it is currently inactive</label>
        </div>
        <div class="form-group">
            <label for="notes">HR Note</label>
            <textarea id="notes" name="notes" rows="3" maxlength="1000"><?= $value('notes') ?></textarea>
            <?= $fieldError('notes') ?>
        </div>
        <div style="display:flex;gap:.75rem;margin-top:1.25rem">
            <button type="submit" class="btn btn-primary">Rehire Employee</button>
            <a href="<?= $base ?>/hr/employees?status=archived" class="btn btn-secondary">Cancel</a>
        </div>
    </form>
</div>

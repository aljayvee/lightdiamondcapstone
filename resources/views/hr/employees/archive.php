<?php

declare(strict_types=1);

use Wbpms\Http\View\Formatter;

/** @var array<string,mixed> $employee */
/** @var array<string,string> $errors */
/** @var list<array<string,mixed>> $unapprovedPayrollRuns */
$errors ??= [];
$unapprovedPayrollRuns ??= [];
$fieldError = static fn(string $key): string => isset($errors[$key])
    ? '<span class="field-error">' . Formatter::escape($errors[$key]) . '</span>' : '';
?>

<div class="page-header">
    <h1>Archive Employee</h1>
    <a href="<?= $base ?>/hr/employees/<?= (int) $employee['id'] ?>/edit" class="btn btn-secondary"><?= \Wbpms\Http\View\Icon::svg('chevron-left') ?> Back to Employee</a>
</div>

<div class="card" style="max-width:720px">
    <p><strong><?= Formatter::escape($employee['last_name']) ?>, <?= Formatter::escape($employee['first_name']) ?></strong>
        <span class="muted">(<?= Formatter::escape($employee['employee_number']) ?>)</span></p>
    <p class="muted">Archiving preserves payroll, attendance, requests, and audit history. It closes active branch, schedule, salary, biometric, and linked-account access after the final working day.</p>

    <?php if (isset($errors['form'])): ?>
    <div class="flash flash-error" role="alert"><?= Formatter::escape($errors['form']) ?></div>
    <?php endif; ?>

    <?php if ($unapprovedPayrollRuns !== []): ?>
    <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:6px;padding:.75rem 1rem;margin-bottom:1rem;color:#92400e;font-size:.875rem">
        <strong>Note — Unapproved Payroll Run(s) Found:</strong>
        This employee is currently included in <?= count($unapprovedPayrollRuns) ?> unapproved payroll run(s). Archiving is allowed; recomputing those runs will automatically pay worked days up to the employee's last working date.
    </div>
    <?php endif; ?>

    <form method="POST" action="<?= $base ?>/hr/employees/<?= (int) $employee['id'] ?>/archive">
        <input type="hidden" name="_csrf" value="<?= Formatter::escape($csrf) ?>">
        <div class="form-row">
            <div class="form-group">
                <label for="last_working_date">Last Working Date <span style="color:#dc2626">*</span></label>
                <input type="date" id="last_working_date" name="last_working_date"
                    value="<?= Formatter::escape((string) ($_POST['last_working_date'] ?? '')) ?>" required
                    class="<?= isset($errors['last_working_date']) ? 'is-invalid' : '' ?>">
                <small class="muted">Assignments close on the next calendar day.</small>
                <?= $fieldError('last_working_date') ?>
            </div>
            <div class="form-group">
                <label for="reason">Reason <span style="color:#dc2626">*</span></label>
                <select id="reason" name="reason" required class="<?= isset($errors['reason']) ? 'is-invalid' : '' ?>">
                    <option value="">&mdash; select &mdash;</option>
                    <?php foreach (['Resignation', 'End of Contract', 'Retirement', 'Termination', 'AWOL', 'Deceased', 'Other'] as $reason): ?>
                    <option value="<?= $reason ?>" <?= (($_POST['reason'] ?? '') === $reason) ? 'selected' : '' ?>><?= $reason ?></option>
                    <?php endforeach; ?>
                </select>
                <?= $fieldError('reason') ?>
            </div>
        </div>
        <div class="form-group">
            <label for="notes">HR Note</label>
            <textarea id="notes" name="notes" rows="4" maxlength="1000" placeholder="Optional exit or handover note"><?= Formatter::escape((string) ($_POST['notes'] ?? '')) ?></textarea>
            <?= $fieldError('notes') ?>
        </div>
        <div style="display:flex;gap:.75rem;margin-top:1.25rem">
            <button type="submit" class="btn btn-warning">Archive Employee</button>
            <a href="<?= $base ?>/hr/employees/<?= (int) $employee['id'] ?>/edit" class="btn btn-secondary">Cancel</a>
        </div>
    </form>
</div>

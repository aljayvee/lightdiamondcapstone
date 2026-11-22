<?php

declare(strict_types=1);

use Wbpms\Http\View\Formatter;

/**
 * HR — Edit employee form.
 * Shared with create (when $employee is empty/null).
 *
 * @var array{
 *     id: int,
 *     employee_number: string,
 *     first_name: string,
 *     last_name: string,
 *     middle_name: string,
 *     status: string,
 *     effective_from: string,
 *     branch_id: int,
 *     schedule_id: int,
 *     device_id: int,
 *     enrollment_code: string,
 *     daily_rate: string
 * }|null $employee
 * @var list<array{id:int,name:string}> $branches
 * @var list<array{id:int,name:string}> $schedules
 * @var list<array{id:int,name:string}> $devices
 * @var array<string,string>            $errors
 * @var string                          $csrf
 */

$employee ??= null;
$branches ??= [];
$schedules ??= [];
$devices  ??= [];
$positions ??= [];
$minimumDailyWage ??= \Wbpms\Domain\Validation\DailyRateRule::FALLBACK_MINIMUM;
$nextEmployeeNumber ??= '';
$errors   ??= [];
$csrf     ??= '';

$manilaToday = new \DateTimeImmutable('today', new \DateTimeZone('Asia/Manila'));
$minBirthdate = $manilaToday->modify('-100 years')->format('Y-m-d');
$maxBirthdate = $manilaToday->modify('-18 years')->format('Y-m-d');
$maxEffectiveDate = $manilaToday->modify('+30 days')->format('Y-m-d');

$isEdit  = $employee !== null;
$title   = $isEdit ? 'Edit Employee' : 'Add Employee';
$action  = $isEdit
    ? $base . '/hr/employees/' . (int) $employee['id']
    : $base . '/hr/employees';

// Populate with existing values (edit) or empty/posted defaults (create)
$v = static fn(string $key, string $fallback = ''): string =>
    Formatter::escape((string) ($employee[$key] ?? ($_POST[$key] ?? $fallback)));
$sel = static fn(int|string $id, int|string $cmp): string =>
    ((string) $id === (string) $cmp) ? 'selected' : '';
$err = static fn(string $key): string => isset($errors[$key])
    ? '<span class="field-error">' . Formatter::escape($errors[$key]) . '</span>' : '';
?>

<div class="page-header">
    <h1><?= $title ?></h1>
    <a href="<?= $base ?>/hr/employees" class="btn btn-secondary"><?= \Wbpms\Http\View\Icon::svg('chevron-left') ?> Back to Employees</a>
</div>

<div class="card" style="max-width:720px">
    <form method="POST" action="<?= Formatter::escape($action) ?>" novalidate data-validate>
        <input type="hidden" name="_csrf" value="<?= Formatter::escape($csrf) ?>">
        <?php if ($isEdit): ?>
        <input type="hidden" name="_method" value="PUT">
        <?php endif; ?>

        <h2 style="margin-top:0">Personal Information</h2>
        <div class="form-row-3">
            <div class="form-group">
                <label for="first_name">First Name <span style="color:#dc2626">*</span></label>
                <input type="text" id="first_name" name="first_name"
                    value="<?= $v('first_name') ?>"
                    maxlength="50"
                    required
                    pattern="[A-Za-zÀ-ÖØ-öø-ÿ][A-Za-zÀ-ÖØ-öø-ÿ .'\-]*"
                    data-msg-required="First name is required."
                    data-msg-pattern="First name may only contain letters, spaces, hyphens, apostrophes, and periods."
                    class="<?= isset($errors['first_name']) ? 'is-invalid' : '' ?>">
                <?= $err('first_name') ?>
            </div>
            <div class="form-group">
                <label for="middle_name">Middle Name</label>
                <input type="text" id="middle_name" name="middle_name"
                    value="<?= $v('middle_name') ?? $v('middle_initial') ?>"
                    maxlength="50"
                    pattern="[A-Za-zÀ-ÖØ-öø-ÿ][A-Za-zÀ-ÖØ-öø-ÿ .'\-]*"
                    data-msg-pattern="Middle name may only contain letters, spaces, hyphens, apostrophes, and periods."
                    class="<?= isset($errors['middle_name']) ? 'is-invalid' : '' ?>">
                <?= $err('middle_name') ?>
            </div>
            <div class="form-group">
                <label for="last_name">Last Name <span style="color:#dc2626">*</span></label>
                <input type="text" id="last_name" name="last_name"
                    value="<?= $v('last_name') ?>"
                    maxlength="50"
                    required
                    pattern="[A-Za-zÀ-ÖØ-öø-ÿ][A-Za-zÀ-ÖØ-öø-ÿ .'\-]*"
                    data-msg-required="Last name is required."
                    data-msg-pattern="Last name may only contain letters, spaces, hyphens, apostrophes, and periods."
                    class="<?= isset($errors['last_name']) ? 'is-invalid' : '' ?>">
                <?= $err('last_name') ?>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label for="birthdate">Birthdate <span style="color:#dc2626">*</span></label>
                <input type="date" id="birthdate" name="birthdate"
                    value="<?= $v('birthdate') ?>"
                    min="<?= Formatter::escape($minBirthdate) ?>"
                    max="<?= Formatter::escape($maxBirthdate) ?>"
                    required
                    data-msg-required="Birthdate is required."
                    data-msg-max="Employee is under age. Employees must be at least 18 years old (born on or before <?= Formatter::escape(Formatter::date($maxBirthdate)) ?>)."
                    data-msg-min="Employee age cannot exceed 100 years. Please check the birthdate year."
                    class="<?= isset($errors['birthdate']) ? 'is-invalid' : '' ?>">
                <small class="muted">Must be 18 to 100 years old.</small>
                <?= $err('birthdate') ?>
            </div>
            <div class="form-group">
                <label for="contact_number">Contact Number <span style="color:#dc2626">*</span></label>
                <input type="text" id="contact_number" name="contact_number"
                    value="<?= $v('contact_number') ?>"
                    placeholder="09XXXXXXXXX"
                    maxlength="11"
                    pattern="09[0-9]{9}"
                    inputmode="numeric"
                    required
                    data-msg-required="Contact number is required."
                    data-msg-pattern="Contact number must start with 09 and be exactly 11 digits (e.g. 09171234567)."
                    class="<?= isset($errors['contact_number']) ? 'is-invalid' : '' ?>">
                <?= $err('contact_number') ?>
            </div>
            <div class="form-group">
                <label for="email">Email <span style="color:#dc2626">*</span></label>
                <input type="email" id="email" name="email"
                    value="<?= $v('email') ?>"
                    maxlength="254"
                    required
                    data-msg-required="Email address is required."
                    data-msg-type="Please enter a valid email address."
                    class="<?= isset($errors['email']) ? 'is-invalid' : '' ?>"
                    placeholder="e.g. juan@lightdiamond.com">
                <?= $err('email') ?>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label for="position">Job Position<span style="color:#dc2626">*</span></label>
                <select id="position_select" name="position"
                        onchange="syncPositionInput(this, true)"
                        required
                        data-msg-required="Position / Job title is required."
                        class="<?= isset($errors['position']) ? 'is-invalid' : '' ?>">
                    <option value="">— select position —</option>
                    <?php
                    $currentPosition = (string) ($employee['position'] ?? ($_POST['position'] ?? ''));
                    $matched = false;
                    foreach ($positions ?? [] as $pos):
                        $selected = ($pos['name'] === $currentPosition);
                        if ($selected) $matched = true;
                        $reqReg = (int) ($pos['requires_regular'] ?? 0);
                        $defRate = ($pos['default_daily_rate'] ?? null) !== null
                            ? number_format((float) $pos['default_daily_rate'], 2, '.', '') : '';
                    ?>
                    <option value="<?= Formatter::escape($pos['name']) ?>"
                            data-requires-regular="<?= $reqReg ?>"
                            data-default-rate="<?= Formatter::escape($defRate) ?>"
                            <?= $selected ? 'selected' : '' ?>>
                        <?= Formatter::escape($pos['name']) ?>
                        <?php if ($pos['department']): ?>
                            (<?= Formatter::escape($pos['department']) ?>)
                        <?php endif; ?>
                    </option>
                    <?php endforeach; ?>
                    <option value="__other__" <?= (!$matched && $currentPosition !== '') ? 'selected' : '' ?>>
                        Other (type below)
                    </option>
                </select>
                <input type="text" id="position_other" name="position_other"
                       value="<?= (!$matched && $currentPosition !== '') ? Formatter::escape($currentPosition) : '' ?>"
                       placeholder="Type custom position title"
                       data-msg-required="Position / Job title is required."
                       style="margin-top:.4rem;<?= (!$matched && $currentPosition !== '') ? '' : 'display:none' ?>">
                <small class="muted">
                    Can't find the title?
                    <a href="<?= $base ?>/hr/settings/positions" target="_blank">Add it in Settings <?= \Wbpms\Http\View\Icon::svg('chevron-right') ?></a>
                </small>
                <?= $err('position') ?>
            </div>
            <div class="form-group">
                <label for="employee_type">Employee Type <span style="color:#dc2626">*</span></label>
                <?php $currentEmployeeType = (string) ($employee['employee_type'] ?? ($_POST['employee_type'] ?? 'Regular')); ?>
                <select id="employee_type" name="employee_type" onchange="syncContractReview()" class="<?= isset($errors['employee_type']) ? 'is-invalid' : '' ?>">
                    <option value="Regular"      <?= $sel($currentEmployeeType, 'Regular') ?>>Regular</option>
                    <option value="Probationary" <?= $sel($currentEmployeeType, 'Probationary') ?>>Probationary</option>
                    <option value="Contractual"  <?= $sel($currentEmployeeType, 'Contractual') ?>>Contractual</option>
                </select>
                <?= $err('employee_type') ?>
            </div>
        </div>

        <?php
        // Contract review date: required for Contractual / Probationary. The HR
        // Head's bell lists the employee from 14 days before this date until
        // HR changes the type to Regular or sets a new date. [EMP-024]
        $needsReview = \Wbpms\Domain\Employee\ContractReviewPolicy::needsReview($currentEmployeeType);
        $reviewValue = (string) ($_POST['contract_review_date'] ?? ($employee['contract_review_date'] ?? ''));
        $reviewStart = $isEdit ? (string) ($employee['hire_date'] ?? '') : '';
        ?>
        <div class="form-row" id="contract_review_row" style="<?= $needsReview ? '' : 'display:none' ?>">
            <div class="form-group">
                <label for="contract_review_date">Contract Review Date <span style="color:#dc2626">*</span></label>
                <input type="date" id="contract_review_date" name="contract_review_date"
                    value="<?= Formatter::escape($reviewValue) ?>"
                    data-start="<?= Formatter::escape($reviewStart) ?>"
                    <?= $needsReview ? 'required' : 'disabled' ?>
                    data-msg-required="Contract review date is required for Contractual and Probationary employees."
                    class="<?= isset($errors['contract_review_date']) ? 'is-invalid' : '' ?>">
                <small class="muted">The date HR will review this employee's contract for renewal or regularisation.
                    The HR Head is notified <?= \Wbpms\Domain\Employee\ContractReviewPolicy::DUE_SOON_DAYS ?> days before.
                    Changing the type to Regular clears it.</small>
                <?= $err('contract_review_date') ?>
            </div>
        </div>
        <script>
        function syncContractReview() {
            var type  = document.getElementById('employee_type');
            var row   = document.getElementById('contract_review_row');
            var input = document.getElementById('contract_review_date');
            if (!type || !row || !input) { return; }
            var needs = type.value === 'Contractual' || type.value === 'Probationary';
            row.style.display = needs ? '' : 'none';
            input.disabled = !needs;
            input.required = needs;
            if (needs && input.value === '') {
                // Pre-fill start date + 6 months; HR can change it.
                var startEl = document.getElementById('effective_from');
                var start = input.getAttribute('data-start') || (startEl ? startEl.value : '');
                if (/^\d{4}-\d{2}-\d{2}$/.test(start)) {
                    var p = start.split('-');
                    var d = new Date(Date.UTC(+p[0], +p[1] - 1 + 6, +p[2]));
                    input.value = d.toISOString().slice(0, 10);
                }
            }
        }
        </script>

        <script>
        function syncPositionInput(sel, isUserChange) {
            var other = document.getElementById('position_other');
            if (sel.value === '__other__') {
                other.style.display = '';
                other.name = 'position';
                other.required = true;
                sel.name = '_position_select';
                sel.required = false;
                sel.removeAttribute('required');
            } else {
                other.style.display = 'none';
                other.name = 'position_other';
                other.required = false;
                other.removeAttribute('required');
                sel.name = 'position';
                sel.required = true;

                if (isUserChange && sel.selectedIndex >= 0) {
                    var opt = sel.options[sel.selectedIndex];
                    if (typeof wbFillDefaultRate === 'function') { wbFillDefaultRate(opt); }
                    var reqReg = opt ? opt.getAttribute('data-requires-regular') : '0';
                    var typeSel = document.getElementById('employee_type');
                    if (typeSel && reqReg === '1') {
                        typeSel.value = 'Regular';
                        if (typeof syncContractReview === 'function') { syncContractReview(); }
                    }
                }
            }
        }
        // Run on page load to handle the pre-selected "Other" state
        (function(){ syncPositionInput(document.getElementById('position_select'), false); })();
        </script>

        <div class="form-row">
            <div class="form-group">
                <label for="employee_number">Employee No.</label>
                <?php if ($isEdit): ?>
                <input type="text" id="employee_number" name="employee_number"
                    value="<?= $v('employee_number') ?>"
                    readonly style="background:#f3f4f6">
                <small class="muted">Assigned when the employee was added; cannot be changed.</small>
                <?php else: ?>
                <?php /* Auto-generated by store() (EMP-001, EMP-002, ...). Display only, never posted. */ ?>
                <input type="text" id="employee_number"
                    value=""
                    data-next="<?= Formatter::escape((string) $nextEmployeeNumber) ?>"
                    readonly tabindex="-1" aria-readonly="true"
                    placeholder="Generated after first and last name"
                    style="background:#f3f4f6">
                <small class="muted">Generated automatically once the first and last name are filled in. The final number is confirmed when you save.</small>
                <script>
                // Show the next number once both names are filled in.
                document.addEventListener('DOMContentLoaded', function () {
                    var first = document.getElementById('first_name');
                    var last  = document.getElementById('last_name');
                    var num   = document.getElementById('employee_number');
                    if (!first || !last || !num) { return; }
                    var sync = function () {
                        num.value = (first.value.trim() !== '' && last.value.trim() !== '') ? num.dataset.next : '';
                    };
                    first.addEventListener('input', sync);
                    last.addEventListener('input', sync);
                    sync();
                });
                </script>
                <?php endif; ?>
                <?= $err('employee_number') ?>
            </div>
            <div class="form-group">
                <label for="effective_from"><?= $isEdit ? 'Hire date' : 'Effective from (Hire date)' ?> <span style="color:#dc2626">*</span></label>
                <input type="date" id="effective_from" name="effective_from"
                    value="<?= $v('effective_from') ?>"
                    onchange="if (typeof syncContractReview === 'function') { syncContractReview(); }"
                    <?= !$isEdit ? 'required max="' . Formatter::escape($maxEffectiveDate) . '"' : '' ?>
                    class="<?= isset($errors['effective_from']) ? 'is-invalid' : '' ?>"
                    <?= $isEdit ? 'readonly style="background:#f3f4f6"' : '' ?>>
                <?php if (!$isEdit): ?>
                <small class="muted">Must be on or before employee's first biometric punch date so punches match.</small>
                <?php endif; ?>
                <?= $err('effective_from') ?>
            </div>
        </div>

        <h2>Government IDs</h2>
        <div class="form-row-3">
            <div class="form-group">
                <label for="sss_number">SSS Number</label>
                <input type="text" id="sss_number" name="sss_number"
                    value="<?= $v('sss_number') ?>"
                    placeholder="XX-XXXXXXX-X"
                    maxlength="13"
                    pattern="\d{2}-?\d{7}-?\d"
                    title="SSS number must be in XX-XXXXXXX-X format (10 digits, e.g. 34-5678901-2)."
                    inputmode="numeric"
                    class="<?= isset($errors['sss_number']) ? 'is-invalid' : '' ?>">
                <?= $err('sss_number') ?>
            </div>
            <div class="form-group">
                <label for="philhealth_number">PhilHealth Number</label>
                <input type="text" id="philhealth_number" name="philhealth_number"
                    value="<?= $v('philhealth_number') ?>"
                    placeholder="12-digit number"
                    maxlength="15"
                    pattern="[\d\- ]{12,15}"
                    title="PhilHealth number must be 12 digits."
                    inputmode="numeric"
                    class="<?= isset($errors['philhealth_number']) ? 'is-invalid' : '' ?>">
                <?= $err('philhealth_number') ?>
            </div>
            <div class="form-group">
                <label for="pagibig_number">Pag-IBIG Number</label>
                <input type="text" id="pagibig_number" name="pagibig_number"
                    value="<?= $v('pagibig_number') ?>"
                    placeholder="12-digit number"
                    maxlength="15"
                    pattern="[\d\- ]{12,15}"
                    title="Pag-IBIG number must be 12 digits."
                    inputmode="numeric"
                    class="<?= isset($errors['pagibig_number']) ? 'is-invalid' : '' ?>">
                <?= $err('pagibig_number') ?>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label for="tin_number">TIN</label>
                <input type="text" id="tin_number" name="tin_number"
                    value="<?= $v('tin_number') ?>"
                    placeholder="XXX-XXX-XXX"
                    maxlength="16"
                    pattern="\d{3}-?\d{3}-?\d{3}(-?\d{3})?"
                    title="TIN must be 9 or 12 digits (e.g. 123-456-789 or 123-456-789-000)."
                    inputmode="numeric"
                    class="<?= isset($errors['tin_number']) ? 'is-invalid' : '' ?>">
                <?= $err('tin_number') ?>
            </div>
        </div>

        <h2>Assignment &amp; Salary</h2>
        <div class="form-row">
            <div class="form-group">
                <label for="branch_id">Branch <?= !$isEdit ? '<span style="color:#dc2626">*</span>' : '' ?></label>
                <?php
                // On edit: the select is visually disabled so HR cannot change it directly
                // (use Transfer Branch instead), but we still need the value to survive
                // a validation-error re-render. A hidden input carries the submitted value;
                // the visible select is purely decorative and uses `disabled` + `tabindex="-1"`.
                $currentBranchId = (string) ($employee['branch_id'] ?? ($_POST['branch_id'] ?? ''));
                ?>
                <?php if ($isEdit): ?>
                <input type="hidden" name="branch_id" value="<?= Formatter::escape($currentBranchId) ?>">
                <?php endif; ?>
                <select id="branch_id"
                    <?= !$isEdit ? 'name="branch_id"' : 'tabindex="-1" aria-hidden="true"' ?>
                    <?= $isEdit ? 'disabled style="background:#f3f4f6;color:#6b7280;cursor:not-allowed"' : '' ?>
                    class="<?= isset($errors['branch_id']) ? 'is-invalid' : '' ?>"
                    <?= !$isEdit ? 'required' : '' ?>>
                    <option value="">— select —</option>
                    <?php foreach ($branches as $branch): ?>
                    <option value="<?= (int) $branch['id'] ?>"
                        <?= $sel($branch['id'], $currentBranchId) ?>>
                        <?= Formatter::escape($branch['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <?= $err('branch_id') ?>
                <?php if ($isEdit): ?>
                <small class="muted">To change branches, use <strong>Transfer Branch</strong> below so the effective date and history are recorded safely.</small>
                <?php endif; ?>
            </div>
            <div class="form-group">
                <label for="schedule_id">Work Schedule <span style="color:#dc2626">*</span></label>
                <select id="schedule_id" name="schedule_id"
                    required
                    class="<?= isset($errors['schedule_id']) ? 'is-invalid' : '' ?>">
                    <option value="">— select —</option>
                    <?php foreach ($schedules as $schedule): ?>
                    <option value="<?= (int) $schedule['id'] ?>"
                        <?= $sel($schedule['id'], $employee['schedule_id'] ?? ($_POST['schedule_id'] ?? '')) ?>>
                        <?= Formatter::escape($schedule['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <?= $err('schedule_id') ?>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label for="daily_rate">Daily Rate (₱) <span style="color:#dc2626">*</span></label>
                <input type="number" id="daily_rate" name="daily_rate"
                    value="<?= $v('daily_rate') ?>"
                    min="<?= Formatter::escape(number_format((float) $minimumDailyWage, 2, '.', '')) ?>"
                    max="<?= Formatter::escape(number_format(\Wbpms\Http\Controllers\EmployeeController::MAX_DAILY_RATE, 2, '.', '')) ?>"
                    step="0.01"
                    required
                    data-msg-required="Daily rate is required."
                    data-msg-min="Daily rate cannot be below the DOLE Region XII minimum daily wage (₱<?= Formatter::escape(number_format((float) $minimumDailyWage, 2)) ?>)."
                    data-msg-max="<?= Formatter::escape(\Wbpms\Domain\Validation\DailyRateRule::maxMessage()) ?>"
                    class="<?= isset($errors['daily_rate']) ? 'is-invalid' : '' ?>">
                <small class="muted" id="daily_rate_hint" style="display:none"></small>
                <small class="muted">Filled in from the position's default rate (set in Settings); change it if this employee's rate is different. DOLE Region XII minimum daily wage: ₱<?= Formatter::escape(number_format((float) $minimumDailyWage, 2)) ?></small>
                <?= $err('daily_rate') ?>
                <?php require __DIR__ . '/_default_rate_script.php'; ?>
            </div>
            <div class="form-group">
                <label for="status">Status</label>
                <select id="status" name="status">
                    <?php
                    $currentStatus = strtolower((string) ($employee['status'] ?? ($_POST['status'] ?? 'active')));
                    ?>
                    <option value="active"   <?= $currentStatus === 'active'   ? 'selected' : '' ?>>Active</option>
                    <option value="inactive" <?= $currentStatus === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                </select>
            </div>
        </div>

        <h2>Biometric Enrollment</h2>
        <div class="form-row">
            <div class="form-group">
                <label for="device_id">Biometric Device <?= !$isEdit ? '<span style="color:#dc2626">*</span>' : '' ?></label>
                <select id="device_id" name="device_id"
                    <?= !$isEdit ? 'required' : '' ?>
                    class="<?= isset($errors['device_id']) ? 'is-invalid' : '' ?>">
                    <option value="">— select —</option>
                    <?php foreach ($devices as $device): ?>
                    <option value="<?= (int) $device['id'] ?>"
                        <?= $sel($device['id'], $employee['device_id'] ?? ($_POST['device_id'] ?? '')) ?>>
                        <?= Formatter::escape($device['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <?= $err('device_id') ?>
                <?php if ($isEdit): ?>
                <small class="muted">Changing the device or Enroll ID corrects it if no punches were matched yet; otherwise the new ID applies from today.</small>
                <?php endif; ?>
            </div>
            <div class="form-group">
                <label for="enrollment_code">Biometric Enroll ID <?= !$isEdit ? '<span style="color:#dc2626">*</span>' : '' ?></label>
                <input type="text" id="enrollment_code" name="enrollment_code"
                    value="<?= $v('enrollment_code') ?>"
                    inputmode="numeric"
                    pattern="\d{1,20}"
                    placeholder="e.g. 2, 13, 21"
                    <?= !$isEdit ? 'required' : '' ?>
                    class="<?= isset($errors['enrollment_code']) ? 'is-invalid' : '' ?>">
                <small class="muted">Digits-only Enroll ID shown in the biometric .xls workbook (e.g. <code>21</code> for Richie Tagaduar).</small>
                <?= $err('enrollment_code') ?>
            </div>
        </div>

        <?php if (!empty($errors)): ?>
        <div class="flash flash-error" role="alert">
            Please correct the highlighted fields before saving.
        </div>
        <?php endif; ?>

        <div class="form-action-bar" style="display:flex;flex-wrap:wrap;gap:.75rem;margin-top:1.25rem">
            <button type="submit" class="btn btn-primary">
                <?= $isEdit ? 'Save Changes' : 'Create Employee' ?>
            </button>
            <a href="<?= $base ?>/hr/employees" class="btn btn-secondary">Cancel</a>
            <?php if ($isEdit): ?>
                <a href="<?= $base ?>/hr/employees/<?= (int) $employee['id'] ?>/documents"
                   class="btn btn-secondary">Documents</a>
                <a href="<?= $base ?>/hr/employees/<?= (int) $employee['id'] ?>/bank"
                   class="btn btn-secondary">Bank Account</a>
                <a href="<?= $base ?>/hr/employees/<?= (int) $employee['id'] ?>/transfer"
                   class="btn btn-warning" style="margin-left:auto;">
                    Transfer Branch
                </a>
                <?php if (strtolower((string) $employee['status']) !== 'archived'): ?>
                <a href="<?= $base ?>/hr/employees/<?= (int) $employee['id'] ?>/archive"
                   class="btn btn-secondary">Archive</a>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </form>
</div>

<script src="<?= $base ?>/assets/inline-validate.js"></script>
<?php if (!$isEdit): ?>
<script>
// The email becomes the employee's login, so HR confirms it before the
// account is created. Registered on window load so it runs after the inline
// validator and only asks when the form is otherwise valid. [QA EMP TC2]
window.addEventListener('load', function () {
    var form = document.querySelector('form[data-validate]');
    var email = document.getElementById('email');
    if (!form || !email) return;
    // Uses the shared on-page confirmation (assets/confirm-dialog.js): this
    // listener runs before it, so the message carries the email typed now.
    form.addEventListener('submit', function (e) {
        if (e.defaultPrevented) return;
        var address = email.value.trim();
        form.setAttribute('data-confirm', 'Create this employee with the login email\n\n' + address + '\n\nIs this email address correct?');
        form.setAttribute('data-confirm-ok', 'Yes, create employee');
    });
    form.addEventListener('confirm:cancel', function () {
        email.focus();
    });
});
</script>
<?php endif; ?>

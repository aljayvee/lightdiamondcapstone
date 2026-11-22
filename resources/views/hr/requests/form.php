<?php

use Wbpms\Http\View\Formatter;

/**
 * View: hr/requests/form
 *
 * HR Head submits a request on behalf of an employee.
 *
 * Variables injected by RequestsController::create()/store() + ViewRenderer:
 *   list<array{id:int,employee_number:string,last_name:string,first_name:string,branch_name:string}> $employees
 *   list<array{request_type_id:int,type_name:string}> $types
 *   array $balances — RequestFormBalances::payload() (sick-leave credit per year, unpaid cash advance)
 *   array<string,string> $errors   — '_general' for service errors, field keys for field errors
 *   array<string,mixed>  $old      — repopulated POST values on re-render
 *   string $base
 *   string $csrf
 */

$employees ??= [];
$types     ??= [];
$balances  ??= \Wbpms\Domain\Requests\RequestFormBalances::payload((int) date('Y'), [], []);
$errors    ??= [];
$old       ??= [];
$base      ??= '';
$csrf      ??= '';

// Helpers
$o   = static fn(string $key, string $fallback = ''): string =>
    Formatter::escape((string) ($old[$key] ?? $fallback));
$err = static fn(string $key): string => isset($errors[$key])
    ? '<span style="color:#dc2626;font-size:.8rem;display:block;margin-top:.2rem">'
      . Formatter::escape($errors[$key]) . '</span>'
    : '';

$selectedEmployee = (string) ($old['employee_id'] ?? '');
$selectedType     = (string) ($old['request_type_id'] ?? '');
$todayManila      = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Manila')))->format('Y-m-d');

// Build a JS map: request_type_id → code ('leave' | 'overtime' | 'cash_advance')
$codeMap  = ['Leave' => 'leave', 'Overtime' => 'overtime', 'CashAdvance' => 'cash_advance'];
$typeCodeMap = [];
foreach ($types as $t) {
    $typeCodeMap[(int) $t['request_type_id']] = $codeMap[$t['type_name']] ?? strtolower($t['type_name']);
}
?>

<div class="page-head" style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.25rem">
    <div>
        <h1 style="margin:0 0 .2rem">New Request</h1>
    </div>
    <a href="<?= $base ?>/hr/requests" class="btn btn-secondary"><?= \Wbpms\Http\View\Icon::svg('chevron-left') ?> Back to Requests</a>
</div>

<div class="card" style="max-width:640px;padding:1.5rem">

    <?php if (!empty($errors['_general'])): ?>
    <div class="alert error" role="alert"
         style="margin-bottom:1.25rem;padding:.75rem 1rem;background:#fef2f2;border:1px solid #fca5a5;border-radius:6px;color:#991b1b">
        <?= Formatter::escape($errors['_general']) ?>
    </div>
    <?php endif; ?>

    <form method="POST" action="<?= $base ?>/hr/requests" novalidate data-validate>
        <input type="hidden" name="_csrf" value="<?= Formatter::escape($csrf) ?>">

        <!-- ── Employee picker ─────────────────────────────────────────── -->
        <div class="form-group" style="margin-bottom:1rem">
            <label for="employee_id"
                   style="display:block;font-weight:500;font-size:.875rem;margin-bottom:.3rem">
                Employee <span style="color:#dc2626">*</span>
            </label>
            <select id="employee_id" name="employee_id"
                    style="width:100%;padding:.45rem .7rem;border:1px solid <?= isset($errors['employee_id']) ? '#dc2626' : '#d1d5db' ?>;border-radius:4px;font-size:.9rem"
                    required data-msg-required="Please select an employee.">
                <option value="">— select employee —</option>
                <?php foreach ($employees as $emp): ?>
                <option value="<?= (int) $emp['id'] ?>"
                    <?= ($selectedEmployee === (string) $emp['id']) ? 'selected' : '' ?>>
                    <?= Formatter::escape($emp['last_name'] . ', ' . $emp['first_name']) ?>
                    <?php if (!empty($emp['branch_name']) && $emp['branch_name'] !== '—'): ?>
                        (<?= Formatter::escape($emp['branch_name']) ?>)
                    <?php endif; ?>
                    — <?= Formatter::escape($emp['employee_number']) ?>
                </option>
                <?php endforeach; ?>
            </select>
            <?= $err('employee_id') ?>
        </div>

        <!-- ── Balances of the chosen employee (same figures as the employee's My Requests) [REQ-025] ── -->
        <div id="balance-panel" aria-live="polite"
             style="display:none;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:.75rem;margin:-.25rem 0 1rem">
            <div style="border:1px solid #e5e7eb;border-radius:6px;padding:.65rem .9rem;background:#fafaf9">
                <div style="display:flex;align-items:center;gap:.4rem;font-size:.72rem;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.05em">
                    <?= \Wbpms\Http\View\Icon::svg('leaf', 16) ?> Sick leave <span id="bal-leave-year"></span>
                </div>
                <div style="margin-top:.3rem;font-size:.9rem;color:#374151">
                    <strong id="bal-leave-left" style="font-size:1.2rem">—</strong>
                    / <span id="bal-leave-total">—</span> days left
                </div>
                <div id="bal-leave-note" style="font-size:.75rem;color:#9ca3af;margin-top:.15rem">Credit of the year of the From date.</div>
            </div>
            <div style="border:1px solid #e5e7eb;border-radius:6px;padding:.65rem .9rem;background:#fafaf9">
                <div style="display:flex;align-items:center;gap:.4rem;font-size:.72rem;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.05em">
                    <?= \Wbpms\Http\View\Icon::svg('banknote', 16) ?> Cash advance
                </div>
                <div style="margin-top:.3rem;font-size:.9rem;color:#374151">
                    <strong id="bal-ca-out" style="font-size:1.2rem">—</strong> unpaid
                </div>
                <div style="font-size:.75rem;color:#9ca3af;margin-top:.15rem">
                    Max: ₱<?= number_format(\Wbpms\Domain\Requests\RequestFormBalances::CASH_ADVANCE_MAX, 2) ?>/month (₱500/wk)
                </div>
            </div>
        </div>

        <!-- ── Request type ────────────────────────────────────────────── -->
        <div class="form-group" style="margin-bottom:1rem">
            <label for="request_type_id"
                   style="display:block;font-weight:500;font-size:.875rem;margin-bottom:.3rem">
                Request Type <span style="color:#dc2626">*</span>
            </label>
            <select id="request_type_id" name="request_type_id"
                    style="width:100%;padding:.45rem .7rem;border:1px solid <?= isset($errors['request_type_id']) ? '#dc2626' : '#d1d5db' ?>;border-radius:4px;font-size:.9rem"
                    required data-msg-required="Please select a request type.">
                <option value="">— select type —</option>
                <?php foreach ($types as $t): ?>
                <option value="<?= (int) $t['request_type_id'] ?>"
                    <?= ($selectedType === (string) $t['request_type_id']) ? 'selected' : '' ?>>
                    <?= Formatter::escape(Formatter::label($t['type_name'])) ?>
                </option>
                <?php endforeach; ?>
            </select>
            <?= $err('request_type_id') ?>
        </div>

        <!-- ── Leave fields ─────────────────────────────────────────────── -->
        <div id="section-leave" style="display:none">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem;margin-bottom:1rem">
                <div>
                    <label for="start_date"
                           style="display:block;font-weight:500;font-size:.875rem;margin-bottom:.3rem">From</label>
                    <input type="date" id="start_date" name="start_date"
                           value="<?= $o('start_date') ?>"
                           style="width:100%;padding:.45rem .7rem;border:1px solid <?= isset($errors['start_date']) ? '#dc2626' : '#d1d5db' ?>;border-radius:4px;font-size:.9rem">
                    <?= $err('start_date') ?>
                </div>
                <div>
                    <label for="end_date"
                           style="display:block;font-weight:500;font-size:.875rem;margin-bottom:.3rem">To</label>
                    <input type="date" id="end_date" name="end_date"
                           value="<?= $o('end_date') ?>"
                           style="width:100%;padding:.45rem .7rem;border:1px solid <?= isset($errors['end_date']) ? '#dc2626' : '#d1d5db' ?>;border-radius:4px;font-size:.9rem">
                    <?= $err('end_date') ?>
                </div>
            </div>
            <p style="margin:-.5rem 0 1rem;font-size:.8rem;color:#6b7280">
                Only the employee's scheduled working days are charged. Rest days (Saturday), holidays and days already worked are not counted.
            </p>
        </div>

        <!-- ── Overtime fields ──────────────────────────────────────────── -->
        <div id="section-overtime" style="display:none">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem;margin-bottom:.75rem">
                <div>
                    <label for="date_from"
                           style="display:block;font-weight:500;font-size:.875rem;margin-bottom:.3rem">From</label>
                    <input type="date" id="date_from" name="date_from"
                           value="<?= $o('date_from') ?>"
                           style="width:100%;padding:.45rem .7rem;border:1px solid <?= isset($errors['date_from']) ? '#dc2626' : '#d1d5db' ?>;border-radius:4px;font-size:.9rem">
                    <?= $err('date_from') ?>
                </div>
                <div>
                    <label for="date_to"
                           style="display:block;font-weight:500;font-size:.875rem;margin-bottom:.3rem">To</label>
                    <input type="date" id="date_to" name="date_to"
                           value="<?= $o('date_to') ?>"
                           style="width:100%;padding:.45rem .7rem;border:1px solid <?= isset($errors['date_to']) ? '#dc2626' : '#d1d5db' ?>;border-radius:4px;font-size:.9rem">
                    <?= $err('date_to') ?>
                </div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem;margin-bottom:1rem">
                <div>
                    <label for="start_time"
                           style="display:block;font-weight:500;font-size:.875rem;margin-bottom:.3rem">
                        Start Time
                    </label>
                    <input type="text" id="start_time" name="start_time" pattern="(?:[01][0-9]|2[0-3]):[0-5][0-9]"
                           value="<?= $o('start_time') ?>" placeholder="HH:MM"
                           style="width:100%;padding:.45rem .7rem;border:1px solid <?= isset($errors['start_time']) ? '#dc2626' : '#d1d5db' ?>;border-radius:4px;font-size:.9rem">
                    <?= $err('start_time') ?>
                </div>
                <div>
                    <label for="end_time"
                           style="display:block;font-weight:500;font-size:.875rem;margin-bottom:.3rem">
                        End Time
                    </label>
                    <input type="text" id="end_time" name="end_time" pattern="(?:[01][0-9]|2[0-3]):[0-5][0-9]"
                           value="<?= $o('end_time') ?>" placeholder="HH:MM"
                           style="width:100%;padding:.45rem .7rem;border:1px solid <?= isset($errors['end_time']) ? '#dc2626' : '#d1d5db' ?>;border-radius:4px;font-size:.9rem">
                    <?= $err('end_time') ?>
                </div>
            </div>
            <p class="muted" style="margin:-.5rem 0 1rem;font-size:.8rem">Use 24-hour time (HH:MM), for example 13:30. The same time window applies to every day in the range.</p>
        </div>

        <!-- ── Cash advance fields ──────────────────────────────────────── -->
        <div id="section-cash-advance" style="display:none">
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:.75rem;margin-bottom:.5rem">
                <div>
                    <label for="advance_date"
                           style="display:block;font-weight:500;font-size:.875rem;margin-bottom:.3rem">
                        Cash Advance Date <span style="color:#dc2626">*</span>
                    </label>
                    <input type="date" id="advance_date" name="advance_date"
                           value="<?= $o('advance_date', $todayManila) ?>"
                           style="width:100%;padding:.45rem .7rem;border:1px solid <?= isset($errors['advance_date']) ? '#dc2626' : '#d1d5db' ?>;border-radius:4px;font-size:.9rem">
                    <?= $err('advance_date') ?>
                </div>
                <div>
                    <label for="amount_requested"
                           style="display:block;font-weight:500;font-size:.875rem;margin-bottom:.3rem">
                        Amount Requested (₱)
                    </label>
                    <input type="number" id="amount_requested" name="amount_requested"
                           value="<?= $o('amount_requested') ?>"
                           min="1" max="2000" step="0.01"
                           style="width:100%;padding:.45rem .7rem;border:1px solid <?= isset($errors['amount_requested']) ? '#dc2626' : '#d1d5db' ?>;border-radius:4px;font-size:.9rem">
                    <small id="ca-week-cap-hint" style="color:#6b7280;font-size:.78rem">Maximum: ₱2,000.00/month (₱500.00 × remaining paydays in month).</small>
                    <?= $err('amount_requested') ?>
                </div>
            </div>
            <p style="margin:0 0 1rem;font-size:.8rem;color:#6b7280">
                The date the cash is given. Repayments deduct ₱500.00/week starting in this cutoff so the advance is paid within the same payroll month
                (max ₱2,000.00/month, scaled by remaining open paydays). New advances are blocked while a prior-month balance remains unpaid.
            </p>
        </div>

        <!-- ── Reason ───────────────────────────────────────────────────── -->
        <div class="form-group" style="margin-bottom:1.25rem">
            <label for="reason"
                   style="display:block;font-weight:500;font-size:.875rem;margin-bottom:.3rem">
                Reason / Notes <span style="color:#dc2626">*</span>
            </label>
            <textarea id="reason" name="reason" rows="4"
                      placeholder="Describe the reason for this request..."
                      style="width:100%;padding:.45rem .7rem;border:1px solid <?= isset($errors['reason']) ? '#dc2626' : '#d1d5db' ?>;border-radius:4px;font-size:.9rem;resize:vertical"
                      required data-msg-required="Reason is required."><?= $o('reason') ?></textarea>
            <?= $err('reason') ?>
        </div>

        <div style="display:flex;gap:.75rem">
            <button type="submit" class="btn btn-primary">Submit Request</button>
            <a href="<?= $base ?>/hr/requests" class="btn btn-secondary">Cancel</a>
        </div>
    </form>
</div>

<script>
(function () {
    var sel   = document.getElementById('request_type_id');
    var types = <?= json_encode($typeCodeMap, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;

    function setSection(id, visible) {
        var section = document.getElementById(id);
        section.style.display = visible ? '' : 'none';
        section.querySelectorAll('input, select, textarea').forEach(function (field) {
            field.disabled = !visible;
        });
    }

    function update() {
        var code = types[sel.value] || '';
        setSection('section-leave', code === 'leave');
        setSection('section-overtime', code === 'overtime');
        setSection('section-cash-advance', code === 'cash_advance');
    }

    sel.addEventListener('change', update);
    update(); // apply on page load when re-rendering with POST data
}());

// Sick-leave credit and unpaid cash advance of the chosen employee. [REQ-025]
(function () {
    var data   = <?= json_encode($balances, JSON_FORCE_OBJECT | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
    var emp    = document.getElementById('employee_id');
    var from   = document.getElementById('start_date');
    var panel  = document.getElementById('balance-panel');
    var money  = function (n) {
        return '₱' + Number(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    };

    function year() {
        var m = /^(\d{4})-\d{2}-\d{2}$/.exec(from && !from.disabled ? from.value : '');
        return m ? parseInt(m[1], 10) : data.currentYear;
    }

    function render() {
        if (!emp.value) {
            panel.style.display = 'none';
            return;
        }
        var y      = year();
        var credit = (data.leave[y] || {})[emp.value] || { balance: data.sickLeaveDays, entitled: data.sickLeaveDays };
        var owed   = data.cashAdvance[emp.value] || 0;
        var left   = document.getElementById('bal-leave-left');
        var out    = document.getElementById('bal-ca-out');

        document.getElementById('bal-leave-year').textContent = String(y);
        left.textContent = Number(credit.balance).toFixed(2);
        left.style.color = credit.balance > 0 ? '#059669' : '#dc2626';
        document.getElementById('bal-leave-total').textContent = Number(credit.entitled).toFixed(2);
        out.textContent  = money(owed);
        out.style.color  = owed > 0 ? '#d97706' : '#059669';
        panel.style.display = 'grid';
    }

    emp.addEventListener('change', render);
    document.getElementById('request_type_id').addEventListener('change', render);
    if (from) { from.addEventListener('change', render); from.addEventListener('input', render); }
    render();
}());

// Dynamic Cash Advance weekly cap hint based on advance_date. [REQ-026]
(function () {
    var advDate = document.getElementById('advance_date');
    var amtInput = document.getElementById('amount_requested');
    var hint = document.getElementById('ca-week-cap-hint');
    if (!advDate || !amtInput || !hint) { return; }

    var months = ['January','February','March','April','May','June','July','August','September','October','November','December'];
    function updateCapHint() {
        var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(advDate.value || '');
        if (!m) {
            amtInput.max = '2000';
            hint.textContent = 'Maximum: ₱2,000.00/month (₱500.00 × remaining paydays in month).';
            return;
        }
        var y = parseInt(m[1], 10), mo = parseInt(m[2], 10) - 1, d = parseInt(m[3], 10);
        var dt = new Date(Date.UTC(y, mo, d));
        if (dt.getUTCFullYear() !== y || dt.getUTCMonth() !== mo || dt.getUTCDate() !== d) { return; }
        var daysSinceFri = (dt.getUTCDay() + 2) % 7;
        var firstPay = new Date(dt.getTime() + (7 - daysSinceFri) * 86400000);
        var payYear = firstPay.getUTCFullYear();
        var payMonth = firstPay.getUTCMonth();
        var monthEnd = new Date(Date.UTC(payYear, payMonth + 1, 0));
        var lastFri = new Date(monthEnd.getTime() - ((monthEnd.getUTCDay() + 2) % 7) * 86400000);
        var paydays = Math.floor((lastFri.getTime() - firstPay.getTime()) / (7 * 86400000)) + 1;
        var maxAmt = Math.min(2000, Math.max(0, paydays) * 500);
        amtInput.max = String(maxAmt);
        hint.textContent = 'Up to ₱' + maxAmt.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
            + ' for this week (' + paydays + ' payday' + (paydays === 1 ? '' : 's') + ' left in ' + months[payMonth] + ' ' + payYear + ' × ₱500/wk).';
    }
    advDate.addEventListener('change', updateCapHint);
    advDate.addEventListener('input', updateCapHint);
    updateCapHint();
}());
</script>

<script src="<?= $base ?>/assets/inline-validate.js"></script>

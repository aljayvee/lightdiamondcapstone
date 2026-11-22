<?php

use Wbpms\Http\View\Formatter;

/**
 * Employee portal — Submit a new request (leave, overtime, cash advance).
 *
 * @var list<array{id:int,name:string,code:string}> $requestTypes
 * @var int    $sickLeaveBalance    Remaining sick leave days
 * @var array<string,string> $errors   Key '_general' for service-level errors
 * @var string $csrf
 * @var string $base
 */

$requestTypes     ??= [];
$sickLeaveBalance ??= 0;
$cashAdvanceOutstanding ??= 0.0;
$errors           ??= [];
$csrf             ??= '';
$base             ??= '';

// Helper: re-populate a POST field safely
$fp  = static fn(string $key, string $fallback = ''): string =>
    Formatter::escape((string) ($_POST[$key] ?? $fallback));
// Helper: inline field error
$err = static fn(string $key): string => isset($errors[$key])
    ? '<span class="field-error" style="color:#dc2626;font-size:.8rem;display:block;margin-top:.25rem">'
      . Formatter::escape($errors[$key]) . '</span>'
    : '';

$selectedType = (string) ($_POST['request_type_id'] ?? '');
?>

<div class="page-header" style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.25rem">
    <h1 style="margin:0">New Request</h1>
    <a href="<?= $base ?>/employee/requests" class="btn btn-secondary"><?= \Wbpms\Http\View\Icon::svg('chevron-left') ?> My Requests</a>
</div>

<div class="card" style="max-width:600px;padding:1.5rem">

    <!-- Leave balance and cash advance info panel -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem;margin-bottom:1.25rem">
        <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:.85rem">
            <div style="font-size:.7rem;color:#166534;text-transform:uppercase;letter-spacing:.05em;margin-bottom:.25rem">
                Sick Leave Balance
            </div>
            <div style="font-size:1.4rem;font-weight:700;color:#15803d">
                <?= number_format((float) $sickLeaveBalance, 1) ?>
                <span style="font-size:.8rem;font-weight:400">day<?= (float) $sickLeaveBalance !== 1.0 ? 's' : '' ?> remaining</span>
            </div>
            <div style="font-size:.75rem;color:#166534;margin-top:.2rem">
                Max 4 paid sick days per year
            </div>
        </div>
        <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:.85rem">
            <div style="font-size:.7rem;color:#1e40af;text-transform:uppercase;letter-spacing:.05em;margin-bottom:.25rem">
                Outstanding Cash Advance
            </div>
            <div style="font-size:1.4rem;font-weight:700;color:<?= $cashAdvanceOutstanding > 0 ? '#dc2626' : '#15803d' ?>">
                ₱<?= number_format($cashAdvanceOutstanding, 2) ?>
            </div>
            <div style="font-size:.75rem;color:#1e40af;margin-top:.2rem">
                Unpaid balance (₱500/week deducted · max ₱2,000/month)
            </div>
        </div>
    </div>

    <?php if ($sickLeaveBalance <= 1): ?>
    <div class="alert warning" role="alert" style="margin-bottom:1rem;padding:.75rem 1rem;background:#fefce8;border:1px solid #fde047;border-radius:6px;font-size:.875rem;color:#713f12">
        <?= \Wbpms\Http\View\Icon::svg('alert') ?> You have <strong><?= number_format((float) $sickLeaveBalance, 1) ?></strong> sick leave
        day<?= (float) $sickLeaveBalance !== 1.0 ? 's' : '' ?> remaining.
        A leave request will be blocked if your balance is insufficient.
    </div>
    <?php endif; ?>

    <?php if (!empty($errors['_general'])): ?>
    <div class="alert error" role="alert" style="margin-bottom:1rem">
        <?= Formatter::escape($errors['_general']) ?>
    </div>
    <?php endif; ?>

    <form method="POST" action="<?= $base ?>/employee/requests" novalidate>
        <input type="hidden" name="_csrf" value="<?= Formatter::escape($csrf) ?>">

        <!-- Request type -->
        <div class="form-group" style="margin-bottom:1rem">
            <label for="request_type_id" style="display:block;font-weight:500;margin-bottom:.3rem">
                Request Type <span style="color:#dc2626">*</span>
            </label>
            <select id="request_type_id" name="request_type_id"
                    style="width:100%;padding:.45rem .7rem;border:1px solid <?= isset($errors['request_type_id']) ? '#dc2626' : '#d1d5db' ?>;border-radius:4px;font-size:.9rem"
                    required>
                <option value="">— select type —</option>
                <?php foreach ($requestTypes as $type): ?>
                <option value="<?= (int) $type['id'] ?>"
                    <?= ($selectedType === (string) $type['id']) ? 'selected' : '' ?>>
                    <?= Formatter::escape(Formatter::label($type['name'])) ?>
                </option>
                <?php endforeach; ?>
            </select>
            <?= $err('request_type_id') ?>
        </div>

        <!-- ── Leave fields ─────────────────────────────────────────────── -->
        <div id="section-leave" style="display:none">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem;margin-bottom:1rem">
                <div class="form-group">
                    <label for="start_date" style="display:block;font-weight:500;margin-bottom:.3rem">From</label>
                    <input type="date" id="start_date" name="start_date"
                           value="<?= $fp('start_date') ?>"
                           style="width:100%;padding:.45rem .7rem;border:1px solid <?= isset($errors['start_date']) ? '#dc2626' : '#d1d5db' ?>;border-radius:4px;font-size:.9rem">
                    <?= $err('start_date') ?>
                </div>
                <div class="form-group">
                    <label for="end_date" style="display:block;font-weight:500;margin-bottom:.3rem">To</label>
                    <input type="date" id="end_date" name="end_date"
                           value="<?= $fp('end_date') ?>"
                           style="width:100%;padding:.45rem .7rem;border:1px solid <?= isset($errors['end_date']) ? '#dc2626' : '#d1d5db' ?>;border-radius:4px;font-size:.9rem">
                    <?= $err('end_date') ?>
                </div>
            </div>
            <p style="margin:-.5rem 0 1rem;font-size:.8rem;color:#6b7280">
                Only your scheduled working days are charged. Rest days (Saturday), holidays and days you already worked are not counted.
            </p>
        </div>

        <!-- ── Overtime fields ──────────────────────────────────────────── -->
        <div id="section-overtime" style="display:none">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem;margin-bottom:.75rem">
                <div class="form-group">
                    <label for="date_from" style="display:block;font-weight:500;margin-bottom:.3rem">From</label>
                    <input type="date" id="date_from" name="date_from"
                           value="<?= $fp('date_from') ?>"
                           style="width:100%;padding:.45rem .7rem;border:1px solid <?= isset($errors['date_from']) ? '#dc2626' : '#d1d5db' ?>;border-radius:4px;font-size:.9rem">
                    <?= $err('date_from') ?>
                </div>
                <div class="form-group">
                    <label for="date_to" style="display:block;font-weight:500;margin-bottom:.3rem">To</label>
                    <input type="date" id="date_to" name="date_to"
                           value="<?= $fp('date_to') ?>"
                           style="width:100%;padding:.45rem .7rem;border:1px solid <?= isset($errors['date_to']) ? '#dc2626' : '#d1d5db' ?>;border-radius:4px;font-size:.9rem">
                    <?= $err('date_to') ?>
                </div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem;margin-bottom:1rem">
                <div class="form-group">
                    <label for="start_time" style="display:block;font-weight:500;margin-bottom:.3rem">Start Time</label>
                    <input type="text" id="start_time" name="start_time" pattern="(?:[01][0-9]|2[0-3]):[0-5][0-9]"
                           value="<?= $fp('start_time') ?>" placeholder="HH:MM"
                           style="width:100%;padding:.45rem .7rem;border:1px solid <?= isset($errors['start_time']) ? '#dc2626' : '#d1d5db' ?>;border-radius:4px;font-size:.9rem">
                    <?= $err('start_time') ?>
                </div>
                <div class="form-group">
                    <label for="end_time" style="display:block;font-weight:500;margin-bottom:.3rem">End Time</label>
                    <input type="text" id="end_time" name="end_time" pattern="(?:[01][0-9]|2[0-3]):[0-5][0-9]"
                           value="<?= $fp('end_time') ?>" placeholder="HH:MM"
                           style="width:100%;padding:.45rem .7rem;border:1px solid <?= isset($errors['end_time']) ? '#dc2626' : '#d1d5db' ?>;border-radius:4px;font-size:.9rem">
                    <?= $err('end_time') ?>
                </div>
            </div>
            <p class="muted" style="margin:-.5rem 0 1rem;font-size:.8rem">Use 24-hour time (HH:MM), for example 13:30. The same time window applies to every day in the range.</p>
        </div>

        <!-- ── Cash advance fields ──────────────────────────────────────── -->
        <div id="section-cash-advance" style="display:none">
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:.75rem;margin-bottom:.5rem">
                <div class="form-group">
                    <label for="advance_date" style="display:block;font-weight:500;margin-bottom:.3rem">
                        Cash Advance Date <span style="color:#dc2626">*</span>
                    </label>
                    <input type="date" id="advance_date" name="advance_date"
                           value="<?= $fp('advance_date', (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Manila')))->format('Y-m-d')) ?>"
                           style="width:100%;padding:.45rem .7rem;border:1px solid <?= isset($errors['advance_date']) ? '#dc2626' : '#d1d5db' ?>;border-radius:4px;font-size:.9rem">
                    <?= $err('advance_date') ?>
                </div>
                <div class="form-group">
                    <label for="amount_requested" style="display:block;font-weight:500;margin-bottom:.3rem">
                        Amount Requested (₱)
                    </label>
                    <input type="number" id="amount_requested" name="amount_requested"
                           value="<?= $fp('amount_requested') ?>"
                           min="1" max="2000" step="0.01"
                           style="width:100%;padding:.45rem .7rem;border:1px solid <?= isset($errors['amount_requested']) ? '#dc2626' : '#d1d5db' ?>;border-radius:4px;font-size:.9rem">
                    <small id="ca-week-cap-hint" style="color:#6b7280;font-size:.78rem">Maximum: ₱2,000.00/month (₱500.00 × remaining paydays in month).</small>
                    <?= $err('amount_requested') ?>
                </div>
            </div>
            <p style="margin:0 0 1rem;font-size:.8rem;color:#6b7280">
                The day you need the cash. Repayments deduct ₱500.00/week starting in this cutoff so the advance is paid within the same payroll month
                (max ₱2,000.00/month, scaled by remaining open paydays). New advances require any prior-month balance to be paid off first.
            </p>
        </div>

        <!-- Reason -->
        <div class="form-group" style="margin-bottom:1rem">
            <label for="reason" style="display:block;font-weight:500;margin-bottom:.3rem">
                Reason / Notes <span style="color:#dc2626">*</span>
            </label>
            <textarea id="reason" name="reason" rows="4"
                      placeholder="Describe the reason for this request..."
                      style="width:100%;padding:.45rem .7rem;border:1px solid <?= isset($errors['reason']) ? '#dc2626' : '#d1d5db' ?>;border-radius:4px;font-size:.9rem;resize:vertical"
                      required><?= $fp('reason') ?></textarea>
            <?= $err('reason') ?>
        </div>

        <div style="display:flex;gap:.75rem;margin-top:1.25rem">
            <button type="submit" class="btn btn-primary">Submit Request</button>
            <a href="<?= $base ?>/employee/requests" class="btn btn-secondary">Cancel</a>
        </div>
    </form>
</div>

<script>
(function () {
    var sel   = document.getElementById('request_type_id');
    var types = <?= json_encode(
        array_column($requestTypes, 'code', 'id'),
        JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT
    ) ?>;

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

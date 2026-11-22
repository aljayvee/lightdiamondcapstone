<?php
/**
 * View: hr/reports/transaction-slips  (GET /hr/reports/transaction-slips)
 *
 * Prints payroll details onto BDO Cash Transaction Slips (stamp printing).
 *
 * HR gets the pre-printed slips from BDO (Light Diamond is a BDO client).
 * Print mode (?print=1) never reproduces the bank's slip: it shows only the
 * details (class .d) on a blank 212.8 x 100 mm sheet, positioned in mm so
 * they land in the slip's boxes, with neutral guide tags (class .g) that do
 * not print. The office printer's offset is built in (--cal-x/--cal-y,
 * measured from HR's printed slip); HR fine-tunes from the toolbar
 * (--nudge-x/--nudge-y, saved in the browser) and can test on plain paper
 * with alignment crosses.
 * Field positions come from a 400 dpi scan: docs/bdo-transaction-slip-layout.md.
 * Screen mode (no ?print) shows the filter form + table. [REP-021]
 *
 * Variables injected by ReportsController::transactionSlips():
 *   array  $slips            — rows from payroll + bank_details join
 *   array  $branches         — for filter dropdown
 *   array  $employees        — for filter dropdown
 *   array  $periods          — approved payroll periods for dropdown
 *   array  $filters          — current filter values
 *   int    $missingBankCount — number of slips with no bank account on file
 *   bool   $printMode        — true when ?print=1 (hides UI chrome)
 *   string $base
 */

use Wbpms\Http\View\Formatter;

$e = static fn(string $s): string => Formatter::escape($s);

$slips            ??= [];
$branches         ??= [];
$employees        ??= [];
$periods          ??= [];
$filters          ??= ['payroll_period_id' => 0, 'branch_id' => 0, 'employee_id' => 0];
$missingBankCount ??= 0;
$printMode        ??= false;
$base             ??= '';
?>

<?php if (!$printMode): ?>
<!-- ================================================================
     SCREEN MODE
     ================================================================ -->
<div class="page-head">
    <div>
        <h1>Transaction Slips</h1>
        <p>Print payroll details onto the BDO Cash Transaction Slips you get from the bank, for an approved payroll period.</p>
    </div>
    <?php if (!empty($slips)): ?>
    <a href="<?= $e($base) ?>/hr/reports/transaction-slips?<?= http_build_query(array_merge($filters, ['print' => '1'])) ?>"
       target="_blank" class="btn btn-primary"><?= \Wbpms\Http\View\Icon::svg('printer') ?> Print Slips</a>
    <?php endif; ?>
</div>

<?php if ($missingBankCount > 0): ?>
<div class="alert info" role="alert" style="margin-bottom:1rem">
    <strong><?= \Wbpms\Http\View\Icon::svg('alert') ?> <?= (int) $missingBankCount ?> employee(s) have no bank account on file.</strong>
    Their slips will print with blank account fields.
    Add bank account details through Employee Management to auto-fill these fields.
</div>
<?php endif; ?>

<div class="card" style="margin-bottom:1.25rem">
    <form method="GET" action="<?= $e($base) ?>/hr/reports/transaction-slips"
          style="display:flex;flex-wrap:wrap;gap:1rem;align-items:flex-end">
        <div style="flex:1;min-width:180px">
            <label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px">
                Payroll Period <span style="color:var(--bad)">*</span>
            </label>
            <select name="payroll_period_id" required style="width:100%;padding:8px 10px;border:1px solid var(--line);border-radius:4px;font-size:13px">
                <option value="">— Select period —</option>
                <?php foreach ($periods as $p): ?>
                    <option value="<?= (int) $p['payroll_period_id'] ?>"
                        <?= (int) $filters['payroll_period_id'] === (int) $p['payroll_period_id'] ? 'selected' : '' ?>>
                        <?= $e(Formatter::date($p['period_start'])) ?> – <?= $e(Formatter::date($p['period_end'])) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div style="flex:1;min-width:160px">
            <label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px">Branch</label>
            <select name="branch_id" style="width:100%;padding:8px 10px;border:1px solid var(--line);border-radius:4px;font-size:13px">
                <option value="">All Branches</option>
                <?php foreach ($branches as $b): ?>
                    <option value="<?= (int) $b['branch_id'] ?>"
                        <?= (int) $filters['branch_id'] === (int) $b['branch_id'] ? 'selected' : '' ?>>
                        <?= $e($b['branch_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div style="flex:1;min-width:180px">
            <label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px">Employee</label>
            <select name="employee_id" style="width:100%;padding:8px 10px;border:1px solid var(--line);border-radius:4px;font-size:13px">
                <option value="">All Employees</option>
                <?php foreach ($employees as $emp): ?>
                    <option value="<?= (int) $emp['employee_id'] ?>"
                        <?= (int) $filters['employee_id'] === (int) $emp['employee_id'] ? 'selected' : '' ?>>
                        <?= $e($emp['employee_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <button type="submit" class="btn btn-primary" style="padding:8px 20px;font-size:13px">
                Generate Slips
            </button>
        </div>
    </form>
</div>

<?php if ($filters['payroll_period_id'] === 0): ?>
    <div class="card" style="text-align:center;padding:2.5rem;color:var(--ink-2)">
        <p style="font-size:1.1rem;margin:0">Select a payroll period above to generate transaction slips.</p>
        <?php if (empty($periods)): ?>
        <p style="margin-top:.5rem;font-size:.875rem">No approved payroll runs found. Payroll must be approved before slips can be generated.</p>
        <?php endif; ?>
    </div>
<?php elseif (empty($slips)): ?>
    <div class="card" style="text-align:center;padding:2.5rem;color:var(--ink-2)">
        <p>No payroll records found for the selected filters.</p>
    </div>
<?php else: ?>
<div class="card" style="margin-bottom:1rem;padding:0">
    <div style="padding:12px 16px;border-bottom:1px solid var(--line);display:flex;justify-content:space-between;align-items:center">
        <strong><?= count($slips) ?> slip(s) found</strong>
        <a href="<?= $e($base) ?>/hr/reports/transaction-slips?<?= $e(http_build_query(array_merge($filters, ['print' => '1']))) ?>"
           target="_blank" class="btn btn-primary" style="font-size:12px;padding:6px 14px">
            <?= \Wbpms\Http\View\Icon::svg('printer') ?> Open Print Preview
        </a>
    </div>
    <table class="data-table" style="font-size:13px">
        <thead>
            <tr>
                <th>Employee</th>
                <th>Branch</th>
                <th>Period</th>
                <th>Net Pay</th>
                <th>Account Name</th>
                <th>Account No.</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($slips as $slip): ?>
        <tr>
            <td>
                <div style="font-weight:600"><?= $e($slip['employee_name']) ?></div>
                <div style="font-size:11px;color:var(--ink-2)"><?= $e($slip['employee_number']) ?></div>
            </td>
            <td><?= $e($slip['branch_name']) ?></td>
            <td style="font-size:11px"><?= $e(Formatter::date($slip['period_start'])) ?> – <?= $e(Formatter::date($slip['period_end'])) ?></td>
            <td style="font-weight:700">₱<?= number_format((float) $slip['net_pay'], 2) ?></td>
            <td><?= $slip['account_name'] ? $e($slip['account_name']) : '<span style="color:var(--bad);font-size:11px">Not on file</span>' ?></td>
            <td><?= $slip['account_number'] ? $e($slip['account_number']) : '<span style="color:var(--bad);font-size:11px">Not on file</span>' ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php else: ?>
<!-- ================================================================
     PRINT MODE — stamp printing onto BDO pre-printed slips
     The system never draws the bank's slip. HR feeds the real Cash
     Transaction Slips they get from BDO, and only the details (class .d)
     are printed, absolutely positioned in mm so they land in the slip's
     boxes (slip area 212.8 × 100 mm, measured). On screen the details sit on a blank
     sheet with neutral guide tags (class .g) in our own words; the tags,
     the outline and the toolbar never print. Alignment per printer:
     --nudge-x / --nudge-y, set from the toolbar and remembered in this
     browser, on top of the built-in office printer calibration
     --cal-x / --cal-y. Never move individual fields to calibrate.
     ================================================================ -->
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Slip Printing — <?= $e(date('m/d/Y')) ?></title>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
    --guide:   #94a3b8;
    /* Built-in calibration for the office printer, measured 2026-11-22 from a
       photo of HR's printed slip (printed at +1.0 / +1.0 mm, details landed
       3.1-3.7 mm left and 0.1-1.1 mm low). See docs/bdo-transaction-slip-layout.md. */
    --cal-x:   3.4mm;
    --cal-y:   -0.5mm;
    --nudge-x: 0mm;   /* fine-tuning on top of the calibration: + moves details right */
    --nudge-y: 0mm;   /* fine-tuning on top of the calibration: + moves details down  */
}

body {
    font-family: Arial, Helvetica, sans-serif;
    background: #e8e8e8;
    color: #000;
}

/* ── Screen toolbar ─────────────────────────── */
.no-print {
    background: #1e293b;
    color: #f1f5f9;
    padding: 8px 14px;
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px 14px;
    font-size: 12px;
    position: sticky;
    top: 0;
    z-index: 100;
}
.no-print button {
    background: #2563eb;
    color: #fff;
    border: none;
    border-radius: 4px;
    padding: 5px 12px;
    font-size: 12px;
    cursor: pointer;
    font-weight: 700;
    display: inline-flex;
    align-items: center;
    gap: 5px;
}
.no-print button.secondary { background: #475569; }
.no-print button.step      { background: #334155; padding: 3px 9px; min-width: 26px; justify-content: center; }
.no-print a     { color: #93c5fd; font-size: 11px; text-decoration: none; }
.no-print .grp  { display: inline-flex; align-items: center; gap: 4px; }
.no-print .val  { min-width: 52px; text-align: center; font-variant-numeric: tabular-nums; font-weight: 700; }
.no-print .hint { opacity: .75; flex-basis: 100%; }

/* ── Slip area: 212.8mm × 100mm (measured size of the BDO pre-printed slip) ── */
.slip {
    position: relative;
    width: 212.8mm;
    height: 100mm;
    margin: 8mm auto;
    background: #fff;
    outline: .3mm dashed #cbd5e1;
    overflow: hidden;
    page-break-after: always;
    page-break-inside: avoid;
    font-family: "Arial Narrow", "Roboto Condensed", "Helvetica Neue", Arial, sans-serif;
    font-stretch: condensed;
}
.slip:last-child { page-break-after: auto; }
.slip > * { position: absolute; }

/* ── Guide tags (.g): screen only, our own words ── */
.g {
    font-family: Arial, Helvetica, sans-serif;
    font-size: 5.5pt;
    color: var(--guide);
    text-transform: uppercase;
    letter-spacing: .04em;
    white-space: nowrap;
    line-height: 1;
    transform: translate(calc(var(--cal-x) + var(--nudge-x)), calc(var(--cal-y) + var(--nudge-y)));
}

/* ── Alignment crosses (.rx): screen + test print only. 4 mm cross, centre on a
      line corner of the real slip; drawn with borders so it prints without
      "background graphics". ── */
.rx { width: 4mm; height: 4mm; transform: translate(calc(var(--cal-x) + var(--nudge-x)), calc(var(--cal-y) + var(--nudge-y))); }
.rx::before, .rx::after { content: ""; position: absolute; border: 0 solid #dc2626; }
.rx::before { left: 2mm; top: 0; height: 4mm; border-left-width: .25mm; }
.rx::after  { left: 0; top: 2mm; width: 4mm; border-top-width: .25mm; }

/* ── Details (.d): screen + print ── */
.d     { font-weight: 700; color: #000; white-space: nowrap; overflow: hidden; line-height: 4.4mm; height: 4.4mm;
         transform: translate(calc(var(--cal-x) + var(--nudge-x)), calc(var(--cal-y) + var(--nudge-y))); }
.d.v   { font-size: 9pt; }
.d.vs  { font-size: 8pt; }
.d.chk { width: 2.4mm; height: 2.4mm; line-height: 2.4mm; text-align: center; font-size: 8pt; }
.d.tot { height: 8.26mm; line-height: 8.26mm; font-size: 11pt; text-align: center; }
.r     { text-align: right; }
.c     { text-align: center; }
@media screen {
    .d { background: rgba(37, 99, 235, .06); }
}

/* ── Print: details only, onto the pre-printed slip ── */
@media print {
    @page         { size: A5 landscape; margin: 0; }
    .no-print, .g { display: none !important; }
    body          { background: #fff; }
    .slip         { margin: 0; outline: none; background: transparent; }
    .rx           { display: none; }
    .d            { color: #000 !important; background: none !important; }
    /* Test alignment: one slip on plain paper, with alignment crosses */
    body.test-print .slip ~ .slip { display: none !important; }
    body.test-print .rx           { display: block; }
}
</style>
</head>
<body>

<div class="no-print">
    <button type="button" id="print-all"><?= \Wbpms\Http\View\Icon::svg('printer') ?> Print All Slips (<?= count($slips) ?>)</button>
    <button type="button" class="secondary" id="print-test" title="Prints the first slip with 4 red crosses on plain paper. Hold it over a blank BDO slip against the light: each cross centre should sit on a line corner of the slip.">Test Alignment (plain paper)</button>
    <span class="grp">Left / Right
        <button type="button" class="step" data-axis="x" data-step="-0.5" aria-label="Move details left">&minus;</button>
        <span class="val" id="nudge-x-val">0.0 mm</span>
        <button type="button" class="step" data-axis="x" data-step="0.5" aria-label="Move details right">+</button>
    </span>
    <span class="grp">Up / Down
        <button type="button" class="step" data-axis="y" data-step="-0.5" aria-label="Move details up">&minus;</button>
        <span class="val" id="nudge-y-val">0.0 mm</span>
        <button type="button" class="step" data-axis="y" data-step="0.5" aria-label="Move details down">+</button>
    </span>
    <button type="button" class="step" id="nudge-reset">Reset</button>
    <a href="<?= $e($base) ?>/hr/reports/transaction-slips"><?= \Wbpms\Http\View\Icon::svg('chevron-left') ?> Back to Transaction Slips</a>
    <span class="hint">
        Load the BDO Network Bank Cash Transaction Slips into the printer. Only the ticks, account name, account number, date and total amount are printed
        (A5 landscape, no margins, scale 100 % / Default, slip against the left guide). The office printer's offset is built in, so start at 0.0 mm;
        use Left / Right and Up / Down only to fine-tune. Test first: each red cross must sit on a line corner of the slip. Fine-tuning is saved on this computer.
        &nbsp;·&nbsp; <?= count($slips) ?> slip(s) &nbsp;·&nbsp; <?= $e(date('m/d/Y')) ?>
    </span>
</div>

<?php foreach ($slips as $slip):
    $accountName = !empty($slip['account_name'])   ? (string) $slip['account_name']   : '';
    $accountNo   = !empty($slip['account_number']) ? (string) $slip['account_number'] : '';
    $accountType = !empty($slip['account_type'])   ? strtolower((string) $slip['account_type']) : 'savings';
    $isSavings   = $accountType === 'savings';
    $isCurrent   = $accountType === 'current';
    $netPay      = number_format((float) $slip['net_pay'], 2);
?>
<div class="slip">

    <!-- Alignment crosses (screen + test print): each centre is a corner of a line on the BDO slip -->
    <div class="rx" style="left:32.8mm;top:19.78mm" title="Start of the Account Name line"></div>
    <div class="rx" style="left:119.5mm;top:58.55mm" title="End of the bottom line, left part"></div>
    <div class="rx" style="left:158.37mm;top:19.02mm" title="Top of the Pieces column"></div>
    <div class="rx" style="left:173.9mm;top:85.19mm" title="Bottom of the Amount column"></div>

    <!-- Guide tags (screen only) -->
    <div class="g" style="left:6.3mm;top:14.6mm">Deposit</div>
    <div class="g" style="left:9.4mm;top:17.9mm">Current</div>
    <div class="g" style="left:9.4mm;top:21.2mm">Savings</div>
    <div class="g" style="left:35.5mm;top:15.3mm">Account name</div>
    <div class="g" style="left:35.5mm;top:23.0mm">Account number</div>
    <div class="g" style="left:132.7mm;top:6.3mm">Date</div>
    <div class="g" style="left:176.6mm;top:77.2mm">Total</div>

    <!-- Details (screen + print): mm positions measured on a 400 dpi scan of a blank BDO slip
         (docs/bdo-transaction-slip-layout.md). Each value sits just above its printed line.
         Ticks: the tick glyph's ink sits 0.65 mm right of its em box centre, so each tick box
         starts 0.65 mm left of its checkbox (measured on HR's printed slip, 2026-11-22). -->
    <div class="d chk" style="left:2.59mm;top:14.03mm">✓</div>
    <?php if ($isCurrent): ?><div class="d chk" style="left:5.76mm;top:17.27mm">✓</div><?php endif; ?>
    <?php if ($isSavings): ?><div class="d chk" style="left:5.76mm;top:20.57mm">✓</div><?php endif; ?>

    <div class="d v"  style="left:35.5mm;top:17.18mm;width:85.5mm"><?= $e($accountName) ?></div>
    <div class="d v"  style="left:35.5mm;top:24.86mm;width:85.5mm"><?= $e($accountNo) ?></div>
    <!-- Only these are printed (HR, 2026-11-22): the Deposits + Savings/Current
         ticks, Account Name, Account No., Date (day of printing = deposit date) and
         Total Amount. Payor, Reference, Company and the Denomination rows stay blank. -->

    <div class="d v"  style="left:132.7mm;top:8.16mm;width:70mm"><?= $e(date('m/d/Y')) ?></div>

    <div class="d tot"   style="left:176.6mm;top:78.93mm;width:31mm">₱ <?= $e($netPay) ?></div>

</div><!-- /.slip -->
<?php endforeach; ?>

<script>
(function () {
    // v2 (2026-11-22): the printer offset is now built in (--cal-x/--cal-y), so
    // fine-tuning saved before that (e.g. +1.0 / +1.0 mm) must not be added on top.
    var KEY = 'wbpms.slipNudge.v2';
    var LIMIT = 15; // mm either way
    var nudge = { x: 0, y: 0 };
    try {
        var saved = JSON.parse(window.localStorage.getItem(KEY) || 'null');
        if (saved && isFinite(saved.x) && isFinite(saved.y)) {
            nudge.x = Math.max(-LIMIT, Math.min(LIMIT, Number(saved.x)));
            nudge.y = Math.max(-LIMIT, Math.min(LIMIT, Number(saved.y)));
        }
    } catch (err) { /* storage blocked: start at 0, 0 */ }

    function label(v) { return (v > 0 ? '+' : '') + v.toFixed(1) + ' mm'; }

    function apply(save) {
        var root = document.documentElement.style;
        root.setProperty('--nudge-x', nudge.x.toFixed(1) + 'mm');
        root.setProperty('--nudge-y', nudge.y.toFixed(1) + 'mm');
        document.getElementById('nudge-x-val').textContent = label(nudge.x);
        document.getElementById('nudge-y-val').textContent = label(nudge.y);
        if (save) {
            try { window.localStorage.setItem(KEY, JSON.stringify(nudge)); } catch (err) { /* not saved */ }
        }
    }

    document.querySelectorAll('.no-print .step[data-axis]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var axis = btn.getAttribute('data-axis');
            var next = Math.round((nudge[axis] + Number(btn.getAttribute('data-step'))) * 10) / 10;
            nudge[axis] = Math.max(-LIMIT, Math.min(LIMIT, next));
            apply(true);
        });
    });
    document.getElementById('nudge-reset').addEventListener('click', function () {
        nudge = { x: 0, y: 0 };
        apply(true);
    });

    document.getElementById('print-all').addEventListener('click', function () {
        document.body.classList.remove('test-print');
        window.print();
    });
    document.getElementById('print-test').addEventListener('click', function () {
        document.body.classList.add('test-print');
        window.print();
    });
    window.addEventListener('afterprint', function () {
        document.body.classList.remove('test-print');
    });

    apply(false);
}());
</script>
</body>
</html>
<?php
// Print mode outputs its own full HTML document; stop layout.php from wrapping it.
exit;
endif; // end printMode
?>

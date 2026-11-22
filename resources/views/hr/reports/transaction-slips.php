<?php
/**
 * View: hr/reports/transaction-slips  (GET /hr/reports/transaction-slips)
 *
 * Generates printable BDO Cash Transaction Slips.
 *
 * Print mode (?print=1) renders a replica of the BDO Network Bank Cash
 * Transaction Slip on screen (form art, class .f) with the fill-in values
 * (class .d) absolutely positioned in mm at coordinates measured from the
 * scanned slip (2000 × 967 px → 210 × 101.5 mm).  When printed, only .d is
 * output — onto the physical, pre-printed slip.  Calibrate a printer with
 * the --nudge-x / --nudge-y CSS variables.  Screen mode (no ?print) shows the
 * filter form + table.
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
        <p>Generate printable BDO Cash Transaction Slips for an approved payroll period.</p>
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
     PRINT MODE — BDO Network Bank Cash Transaction Slip
     Every label, line, box and value is absolutely positioned in mm,
     measured from the scanned BDO slip (2000 × 967 px → 210 × 101.5 mm,
     0.105 mm/px).  Two layers:
       .f  = form art (header, labels, lines, boxes, grid)  — screen only
       .d  = fill-in data (values + ticks)                  — screen + print
     On paper only .d is printed, so it lands on the pre-printed slip.
     Calibrate a printer by changing --nudge-x / --nudge-y only; never
     move individual fields.
     ================================================================ -->
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>BDO Cash Transaction Slips — <?= $e(date('m/d/Y')) ?></title>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
    --bdo-blue:  #0d4fa3;
    --form-ink:  #1b2d55;
    --line:      #24406f;
    --rp-bg:     #e6edf4;
    --nudge-x:   0mm;   /* printer calibration: + moves data right */
    --nudge-y:   0mm;   /* printer calibration: + moves data down  */
}

body {
    font-family: Arial, Helvetica, sans-serif;
    background: #e8e8e8;
    color: #000;
}
.slip {
    font-family: "Arial Narrow", "Roboto Condensed", "Helvetica Neue", Arial, sans-serif;
    font-stretch: condensed;
}

/* ── Screen toolbar ─────────────────────────── */
.no-print {
    background: #1a3a60;
    color: #f0f6ff;
    padding: 7px 14px;
    display: flex;
    align-items: center;
    gap: 10px;
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
    padding: 5px 16px;
    font-size: 12px;
    cursor: pointer;
    font-weight: 700;
}
.no-print a { color: #93c5fd; font-size: 11px; text-decoration: none; }

/* ── Slip canvas: 210mm × 101.5mm, true BDO slip aspect ratio ── */
.slip {
    position: relative;
    width: 210mm;
    height: 101.5mm;
    margin: 8mm auto;
    background: #fff;
    box-shadow: 0 1px 4px rgba(0, 0, 0, .25);
    overflow: hidden;
    page-break-after: always;
    page-break-inside: avoid;
}
.slip:last-child { page-break-after: auto; }
.slip > * { position: absolute; }

/* ── Form art (.f) ──────────────────────────── */
.hdr   { left: 0; top: 0; width: 123.4mm; height: 12.1mm; background: var(--bdo-blue); }
.rpbg  { left: 123.4mm; top: 0; width: 86.6mm; height: 101.5mm; background: var(--rp-bg); }
.logo  { left: 3mm; top: 2.4mm; font-size: 22pt; font-weight: 900; color: #fff; letter-spacing: -.04em; line-height: 1; }
.net   { left: 22mm; top: 4.6mm; font-size: 12pt; font-weight: 700; color: #fff; line-height: 1; }
.ttl   { left: 80.3mm; top: 4.0mm; font-size: 13pt; font-weight: 700; color: #fff; line-height: 1; }

.lbl   { font-size: 7pt; color: var(--form-ink); line-height: 1.12; white-space: nowrap; }
.lbl.sm { font-size: 6.3pt; letter-spacing: -.01em; }
.lbl.xs { font-size: 5.6pt; font-weight: 700; letter-spacing: -.02em; }
.lbl.ft { font-size: 6pt; font-style: italic; letter-spacing: -.01em; }
.lbl.b  { font-weight: 700; }

.hl    { height: 0; border-top: .7pt solid var(--line); }               /* horizontal rule */
.vl    { width: 0;  border-left: .7pt solid var(--line); }              /* vertical rule   */
.tk    { width: 0;  height: 1.2mm; border-left: .7pt solid var(--line); } /* code tick       */
.bx    { width: 2.4mm; height: 2.4mm; border: .8pt solid var(--form-ink); background: #fff; }

/* ── Data (.d) ──────────────────────────────── */
.d     { font-weight: 700; color: #000; white-space: nowrap; overflow: hidden; line-height: 4.4mm; height: 4.4mm;
         transform: translate(var(--nudge-x), var(--nudge-y)); }
.d.v   { font-size: 9pt; }
.d.vs  { font-size: 8pt; }
.d.chk { width: 2.4mm; height: 2.4mm; line-height: 2.4mm; text-align: center; font-size: 8pt; }
.d.row { height: 8.1mm; line-height: 8.1mm; font-size: 9pt; }
.d.tot { height: 8.2mm; line-height: 8.2mm; font-size: 11pt; text-align: right; }
.r     { text-align: right; }
.c     { text-align: center; }

/* ── Print: data only, onto the pre-printed slip ── */
@media print {
    @page      { size: A5 landscape; margin: 0; }
    .no-print  { display: none !important; }
    body       { background: #fff; }
    .slip      { margin: 0; box-shadow: none; background: transparent; }
    .f         { visibility: hidden !important; }
    .d         { color: #000 !important; }
}
</style>
</head>
<body>

<div class="no-print">
    <button onclick="window.print()"><?= \Wbpms\Http\View\Icon::svg('printer') ?> Print All Slips (<?= count($slips) ?>)</button>
    <a href="<?= $e($base) ?>/hr/reports/transaction-slips"><?= \Wbpms\Http\View\Icon::svg('chevron-left') ?> Back to Transaction Slips</a>
    <span style="margin-left:auto;opacity:.75">
        Preview shows the full BDO slip &nbsp;·&nbsp; printing outputs data only (for pre-printed slips)
        &nbsp;·&nbsp; <?= count($slips) ?> slip(s) &nbsp;·&nbsp; <?= $e(date('m/d/Y')) ?>
    </span>
</div>

<?php foreach ($slips as $slip):
    $accountName = !empty($slip['account_name'])   ? (string) $slip['account_name']   : '';
    $accountNo   = !empty($slip['account_number']) ? (string) $slip['account_number'] : '';
    $accountType = !empty($slip['account_type'])   ? strtolower((string) $slip['account_type']) : 'savings';
    $isSavings   = $accountType === 'savings';
    $isCurrent   = $accountType === 'current';
    $referenceNo = 'PR-' . date('Ymd', (int) strtotime((string) $slip['period_start']))
                   . '-' . str_pad((string) $slip['employee_id'], 4, '0', STR_PAD_LEFT);
    $netPay      = number_format((float) $slip['net_pay'], 2);
?>
<div class="slip">

    <!-- ===================== FORM ART (screen only) ===================== -->
    <div class="f hdr"></div>
    <div class="f rpbg"></div>
    <div class="f logo">BDO</div>
    <div class="f net">Network Bank</div>
    <div class="f ttl">Cash Transaction Slip</div>

    <!-- Transaction-type checkboxes -->
    <div class="f bx" style="left:3.2mm;top:15.3mm"></div> <div class="f lbl b" style="left:6.3mm;top:15.1mm">Deposits</div>
    <div class="f bx" style="left:6.4mm;top:18.6mm"></div> <div class="f lbl b" style="left:9.4mm;top:18.4mm">Current</div>
    <div class="f bx" style="left:6.4mm;top:21.8mm"></div> <div class="f lbl b" style="left:9.4mm;top:21.6mm">Savings</div>
    <div class="f bx" style="left:6.4mm;top:25.1mm"></div> <div class="f lbl b" style="left:9.4mm;top:24.9mm">Time Deposit /<br>&nbsp;Placement</div>
    <div class="f bx" style="left:9.6mm;top:30.6mm"></div> <div class="f lbl b" style="left:12.6mm;top:30.4mm">For Account with<br>Deposit Reference<br>Facility</div>
    <div class="f bx" style="left:3.2mm;top:39.5mm"></div> <div class="f lbl b" style="left:6.3mm;top:39.3mm">Bills payment</div>
    <div class="f bx" style="left:3.2mm;top:54.5mm"></div> <div class="f lbl b" style="left:6.3mm;top:54.3mm">Payment</div>
    <div class="f bx" style="left:6.6mm;top:57.9mm"></div> <div class="f lbl b" style="left:9.6mm;top:57.7mm">Loan</div>
    <div class="f bx" style="left:16.1mm;top:57.9mm"></div><div class="f lbl b" style="left:19.1mm;top:57.7mm">Trade</div>
    <div class="f lbl xs" style="left:3.6mm;top:62.4mm">Machine Validation</div>

    <!-- Field labels -->
    <div class="f lbl b" style="left:33.8mm;top:15.1mm">Account Name</div>
    <div class="f lbl b" style="left:33.8mm;top:23.5mm">Account No.</div>
    <div class="f lbl b" style="left:33.8mm;top:31.0mm">Payor's Name</div>
    <div class="f lbl b" style="left:77.7mm;top:31.0mm">Reference No.</div>
    <div class="f lbl b" style="left:34.1mm;top:39.2mm">Company Name</div>
    <div class="f lbl b" style="left:77.7mm;top:39.2mm">Institution Code</div>
    <div class="f lbl b" style="left:106.4mm;top:39.2mm">Product Code</div>
    <div class="f lbl b" style="left:34.1mm;top:46.9mm">Subscriber's Name</div>
    <div class="f lbl b" style="left:77.7mm;top:46.9mm">Subscriber's Account No.</div>
    <div class="f lbl b" style="left:34.1mm;top:54.5mm">Borrower's Name</div>
    <div class="f lbl b sm" style="left:77.7mm;top:54.6mm">Promissory Note No. / Trade Reference No.</div>
    <div class="f lbl ft" style="left:42.5mm;top:94.8mm">This serves as your receipt when machine validated.</div>

    <!-- Left-panel rules -->
    <div class="f hl" style="left:33.8mm;top:22.6mm;width:86.4mm"></div>
    <div class="f hl" style="left:33.8mm;top:30.5mm;width:86.4mm"></div>
    <div class="f hl" style="left:3.2mm;top:38.6mm;width:117mm"></div>
    <div class="f hl" style="left:34.1mm;top:46.1mm;width:86.1mm"></div>
    <div class="f hl" style="left:3.2mm;top:53.9mm;width:117mm"></div>
    <div class="f hl" style="left:3.2mm;top:61.4mm;width:117mm"></div>
    <!-- Institution / Product code ticks -->
    <?php foreach ([77.7, 82.4, 87.1, 91.8, 96.5, 106.0, 110.7, 115.4, 120.0] as $tx): ?>
    <div class="f tk" style="left:<?= $tx ?>mm;top:44.9mm"></div>
    <?php endforeach; ?>

    <!-- Right panel: Date + denomination grid -->
    <div class="f lbl b" style="left:126.3mm;top:4.0mm;font-size:8pt">Date</div>
    <div class="f hl" style="left:126.3mm;top:13.7mm;width:79mm"></div>
    <div class="f lbl b" style="left:126.3mm;top:14.8mm;font-size:7.2pt">Use separate slip(s) for each type of transaction.</div>
    <div class="f lbl b c" style="left:126.3mm;top:18.9mm;width:31.9mm">Denomination</div>
    <div class="f lbl b c" style="left:158.2mm;top:18.9mm;width:15.6mm">Pieces</div>
    <div class="f lbl b c" style="left:173.8mm;top:18.9mm;width:31.5mm">Amount</div>
    <?php foreach ([22.1, 30.2, 38.4, 46.7, 55.1, 63.3, 71.6, 79.8, 88.0] as $ry): ?>
    <div class="f hl" style="left:126.3mm;top:<?= $ry ?>mm;width:79mm"></div>
    <?php endforeach; ?>
    <div class="f vl" style="left:158.2mm;top:22.1mm;height:57.7mm"></div>
    <div class="f vl" style="left:173.8mm;top:22.1mm;height:65.9mm"></div>
    <div class="f lbl b r" style="left:140mm;top:82.6mm;width:32.4mm;font-size:8.5pt">Total Amount</div>

    <!-- ===================== DATA (screen + print) ===================== -->
    <div class="d chk" style="left:3.2mm;top:15.3mm">✓</div>
    <?php if ($isCurrent): ?><div class="d chk" style="left:6.4mm;top:18.6mm">✓</div><?php endif; ?>
    <?php if ($isSavings): ?><div class="d chk" style="left:6.4mm;top:21.8mm">✓</div><?php endif; ?>

    <div class="d v"  style="left:34.5mm;top:18.0mm;width:85mm"><?= $e($accountName) ?></div>
    <div class="d v"  style="left:34.5mm;top:25.9mm;width:85mm"><?= $e($accountNo) ?></div>
    <div class="d v"  style="left:34.5mm;top:34.3mm;width:42mm"><?= $e((string) $slip['employee_name']) ?></div>
    <div class="d vs" style="left:78.2mm;top:34.3mm;width:42mm"><?= $e($referenceNo) ?></div>
    <div class="d v"  style="left:34.8mm;top:41.5mm;width:42mm">Light Diamond Enterprises</div>

    <div class="d v"  style="left:131mm;top:9.0mm;width:70mm"><?= $e(date('m/d/Y')) ?></div>

    <div class="d row"   style="left:127.5mm;top:22.1mm;width:30mm">Payroll</div>
    <div class="d row c" style="left:158.2mm;top:22.1mm;width:15.6mm">1</div>
    <div class="d row r" style="left:174.5mm;top:22.1mm;width:30mm">₱ <?= $e($netPay) ?></div>
    <div class="d tot"   style="left:174.5mm;top:79.8mm;width:30mm">₱ <?= $e($netPay) ?></div>

</div><!-- /.slip -->
<?php endforeach; ?>

</body>
</html>
<?php
// Print mode outputs its own full HTML document; stop layout.php from wrapping it.
exit;
endif; // end printMode
?>

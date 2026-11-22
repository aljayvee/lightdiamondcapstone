<?php

declare(strict_types=1);

namespace Wbpms\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Owner/adviser rule 2026-11-22: the system must not reproduce BDO's Cash
 * Transaction Slip. HR gets the pre-printed slips from BDO and the system
 * prints only the details onto them, like a stamp machine. The print page
 * used to draw a copy of the slip on screen (BDO header, logo text, labels,
 * grid), hidden only when printing. [REP-021]
 *
 * The view ends print mode with exit, so these are source checks.
 */
final class TransactionSlipStampPrintTest extends TestCase
{
    private static function view(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/resources/views/hr/reports/transaction-slips.php');
    }

    public function testNoCopyOfTheBankSlipIsDrawn(): void
    {
        $view = self::view();

        self::assertDoesNotMatchRegularExpression('/class="f[\s"]/', $view, 'No form-art layer may be drawn.');
        // The bank's name may appear in instructions, never drawn on the slip.
        $slipBody = substr($view, (int) strpos($view, '<div class="slip">'));
        $slipBody = substr($slipBody, 0, (int) strpos($slipBody, '<!-- /.slip -->'));
        self::assertStringNotContainsString('Network Bank', $slipBody);
        self::assertStringNotContainsString('>BDO<', $slipBody);
        self::assertStringNotContainsString('--bdo-blue', $view);
        self::assertStringNotContainsString('Machine Validation', $view);
        self::assertStringNotContainsString('Use separate slip(s)', $view);
        self::assertStringNotContainsString('This serves as your receipt', $view);
    }

    public function testOnlyDetailsPrint(): void
    {
        $view = self::view();
        $print = substr($view, (int) strpos($view, '@media print'));
        $print = substr($print, 0, (int) strpos($print, '</style>'));

        self::assertStringContainsString('.no-print, .g { display: none !important; }', $print);
        self::assertStringContainsString('.rx           { display: none; }', $print);
        self::assertStringContainsString('@page         { size: A5 landscape; margin: 0; }', $print);
        self::assertStringContainsString('body.test-print .rx           { display: block; }', $print);
    }

    public function testEveryPrintedLayerFollowsThePrinterAlignment(): void
    {
        $view = self::view();

        // Details, guide tags and alignment crosses all move together: the built-in
        // office printer calibration plus HR's fine-tuning.
        self::assertSame(3, substr_count($view, 'transform: translate(calc(var(--cal-x) + var(--nudge-x)), calc(var(--cal-y) + var(--nudge-y)));'));
        // Measured 2026-11-22 from HR's printed slip (printed at +1.0 / +1.0 mm:
        // details landed 3.1-3.7 mm left and 0.1-1.1 mm low).
        self::assertStringContainsString('--cal-x:   3.4mm;', $view);
        self::assertStringContainsString('--cal-y:   -0.5mm;', $view);
        self::assertStringContainsString('--nudge-x: 0mm;', $view);
        self::assertStringContainsString('--nudge-y: 0mm;', $view);
        // New key: fine-tuning saved before the calibration (+1.0 / +1.0) is not stacked on it.
        self::assertStringContainsString("var KEY = 'wbpms.slipNudge.v2';", $view);
        self::assertStringNotContainsString("var KEY = 'wbpms.slipNudge';", $view);
        self::assertStringContainsString('var LIMIT = 15;', $view);
        // Browser storage can be blocked: reads and writes are guarded.
        self::assertSame(2, substr_count($view, 'catch (err)'));
    }

    /**
     * Positions measured on a 400 dpi scan of a blank BDO slip (212.8 x 100 mm):
     * each value sits just above its printed line, ticks inside the boxes, the
     * amounts inside the Amount column. See docs/bdo-transaction-slip-layout.md.
     */
    public function testDetailPositionsMatchTheMeasuredSlip(): void
    {
        $view = self::view();

        self::assertMatchesRegularExpression('/width: 212\.8mm;\s+height: 100mm;/', $view);
        foreach ([
            'left:2.59mm;top:14.03mm',              // Deposits tick (box 3.24; glyph ink sits 0.65 mm right)
            'left:5.76mm;top:17.27mm',              // Current tick (box 6.41)
            'left:5.76mm;top:20.57mm',              // Savings tick (box 6.41)
            'left:35.5mm;top:17.18mm;width:85.5mm', // account name (line at 21.78)
            'left:35.5mm;top:24.86mm;width:85.5mm', // account number (line at 29.46)
            'left:176.6mm;top:78.93mm;width:31mm',  // total row (78.93-87.19)
        ] as $position) {
            self::assertStringContainsString($position, $view);
        }
        // HR 2026-11-22: Payor's Name, Reference No. and Company Name stay blank;
        // the amounts are centred in the Amount column, not against its edge.
        self::assertStringNotContainsString('$referenceNo', $view);
        $slipBody = substr($view, (int) strpos($view, '<div class="slip">'));
        self::assertStringNotContainsString("\$slip['employee_name']", $slipBody);
        self::assertStringNotContainsString('>Light Diamond Enterprises</div>', $view);
        self::assertStringContainsString('.d.tot { height: 8.26mm; line-height: 8.26mm; font-size: 11pt; text-align: center; }', $view);
        // HR 2026-11-22: only ticks, Account Name, Account No., Date and Total Amount print.
        self::assertSame(1, substr_count($slipBody, '<div class="d tot"'));
        self::assertSame(3, substr_count($slipBody, '<div class="d v"'));    // account name, number, date
        self::assertSame(0, substr_count($slipBody, '<div class="d row'));   // no Payroll / 1 / row amount
        self::assertStringContainsString('left:132.7mm;top:8.16mm;width:70mm"><?= $e(date(\'m/d/Y\')) ?></div>', $slipBody); // date above its line
        // Four alignment crosses, centred on line corners of the slip.
        self::assertSame(4, substr_count($view, '<div class="rx" style="'));
    }
}

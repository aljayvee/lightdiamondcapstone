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
        self::assertStringNotContainsString('Network Bank', $view);
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

        // Details, guide tags and alignment crosses all move together.
        self::assertSame(3, substr_count($view, 'transform: translate(var(--nudge-x), var(--nudge-y));'));
        self::assertStringContainsString("var KEY = 'wbpms.slipNudge';", $view);
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
            'left:3.24mm;top:14.03mm',              // Deposits tick
            'left:6.41mm;top:17.27mm',              // Current tick
            'left:6.41mm;top:20.57mm',              // Savings tick
            'left:35.5mm;top:17.18mm;width:85.5mm', // account name (line at 21.78)
            'left:35.5mm;top:24.86mm;width:85.5mm', // account number (line at 29.46)
            'left:35.5mm;top:33.32mm;width:42.5mm', // payor (line at 37.62)
            'left:79.2mm;top:33.32mm;width:42mm',   // reference
            'left:35.5mm;top:40.71mm;width:42.5mm', // company (line at 45.31)
            'left:132.7mm;top:8.16mm;width:70mm',   // date (line at 12.86)
            'left:129.2mm;top:21.02mm;width:30mm',  // description row
            'left:160.37mm;top:21.02mm;width:15.5mm', // pieces column (160.37-175.9)
            'left:176.6mm;top:21.02mm;width:31mm',  // amount column (175.9-208.3)
            'left:176.6mm;top:78.93mm;width:31mm',  // total row (78.93-87.19)
        ] as $position) {
            self::assertStringContainsString($position, $view);
        }
        // Four alignment crosses, centred on line corners of the slip.
        self::assertSame(4, substr_count($view, '<div class="rx" style="'));
    }
}

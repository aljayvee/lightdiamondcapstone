<?php

declare(strict_types=1);

namespace Wbpms\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Wbpms\Domain\Payroll\PayrollMonth;

/**
 * Bug 2026-11-22: Settings → Payroll Periods → Generate for another month
 * (e.g. August 2025) came back showing November 2026, so HR thought the
 * month was changed back. The periods were created; the page just lost the
 * month. The month now stays in the URL. [PAY-033]
 */
final class PayrollMonthTest extends TestCase
{
    /** @return array<string, array{mixed, ?string}> */
    public static function parseCases(): array
    {
        return [
            'valid past month'    => ['2025-08', '2025-08'],
            'trimmed'             => [' 2026-11 ', '2026-11'],
            'month 13'            => ['2025-13', null],
            'month 00'            => ['2025-00', null],
            'full date'           => ['2025-08-01', null],
            'words'               => ['August 2025', null],
            'too old'             => ['1999-12', null],
            'not a string'        => [['2025-08'], null],
            'missing'             => [null, null],
            'script injection'    => ['2025-08"><script>', null],
        ];
    }

    #[DataProvider('parseCases')]
    public function testParse(mixed $input, ?string $expected): void
    {
        self::assertSame($expected, PayrollMonth::parse($input));
    }

    public function testLabel(): void
    {
        self::assertSame('August 2025', PayrollMonth::label('2025-08'));
        self::assertSame('November 2026', PayrollMonth::label('2026-11'));
    }

    /** @return array<string, array{string, string, string, bool}> */
    public static function overlapCases(): array
    {
        return [
            'inside the month'               => ['2025-08', '2025-08-08', '2025-08-14', true],
            'starts in July, ends in August' => ['2025-08', '2025-07-25', '2025-07-31', false],
            'crosses into August'            => ['2025-08', '2025-07-31', '2025-08-06', true],
            'crosses out of August'          => ['2025-08', '2025-08-29', '2025-09-04', true],
            'next month'                     => ['2025-08', '2025-09-05', '2025-09-11', false],
            'same month a year later'        => ['2025-08', '2026-08-07', '2026-08-13', false],
        ];
    }

    #[DataProvider('overlapCases')]
    public function testOverlaps(string $month, string $start, string $end, bool $expected): void
    {
        self::assertSame($expected, PayrollMonth::overlaps($month, $start, $end));
    }

    public function testFilterKeepsOnlyThatMonthsCutoffsInOrder(): void
    {
        $periods = [
            ['payroll_period_id' => 50, 'period_start' => '2026-11-20', 'period_end' => '2026-11-26'],
            ['payroll_period_id' => 45, 'period_start' => '2025-08-29', 'period_end' => '2025-09-04'],
            ['payroll_period_id' => 41, 'period_start' => '2025-08-01', 'period_end' => '2025-08-07'],
            ['payroll_period_id' => 30, 'period_start' => '2025-07-25', 'period_end' => '2025-07-31'],
        ];

        self::assertSame([45, 41], array_column(PayrollMonth::filter('2025-08', $periods), 'payroll_period_id'));
        self::assertSame([], PayrollMonth::filter('2024-01', $periods));
    }

    /** Guards the wiring a unit test cannot run without a database. */
    public function testGenerateReturnsToTheGeneratedMonth(): void
    {
        $root = dirname(__DIR__, 2);
        $settings = (string) file_get_contents($root . '/app/Http/Controllers/SettingsController.php');
        self::assertStringContainsString("\$this->redirect('/hr/settings/periods?month=' . rawurlencode(\$month));", $settings);
        self::assertStringContainsString("'payroll_month' => \$month ?? date('Y-m'),", $settings);

        $view = (string) file_get_contents($root . '/resources/views/hr/settings/index.php');
        self::assertStringContainsString('PayrollMonth::filter($focus_month, $periods)', $view);
        self::assertStringContainsString('/hr/payroll/create?month=', $view);

        $payroll = (string) file_get_contents($root . '/app/Http/Controllers/PayrollController.php');
        self::assertStringContainsString("'selectedMonth'    => PayrollMonth::parse(\$_GET['month'] ?? null),", $payroll);
        self::assertStringContainsString("'selectedPeriodId' => \$data['payroll_period_id'],", $payroll);
    }
}

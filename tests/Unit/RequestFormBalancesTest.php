<?php

declare(strict_types=1);

namespace Wbpms\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Wbpms\Domain\Requests\RequestFormBalances;

/**
 * HR Head New Request form shows the chosen employee's sick-leave credit and
 * unpaid cash advance, the same figures the employee sees on My Requests.
 * [REQ-025]
 */
final class RequestFormBalancesTest extends TestCase
{
    public function testYearsAreLastThisAndEveryYearOnFile(): void
    {
        self::assertSame([2024, 2025, 2026], RequestFormBalances::years(2026, [2025, 2024, 2026]));
        self::assertSame([2025, 2026], RequestFormBalances::years(2026, []));
        self::assertSame([2025, 2026], RequestFormBalances::years(2026, [0, 1999, 3000]), 'nonsense years are ignored');
    }

    public function testPayloadKeepsEachYearsBalanceAndOnlyUnpaidAdvances(): void
    {
        $payload = RequestFormBalances::payload(2026, [
            2025 => [['employee_id' => 10, 'entitled_days' => 4.0, 'balance' => 2.0]],
            2026 => [['employee_id' => 10, 'entitled_days' => 4.0, 'balance' => 3.505]],
        ], [10 => 1500.0, 11 => 0.0, 12 => 0.004]);

        self::assertSame(['balance' => 2.0, 'entitled' => 4.0], RequestFormBalances::leaveFor($payload, 10, 2025));
        self::assertSame(['balance' => 3.51, 'entitled' => 4.0], RequestFormBalances::leaveFor($payload, 10, 2026));
        self::assertSame([10 => 1500.0], $payload['cashAdvance'], 'paid-off advances are not listed');
        self::assertSame(2000.0, $payload['cashAdvanceMax']);
        self::assertSame(2026, $payload['currentYear']);
    }

    public function testNoCreditOnFileShowsTheFullGrant(): void
    {
        $payload = RequestFormBalances::payload(2026, [], []);

        self::assertSame(['balance' => 4.0, 'entitled' => 4.0], RequestFormBalances::leaveFor($payload, 99, 2025));
    }

    public function testFormRendersThePanelWithTheFiguresForTheScript(): void
    {
        $html = self::render(RequestFormBalances::payload(2026, [
            2025 => [['employee_id' => 13, 'entitled_days' => 4.0, 'balance' => 2.0]],
        ], [13 => 500.0]));

        self::assertStringContainsString('id="balance-panel"', $html);
        self::assertStringContainsString('id="bal-leave-left"', $html);
        self::assertStringContainsString('id="bal-ca-out"', $html);
        self::assertStringContainsString('Max: ₱2,000.00 per request', $html);
        // Figures are embedded as one JSON object the script reads by year and employee.
        self::assertMatchesRegularExpression('/var data\s+= \{"currentYear":2026,/', $html);
        self::assertStringContainsString('"leave":{"2025":{"13":{"balance":2,"entitled":4}}}', $html);
        self::assertStringContainsString('"cashAdvance":{"13":500}', $html);
        // The panel stays hidden until an employee is picked.
        self::assertStringContainsString('<div id="balance-panel" aria-live="polite"', $html);
        self::assertMatchesRegularExpression('/id="balance-panel"[^>]*style="display:none;/', $html);
    }

    public function testEmptyFiguresStillRenderAsObjects(): void
    {
        $html = self::render(RequestFormBalances::payload(2026, [], []));

        self::assertStringContainsString('"leave":{},"cashAdvance":{}', $html, 'empty maps must be JSON objects, not arrays');
    }

    /** @param array<string,mixed> $balances */
    private static function render(array $balances): string
    {
        $employees = [['id' => 13, 'employee_number' => 'EMP-013', 'last_name' => 'Cañete', 'first_name' => 'Jayvan', 'branch_name' => 'Banga']];
        $types     = [['request_type_id' => 1, 'type_name' => 'Leave']];
        $errors    = [];
        $old       = [];
        $base      = '';
        $csrf      = 't';

        ob_start();
        include dirname(__DIR__, 2) . '/resources/views/hr/requests/form.php';

        return (string) ob_get_clean();
    }
}

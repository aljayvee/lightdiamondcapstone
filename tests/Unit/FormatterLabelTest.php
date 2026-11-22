<?php

declare(strict_types=1);

namespace Wbpms\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Wbpms\Http\View\Formatter;

/**
 * Stored codes ("CashAdvance", "HRHead", "SpecialNonWorking") used to reach
 * the screen as-is. Formatter::label() turns them into words.
 */
final class FormatterLabelTest extends TestCase
{
    /** @return iterable<string, array{?string, string}> */
    public static function codes(): iterable
    {
        yield 'role hr'           => ['HRHead', 'HR Head'];
        yield 'role owner'        => ['BusinessOwner', 'Business Owner'];
        yield 'request type'      => ['CashAdvance', 'Cash Advance'];
        yield 'holiday type'      => ['SpecialNonWorking', 'Special Non-Working'];
        yield 'contribution'      => ['PagIBIG', 'Pag-IBIG'];
        yield 'camel case'        => ['ReviewRequired', 'Review Required'];
        yield 'snake case'        => ['manual_adjustment', 'Manual Adjustment'];
        yield 'plain word'        => ['Leave', 'Leave'];
        yield 'already readable'  => ['Overtime', 'Overtime'];
        yield 'empty'             => ['', '—'];
        yield 'null'              => [null, '—'];
    }

    #[DataProvider('codes')]
    public function testLabel(?string $code, string $expected): void
    {
        self::assertSame($expected, Formatter::label($code));
    }
}

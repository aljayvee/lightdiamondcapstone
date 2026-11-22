<?php

declare(strict_types=1);

namespace Wbpms\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Wbpms\Http\View\Formatter;

/**
 * Payroll run timestamps are stored in UTC (PayrollService::utcNow()) and
 * must be shown in Manila time, so an approval at 01:34 Manila on 11/21 does
 * not read as 11/20.
 */
final class FormatterUtcDateTimeTest extends TestCase
{
    /** @return array<string, array{?string, string}> */
    public static function cases(): array
    {
        return [
            'evening UTC is next day in Manila' => ['2026-11-20 17:34:59', '11/21/26 01:34'],
            'morning UTC same day'              => ['2026-11-21 01:12:58', '11/21/26 09:12'],
            'year boundary'                     => ['2026-12-31 16:00:00', '01/01/27 00:00'],
            'null'                              => [null, '—'],
            'empty'                             => ['', '—'],
        ];
    }

    #[DataProvider('cases')]
    public function testConvertsUtcToManila(?string $stored, string $expected): void
    {
        $this->assertSame($expected, Formatter::utcDateTime($stored));
    }

    public function testDoesNotDependOnPhpDefaultTimezone(): void
    {
        $previous = date_default_timezone_get();
        date_default_timezone_set('America/New_York');
        try {
            $this->assertSame('11/21/26 01:34', Formatter::utcDateTime('2026-11-20 17:34:59'));
        } finally {
            date_default_timezone_set($previous);
        }
    }
}

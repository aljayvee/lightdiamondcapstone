<?php

declare(strict_types=1);

namespace Wbpms\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The test-data seeder must produce values HR could also type into the
 * Add/Edit Employee and Bank Account forms (same rules as
 * EmployeeController::validate() and ::saveBank()), unique per employee and
 * clearly fake (99 / 9999 prefixes). [EMP-025]
 */
final class EmployeeTestIdsAndBankSeederTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2) . '/database/seeds/EmployeeTestIdsAndBankSeeder.php';
    }

    #[Test]
    public function governmentIdsPassTheEmployeeFormRules(): void
    {
        foreach ([1, 15, 37, 999, 12_345, 9_999_999] as $id) {
            $ids = \EmployeeTestIdsAndBankSeeder::testGovernmentIds($id);

            self::assertMatchesRegularExpression('/^\d{10}$/', str_replace('-', '', $ids['sss_number']));
            self::assertLessThanOrEqual(13, mb_strlen($ids['sss_number']));
            self::assertMatchesRegularExpression('/^\d{2}-?\d{7}-?\d$/', $ids['sss_number']);

            self::assertMatchesRegularExpression('/^\d{12}$/', str_replace(['-', ' '], '', $ids['philhealth_number']));
            self::assertLessThanOrEqual(15, mb_strlen($ids['philhealth_number']));

            self::assertMatchesRegularExpression('/^\d{12}$/', str_replace(['-', ' '], '', $ids['pagibig_number']));
            self::assertLessThanOrEqual(15, mb_strlen($ids['pagibig_number']));

            self::assertMatchesRegularExpression('/^\d{9}(\d{3})?$/', str_replace('-', '', $ids['tin_number']));
            self::assertLessThanOrEqual(16, mb_strlen($ids['tin_number']));
            self::assertMatchesRegularExpression('/^\d{3}-?\d{3}-?\d{3}(-?\d{3})?$/', $ids['tin_number']);

            self::assertStringStartsWith('99-', $ids['sss_number']);
            self::assertStringStartsWith('9999-', $ids['pagibig_number']);
        }
    }

    #[Test]
    public function valuesAreUniquePerEmployee(): void
    {
        $seen = ['sss_number' => [], 'philhealth_number' => [], 'pagibig_number' => [], 'tin_number' => [], 'account' => []];

        for ($id = 1; $id <= 2_000; $id++) {
            foreach (\EmployeeTestIdsAndBankSeeder::testGovernmentIds($id) as $key => $value) {
                $seen[$key][$value] = true;
            }
            $seen['account'][\EmployeeTestIdsAndBankSeeder::testAccountNumber($id)] = true;
        }

        foreach ($seen as $key => $values) {
            self::assertCount(2_000, $values, $key . ' repeats for different employees');
        }
    }

    #[Test]
    public function accountNumberPassesTheBankFormRule(): void
    {
        $number = \EmployeeTestIdsAndBankSeeder::testAccountNumber(15);

        self::assertSame('999900000015', $number);
        self::assertMatchesRegularExpression('/^[\d\-\s]+$/', $number);
        self::assertLessThanOrEqual(50, strlen($number));
    }
}

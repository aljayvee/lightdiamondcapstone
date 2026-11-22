<?php

declare(strict_types=1);

use Phinx\Seed\AbstractSeed;

/**
 * Seeder: 2026 Philippine Public Holidays
 *
 * Seeds the `holiday_calendar` table with official Regular and Special
 * Non-Working holidays for 2026 per Proclamation No. 1006 (s. 2025), plus
 * Eid'l Fitr (Proclamation No. 1189, s. 2026) and Eid'l Adha (Proclamation
 * No. 1264, s. 2026), which are proclaimed separately each year.
 *
 * ⚠  ADVISORY NOTICE ⚠
 * Presidential proclamations may add, move, or bridge holidays during the year.
 * HR must review and update this list against the official Official Gazette
 * before the first 2026 payroll run. This seeder provides the best-known
 * baseline at the time of development.
 *
 * Holiday types and multipliers enforced by DB CHECK constraint:
 *   Regular holiday           → pay_multiplier = 2.00 (worked: 200 %; absent: 100 %)
 *   Special (Working)         → pay_multiplier = 1.00 (worked: 100 %; absent:   0 %)
 *   SpecialNonWorking holiday → pay_multiplier = 1.30 (worked: 130 %; absent:   0 %)
 *
 * REQ047 | ADR-0001 §Holiday Pay
 */
class HolidayCalendar2026Seeder extends AbstractSeed
{
    /**
     * The 2026 Philippine holiday list.
     *
     * Each entry: [YYYY-MM-DD, 'Description', 'Regular'|'Special'|'SpecialNonWorking', multiplier]
     *
     * @return list<array{0:string,1:string,2:string,3:float}>
     */
    private function holidays(): array
    {
        return [
            // ---------------------------------------------------------------
            // REGULAR HOLIDAYS (Proclamation 1006 s.2025)
            // ---------------------------------------------------------------
            ['2026-01-01', "New Year's Day",                          'Regular', 2.00],
            ['2026-04-02', 'Maundy Thursday',                         'Regular', 2.00],
            ['2026-04-03', 'Good Friday',                             'Regular', 2.00],
            ['2026-04-09', 'Araw ng Kagitingan (Day of Valor)',       'Regular', 2.00],
            ['2026-05-01', 'Labor Day',                               'Regular', 2.00],
            ['2026-06-12', 'Independence Day',                        'Regular', 2.00],
            ['2026-08-31', 'National Heroes Day',                     'Regular', 2.00],
            ['2026-11-30', 'Bonifacio Day',                           'Regular', 2.00],
            ['2026-12-25', 'Christmas Day',                           'Regular', 2.00],
            ['2026-12-30', 'Rizal Day',                               'Regular', 2.00],

            // Islamic holidays, proclaimed separately once the dates are set.
            ['2026-03-20', "Eid'l Fitr",                              'Regular', 2.00], // Proc. 1189 s.2026
            ['2026-05-27', "Eid'l Adha",                              'Regular', 2.00], // Proc. 1264 s.2026

            // ---------------------------------------------------------------
            // SPECIAL (NON-WORKING) DAYS (Proclamation 1006 s.2025)
            // Jan 2 and Oct 31 are not declared for 2026.
            // ---------------------------------------------------------------
            ['2026-02-17', 'Chinese New Year',                           'SpecialNonWorking', 1.30],
            ['2026-04-04', 'Black Saturday',                             'SpecialNonWorking', 1.30],
            ['2026-08-21', 'Ninoy Aquino Day',                          'SpecialNonWorking', 1.30],
            ['2026-11-01', "All Saints' Day",                            'SpecialNonWorking', 1.30],
            ['2026-11-02', "All Souls' Day",                             'SpecialNonWorking', 1.30],
            ['2026-12-08', 'Feast of the Immaculate Conception',        'SpecialNonWorking', 1.30],
            ['2026-12-24', 'Christmas Eve',                              'SpecialNonWorking', 1.30],
            ['2026-12-31', 'Last Day of the Year',                       'SpecialNonWorking', 1.30],

            // ---------------------------------------------------------------
            // SPECIAL (WORKING) DAY (Proclamation 1006 s.2025): ordinary pay
            // ---------------------------------------------------------------
            ['2026-02-25', 'EDSA People Power Revolution Anniversary',   'Special', 1.00],
        ];
    }

    public function run(): void
    {
        $now = date('Y-m-d H:i:s');

        foreach ($this->holidays() as [$date, $description, $type, $multiplier]) {
            // Idempotent: skip if this date already exists (unique index on holiday_date)
            $exists = $this->fetchRow(
                "SELECT holiday_id FROM holiday_calendar WHERE holiday_date = '{$date}'"
            );

            if ($exists !== false) {
                continue;
            }

            // Escape description for safe inline insertion
            $safeDescription = str_replace("'", "\\'", $description);

            $this->execute("
                INSERT INTO holiday_calendar
                    (holiday_date, description, holiday_type, pay_multiplier, status, created_at, updated_at)
                VALUES
                    ('{$date}', '{$safeDescription}', '{$type}', {$multiplier}, 'Active', '{$now}', '{$now}')
            ");
        }
    }
}

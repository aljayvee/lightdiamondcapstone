<?php

declare(strict_types=1);

use Phinx\Seed\AbstractSeed;

/**
 * Seeder: 2025 Philippine Public Holidays
 *
 * Needed because payroll is computed for past 2025 weeks from old biometric
 * data. With no 2025 rows, Aug 21, 2025 (Ninoy Aquino Day) was paid as an
 * ordinary day in payroll run 35 (found 2026-11-22). [PAY-034]
 *
 * Sources (read 2026-11-22):
 *   - Proclamation No. 727, s. 2024 — regular holidays, special (non-working)
 *     days, additional special (non-working) days and the special (working)
 *     day for 2025 (lawphil.net/executive/proc/proc2024/proc_727_2024.html).
 *   - Proclamation No. 729, s. 2024 — 27 July 2025, Iglesia ni Cristo
 *     founding anniversary, special (non-working) day.
 *   - Proclamation No. 839, s. 2025 — 1 April 2025, Eid'l Fitr, regular.
 *   - Proclamation No. 878, s. 2025 — 12 May 2025, national and local
 *     elections, special (non-working) day.
 *   - Proclamation No. 911, s. 2025 — 6 June 2025, Eid'l Adha, regular.
 *
 * Local (provincial / municipal) special days are not included; HR adds them
 * in Settings → Holidays if the branch observes them.
 *
 * Holiday types and multipliers (DB CHECK constraint):
 *   Regular holiday           → 2.00 (worked: 200 %; absent: 100 %)
 *   Special (Working)         → 1.00 (worked: 100 %; absent:   0 %)
 *   SpecialNonWorking holiday → 1.30 (worked: 130 %; absent:   0 %)
 *
 * Idempotent: a date that already exists is left alone.
 * REQ047 | ADR-0001 §Holiday Pay
 */
class HolidayCalendar2025Seeder extends AbstractSeed
{
    /**
     * Each entry: [YYYY-MM-DD, 'Description', 'Regular'|'Special'|'SpecialNonWorking', multiplier]
     *
     * @return list<array{0:string,1:string,2:string,3:float}>
     */
    public static function holidays(): array
    {
        return [
            // ---------------------------------------------------------------
            // REGULAR HOLIDAYS (Proclamation 727 s.2024)
            // ---------------------------------------------------------------
            ['2025-01-01', "New Year's Day",                          'Regular', 2.00],
            ['2025-04-09', 'Araw ng Kagitingan (Day of Valor)',       'Regular', 2.00],
            ['2025-04-17', 'Maundy Thursday',                         'Regular', 2.00],
            ['2025-04-18', 'Good Friday',                             'Regular', 2.00],
            ['2025-05-01', 'Labor Day',                               'Regular', 2.00],
            ['2025-06-12', 'Independence Day',                        'Regular', 2.00],
            ['2025-08-25', 'National Heroes Day',                     'Regular', 2.00],
            ['2025-11-30', 'Bonifacio Day',                           'Regular', 2.00],
            ['2025-12-25', 'Christmas Day',                           'Regular', 2.00],
            ['2025-12-30', 'Rizal Day',                               'Regular', 2.00],

            // Islamic holidays, proclaimed separately.
            ['2025-04-01', "Eid'l Fitr",                              'Regular', 2.00], // Proc. 839 s.2025
            ['2025-06-06', "Eid'l Adha",                              'Regular', 2.00], // Proc. 911 s.2025

            // ---------------------------------------------------------------
            // SPECIAL (NON-WORKING) DAYS (Proclamation 727 s.2024)
            // ---------------------------------------------------------------
            ['2025-08-21', 'Ninoy Aquino Day',                        'SpecialNonWorking', 1.30],
            ['2025-11-01', "All Saints' Day",                         'SpecialNonWorking', 1.30],
            ['2025-12-08', 'Feast of the Immaculate Conception',      'SpecialNonWorking', 1.30],
            ['2025-12-31', 'Last Day of the Year',                    'SpecialNonWorking', 1.30],
            // Additional special (non-working) days, same proclamation.
            ['2025-01-29', 'Chinese New Year',                        'SpecialNonWorking', 1.30],
            ['2025-04-19', 'Black Saturday',                          'SpecialNonWorking', 1.30],
            ['2025-10-31', "All Saints' Day Eve",                     'SpecialNonWorking', 1.30],
            ['2025-12-24', 'Christmas Eve',                           'SpecialNonWorking', 1.30],
            // Declared by separate proclamations.
            ['2025-05-12', 'National and Local Elections',            'SpecialNonWorking', 1.30], // Proc. 878 s.2025
            ['2025-07-27', 'Iglesia ni Cristo Founding Anniversary',  'SpecialNonWorking', 1.30], // Proc. 729 s.2024

            // ---------------------------------------------------------------
            // SPECIAL (WORKING) DAY (Proclamation 727 s.2024): ordinary pay
            // ---------------------------------------------------------------
            ['2025-02-25', 'EDSA People Power Revolution Anniversary', 'Special', 1.00],
        ];
    }

    public function run(): void
    {
        $now = date('Y-m-d H:i:s');

        foreach (self::holidays() as [$date, $description, $type, $multiplier]) {
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

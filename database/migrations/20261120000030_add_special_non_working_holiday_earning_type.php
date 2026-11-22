<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Migration: Add 'SpecialNonWorkingHoliday' to payroll_earnings.earning_type ENUM.
 *
 * Root cause:
 *   Migration 20260912000017 added 'SpecialNonWorking' to holiday_calendar.holiday_type
 *   and HolidayPayCalculator::earningType() returns 'SpecialNonWorkingHoliday' for it,
 *   but payroll_earnings.earning_type was never extended beyond:
 *     ENUM('Basic','Overtime','RegularHoliday','SpecialHoliday','ThirteenthMonth','ManualAdjustment')
 *
 *   Computing any payroll run that includes a worked Special Non-Working holiday
 *   (e.g. All Saints' Day Nov 1, Feast of the Immaculate Conception Dec 8,
 *   Christmas Eve Dec 24, Ninoy Aquino Day Aug 21) failed on strict MySQL/MariaDB
 *   with SQLSTATE[01000] Warning 1265: Data truncated for column 'earning_type'.
 *
 * Idempotent: checks COLUMN_TYPE before altering.
 */
final class AddSpecialNonWorkingHolidayEarningType extends AbstractMigration
{
    public function up(): void
    {
        $row = $this->fetchRow(
            "SELECT COLUMN_TYPE
               FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'payroll_earnings'
                AND COLUMN_NAME = 'earning_type'"
        );
        if ($row !== false && str_contains((string) $row['COLUMN_TYPE'], 'SpecialNonWorkingHoliday')) {
            return;
        }

        $this->execute(
            "ALTER TABLE `payroll_earnings`
             MODIFY COLUMN `earning_type`
                ENUM('Basic','Overtime','RegularHoliday','SpecialHoliday','SpecialNonWorkingHoliday','ThirteenthMonth','ManualAdjustment')
                NOT NULL"
        );
    }

    public function down(): void
    {
        // Forward-only migration: removing 'SpecialNonWorkingHoliday' would truncate
        // existing holiday earning rows.
    }
}

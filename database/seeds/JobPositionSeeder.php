<?php

declare(strict_types=1);

use Phinx\Seed\AbstractSeed;

/**
 * JobPositionSeeder — pre-loads all known job positions for Light Diamond Enterprises.
 *
 * Source: Capstone 1 Final Submission — Project Context (page 2–3):
 *   Banga branch  : Store In-charge, Sales Clerk, Cashier, Checker, Encoder,
 *                   Driver, Laborer, Secretary, HR Head, Warehouse In-charge
 *   Surallah branch: Sales Clerk, Cashier, Checker, Encoder
 *
 * Additional standard positions added for completeness:
 *   Business Owner, Accounting Staff
 *
 * Run order: no dependencies (no FK to other tables).
 * Idempotent: skips rows where position_title already exists.
 */
class JobPositionSeeder extends AbstractSeed
{
    public function run(): void
    {
        $now = date('Y-m-d H:i:s');

        // [position_title, department, sort_order, default_daily_rate, requires_regular]
        $positions = [
            // Management / Admin
            ['Business Owner',      'Management',   1,  1000.00, 1],
            ['Store In-charge',     'Management',   2,   650.00, 1],
            ['HR Head',             'Admin',        3,   700.00, 1],
            ['Secretary',           'Admin',        4,   500.00, 0],
            ['Accounting Staff',    'Admin',        5,   550.00, 1],

            // Operations / Sales
            ['Sales Clerk',         'Sales',        10,  476.00, 0],
            ['Cashier',             'Sales',        11,  480.00, 0],
            ['Checker',             'Operations',   12,  480.00, 0],
            ['Encoder',             'Operations',   13,  480.00, 0],

            // Warehouse / Logistics
            ['Warehouse In-charge', 'Warehouse',    20,  550.00, 1],
            ['Driver',              'Logistics',    21,  500.00, 0],
            ['Laborer',             'Warehouse',    22,  476.00, 0],
        ];

        foreach ($positions as [$title, $dept, $sort, $defaultRate, $reqReg]) {
            $exists = $this->fetchRow(
                "SELECT position_id FROM job_position WHERE position_title = '" .
                addslashes($title) . "'"
            );

            if ($exists !== false) {
                $this->execute(
                    "UPDATE job_position
                        SET default_daily_rate = COALESCE(default_daily_rate, {$defaultRate}),
                            requires_regular   = {$reqReg}
                      WHERE position_id = " . (int) $exists['position_id']
                );
                continue; // already seeded
            }

            $this->execute(
                "INSERT INTO job_position
                    (position_title, department, default_daily_rate, requires_regular, status, sort_order, created_at, updated_at)
                 VALUES
                    ('" . addslashes($title) . "',
                     '" . addslashes($dept)  . "',
                     {$defaultRate}, {$reqReg},
                     'Active', {$sort},
                     '{$now}', '{$now}')"
            );
        }
    }
}

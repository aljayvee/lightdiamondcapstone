# fix(benefits): align SSS bracket ranges with SSS Circular 2024-006

## Problem
The SSS bracket table was built as **MSC .. MSC + 499.99**, shifting every range up by ₱250. SSS Circular No. 2024-006 centres each bracket on the MSC: **MSC − 250.00 .. MSC + 249.99** (below 5,250 → MSC 5,000; 34,750+ → MSC 35,000).

EEMRs in the upper half of a band landed one MSC step too low:

| Position | EEMR | Was | Now | Employee | Employer |
|---|---|---|---|---|---|
| Checker / Warehouse Incharge | 12,259.17 | 12,000 | 12,500 | +25.00 | +50.00 |
| Laborer | 11,998.33 | 11,500 | 12,000 | +25.00 | +50.00 |
| HR Head | 18,258.33 | 18,000 | 18,500 | +25.00 | +50.00 |

PhilHealth and Pag-IBIG were already correct.

## Changes
- `app/Domain/Payroll/SssContributionSchedule.php` (new): the 61 brackets, MSC lookup, EC premium amount.
- `database/migrations/20261120000029_…` (new): rebuilds brackets for `PH-STATUTORY-2026` and `CONTRIB-2026`. Idempotent; `down()` is a no-op by design.
- `database/seeds/Contribution2026Seeder.php`: generates the same ranges for fresh installs.
- `tests/Unit/SssContributionScheduleTest.php` (new): circular rows, 5,250 / 34,750 edges, HR Head boundary, all 10 position totals from the payroll calculation sheet.

## Verification
- `php -l` passes on all files; PHPStan level 6: no errors on the new files.
- PHPUnit 12 not run locally (XAMPP PHP 8.2.12). Stand-in script on PHP 8.2: 222/222 checks, including migration + seeder rows == schedule class.
- **Already applied in production** (2026-11-20): DB backed up first, `phinx migrate -e production` applied 029, 7 bracket lookups on the live DB match the circular, `/login` and `/health` return 200.

## Not included
- Employer-only EC premium (₱10 / ₱30) is still not recorded; needs a `PayrollService` change.
- Regression case `BEN-011` lives in the regression generator, which has other uncommitted work; it will follow separately.

🤖 Generated with [Claude Code](https://claude.com/claude-code)

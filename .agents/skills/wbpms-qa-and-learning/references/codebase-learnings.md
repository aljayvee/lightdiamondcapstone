# WBPMS Empirical Codebase Learnings & Reference Map

Verified lessons learned directly from `c:\xampp\htdocs\wbpms`. Append new entries whenever an agent resolves a subtle bug, discovers a doc-vs-code drift, or hits a test/environment constraint.

---

## 1. Environment & Testing Learnings

### L-001: Local XAMPP PHP 8.2.12 vs. PHPUnit 12 (>= 8.3) & Railway PHP 8.3
- **Logged By**: Antigravity (Gemini 4 Neon) — 2026-11-20
- **Symptom**: Running `php` or `composer` in PowerShell fails (`not recognized`), and running `C:\xampp\php\php.exe vendor/bin/phpunit` fails with `This version of PHPUnit requires PHP >= 8.3. You are using PHP 8.2.12`.
- **Root Cause**: Local XAMPP ships PHP 8.2.12 without adding it to Windows `PATH`, while `composer.json` requires `php: ^8.3` + `phpunit/phpunit: ^12.0` and Railway (`nixpacks.toml`) builds with `php83`.
- **Resolution**: Use a PHP >= 8.3 runtime to execute PHPUnit 12; use `C:\xampp\php\php.exe` only for `php -l`, PHPStan, and `regression/generate_regression_tests.php`.
- **Prevention Rule**: Never report PHPUnit as passing unless a PHP >= 8.3 binary actually executed it. Avoid PHP 8.4+ syntax so Railway (`php83`) builds cleanly.

### L-002: Integration Test Suite Silently Skips When `wbpms_test` Is Unreachable
- **Logged By**: Antigravity (Gemini 4 Neon) — 2026-11-20
- **Symptom**: Running `composer test` can exit without integration failures even when `wbpms_test` is down or unmigrated.
- **Root Cause**: `tests/Integration/IntegrationTestCase::openConnection()` catches `PDOException` and calls `static::markTestSkipped(...)`.
- **Resolution**: Always inspect PHPUnit's skipped test count and run `vendor/bin/phinx migrate -c phinx.php -e testing` before integration runs.
- **Prevention Rule**: Report `passed / failed / errors / skipped` explicitly in every test summary.

### L-003: Last Known `.phpunit.result.cache` Baseline Defects
- **Logged By**: Antigravity (Gemini 4 Neon) — 2026-11-20
- **Observation**: `.phpunit.result.cache` (updated 2026-11-20) lists 22 entries under `defects` across `MonthlyContributionCutoffTest` (1), `PayrollImmutabilityTest` (7), `RequestLifecycleTest` (10), and `SalaryHistoryTest` (4).
- **Prevention Rule**: When running PHPUnit on PHP >= 8.3, distinguish pre-existing baseline failures/skips from regressions introduced by your own changes, and fix or report them transparently.

---

## 2. Database, PDO & Domain Learnings

### L-004: Native PDO Prepares Reject Duplicate Named Placeholders (`HY093`)
- **Logged By**: Antigravity (Gemini 4 Neon) — 2026-11-20 (from commit `c309d33` & `IntegrationTestCase.php`)
- **Symptom**: SQL queries reusing `:param` more than once throw `SQLSTATE[HY093]: Invalid parameter number`.
- **Root Cause**: `PDO::ATTR_EMULATE_PREPARES => false` is enforced.
- **Prevention Rule**: Use distinct named placeholders (`:start_1`, `:start_2`) whenever a bound value appears multiple times in one SQL statement.

### L-005: Holiday Calendar Types Include `SpecialNonWorking`
- **Logged By**: Antigravity (Gemini 4 Neon) — 2026-11-20 (from `HolidayCalendar2026Seeder.php` diff)
- **Rule**: Holiday types enforced by DB CHECK constraint are `Regular` (`2.00`), `Special` working (`1.00`), and `SpecialNonWorking` (`1.30`).

### L-006: `work_schedule` Is a Reusable Template Decoupled from `employee_id`
- **Logged By**: Antigravity (Gemini 4 Neon) — 2026-11-20 (from `DemoEmployeeSeeder.php` & `IntegrationTestCase.php`)
- **Rule**: Since migration `20260902000007`, schedules are stored as reusable templates in `work_schedule` and linked to employees via `employee_schedule_assignment`.

### L-007: Known Doc-vs-Code Discrepancies (Trust Code, Flag to User)
- **Controllers vs. Repositories**: `docs/tech.md` claims SQL lives only in repositories, but `AttendanceController`, `ReportsController`, `EmployeeController`, and `PayrollController` run inline PDO queries. Place *new* business logic in `app/Domain` / `app/Application` and SQL in `app/Infrastructure/Persistence`.
- **Mail**: `docs/tech.md` mentions PHPMailer, but actual code uses a custom `fsockopen` SMTP client in `app/Infrastructure/Mail/Mailer.php`.
- **Money**: `PayrollService` docblocks mention integer centavos, while actual calculations use `float` with `round(..., 2)` stored in `DECIMAL(12,2)`.
- **Router & ADRs**: `router.php` is at repo root (not `public/router.php`), and `docs/adr/` does not exist despite code comments citing `ADR-0001..0003`.

### L-008: `PDOException` Extends `RuntimeException` — Catch Order Leaks SQL Text
- **Logged By**: Claude Code (Claude Opus 5.5) — 2026-11-20
- **Symptom**: Duplicate schedule name showed `SQLSTATE[23000] … uq_ws_name` under the field; employee/schedule saves showed "500 Something went wrong".
- **Root Cause**: `catch (\RuntimeException $e)` also catches `PDOException` and printed `getMessage()`; elsewhere only `PDOException` was caught, so domain `RuntimeException`s escaped to the 500 handler.
- **Resolution**: Branch on `instanceof \PDOException`; map known unique keys to field messages; rethrow unknown DB errors.
- **Prevention Rule**: Never render `getMessage()` of a `PDOException`; pre-validate uniqueness before insert.

### L-009: Effective-Dated `effective_to` Semantics Differ by Table
- **Logged By**: Claude Code (Claude Opus 5.5) — 2026-11-20
- **Rule**: `employee_schedule_assignment.effective_to` is an **inclusive** last day (assign closes at `new_from - 1`; payroll/timesheet use `>=`). `employee_biometric_enrollment` / branch matching use **exclusive** `effective_to > date`. Migration `20261120000028` relaxed `chk_esa_period` to `>=`; other tables still use `>`.
- **Prevention Rule**: Check the table's existing readers before writing an `effective_to` comparison; a superseded assignment is `status='Archived'` **with** an end date and still governs its dates.

### L-010: Seventh-day Adventist Client — Saturday Rest, Sunday Workday
- **Logged By**: Claude Code (Claude Opus 5.5) — 2026-11-20
- **Rule**: Biometric data (May–Aug 2025) has zero Saturday punches and many Sunday punches. Schedules must be Sun–Fri with Saturday rest; absence/late streaks treat rest days and holidays as neutral. Overtime is paid only from approved requests (owner decision 2026-11-20).

### L-011: QA Sheet "Fixed" Notes Were Not in This Repository
- **Logged By**: Claude Code (Claude Opus 5.5) — 2026-11-20
- **Symptom**: "CAPSTONE - PAYROLL" QA sheet notes claim fixes (e.g. `tests/Unit/EmployeeShowViewTest.php`, `employee.hire_time` migration) that do not exist here.
- **Prevention Rule**: Verify a claimed fix in this checkout (file/migration/test exists) before marking a QA case passed; it may live in another copy such as `WBPMS-dev`.

### L-012: Production Is a VPS, Not Railway (Doc Drift)
- **Logged By**: Claude Code (Claude Opus 5.5) — 2026-11-20
- **Rule**: Production runs at `https://lightdiamond-payroll.online` on a VPS (nginx + php8.3-fpm + MariaDB 10.11, app at `/var/www/wbpms`, **not** a git checkout; other unrelated sites share the server). `AGENTS.md`, `Agent Handshake.md` and `nixpacks.toml` still describe Railway auto-deploy on push.
- **Prevention Rule**: A push does not deploy. Before copying files, checksum-compare server files against both the local working tree and `HEAD` (line endings normalized) so server-side edits are never overwritten; back up DB + files to `/root/backups/wbpms/`; run `phinx migrate -e production` as `www-data`; reload `php8.3-fpm` (OPcache).

### L-013: Real Biometric `.xls` Logs Contain Double-Tap Pairs, Half-Days Outside Break, and Partial-Month Exports
- **Logged By**: Antigravity (Gemini) — 2026-11-20
- **Symptom**: Across the 7 real `.xls` exports (Sep–Dec 2025, Jun–Aug 2026; 9,020 tokens), 6 days had two same-side double-taps (`1–33` min apart, e.g. `16:48 16:49` or `06:49 06:59`) marked `Complete` with 541–588 min penalties; 27 half-days did not span `12:00–13:00` yet had 60 min subtracted from worked and charged to late/undertime; and `Daily Log - H(20260830_1439)aug.xls` ends on `08/28 Fri` (partial month).
- **Root Cause**: `WorkSchedule` ignored `break_start_time`/`break_end_time`, `TimesheetGenerator`/`PdoAttendanceImportGateway` treated any 2 punches as a completed shift, and `PayrollService` filtered `salary.status = 'Active'` + `esa.status = 'Active'` + hardcoded `isRestDay: false` + skipped unworked Regular Holidays.
- **Resolution**: Added `WorkSchedule::breakOverlapMinutes()` and `isDuplicateTapSpan()` (<= 60 min same side of shift midpoint), wired break windows across import/adjustment/OT, and fixed `PayrollService` historical salary/schedule joins, per-day `standard_minutes`, rest-day holiday multipliers, and unworked Regular Holiday pay.
- **Prevention Rule**: Keep `XlsAccuracyCalculationTest` passing against all biometric daily-log edge cases; note also that `PayrollAttendancePolicyTest`, `AttendanceScheduleWorkdayTest`, and `AttendancePolicyServiceTest` use minimal SQLite tables without `esa.status` or `attendance_import_batch` — check unit SQLite schemas before adding columns/joins to shared helper queries.

---

### L-014: SSS Brackets Are Centred on the MSC (±₱250), Not Anchored at It

- **Symptom**: EEMR 12,259.17 (₱470/day) got MSC 12,000 instead of 12,500; Laborer, HR Head, Checker, Warehouse Incharge under-deducted ₱25/month; other positions looked correct, so spot checks passed.
- **Root Cause**: Bracket rows generated as `salary_from = MSC`, `salary_to = MSC + 499.99` instead of the circular's "Range of Compensation" `MSC − 250 .. MSC + 249.99`.
- **Fix**: Migration `20261120000029` rebuilds rows; `SssContributionSchedule` is the single tested source for the 2025 schedule.
- **Prevention Rule**: Copy statutory tables from the official "Range of Compensation" column, never infer from MSC values. Always test a value just over a boundary (HR Head 18,258.33 → 18,500) and the floor/ceiling edges (5,250 / 34,750).

### L-015: `payroll_earnings.earning_type` ENUM Must Stay Synced With `HolidayPayCalculator::earningType()`
- **Logged By**: Antigravity (Gemini) — 2026-11-20
- **Symptom**: Running `PayrollService::computeRun()` on live strict MariaDB for any period containing a worked `SpecialNonWorking` holiday (e.g. `2025-11-01`, `2025-12-08`, `2025-12-24`, `2026-08-21`) crashed with `SQLSTATE[01000]: Warning: 1265 Data truncated for column 'earning_type'`.
- **Root Cause**: Migration `20260912000017` added `'SpecialNonWorking'` to `holiday_calendar.holiday_type` and `HolidayPayCalculator::earningType()` returned `'SpecialNonWorkingHoliday'`, but `payroll_earnings.earning_type` was never extended to include `'SpecialNonWorkingHoliday'`.
- **Fix**: Added idempotent forward-only migration `20261120000030_add_special_non_working_holiday_earning_type.php` and locked it with live MariaDB integration test `tests/Integration/XlsPayrollCalculationIntegrationTest.php`.
- **Prevention Rule**: Whenever adding a new domain enum case or earning/deduction label, verify the target MySQL `ENUM(...)` column by executing the persistence path against live MariaDB (`wbpms_test`), not just unit mocks.

### L-016: Partial-Month `.xls` Batch Coverage Must Be Bounded to `biometric_punch` Dates & Earnings Must Equal Gross
- **Logged By**: Antigravity (Gemini) — 2026-11-20
- **Symptom**: (1) An approved partial-month `.xls` export like `Daily Log - H(20260830_1439)aug.xls` (columns `08/01..08/28`) caused `PayrollService::approvedAttendanceUploadDates()` and `AttendanceController::attendanceUploadCoverage()` to mark `2026-08-30 Sun` / `2026-08-31 Mon` as covered, creating false unpaid absences. (2) Zero-worked `Approved` attendance rows on `Special`/`SpecialNonWorking` holidays reduced `$grossPay` via negative `$holidayDayAdj` while leaving `Basic Pay` unreduced, breaking `SUM(payroll_earnings.amount) === payroll.gross_pay`. (3) `AttendancePolicyService` queries lacked `LEFT JOIN attendance_import_batch` cancelled-batch filters.
- **Root Cause**: Coverage queries assumed every `attendance_import_batch` covered the full `(source_year, source_month)` calendar month; `computeRun()` only appended positive holiday premiums to `payroll_earnings`; and `AttendancePolicyService` filtered only `attendance.status` (or omitted it in `evaluateEmployee()`).
- **Resolution**: Bounded batch coverage to `[MIN(DATE(source_local_at)), MAX(DATE(source_local_at))]` from `biometric_punch` (falling back to full month when no punches exist or minimal SQLite unit test tables omit `biometric_punch`), decremented `$basicDays`/`$basicPay` for unpaid zero-worked special holiday rows, and added cancelled-batch filtering across all 4 `AttendancePolicyService` attendance queries.
- **Prevention Rule**: Always assert `SUM(payroll_earnings.amount) === payroll.gross_pay` in payroll integration tests, bound batch coverage by actual punch dates when present, and pair `a.status <> 'Cancelled'` with `LEFT JOIN attendance_import_batch aib ... (a.import_batch_id IS NULL OR aib.status <> 'Cancelled')` on every operational `attendance` query.

### L-017: Capstone Defense Fixes — Effective-Date CHECK Constraints, Re-Match Dedup, CA Settlement on Approval & `Connection(['pdo' => $pdo])`
- **Logged By**: Antigravity (Gemini) — 2026-11-21
- **Symptom**:
  1. Archiving an employee on their first working day (or changing salary on consecutive days) threw `SQLSTATE[23000]: Check constraint 'chk_salary_period' is violated` because `salary` still had `effective_to > effective_from`.
  2. Re-uploading a biometric `.xls` workbook after registering a missing `employee_biometric_enrollment` (e.g., "Richie Tagaduar" Enroll ID 21) skipped all previously `unmatched` punches as duplicates because `PdoAttendanceImportGateway::isDuplicatePunch()` did not check `match_status = 'matched'`.
  3. Re-running `PayrollService::computeRun()` repeatedly decremented `cash_advance_history.remaining_balance` on every draft computation instead of upon Owner approval, and deducted statutory EE contributions even when `$grossPay == 0.0`.
  4. `SalaryHistoryTest` and `PayrollImmutabilityTest` passed `new Connection(['pdo' => $this->pdo])`, which `Connection::__construct()` ignored when `$existing` (the 2nd parameter) was omitted, opening a second unisolated PDO connection.
- **Resolution**:
  1. Migration `20261121000031` relaxed `chk_salary_period` to `>=` (matching `chk_esa_period` from migration `028`), added `minimum_daily_wage`, `job_position` default rates/`requires_regular`, `employee.position_id` FK, `sessions.user_id` FK, `users` email verification columns, and dropped 9 unused tables + `employee.address_id`.
  2. Scoped `isDuplicatePunch()` to `match_status = 'matched'`, added `ON DUPLICATE KEY UPDATE` in `insertPunch()`, and added `rematchUnmatchedPunches()` + `diagnoseEnrollmentMatch()`.
  3. Moved `cash_advance_history` balance decrement + `cash_advance_repayment` insert to `PayrollService::approve()`, added `WithholdingTaxCalculator` (BIR TRAIN weekly table), and skipped statutory EE contributions/tax/CA when `$grossPay <= 0.0`.
  4. Updated `Connection::__construct()` to accept `$config['pdo'] instanceof PDO` when `$existing` is null, bringing `SalaryHistoryTest` (4/4) and `PayrollImmutabilityTest` (7/7) to 100% pass on live MariaDB.
- **Prevention Rule**: Never mutate external financial balances (`cash_advance_history`, locked `contribution_record`s) inside idempotent draft computation (`computeRun()`); always settle balances inside `approve()`. Always check both inclusive (`esa`, `salary`) and exclusive (`eba`, `ebe`) effective-date invariants when archiving or computing final pay.

### L-018: Uncommitted Fixes Are Not On The VPS — And Pre-Printed Forms Need Absolute mm Layout
- **Logged By**: Claude Code (Claude Opus 5.5) — 2026-11-21
- **Symptom**: Transaction Slips print preview still showed only floating values after the `@media print` fix was made locally; the slip layout also did not resemble the real BDO slip (A5 flexbox approximation).
- **Root Cause**: The VPS (`/var/www/wbpms`, not a git checkout) still held the older view — local fixes reach production only by explicit file copy. The flexbox layout derived field positions from text flow, so data coordinates had no relation to the physical slip.
- **Resolution**: Rebuilt print mode with every form element (`.f`) and value (`.d`) absolutely positioned in mm measured from the scanned slip (2000×967 px → 210×101.5 mm); `@media print { .f { visibility:hidden } }`; `--nudge-x/-y` for printer calibration. Renumbered duplicate `REP-011` (slip case) to `REP-021`.
- **Prevention Rule**: When a user reports a bug that is "already fixed", diff the VPS copy against local first. For pre-printed forms, position from measured coordinates, never from document flow. Check `generate_regression_tests.php` for duplicate TC IDs after adding one.

### L-019: VPS Copies Mix CRLF/LF, Lack `pdo_sqlite`, And PHPUnit 12 Ignores `@dataProvider` Docblocks
- **Logged By**: Claude Code (Claude Opus 5.5) — 2026-11-21
- **Symptom**: A raw md5 comparison flagged 204/217 server files as different from local; on the VPS's PHP 8.3, 17 Unit tests errored with `PDOException: could not find driver` and `MonthlyContributionCutoffTest` threw `ArgumentCountError`.
- **Root Cause**: Earlier deploys copied some files with CRLF endings and others as LF. The VPS PHP build has no `pdo_sqlite`, which the SQLite-backed Unit tests need. PHPUnit 12 removed docblock annotations, so a `/** @dataProvider x */` test runs with no arguments.
- **Resolution**: Compare checksums after stripping `
` on both sides, and classify each file as server = `HEAD`, server-unique, or local-only before copying. Treat SQLite driver errors on the VPS as environment errors, not failures. Converted all 44 docblock annotations in 8 test files to attributes (2026-11-21). The Unit suite went from 164 to 212 collected tests; six classes had previously reported "No tests found".
- **Prevention Rule**: Before a VPS deploy, diff the server-unique files against local and only copy if local is a superset. Copy migrations first and migrate before swapping code when the new schema is additive. Use PHP attributes (`#[DataProvider]`, `#[Test]`) in tests, never docblock annotations.

### L-020: Native `min`/`max` On Form Inputs Need `data-msg-min`/`data-msg-max`, And Date Pickers Need A `change` Check
- **Logged By**: Claude Code (Claude Opus 5.5) — 2026-11-21
- **Symptom**: On Add Employee, picking an under-age birthdate gave no message until the field lost focus, and then only the generic "Value is too high.". A daily rate of ₱1,000,000 was rejected.
- **Root Cause**: `public/assets/inline-validate.js` validated only on blur (or on change once already invalid), and falls back to generic text when a `max` overflow has no `data-msg-max`. `EmployeeController::validate()` capped the rate at a hardcoded 999,999.99.
- **Resolution**: The script now also validates `type="date"` fields on `change`; the birthdate input carries `data-msg-max`/`data-msg-min`; the server message names the age and the 18th-birthday cutoff. The rate cap is `EmployeeController::MAX_DAILY_RATE` (1,000,000.00, inclusive), used by validation and by the `max` attribute of the edit and rehire forms.
- **Prevention Rule**: Whenever a form input gets `min`/`max`, give it matching `data-msg-min`/`data-msg-max` text. Keep each numeric limit in one constant shared by the server check and the view attribute.

### L-021: Employee Number Was The Biometric Enroll ID (Now EMP-NNN, See L-022); Mixed-Timezone `diff()` Shifts Ages; VPS `vendor/` Has No PHPUnit

- **Symptom**: HR had to type an Employee number by hand. Separately, `testUnderAgeBirthdateIsReportedWithAgeAndCutoff` failed on the VPS (UTC) reporting "16 years old" for a 17-year-old.
- **Root Cause**: On production, 36 of 37 employees have `employee_number` = `employee_biometric_enrollment.device_employee_code` (only `EMP-DEMO-001` differs), so the field duplicated the Enroll ID. The birthdate was parsed in the PHP default timezone but diffed against `today` in `Asia/Manila`; `bootstrap/app.php` sets Manila for web requests, but PHPUnit does not load it.
- **Fix**: `EmployeeController::store()` sets `employee_number` from `enrollment_code` (posted value ignored); a clash is reported on the Enroll ID field. The create form shows a read-only mirror. Birthdate is parsed with `$todayManila->getTimezone()`.
- **Prevention Rule**: Always pass an explicit `Asia/Manila` zone to `createFromFormat()` when comparing with a Manila date. The VPS `vendor/` is installed without dev packages: to run PHPUnit there, upload the local `vendor/` (incl. `phpstan`, which the autoloader requires) plus `resources/` and `database/` into a `/tmp` copy, and delete it afterwards.

### L-022: New Employee Numbers Are EMP-NNN; Repo And Transaction Must Share One `Connection` (Fixed In `store()`/`update()`)

- **Rule**: New employees get `EMP-001`, `EMP-002`, ... from `EmployeeNumberSequence` (highest `EMP-<digits>` + 1). Existing numeric numbers (`126`, ...) and `EMP-DEMO-001` are immutable and ignored by the sequence. Do not reintroduce a typed employee number on create.
- **Pitfall**: `makeRepo()` and `makeConnection()` each build a new `Connection`, so in `store()` the `$repo->createEmployee()` / branch / schedule / salary writes run on a different PDO from `$connection->transaction()`; they are not rolled back if user provisioning fails, and row locks on the transaction PDO can deadlock against repo inserts.
- **Prevention Rule**: Before relying on a transaction in `EmployeeController`, build the repository from the same `Connection` that runs the transaction (`new EmployeeRepository($connection)`).
- **Status (2026-11-21)**: Fixed in `store()` and `update()`; reproduced on `wbpms_test` (5 orphan rows before, 0 after). Other controllers that call `makeRepo()` and `makeConnection()` separately and then open a transaction have the same risk. Running controllers from CLI: `AuthMiddleware::identity()` returns null, so actor id is 0 and `audit_logs` FK fails unless a `users.user_id = 0` placeholder exists (test DB only).

### L-023: `audit_logs` Columns Are `event_type` / `action_performed` / `table_affected` / `record_id` / `description` / `action_at`

- **Symptom**: HR "Re-match Unmatched Punches" showed `Unknown column 'action_type' in 'INSERT INTO'` and matched nothing.
- **Root Cause**: The audit INSERT used a different schema's column names (`action_type`, `target_table`, `target_id`, `old_values`, `new_values`). It ran inside the re-match transaction, so the failure rolled back all the real work.
- **Fix**: Use the real columns; `AuditLogInsertColumnsTest` now checks every `INSERT INTO audit_logs` in `app/`.
- **Prevention Rule**: Copy an existing audit insert (e.g. `AttendanceAdjustmentService`) instead of writing one from memory. An audit write inside a business transaction can undo the business change if it fails. Also: `EmployeeController::store()` swallows re-match errors (`catch (\Throwable) {}`), so a failed save-time re-match leaves punches `unmatched` with no trace; HR must use the Re-match button.

### L-024: Rebuilding A MySQL ENUM In A Migration Can Silently Drop Values; Placeholders Must Be Unique Per Statement

- **Symptom**: Manual Payroll Adjustment showed `SQLSTATE[HY093]: Invalid parameter number`; after fixing that, manual deductions failed with `Data truncated for column 'deduction_type'`.
- **Root Cause**: (1) `:amount` appeared twice in one INSERT (native prepares). (2) `20261018000024` wrote `MODIFY deduction_type ENUM(...)` from an outdated list, removing `ManualAdjustment` that `20260906000012` had added.
- **Fix**: Unique placeholders; migration `20261121000032` restores the value.
- **Prevention Rule**: Before writing `MODIFY ... ENUM(...)`, read the live `COLUMN_TYPE` (or the newest migration that touched the column) and keep every value; prefer an idempotent `COLUMN_TYPE` check like `032`. `SqlPlaceholderReuseTest` and `PayrollLineTypeEnumMigrationTest` now guard both. Test a fix with a real save (rolled-back transaction on prod data is safe because `Connection::transaction()` nests without committing) - the second bug only showed up once the first was fixed.

### L-025: Measure Real Punch Patterns Before Changing The Punch Rule; `DUPLICATE_TAP` Means "Opposite Punch Missing"

- **Symptom**: Any day with 3+ punches became ReviewRequired and went unpaid (owner review C7); payroll skipped such days and every other non-payable day with no message (C6).
- **Root Cause**: The 1/2/3+ punch rule was written three times (`TimesheetGenerator`, `rebuildFromLivePunches()`, `reconcileAttendance()`), each with `count($punches) > 2 ? 'ReviewRequired'`. The 7 real exports in the repo root show 3+ punch days are second scans at arrival/departure (5–30 min apart), not lunch punches.
- **Fix**: One `PunchDayClassifier` for all three paths (89 → 9 review days, minutes unchanged). `PayrollReadinessEvaluator` + `PayrollService::computeRunIfReady()` list and block on unpaid days before Compute.
- **Prevention Rule**: Before changing an attendance rule, run it over the real `.xls` files in the repo root and compare old vs new status counts. Keep `DUPLICATE_TAP` for "only one side scanned" (`XlsAccuracyCalculationTest` enforces DUPLICATE_TAP ⇒ Incomplete); merged scans on a complete day are `REPEATED_SCAN`. A check that must match Compute should reuse Compute's helpers (`eligibleEmployees()`, `employeeWindow()`). A local stand-in test runner must call `tearDown()` in `finally`, or `IntegrationTestCase`'s per-test transaction is never rolled back and later tests fail with duplicate keys. Several AI sessions can work in this checkout at once (one may stop MySQL, another may rotate the ledger); re-read AGENTS.md and the ledger right before editing them.

### L-026: Payroll Lines Go Stale When Attendance Changes After Compute; Holiday Seeder Was Not The 2026 Proclamation

- **Symptom**: HR had to open attendance to check how Basic Pay was reached. On prod, 11 of 78 Basic Pay lines no longer matched attendance days (e.g. 0 paid days vs 5 attendance days).
- **Root Cause**: `payroll_earnings` is a snapshot from Compute (`quantity` = days for Basic). The 11/21 re-check of ReviewRequired days changed attendance after runs 1, 4, 7, 11 were computed; nothing on the run page showed it. Separately, `HolidayCalendar2026Seeder` cited Proc. 727 (the 2025 list): Chinese New Year on Feb 5 (actual Feb 17), Jan 2 and Oct 31 not declared for 2026, EDSA (Feb 25, Special Working) missing. Regular holidays were already correct on prod (12 incl. both Eids).
- **Fix**: `PayrollAttendanceSummaryRepository::forRun()` reads days/minutes with Compute's filters (Complete/Approved, non-cancelled uploads, period + `effective_to`-exclusive branch window); both run views show `N days × ₱rate` and `Attendance: N days · Xh YYm worked`, plus a note when days differ. Seeder aligned to Proc. 1006 s.2025, 1189 and 1264 s.2026 (prod rows not changed).
- **Prevention Rule**: Anything shown next to a computed payroll figure should come from the same filters as Compute, and a mismatch should be shown, not hidden, so HR knows to compute again. Check the year's proclamation (lawphil.net) before seeding holidays; Islamic holidays are proclaimed separately each year.

### L-027: Back-Dated Payroll Needs Dates On Every Obligation; "Current Year" Defaults Break Old Leave

- **Symptom**: Owner computes payroll for past months from old biometric data. A cash advance approved today would be repaid from a June run; sick leave for 2025 approved in 2026 used the 2026 credit.
- **Root Cause**: `cash_advance_history` had no business date (only `approved_at`), and `activeCashAdvanceInfo()` never compared anything to the run period. `RequestService` used `date('Y')` in `assertLeaveBalance`, `assertPayableRequest` and `debitLeaveLedger`, although `leave_entitlement` is already per year.
- **Fix**: `cash_advance_request_detail.advance_date` (migration `20261122000036`), payroll filters `advance_date <= period_end` and subtracts repayments in other open runs; leave year = year of the leave dates (`LeaveDayCounter::creditYear`), cross-year ranges refused.
- **Prevention Rule**: Any deduction or credit that payroll applies must be keyed to a business date compared with the run period, never to "now" or approval time, because runs are computed out of order. When several runs can be open at once, a balance read at compute time must subtract amounts already reserved by the other open runs.

## 3. Regression Test Suite Module Map (196 Cases)

Source of truth: `regression/generate_regression_tests.php` -> `regression/WBPMS_Regression_Test_Suite.xlsx`.

| # | Module | TC Prefix | Range | Focus Areas |
|---|---|---|---|---|
| 1 | Login & Authentication | `AUTH` | `AUTH-001..018` | Role logins, email-only login, RBAC, 30m/12h sessions, CSRF, reset tokens, strong password complexity, email verification, deactivated account notice |
| 2 | Dashboard | `DASH` | `DASH-001..005` | Role-scoped tiles & live counts |
| 3 | User Management | `USER` | `USER-001..012` | CRUD, BusinessOwner-only RBAC, deactivate/archive, self-archive/self-deactivation block, session revocation, menu visibility, archive sync |
| 4 | Employee Management | `EMP` | `EMP-001..024` | CRUD, branch scope, archive/rehire history, AWOL/Deceased archive with unapproved payroll, DOLE minimum wage & position default rates, biometric enrollment & device-branch rules |
| 5 | Work Schedule | `SCH` | `SCH-001..013` | 5/6-day schedules, rest days, holidays, overlap warning, assign errors, break window, shift edits |
| 6 | Attendance | `ATT` | `ATT-001..029` | `.xls` OLE validation, idempotency, matching, dry-run diagnostics, unmatched punch re-match, manual absence override, timesheet, adjustments, OT, policy flags, cancellation |
| 7 | Request Management | `REQ` | `REQ-001..016` | Leave/OT/Cash Advance, 4 paid sick leaves, `Cancelled` (no `Rejected`), filters, HR Head own-request direct routing to Owner |
| 8 | Payroll | `PAY` | `PAY-001..026` | Derived computation, HR->Owner workflow, immutability, cancel (`REQ047`), 13th month, holiday pay, historical rates/schedules, BIR TRAIN withholding tax, zero-gross skip, CA settlement on approval |
| 9 | Manage Salary | `SAL` | `SAL-001..006` | Daily rate, non-mutating history, effective dates, RBAC |
| 10 | Benefits & Deductions | `BEN` | `BEN-001..011` | SSS/PhilHealth/Pag-IBIG (`PH-STATUTORY-2026`), Circular 2024-006 bracket ranges, deductions |
| 11 | Reports | `REP` | `REP-001..020` | Payroll/attendance/request/contribution/13th reports, Total Deductions cards, per-employee filter, CSV/PDF export, BDO overlay slips (`REQ073`), `MM/DD/YY` dates |
| 12 | Employee Self-Service | `PORT` | `PORT-001..010` | Scoped attendance/schedule/payslips/requests, PDF download, unlinked account guard |
| 13 | System / Cross-cutting | `SYS` | `SYS-001..017` | Audit trail, `Asia/Manila`, `MM/DD/YY` + 24h, strict MySQL, DB sessions, CSRF/SQLi/XSS, `/health` |

## 2026-11-21 — Forgot-password email "sent" but never delivered (prod)
- Symptom: `/forgot-password` showed "Check Your Email" on prod, but no email ever arrived.
- Root cause: prod `.env` had `MAIL_DRIVER=log` (the `Mailer` default), which writes to `storage/private/mail.log` and never errors. After switching to Brevo SMTP, Brevo accepted the email (250) but dropped it because the sender domain was not authenticated in Brevo.
- Fix: Brevo SMTP relay in prod `.env` (`smtp-relay.brevo.com:587`, TLS); owner added Brevo DKIM + DMARC records for `lightdiamond-payroll.online`.
- Prevention rule: "Check Your Email" proves nothing about delivery. Verify with a real send to an inbox you own; with `smtp`, a missing `mail.log` means the relay said 250, and anything after that is the relay's own logs (Brevo → Transactional → Logs). Any new sender domain must be authenticated in Brevo first.

## 2026-11-21 — Local browser testing: app DB user has no grants
- Symptom: local Apache `/login` returns 500; `phinx status -e development` says `Access denied for user 'wbpms_user'@'localhost' to database 'wbpms'`.
- Root cause: on this PC `wbpms_user` (both `localhost` and `127.0.0.1`) only has `USAGE`; XAMPP `root` has no password, and `bootstrap/app.php` rejects an empty `DB_PASSWORD`.
- Workaround used (no change to local grants or the repo `.env`): copy `app bootstrap config database public resources routes vendor phinx.php` to the scratchpad, give it its own `.env` (`DB_NAME=wbpms_test`, `DB_USER=root`, `APP_BASE_URL=http://127.0.0.1:8099`, `MAIL_DRIVER=log`), drop `DB_PASSWORD` from the `notEmpty()` list in the copy only, and serve it with `php -S 127.0.0.1:8099 -t public router.php` (router returns false for real files). Emails land in the copy's `storage/private/mail.log`.
- Prevention rule: `wbpms_test` starts with no roles/users; seed only throwaway rows and remove them afterwards.

## 2026-11-21 — Edit Employee silently moved the contract review date
- Symptom: a Contractual/Probationary employee's `contract_review_date` changed to "today + 6 months" every time HR saved the Edit form, even for a phone-number change, so the review never came due.
- Root cause: `EmployeeRepository::updateEmployee()` recalculates the date (from `hire_date` or, if absent, today) whenever `employee_type` is in the attributes and `contract_review_date` is not; `EmployeeController::update()` always sent the type and never the date.
- Fix: the form posts `contract_review_date` and the controller always sends it (NULL for Regular). Rules in `Domain/Employee/ContractReviewPolicy`; HR Head bell reads `ContractReviewRepository::dueOnOrBefore()` through `ViewRenderer`.
- Prevention rule: repository "auto-fill when the key is missing" defaults are risky on partial updates — callers must send every derived field they own. When adding a key to `extractPostFields()`, read it in `validate()` with `?? ''`: `QaPayrollSheetRegressionTest`/`DefenseFixesTest` call `validate()` by reflection with their own fixture arrays.
- Environment note: local MariaDB `mysql.global_priv` (Aria) gives `error 176 "Read page with wrong checksum"` on `CREATE USER`/`GRANT`; don't add a test DB user — use the scratch-copy workaround above.


### Local MariaDB: `wbpms_user` has only USAGE grants (2026-11-21, Claude Code)
- **Symptom**: `phinx seed:run -c phinx.php` → `SQLSTATE[HY000] [1044] Access denied for user 'wbpms_user'@'localhost' to database 'wbpms'`; local MariaDB was also not running.
- **Root cause**: on this XAMPP instance the `.env` user `wbpms_user` (localhost and 127.0.0.1) has `GRANT USAGE` only; root has no password.
- **Fix used**: start `mysqld --defaults-file=C:\xampp\mysql\bin\my.ini --standalone`; run Phinx with a scratch config pointing at root (`phinx.php` loads `.env` with `createUnsafeMutable`, so env-var overrides are ignored).
- **Prevention rule**: before local DB work, check `SHOW GRANTS FOR 'wbpms_user'@'localhost'`; ask the owner before granting privileges. Test-data seeders must refuse `APP_ENV=production` / `-e production`.

## 2026-11-21 — Echoing request input into a redirect URL can 502 on nginx
- Symptom: `POST /reset-password` with a 4 KB `token` returned 502 Bad Gateway; nginx error log: `upstream sent too big header while reading response header from upstream`.
- Root cause: the controller redirected to `/reset-password?token=` . urlencode($posted). PHP happily sends a huge `Location:` header, but nginx's `fastcgi_buffer_size` (4 KB default) rejects it.
- Fix: validate the token format (64 lowercase hex) before any redirect that carries it.
- Prevention rule: never put unvalidated, unbounded user input into a header (Location, Content-Disposition, Set-Cookie). Also: prod's exception handler doesn't log, so access-log status codes are the only trace of a crash — look there first when hunting 500s.

## 2026-11-21 — Scratch-copy E2E: `.env` needs APP_KEY
- Symptom: every page of the scratch copy (`php -S`) returned the generic 500 page, even in `APP_ENV=development`.
- Root cause: `bootstrap/app.php` `$dotenv->required([... 'APP_KEY' ...])` throws before the development error display is set, so no message shows.
- Fix: add any `APP_KEY=<random hex>` to the scratch `.env`. Debug bootstrap failures from CLI: `php -r 'define("APP_ROOT", getcwd()); require "vendor/autoload.php"; require "bootstrap/app.php";'`.
- Prevention rule: scratch `.env` = APP_ENV, APP_BASE_URL, APP_KEY, DB_* (and drop `DB_PASSWORD` from `notEmpty()` in the copy only).

## 2026-11-21 — SSS employer share left out the EC premium
- Symptom: employer SSS figures (Benefits, contribution_record) were ₱10–₱30 per employee per month below the SSS Circular 2024-006 employer total; found while comparing with the romarsares/WBPMS fork.
- Root cause: `SssContributionSchedule::ecFor()` existed but `employer_share` (and migrations `027`/`029`, `Contribution2026Seeder`) stored only 10% of MSC.
- Fix: migration `20261121000035` adds EC to `sss_bracket.employer_share` (only rows still at 2 × EE); schedule class and seeder include EC; SSS `calculation_details` records `msc` + `ec`.
- Prevention rule: `sss_bracket.employer_share` = 10% MSC + EC; employee share never includes EC. The fork fixed the same table by editing already-run migration `027` — a DB that ran `027` never sees such an edit, so always add a new migration.
- Local quirk: `.env` sets `TEST_DB_USER=wbpms_user`, which has no grants on `wbpms_test`, and `phinx.php` loads `.env` mutably, so env overrides do not work; run phinx with a scratchpad config using `root`.

## 2026-11-21 — Audit Trail 500 from a JSON number under strict_types
- Symptom: `/hr/audit` showed "Something Went Wrong" for Owner and HR; nothing in any log.
- Root cause: `hr/audit/index.php` escapes JSON fields through a closure typed `?string`; `payroll_adjusted` stores `"amount": 100` as a JSON number, so `json_decode` returns int and strict_types throws a TypeError. Production has no PHP `error_log` and `public/index.php` swallows the exception into the 500 view, so it left no trace.
- Fix: helpers accept `mixed` and only cast scalars.
- Prevention rule: never type view helpers that receive decoded JSON as `string`; to diagnose a prod 500, reproduce it with a CLI probe that bootstraps the app as www-data and calls the controller inside `ob_start()` / try-catch.

## 2026-11-21 — `$_SESSION['role']` does not exist
- The signed-in role lives in `AuthMiddleware::identity()['role_name']` (session key `SESSION_KEY`). Any view reading `$_SESSION['role']` silently falls back to its default; the cash-advance tracker sent the Owner to HR-only `/hr/requests` (403).

## 2026-11-21 — Timestamps are UTC; dates in views must not assume Manila
- Symptom: run 2 approved 01:34 Manila on 11/21 shows "11/20/26"; run page showed raw `2026-11-20 17:34:59`.
- Root cause: `PayrollService::utcNow()` and MariaDB `NOW()` (server TZ is UTC) store UTC; `Formatter::date()/dateTime()` format the string as-is. Audit Trail is right only because `AuditLogRepository` uses `CONVERT_TZ(..., '+00:00', '+08:00')`.
- Fix: `Formatter::utcDateTime()` for UTC columns (applied to the HR run page).
- Prevention rule: for `created_at/submitted_at/reviewed_at/computed_at/action_at` use `Formatter::utcDateTime()` or `CONVERT_TZ` in SQL; never convert attendance `time_in/time_out` or punch datetimes (stored local). Pure DATE columns (period_start, effective_from) use `Formatter::date()`.

## 2026-11-21 — Some GET pages write (leave entitlement auto-provision)
- `RequestService::leaveBalance()` / `leaveLedger()` call `ensureLeaveEntitlement()`, which INSERTs the year's entitlement + 4-day Grant. So opening `/hr/requests/leave-credits/{id}` (or any balance read) writes. Read-only list queries must treat "no entitlement row" as the default 4.00, not 0.
- Prevention rule: GET crawlers/smoke tests on prod must skip `/hr/requests/leave-credits/{id}` (and any page that calls `leaveBalance()`), or accept that they provision grants.

## 2026-11-21 — `confirm()` is blocked in the Claude browser pane
- The desktop app's browser pane auto-cancels `window.confirm()` (returns false instantly). Buttons with `onclick="return confirm(...)"` (27 in 16 views) silently do nothing there but work in Chrome/Edge. Check nginx `wbpms-access.log` for the POST before debugging server code.

## 2026-11-21 — Use `data-confirm`, never `return confirm(...)`
- All action confirmations go through `public/assets/confirm-dialog.js`: put `data-confirm="Message?"` on the `<form>` or the submit `<button>` (optional `data-confirm-ok="Label"`). It runs after the form's own submit listeners and keeps the submitter's name/value. Do not add new `onclick/onsubmit="return confirm(...)"` — embedded browsers block the pop-up and the button silently does nothing.

## 2026-11-21 — Where prod errors are logged (and why there used to be nothing)
- Symptom: a prod 500 (Audit Trail) and failed Re-match clicks left no message in any log; only status codes in the nginx access log.
- Root cause: `bootstrap/app.php` set `error_reporting(0)` outside development, and `public/index.php`'s exception handler rendered the 500 page without logging. FPM's own `/var/log/php8.3-fpm.log` only holds master-process notices.
- Fix: `Wbpms\Infrastructure\Logging\ErrorLog`; all crashes, caught failures that call `ErrorLog::exception($e, '<place>')`, PHP warnings and `error_log()` go to `/var/www/wbpms/storage/private/logs/app-error.log`. The 500 page shows `Reference: <12 hex>`; grep it.
- Prevention rule: when a controller catches `\Throwable`/`\PDOException` and shows a flash message instead of a 500, call `ErrorLog::exception($e, '<what failed>')` first. Skip expected user errors (`RuntimeException` business messages, duplicate key 1062).
- Clock quirk: nginx logs are server local time (+01:00), MariaDB `created_at` is UTC (one hour earlier), the app log is Asia/Manila. Convert before matching a request to its audit row.

## 2026-11-22 — Leave / OT / Cash Advance → payslip audit (Claude Code, verification only, no code changed)
- Method: prod DB read-only checks, then a prod dump loaded into a local throwaway DB (`wbpms_prodsim`, dropped after) and run with the server's own `app/` code: submit → HR approve → Owner approve → compute → approve → payslip.
- Result: emp 6, run 1 (08/08–08/14/25): +1 day sick leave ₱500, +120 OT min ₱156.25 (500/480 × 1.25), −₱500 cash advance; gross 3,156.25, net 2,656.25 = hand calculation; payslip lines match; CA balance 1,500 → 1,000 + 1 repayment row on run approval.
- Gaps found (not fixed): (1) `RequestService::calcLeaveDays` counts calendar days, so rest days (Saturday) and holidays are debited from the 4-day credit but never paid; (2) the balance is checked only at submit, so two pending leave requests can overspend (balance −2 seen); payroll pays any approved leave without a cap; (3) a run can be computed/approved before its week ends (prod run 26, 11/20–11/26, approved 11/21), and requests approved later for those dates are accepted but can never reach a payslip; (4) OT requests across several dates accept end < start (prod request #25, 12:30→08:20, 1190 min); payroll still caps pay at real punches outside the shift; (5) with several active cash advances, the ₱500/week goes to the newest one first and the ₱2,000 cap is per request, not per employee.
- Follow-up 2026-11-22 (same day): gaps (1)–(3) fixed. Prevention rules: charge leave with `LeaveDayCounter` + `WorkCalendarRepository` (same lookups payroll uses) — never count calendar days; any new "approve" path for Leave/OT must call `RequestService::assertPayableRequest()`; never let a run be submitted/approved before `period_end` (`PayrollPeriodClosure`). Test-data trap: 12/08 is an active SpecialNonWorking holiday, so a Mon–Wed leave that week is 2 days, not 3.

## 2026-11-22 — Effective-dated rows: closing a period on its own start date breaks the CHECK
- Symptom: HR "Save Bank Account" re-rendered the form with `SQLSTATE[23000] … 4025 CONSTRAINT chk_bank_period failed` (prod emp 36).
- Root cause: `bank_details` has `CHECK (effective_to IS NULL OR effective_to > effective_from)`. Saving closed the current row with `effective_to = <new effective_from>`, and the form pre-filled the new date with the current account's date, so the default save made a zero-length period.
- Fix: `Wbpms\Domain\Employee\BankAccountChange::decide()` — later date supersedes, same date corrects in place, earlier date is a field error (`BankAccountDateRejected`). Form defaults to max(today, current date).
- Prevention rule: for any effective-dated table with an `effective_to > effective_from` CHECK (`bank_details`, salary `chk_salary_period`, schedules), decide supersede/correct/reject in a Domain class before writing, under `SELECT … FOR UPDATE`; never pre-fill a "new effective date" field with the current row's own date. Show the rule as a field message, never `$e->getMessage()` from a PDOException.

## 2026-11-22 — `!isset($a, $b)` means "at least one missing", not "neither set"
- Symptom: "Undefined array key 1" at ScheduleController 631/650; false "Time out must be after time in" next to a real Time in error.
- Root cause: multi-argument `isset()` is true only when all are set; the guard meant "no time errors" but ran when one time was invalid, then `explode(':')` on `0800`.
- Prevention rule: to require that none of several error keys is set, write `!isset($e['a']) && !isset($e['b'])`. `!isset($r['a'], $r['b'])` is right only for "a required field is missing".

## 2026-11-22 — `ViewRenderer::render()` used to force HTTP 200
- Any `http_response_code(4xx)` before `render()` was overwritten, so 403/404/422 pages answered 200. `render()` now keeps 400–599 (`ViewRenderer::responseStatus`). For access denied use `ViewRenderer::forbidden()`, never `render('errors/403')` (that view is a full page and needs `$roleName`/`$base`).

## 2026-11-22 — Catch blocks must not relabel every PDOException
- Assign Schedule turned a foreign-key failure into "date overlaps". Map only the constraint codes that mean the user's input (1062 duplicate, 4025 CHECK) and give other failures a generic message with `ErrorLog::reference()`; always log first with `ErrorLog::exception()`.

## 2026-11-22 — `catch (RuntimeException $e)` leaks SQL to users (Claude Code)
- Symptom: toasts and form errors could show "SQLSTATE[23000]: Integrity constraint violation…" or PHP TypeError text.
- Root cause: `PDOException extends RuntimeException`, so `catch (RuntimeException $e) { flashError($e->getMessage()); }` forwards database errors verbatim.
- Fix: `Wbpms\Http\View\UserMessage::from($e)` (domain messages pass; technical ones are logged once and replaced with a reference); `ViewRenderer::flashError()` and `render()` `$errors` sanitize strings as a backstop.
- Prevention rule: never pass `$e->getMessage()` to a view or flash directly; use `UserMessage::from($e)` or `UserMessage::prefixed('…', $e)`.

## 2026-11-22 — Table / filter / date behaviour is shared, not per view (Claude Code)
- `public/assets/ui-enhance.js` enhances every table, GET filter form and date input on signed-in pages. Do not add per-view Filter buttons, pagination or native date pickers; opt out with `data-no-paginate`, `data-no-sort`, `data-no-enhance`, `data-auto-filter="off"`, `data-no-retain`, `data-native-picker`.
- A GET form auto-submits on any **named** control: give helper controls (cascading selects, tab switches) no `name`, or mark them `data-no-auto`.
- Grid columns such as `grid-template-columns: 1fr 360px` grow to fit a wide table (min-width:auto) and push the page sideways; `ui-enhance.css` sets `min-width:0` on inline-grid children so the table scrolls inside its card.
- Display stored codes (CashAdvance, HRHead, SpecialNonWorking) through `Formatter::escape(Formatter::label($code))`.

## 2026-11-22 — Icons come from `Icon::svg()`, never emoji (Claude Code)
- Symptom: owner said the system "looks vibecoded"; emoji and Unicode symbols (🖨 ⚙ ♟ ☰ ✓) render as different colour pictures per OS and clash with the copper palette.
- Fix: `Wbpms\Http\View\Icon::svg('name', $size)` — inline line icons that follow the text colour; scripts use `window.WB_ICONS[name]` (layout.php).
- Prevention rule: never type an emoji or symbol as an icon. Add a missing icon to `Icon::PATHS` (24px grid, 2px round stroke). `NoEmojiIconsTest` fails on new emoji; an unknown name renders the `help` icon, so check names exist.

## 2026-11-22 — Effective-dated forms must show the earliest allowed date (Claude Code)
- Symptom: evaluators reported "the current date cannot be selected" on Transfer and Salary; Edit Salary pre-filled a date that is always refused.
- Root cause: a new branch/salary row must start after the current row's start (the old row closes the day before; the CHECK refuses an empty period), but the form only learned that after Save, with an ISO date in the message.
- Fix: `Domain/Employee/EffectiveDateRule` — `min` on the date input, hint with the earliest date, default = today or the earliest day, MM/DD/YY message.
- Prevention rule: any effective-dated form sets `min` from the current record and never pre-fills the current record's own start date.

## 2026-11-22 — Employee search must match full names (Claude Code)
- Symptom: "Juan Dela Cruz" found nothing; only single-column partial matches worked.
- Fix: `Persistence/EmployeeNameSearch::condition($term, $alias, $prefix)` (each word, any order, unique params). Use it for every employee search instead of `last_name LIKE … OR first_name LIKE …`.

## 2026-11-22 — Cleaning prod data: accounts depend on employees (Claude Code)
- Symptom: plan was "remove employees, keep accounts"; old `cleanup-attendance-payroll.sql` keeps only usernames owner/hrhead/support.
- Root cause: `users.employee_id` is ON DELETE SET NULL, so deleting employees silently leaves Employee-role logins with no employee (portal breaks), and staff (Owner/HR Head) accounts are linked to employee rows too. On prod the old script would delete all 4 HR Head accounts and fails on the missing `password_reset_token` table.
- Fix: `scripts/prod_data_cleanup.sh` keeps staff accounts + their linked employee profiles, removes Employee logins with their employees, checks every table is classified, and commits only after SIGNAL checks.
- Prevention rule: never run `cleanup-attendance-payroll.sql` on prod. A data cleanup runs as one transaction with a dry run first; classify every table (keep / wipe / per-employee) and stop on any new table.
- Quirk: a MariaDB 10.11 `mysqldump` starts with `/*M!999999\- enable the sandbox mode */`, which a 10.4 client (XAMPP) rejects — strip line 1 to restore a prod dump locally. Git-Bash `tar` reads `C:/…` as a remote host; use `/c/…` paths.

## 2026-11-22 — Calculation audit vs. rules (Claude Code, verification only, no code changed)
- Method: 45 hand-calculated scenarios against the pure classes (WorkSchedule, PunchDayClassifier, ApprovedOvertimeCalculator, HolidayPayCalculator, SssContributionSchedule, WithholdingTaxCalculator) + Unit suite on VPS PHP 8.3 (390 tests, 0 fail, 17 `pdo_sqlite` errors) + read-only prod check of `work_schedule`, policy and contribution rows.
- Finding 1 (data): live schedule 1 "Regular Shift (07:00-16:00)" has `break_start_time/break_end_time` NULL, so `WorkSchedule::minutesFor()` takes the no-window path: full days are right, but half days are charged the lunch hour (07:00-12:00 → UT 240 not 180; 13:00-16:00 → late 360 not 300). Not assigned to anyone on 11-22.
- Finding 2 (policy): `ApprovedOvertimeCalculator` caps paid OT at `worked − standard`, so lateness shrinks paid OT and is also deducted at ₱1/min (late 30 + approved 16:00-17:00 → 30 OT min paid). Labor Code Art. 88 says undertime is not offset by OT. Owner to decide.
- Doc-vs-code: requirements 10.9 says holiday OT "SHALL NOT be inferred by compounding", but `PayrollService` passes approved holiday OT to `computeBundle()`, which pays (day multiplier × hourly) × 1.30. Matches DOLE; needs owner confirmation.
- Other: EMP-003 is on schedule 7 (Mon–Fri, Sunday rest) against the Sabbath rule; schedules 6 "aaaaa" and 8 "QAT-TEST" are Active; non-holiday Saturday work pays 1.00× (no rest-day premium).
- Prevention rule: a schedule with `break_minutes > 0` should always have a break window; check `work_schedule` rows, not only code, when auditing minutes.

## 2026-11-22 — A safety ceiling is not a typo guard; wage orders are data (Claude Code)
- Symptom: Add Employee accepted a Daily Rate of ₱1,000,000.
- Root cause: `MAX_DAILY_RATE` was 1,000,000 inclusive — chosen to protect DECIMAL columns, so it never caught an extra zero. Position defaults had no server ceiling.
- Fix: one rule, `Domain/Validation/DailyRateRule` (floor = Settings minimum wage, ceiling ₱10,000), used by Employee Add/Edit/Rehire, Salary Management and position defaults; views read the same constants for `min`/`max`/`data-msg-max`.
- Prevention rule: DOLE sets only a floor (NCR ₱755 is the highest minimum in 2026, not a maximum). Keep the ceiling a realistic typo guard and change it in `DailyRateRule` only. A new Region XII wage order is a Settings change (`payroll_policy_version.minimum_daily_wage`, edited in place, not versioned), not a code change; the ₱476 in code is only a fallback. Next step: ₱501 on 01/01/27.
- Update (same day): the owner rolled the minimum wage back to ₱460 (Settings value and `FALLBACK_MINIMUM`). Confirm with the owner before applying a new wage order — it is a business decision, not automatic.
- Update (same day): the adviser set the cap to ₱755 (NCR minimum used as a company cap). The Settings minimum wage is now also limited to ≤ `DailyRateRule::MAX`, so the floor can never exceed the ceiling.

## 2026-11-22 — Bank accounts are BDO only (Claude Code)
- Symptom: the bank form offered BPI, Metrobank, … and free text, but `PayrollController` BDO slip/disbursement queries use `bank_name = 'BDO'`, so any other bank silently dropped the employee from the slip.
- Fix/rule: `FieldRules::PAYROLL_BANK` + `payrollBank()`; the form shows a read-only BDO field and the server refuses anything else (exact, case-sensitive). Change the bank there only.

## 2026-11-22 — The Business Owner is not an employee (Claude Code)
- Symptom: the owner had a ₱700 daily rate and would have been paid by the next payroll run.
- Root cause: HR created an employee profile (position "Business Owner") and the owner login was linked to it; nothing in User Management or payroll prevented it. Payroll pays every employee with a branch assignment and a salary.
- Fix: `AuthenticationPolicy::isLinkedOwner()` blocks the link in `UserService`; `PayrollService::eligibleEmployees()` skips owner-linked employees as a safety net; prod EMP-002 removed.
- Prevention rule: owner accounts keep `users.employee_id` NULL. Only HR Head and Employee accounts have employee profiles, salaries and payroll.


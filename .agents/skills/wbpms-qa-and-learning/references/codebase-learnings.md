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

---

## 3. Regression Test Suite Module Map (173 Cases)

Source of truth: `regression/generate_regression_tests.php` -> `regression/WBPMS_Regression_Test_Suite.xlsx`.

| # | Module | TC Prefix | Range | Focus Areas |
|---|---|---|---|---|
| 1 | Login & Authentication | `AUTH` | `AUTH-001..017` | Role logins, email-only login, RBAC, 30m/12h sessions, CSRF, reset tokens |
| 2 | Dashboard | `DASH` | `DASH-001..005` | Role-scoped tiles & live counts |
| 3 | User Management | `USER` | `USER-001..011` | CRUD, roles, deactivate/archive, self-archive block, blank username fallback, menu visibility, archive sync |
| 4 | Employee Management | `EMP` | `EMP-001..014` | CRUD, branch scope, archive/rehire history, bank details, private docs, biometric enrollment rules |
| 5 | Work Schedule | `SCH` | `SCH-001..012` | 5/6-day schedules, rest days, holidays, overlap warning, assign errors, break window, shift edits |
| 6 | Attendance | `ATT` | `ATT-001..021` | `.xls` OLE validation, idempotency, matching, timesheet, adjustments, OT, policy flags, cancellation |
| 7 | Request Management | `REQ` | `REQ-001..015` | Leave/OT/Cash Advance, 4 paid sick leaves, `Cancelled` (no `Rejected`), filters |
| 8 | Payroll | `PAY` | `PAY-001..016` | Derived computation, HR->Owner workflow, immutability, cancel (`REQ047`), 13th month |
| 9 | Manage Salary | `SAL` | `SAL-001..006` | Daily rate, non-mutating history, effective dates, RBAC |
| 10 | Benefits & Deductions | `BEN` | `BEN-001..010` | SSS/PhilHealth/Pag-IBIG (`PH-STATUTORY-2026`), brackets, deductions |
| 11 | Reports | `REP` | `REP-001..019` | Payroll/attendance/request/contribution/13th reports, BDO overlay slips (`REQ073`), CSV (`REQ076`), Audit Trail |
| 12 | Employee Self-Service | `PORT` | `PORT-001..010` | Scoped attendance/schedule/payslips/requests, PDF download, unlinked account guard |
| 13 | System / Cross-cutting | `SYS` | `SYS-001..017` | Audit trail, `Asia/Manila`, `MM/DD/YY` + 24h, strict MySQL, DB sessions, CSRF/SQLi/XSS, `/health` |

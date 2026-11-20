# WBPMS Always-Active Engineering, Testing & Multi-Agent Attribution Rules

This rule file is **always active** for every AI agent operating in `c:\xampp\htdocs\wbpms` (including **Antigravity (Gemini)**, **Claude Code**, **OpenAI Codex**, **Kiro**, and any other assistant). Read and follow it before modifying any file.

For deep procedural runbooks, activate the workspace skill `.agents/skills/wbpms-qa-and-learning/SKILL.md`.

---

## 1. Mandatory AI Agent Attribution & Work Ledger

Every AI agent that performs a **Modification**, **Integration**, or **Fix** in this repository MUST record its work in the table below (and log detailed notes in `.agents/skills/wbpms-qa-and-learning/references/agent-work-ledger.md`) before finishing its turn.

### Attribution Rules
1. **Always include your exact AI Name** (e.g., `Antigravity (Gemini 4 Neon)`, `Claude Code`, `OpenAI Codex`, `Kiro`). Never leave the agent column blank or overwrite another agent's attribution.
2. **Never fabricate history**: If referencing prior commits or uncommitted work whose agent is unknown, mark it as `Unattributed (confirm with owner)` until verified.
3. **Size discipline**: Keep `AGENTS.md` under 18 KB (hard system cap: 24 KB). When the active ledger below exceeds 20 rows, rotate older rows into `.agents/skills/wbpms-qa-and-learning/references/agent-work-ledger.md` and keep the most recent entries here.

### Active AI Modification, Integration & Fix Ledger

| Date | AI Name | Type | Files / Scope | Summary of Work Done | Verification |
|---|---|---|---|---|---|
| 2026-11-20 | **Claude Code (Claude Opus 5.5)** | Fix | Schedules, Employees, Users, Attendance; migration `20261120000028`; `tests/Unit/QaPayrollSheetRegressionTest.php`; regression generator | "CAPSTONE - PAYROLL" QA sheet: assign-schedule & employee-create HTTP 500s, duplicate schedule name, break window, shift name on edit, biometric required/duplicate/edit, user menu clipping, archived login status, import "? 0", cancelled days hidden, exact late evidence. | `php -l` pass; PHPStan no new errors; PHPUnit blocked (PHP 8.2) — stand-in 38/38 touched tests; regression 157→173 (`USER-010..011`, `EMP-011..014`, `SCH-009..012`, `ATT-016..021`). Live re-check pending. |
| 2026-11-20 | **Claude Code (Claude Opus 5.5)** | Fix | Attendance import/policy, `AttendanceScheduleRecalculator`, `scripts/repair_timeout_only_punches.php`, roster seeder rest days | Schedule lookup + recalculation on schedule change, cancel guard/rebuild, lone time-out punch, Sabbath-aware AWOL/late streaks, 6200-row timesheet cap. | `php -l` pass; stand-in 30/30; May–Aug 2025 workbooks 1,745/1,745 days match; PHPUnit blocked (PHP 8.2). |
| 2026-11-20 | **Antigravity (Gemini 4 Neon)** | Integration | `AGENTS.md`, `.agents/skills/wbpms-qa-and-learning/*` | Created always-active WBPMS rule with AI-named work ledger, mandatory unit/regression testing gates, and codebase-learning skill + references. | Verified file paths, size cap, YAML frontmatter, and workspace state against repo. |
| 2026-11-20 | `[Pending Owner Confirmation — Prior Agent]` | Modification / Fix | `composer.json`, `composer.lock` | Aligned PHP requirement and platform config from `^8.5` / `8.5.0` to `^8.3` / `8.3.0`; updated `composer serve` to pass `router.php`. | Pending PHP >= 8.3 PHPUnit run. |
| 2026-11-20 | `[Pending Owner Confirmation — Prior Agent]` | Fix | `database/seeds/DemoEmployeeSeeder.php`, `database/seeds/HolidayCalendar2026Seeder.php` | Updated demo seeder for decoupled `work_schedule` templates + `employee_schedule_assignment`; fixed 2026 special non-working holidays to `SpecialNonWorking` (`1.30`). | Pending DB seed/integration verification. |
| 2026-11-20 | `[Pending Owner Confirmation — Prior Agent]` | Integration | `database/seeds/EmployeeRosterSeeder.php`, `.htaccess`, `Agent Handshake.md` | Added idempotent CSV roster importer (`storage/private/employee_roster.csv`), root `.htaccess` access-deny/redirect rules, and `Agent Handshake.md` onboarding doc. | Pending PHP syntax + seeder verification. |
| 2026-10-25..11-11 | `[Historical Repo Commits — see git log]` | Fix / Integration | Controllers, Views, Migrations `024..027`, Reports, Audit Trail | Key baseline fixes preserved: `Formatter::escape()` (`cee0ad3`), PhilHealth 5% / SSS 15% (`1aa89ec`), `PH-STATUTORY-2026` (`82aa438`), `chk_salary_period` guard (`80219dc`), Railway auto-migrate (`028e7bd`), HY093 PDO fix (`c309d33`), 24h text time inputs (`dd1b8e2`, `04ad206`), Audit Trail (`46a4896`), BDO overlay slips (`33072a0`), REQ047 cancellation migration (`aaa4a7e`). | Historical baseline (`git log`). |

---

## 2. Mandatory Unit Testing, Integration Testing & Regression Testing Gates

No code change, bug fix, or integration may be declared complete without verifying all applicable layers and reporting results honestly:

1. **Syntax Check (Always)**: Run `php -l` on every modified or created `.php` file.
2. **Unit Tests (`tests/Unit`)**:
   - Run `vendor/bin/phpunit --configuration phpunit.xml --testsuite Unit` (requires **PHP >= 8.3**).
   - Add or update unit tests in `tests/Unit/` for every pure domain calculation, parser rule, policy evaluation, or bug fix.
3. **Integration Tests (`tests/Integration`)**:
   - Run `vendor/bin/phpunit --configuration phpunit.xml --testsuite Integration` against the migrated `wbpms_test` database (`vendor/bin/phinx migrate -c phinx.php -e testing`).
   - **Silent-Skip Guard**: `IntegrationTestCase` skips the suite instead of failing when MySQL/`wbpms_test` is unreachable. Always report exact counts: `passed / failed / errors / skipped`. Never call a run "green" if integration tests were skipped without stating so.
4. **Static Analysis (PHPStan Level 6)**:
   - Run `vendor/bin/phpstan analyse --configuration phpstan.neon` and ensure zero new errors in `app/`.
5. **Regression Testing (`regression/`)**:
   - Map every modified module to its corresponding Test Case IDs in `regression/generate_regression_tests.php` (157 cases across 13 modules: `AUTH`, `DASH`, `USER`, `EMP`, `SCH`, `ATT`, `REQ`, `PAY`, `SAL`, `BEN`, `REP`, `PORT`, `SYS`).
   - When adding a feature, fixing a regression bug, or changing a business rule, add/update the corresponding test case in `regression/generate_regression_tests.php` and regenerate `regression/WBPMS_Regression_Test_Suite.xlsx` via `php regression/generate_regression_tests.php`.
   - Report the affected TC IDs in your completion summary.
6. **Honest Environment Reporting**:
   - Local XAMPP PHP (`C:\xampp\php\php.exe`) is **PHP 8.2.12**, which cannot run PHPUnit 12 (`>= 8.3`). If PHP >= 8.3 is unavailable in the current shell, explicitly report the blocker and what checks *were* run (e.g., `php -l`, PHPStan, regression generator) — never fabricate a passing PHPUnit result.

---

## 3. Core WBPMS Codebase Invariants (Learned from the Codebase)

1. **Repository & Git Safety**:
   - Never commit, push, or open PRs unless the repository owner explicitly asks (pushing to `main` triggers Railway deploy + live `phinx migrate -e production`).
   - Never commit `.env`, `*.sql`, `*.zip`, `storage/private/*`, or temporary `check_*.php` / `diag_*.php` scripts.
   - Never run destructive DB commands (`phinx rollback`, `DROP`, `TRUNCATE`, cleanup/reseed SQL) against anything other than a confirmed local throwaway database.
2. **PHP & Framework Constraints**:
   - Frameworkless PHP (`Wbpms\` -> `app/`, `Wbpms\Tests\` -> `tests/`). Do not add Laravel, Symfony, or an ORM.
   - Every PHP file starts with `declare(strict_types=1);` and complies with PHPStan level 6.
   - Keep syntax compatible with **PHP 8.3** (Railway production) and avoid PHP 8.4+ syntax (property hooks, asymmetric visibility, `array_find`, pipe operator); watch PHP 8.2 compatibility for local XAMPP runs.
3. **Security Invariants**:
   - **CSRF**: Every mutating HTML form MUST include `<input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">`.
   - **RBAC**: Every non-public route in `routes/web.php` MUST declare an explicit roles array (`BusinessOwner`, `HRHead`, `Employee`). `routes/developer_b.php` is unused.
   - **Employee Scoping**: All Employee self-service queries MUST go through `EmployeeScope`; never trust URL IDs.
   - **Output Escaping**: Escape every dynamic view value with `Formatter::escape()`. Never use `Formatter::date()` or `Formatter::time()` as an escaper (`cee0ad3`).
   - **SQL Safety**: Use PDO prepared statements only. Never reuse the same named parameter twice in a single query (`PDO::ATTR_EMULATE_PREPARES => false` throws `HY093`, see `c309d33`).
   - **Storage**: Never write uploads or generated files under `public/`; use `storage/private/`.
4. **Domain & Schema Invariants**:
   - **Migrations**: Forward-only, idempotent Phinx migrations in `database/migrations/` with timestamps strictly newer than `20261111000027`. Never edit already-run migrations.
   - **Payroll & Requests**: Payroll figures are derived (never hand-edited); approved runs/payslips are immutable; declined requests use status `Cancelled` (there is no `Rejected` status); sick leave has 4 paid days; active contribution policy is `PH-STATUTORY-2026` (PhilHealth 5%, SSS 15%); payroll cutoff is Friday–Thursday (confirm with owner before changing).
   - **Formatting & Time**: Timezone is `Asia/Manila`; UI dates are `MM/DD/YY`; times are 24-hour using `<input type="text" pattern="...">` (never `type="time"`).
   - **Architecture**: Put new pure business logic in `app/Domain/`, orchestration/transactions in `app/Application/`, and SQL in `app/Infrastructure/Persistence/`. (Existing large controllers have inline SQL — match local style on surgical edits, but do not add new business logic to controllers.)

---

## 4. Continuous Codebase Learning Protocol

Whenever you resolve a bug, uncover a doc-vs-code mismatch, fix a failing test, or discover an environment/schema quirk:
1. Verify against the live code first (trust code over outdated docs in `docs/`).
2. Flag doc-vs-code discrepancies explicitly to the user rather than silently rewriting specs.
3. Record the empirical lesson (Symptom, Root Cause, Fix, Prevention Rule) in `.agents/skills/wbpms-qa-and-learning/references/codebase-learnings.md`.
4. Update the AI Attribution Ledger in Section 1 (and `references/agent-work-ledger.md`) with your AI name and verification status.

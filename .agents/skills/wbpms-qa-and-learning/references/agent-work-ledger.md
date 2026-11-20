# WBPMS Multi-Agent Modification, Integration & Fix Ledger

Detailed chronological log of modifications, integrations, and fixes performed by AI agents in `c:\xampp\htdocs\wbpms`. Keep `AGENTS.md` compact (< 18 KB) and store full per-task details here.

## Entry Template

```markdown
### YYYY-MM-DD — [AI Name] — [Modification | Integration | Fix]: Short Title
- **AI Agent**: `<Exact AI Name, e.g., Antigravity (Gemini 4 Neon) | Claude Code | OpenAI Codex | Kiro>`
- **Type**: `Modification` | `Integration` | `Fix`
- **Files Touched**: `path/to/file1`, `path/to/file2`
- **Work Done**: Concrete summary of changes and why.
- **Tests & Regression**:
  - `php -l`: Pass/Fail
  - PHPUnit Unit: Pass/Fail/Blocked (exact counts)
  - PHPUnit Integration: Pass/Fail/Skipped/Blocked (exact counts)
  - PHPStan L6: Pass/Fail/Not Run
  - Regression TC IDs: e.g., `PAY-007`, `SYS-009`
```

---

## Recorded Entries

### 2026-11-20 — Claude Code (Claude Opus 5.5) — Fix: "CAPSTONE - PAYROLL" QA Sheet Defects
- **AI Agent**: Claude Code (Claude Opus 5.5)
- **Type**: Fix
- **Files Touched**:
  - `app/Http/Controllers/ScheduleController.php`, `app/Infrastructure/Persistence/ScheduleRepository.php`, `resources/views/hr/schedules/index.php`, `resources/views/hr/schedules/edit.php`, `resources/views/layout.php`
  - `database/migrations/20261120000028_allow_single_day_schedule_assignment.php` (new)
  - `app/Http/Controllers/EmployeeController.php`, `app/Infrastructure/Persistence/EmployeeRepository.php`, `resources/views/hr/employees/edit.php`
  - `app/Infrastructure/Persistence/EmployeeLifecycleRepository.php`, `resources/views/users/index.php`
  - `app/Http/Controllers/AttendanceController.php`, `app/Application/AttendancePolicyService.php`
  - `tests/Unit/QaPayrollSheetRegressionTest.php` (new), `tests/Unit/Attendance/AttendancePolicyServiceTest.php`
  - `regression/generate_regression_tests.php`, `regression/WBPMS_Regression_Test_Suite.xlsx`
- **Work Done**:
  - SCH TC2: assign schedule no longer 500s (uncaught `RuntimeException` for an earlier date; `chk_esa_period` violation for a next-day reassign). Form errors + migration relaxing the check to `effective_to >= effective_from` (inclusive end). Removed redundant "+ Assign".
  - SCH TC3: duplicate schedule name pre-checked; raw `SQLSTATE` text no longer shown (`PDOException extends RuntimeException`). Edit no longer 500s on a duplicate. Removed modal "×".
  - SCH TC4: break duration derived from break start/end (server + read-only field); break window shown in table; break must be inside the shift.
  - SCH TC5: times were saving, but names like "Day Shift (07:00-16:30)" kept the old shift; a trailing `(HH:MM-HH:MM)` now follows edited times.
  - EMP TC2/TC3/TC11: device + Enroll ID required on create; duplicate active Enroll ID rejected on create/edit; unique-key clashes (`uq_ebe_device_code_from`, `uq_users_account_email`, `uq_users_username`) and provisioning `RuntimeException`s become field errors instead of HTTP 500; email confirmation prompt on create.
  - EMP TC4: `update()` ignored device/Enroll ID; now applied via `EmployeeRepository::changeBiometricEnrollment()` (correct in place if never matched, else end today + new from today).
  - USER TC1: Actions menu opened below the viewport on bottom rows; it now flips upward. USER TC2: archiving an employee archives the login (was `Inactive`).
  - ATT TC1: Recent Imports query lacked `source_year`/`source_month` ("? 0"). ATT TC5: cancelled rows/batches excluded from matrix, list and counts. ATT TC4: late evidence rebuilt per streak (`withLateEvidence`).
  - ATT TC3/TC6 were fixed earlier in this session (see next entry); verified by unit tests.
- **Tests & Regression**:
  - `php -l`: Pass (all touched files)
  - PHPUnit Unit: **Blocked** (PHP 8.2.12; PHPUnit 12 needs >= 8.3). Touched-module tests run through a scratch PHPUnit stand-in: 38 passed, 0 failed (incl. 8 new).
  - PHPUnit Integration: **Not run** (blocked by PHP version; local MySQL was also stopped during verification).
  - PHPStan L6: Pass — no new errors (7 pre-existing remain).
  - Regression: 157 -> 173 cases; added `USER-010..011`, `EMP-011..014`, `SCH-009..012`, `ATT-016..021`.
  - Live browser repro (before fix): SCH TC2 both 500s, SCH TC5 name, USER TC1 clipping. Post-fix live re-check pending (MySQL/Apache stopped).

### 2026-11-20 — Claude Code (Claude Opus 5.5) — Fix: Attendance Calculation, Cancellation & Sabbath Rest Day
- **AI Agent**: Claude Code (Claude Opus 5.5)
- **Type**: Fix
- **Files Touched**: `app/Domain/Attendance/WorkSchedule.php`, `app/Domain/Attendance/TimesheetGenerator.php`, `app/Domain/Attendance/AttendancePolicyEvaluator.php`, `app/Application/AttendancePolicyService.php`, `app/Infrastructure/Persistence/PdoAttendanceImportGateway.php`, `app/Infrastructure/Persistence/AttendanceScheduleRecalculator.php` (new), `app/Http/Controllers/AttendanceController.php`, `app/Http/Controllers/ScheduleController.php`, `scripts/repair_timeout_only_punches.php` (new), `database/seeds/EmployeeRosterSeeder.php` (working days only), tests under `tests/Unit/Attendance/`
- **Work Done**: Schedule lookup at import ignored superseded (Archived) assignments and used an exclusive `effective_to`; attendance minutes are now recalculated when a schedule is assigned/edited (QA ATT TC6, Albert Indo 103-min undertime came from a 07:30–17:00 assignment). Batch cancel no longer cascades onto days a later upload depends on (guard) and rebuilds days still backed by live punches (QA ATT TC5 part 2). Lone after-midpoint punch stored as time-out. AWOL/late streaks skip rest days and holidays (QA ATT TC3). Timesheet row cap 500 -> 6200. Roster seeder Sun–Fri with Saturday rest.
- **Tests & Regression**: `php -l` Pass; PHPUnit blocked (PHP 8.2); stand-in run 30/30 for attendance tests; PHPStan no new errors; May–Aug 2025 workbooks cross-checked 1,745/1,745 employee-days. Regression `ATT-017`, `ATT-019..021`, `SCH-012`.

### 2026-11-20 — Antigravity (Gemini 4 Neon) — Integration: Always-Active Rule & QA/Learning Skill
- **AI Agent**: Antigravity (Gemini 4 Neon)
- **Type**: Integration
- **Files Touched**:
  - `AGENTS.md`
  - `.agents/skills/wbpms-qa-and-learning/SKILL.md`
  - `.agents/skills/wbpms-qa-and-learning/references/agent-work-ledger.md`
  - `.agents/skills/wbpms-qa-and-learning/references/codebase-learnings.md`
- **Work Done**: Established always-active workspace governance (`AGENTS.md`) with mandatory AI-named work attribution, unit/integration/PHPStan/regression testing gates, and codebase-grounded engineering invariants, paired with a progressive-disclosure skill for testing and continuous learning.
- **Tests & Regression**: No PHP source files modified; empirically verified workspace PHP runtime (`C:\xampp\php\php.exe` 8.2.12), regression generator (157 cases across 13 modules), PHPUnit suites (20 Unit / 8 Integration classes), and `.phpunit.result.cache` baseline.

### 2026-11-20 — [Pending Owner Confirmation — Prior Agent] — Modification / Integration / Fix: Uncommitted Working Tree Set
- **AI Agent**: Pending owner confirmation (observed in `git status` on 2026-11-20)
- **Type**: Modification / Integration / Fix
- **Files Touched**:
  - `Agent Handshake.md` (new)
  - `.htaccess` (new)
  - `database/seeds/EmployeeRosterSeeder.php` (new)
  - `composer.json`, `composer.lock` (modified)
  - `database/seeds/DemoEmployeeSeeder.php` (modified)
  - `database/seeds/HolidayCalendar2026Seeder.php` (modified)
- **Work Done**:
  - Added repository onboarding/invariants doc (`Agent Handshake.md`).
  - Added root `.htaccess` redirecting `/wbpms/` to `/wbpms/public/login` and denying direct web access to non-public files.
  - Added idempotent `EmployeeRosterSeeder` importing staff from gitignored `storage/private/employee_roster.csv` with auto-provisioned login accounts and credentials export to `storage/private/employee_roster_credentials.csv`.
  - Aligned `composer.json` / `composer.lock` PHP requirement and platform from `8.5` to `^8.3` / `8.3.0` and updated `composer serve` to include `router.php`.
  - Updated `DemoEmployeeSeeder` for decoupled `work_schedule` templates + `employee_schedule_assignment`.
  - Updated `HolidayCalendar2026Seeder` special non-working holidays to `SpecialNonWorking` (`1.30` multiplier).
- **Tests & Regression**: Affected areas: `SCH-004`, `SCH-005`, `EMP-001`, `SYS-007`, `SYS-012`.

### 2026-10-17 to 2026-11-11 — Historical Committed Baseline (Git History)
- **AI Agent**: Repository contributors / prior AI-assisted sessions (unattributed in git commit trailers; commit `e41a575` notes `.kiro` was excluded from commits).
- **Key Commits Preserved as Codebase Invariants**:
  - `cee0ad3` (2026-11-11) — Fix: Use `Formatter::escape()` instead of `Formatter::date()` for late evidence items.
  - `1aa89ec` (2026-11-11) — Fix: Correct PhilHealth (5%) and SSS (15%) contribution rates.
  - `82aa438` (2026-11-11) — Fix: Rename default contribution policy to `PH-STATUTORY-2026`.
  - `80219dc` (2026-11-11) — Fix: Guard against `chk_salary_period` violation on salary update.
  - `028e7bd` (2026-11-11) — Fix: Run `phinx migrate -e production` on every Railway build.
  - `c309d33` (2026-11-11) — Fix: Resolve `HY093` duplicate PDO parameter in `synchroniseApprovedOvertime`.
  - `dd1b8e2`, `04ad206` (2026-11-11) — Fix: Enforce `type="text"` + `pattern` 24-hour time inputs (avoid browser 12h `type="time"`).
  - `46a4896` (2026-11-07) — Integration: Add Audit Trail module (`AuditController`, `AuditLogRepository`) and benefits updates.
  - `33072a0` (2026-11-07) — Integration: Transaction slip print mode as data-only overlay for pre-printed BDO slips (`REQ073`).
  - `aaa4a7e` (2026-10-25) — Fix: Idempotent migration `025` ensuring `payroll_run` cancellation schema on Railway (`REQ047`).

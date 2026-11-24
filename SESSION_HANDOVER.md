# Active Session Handover State — WBPMS
> Last Updated: 2026-11-24T02:25:00+08:00 | Active Driver: Antigravity (Gemini 4 Neon) — New laptop (`LAPTOP-7SS611QQ`, PHP 8.5.13 + MariaDB 10.11.19) verified; prior `ATT-033` & `PAY-036` fixes deployed, not committed
> Protocol: Tri-Agent Distributed Peer Protocol (Claude Code <-> OpenAI Codex <-> Antigravity)
> Scope: `c:\xampp\htdocs\wbpms` (Light Diamond Enterprises — Frameworkless PHP 8.3 + MariaDB/PDO)

---

## 1. Current Objective & Recent Accomplishments
- **Latest Completed Task (Antigravity, 2026-11-23 11:03 Manila; deployed to VPS, backup `att033_cancelled_reimport_20261123_030350`; NOT committed)**:
  - Diagnosed prod `/var/www/wbpms/storage/private/logs/app-error.log` (11× `SQLSTATE[23000] / 1451` on `attendance_adjustment_ibfk_1`): July re-import failed because cancelled batch `#17` had manual HR adjustments on `attendance_id=9807` (`EMP-003`, `2026-07-29`), and `PdoAttendanceImportGateway` ran `DELETE FROM attendance` + `INSERT`.
  - Fixed `PdoAttendanceImportGateway::saveGeneratedAttendance()` to update `Cancelled` rows in-place (preserving `attendance_id` and append-only `attendance_adjustment` audit history) and `createImportBatch()` to preserve/detach adjusted cancelled rows (`status='Cancelled', import_batch_id=NULL`) before deleting a superseded same-checksum batch. Added `CancelledAttendanceReimportTest` (3/3) and regression `ATT-033` (271 cases, 0 dupes).
- **Defense-day system check (Claude Code, 2026-11-23 ~07:50 Manila; deployed, NOT committed)**: crawled 194 signed-in pages on a throwaway prod clone (0 server errors, pages 10–77 ms) and fixed Owner payslip print "Back" link (`PAY-036`, backup `pay036_owner_payslip_back_20261123_004221`).
- **Earlier Tasks (deployed, NOT committed)**: Monthly Cash Advance Policy (`REQ-026`, `PAY-035`, backup `req026_pay035_cash_advance_monthly_20261122_235942`); removed `Probationary` from Employee Type dropdown (`EMP-032`, backup `emp032_no_probationary_20261122_224007`).

---

## 2. Key Working & Governance Files
- `SESSION_HANDOVER.md`: Hot active state (< 80 lines; refresh in-place at session end).
- `AGENTS.md`: Always-active guardrails & recent AI ledger (strictly < 18 KB).
- `Agent Handshake.md`: Structural reference, invariants, and doc-vs-code truth table.
- `.agents/rules/*.md`: Adapted WBPMS behavioral, collaboration, defensive, and UI/UX rules.
- `.agents/skills/wbpms-qa-and-learning/references/`: Canonical `agent-work-ledger.md` and `codebase-learnings.md`.

---

## 3. Verification & Environment Status
- **Local Machine (`LAPTOP-7SS611QQ`, moved from old laptop 2026-11-24)**:
  - **Local PHP (`C:\xampp\php\php.exe`, not on `PATH`)**: **PHP 8.5.13** with `pdo_mysql`, `pdo_sqlite`, `mbstring`, `openssl`, `gd`, `zip` enabled (`gd`/`zip` enabled 2026-11-24; backup `php.ini.bak_20261124`). Runs `php -l`, PHPUnit 12 Unit suite, PHPStan L6, and `regression/generate_regression_tests.php` natively. Composer is not installed on `PATH` (`vendor/` is present). Still avoid PHP 8.4/8.5-only syntax because production runs PHP 8.3.6.
  - **Local MariaDB (`C:\xampp\mysql\bin\mysqld.exe`)**: **MariaDB 10.11.19** (matches prod 10.11); `wbpms` and `wbpms_test` data folders exist, `.env` targets `127.0.0.1:3306`; XAMPP Apache & MySQL currently stopped.
- **Production Runtime**: VPS (`109.123.239.182` / `lightdiamond-payroll.online`), `/var/www/wbpms` (not a git checkout), PHP 8.3.6 + MariaDB 10.11.
- **Last Verified Test Baseline (2026-11-24 on local PHP 8.5.13)**: Full Unit suite **507/507 passed** (6,148 assertions, 0 failures, 0 errors, 25 PHPUnit notices — `pdo_sqlite` works locally); PHPStan L6 3 pre-existing notices (0 new); `php -l` 3/3 OK on modified PHP files; regression generator OK (`271` cases, 13 modules, `60 KB`). Integration suite not run yet (local MariaDB stopped).
- **Working Tree**: Uncommitted local changes for `PAY-036` and `ATT-033` (both deployed, md5 live = local); `BDO-Transaction-Slip.pdf` and `docs/WBPMS_Calculation_Defense_Guide.pdf` untracked. Do NOT commit, push, or deploy without explicit owner confirmation.

---

## 4. Locked WBPMS Technical Contracts (Zero-Regression Policy)
| # | Invariant Area | Operational Contract |
|---|---|---|
| 1 | **Frameworkless PHP 8.3** | PSR-4 `Wbpms\` -> `app/`, `declare(strict_types=1);`, PDO prepared statements only (no ORM/framework), avoid PHP 8.4+ syntax. |
| 2 | **PDO & Error Safety** | Distinct named placeholders (`HY093`); branch `PDOException` before `RuntimeException`; never leak raw SQL messages to views. |
| 3 | **Security & Scoping** | Explicit roles (`BusinessOwner`, `HRHead`, `Employee`) in `routes/web.php`, `_csrf` on mutating forms, `EmployeeScope` on self-service, `Formatter::escape()` everywhere. |
| 4 | **Payroll & Statutory** | Derived payroll figures; approved runs/payslips immutable; `PH-STATUTORY-2026` (PhilHealth 5%, SSS 15% + EC); 4 paid sick leaves; Friday-Thursday cutoff; `Cancelled` (no `Rejected`); CA max ₱2,000/mo scaled by ₱500 × remaining open paydays. |
| 5 | **UI & Print Hygiene** | Copper/espresso tokens (`app.css`), `Icon::svg()` only (`NoEmojiIconsTest`), `MM/DD/YY` dates, 24h text time inputs, on-page confirms (`SYS-018`), calibrated BDO slip print. |
| 6 | **Migrations & Git** | Forward-only idempotent Phinx migrations (never edit ran migrations); no commit/push/deploy/destructive DB ops without explicit owner approval. |

---

## 5. Immediate Next Steps
1. Integration suite ran on VPS PHP 8.3 (2026-11-23, isolated temp DB, dropped after): 112 tests, 98 pass, 13 errors, 1 failure — all stale test fixtures (`RequestLifecycleTest` ENUM `type_name` + schema drift, `AttendanceAdjustmentServiceTest` missing `site_id`, one CA amount vs new cap, one missing 2026 holiday fixture). Owner to decide on fixing the fixtures. Fresh `phinx migrate` on MariaDB fails at `20260904000010` (`DROP CHECK`). Signed-in cash-advance pages not checked live by an agent.
2. Pending real-world check from HR: one live BDO deposit slip print at `0.0 mm` toolbar offset to confirm `REP-008` recalibration.

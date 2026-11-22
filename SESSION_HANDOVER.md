# Active Session Handover State — WBPMS
> Last Updated: 2026-11-23T07:05:00+08:00 | Active Driver: Claude Code (Claude Opus 5.5) — deployed Antigravity's `REQ-026`/`PAY-035`
> Protocol: Tri-Agent Distributed Peer Protocol (Claude Code <-> OpenAI Codex <-> Antigravity)
> Scope: `c:\xampp\htdocs\wbpms` (Light Diamond Enterprises — Frameworkless PHP 8.3 + MariaDB/PDO)

---

## 1. Current Objective & Recent Accomplishments
- **Latest Completed Task (Antigravity; deployed to VPS by Claude Code 2026-11-23 06:59 Manila, backup `req026_pay035_cash_advance_monthly_20261122_235942` incl. DB dump; NOT committed)**:
  - Implemented Monthly Cash Advance Policy (`REQ-026`, `PAY-035`, Option A approved by owner): `CashAdvanceMonthlyPolicy` enforces max ₱2,000/month scaled to `₱500 × remaining open Friday paydays` in the `pay_date` month (excluding already-Approved paydays, blocking new requests while a prior-month balance is unpaid, with `FOR UPDATE` row lock on Owner approval). `PayrollService` now allocates up to ₱500/wk across multiple due advances (1 deduction row per `history_id`, preserving ₱0 net-pay floor), and HR/Employee request forms display a live weekly cap hint. Zero DB migrations required.
- **Previous Task (Antigravity — deployed to VPS, backup `emp032_no_probationary_20261122_224007`, not committed)**:
  - Removed `Probationary` from Employee Type dropdown (`edit.php`, `rehire.php`) and tightened server validation (`EmployeeController`, `EMP-032`).
- **Earlier Tasks (Antigravity / Claude Code — committed to `main` & deployed)**:
  - `ReviewRequired` attendance confirmation + raw biometric punches + `INCOMPLETE` vs `REVIEW` badges (`ATT-032`); SUGO governance rules/skills adapted to WBPMS; BDO slip recalibration (`REP-008`), Frequency report range (`ATT-031`), HR New Request balances (`REQ-025`), Archive unstarted assignments (`USER-016`), 2025 holiday guard (`PAY-034`).

---

## 2. Key Working & Governance Files
- `SESSION_HANDOVER.md`: Hot active state (< 80 lines; refresh in-place at session end).
- `AGENTS.md`: Always-active guardrails & recent AI ledger (strictly < 18 KB).
- `Agent Handshake.md`: Structural reference, invariants, and doc-vs-code truth table.
- `.agents/rules/*.md`: Adapted WBPMS behavioral, collaboration, defensive, and UI/UX rules.
- `.agents/skills/wbpms-qa-and-learning/references/`: Canonical `agent-work-ledger.md` and `codebase-learnings.md`.

---

## 3. Verification & Environment Status
- **Local PHP (`C:\xampp\php\php.exe`)**: PHP 8.2.12 — supports `php -l`, PHPStan L6, and `regression/generate_regression_tests.php`, but **cannot** run PHPUnit 12 (requires PHP >= 8.3). Local XAMPP MariaDB is currently stopped.
- **Production Runtime**: VPS (`109.123.239.182` / `lightdiamond-payroll.online`), `/var/www/wbpms` (not a git checkout), PHP 8.3.6 + MariaDB 10.11.
- **Last Verified Test Baseline (2026-11-23 on VPS PHP 8.3.6 `/tmp` copy)**: Targeted Unit 60/60 (445 assertions); full Unit suite 504 tests, 0 failures, 18 known env errors (`pdo_sqlite` + prod seeds); PHPStan L6 0 errors; read-only MariaDB 10.11 prod SQL check passed 4/4; regression workbook at 269 cases (`REQ-026`, `PAY-035` added, 0 duplicates).
- **Working Tree**: Uncommitted local changes for `EMP-032` and `REQ-026`/`PAY-035` (both deployed, md5 live = local); `BDO-Transaction-Slip.pdf` intentionally untracked. Do NOT commit, push, or deploy without explicit owner confirmation.

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
1. Await owner confirmation to commit uncommitted work (`EMP-032`, `REQ-026`, `PAY-035`; all live). Integration suite for `RequestLifecycleTest` still not run; signed-in cash-advance pages not checked live by an agent.
2. Pending real-world check from HR: one live BDO deposit slip print at `0.0 mm` toolbar offset to confirm `REP-008` recalibration.

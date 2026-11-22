# Active Session Handover State — WBPMS
> Last Updated: 2026-11-22T23:53:00+08:00 | Active Driver: Antigravity (Gemini 4 Neon)
> Protocol: Tri-Agent Distributed Peer Protocol (Claude Code <-> OpenAI Codex <-> Antigravity)
> Scope: `c:\xampp\htdocs\wbpms` (Light Diamond Enterprises — Frameworkless PHP 8.3 + MariaDB/PDO)

---

## 1. Current Objective & Recent Accomplishments
- **Latest Completed Task (Antigravity — deployed to VPS & committed to `main`)**:
  - Investigated prod `EMP-021` July 2026 report (`07/20` 4-punch `ReviewRequired` vs `07/24` 1-punch `Incomplete` date mix-up) and fixed the underlying `ReviewRequired` workflow bug + UX gaps (`ATT-032`):
    - `AttendanceAdjustmentService::adjust()` now allows confirming `ReviewRequired` → `Complete` without altering times (`adjustment_type = 'review_confirmed'`, logs `old.status`/`new.status`), while still blocking unchanged `Complete` saves and approved payrolls.
    - Added `app/Domain/Attendance/AttendanceReviewNote.php` and `AttendanceAdjustmentService::rawBiometricPunches()` (excluding `Cancelled`/`Processing` batches) so `adjust.php` shows immutable raw punches + why the day needs review.
    - Split Attendance matrix badges (`index.php`) into yellow `INCOMPLETE` vs red `REVIEW`.
- **Earlier Today (Antigravity — committed to `main`)**: Inherited and adapted SUGO's reusable `.md` rules/skills natively for WBPMS (`.agents/rules/`, `ux-flow-personality`, `CLAUDE.md`, `.codex/instructions.md`, `SESSION_HANDOVER.md`).
- **Recent Production Fixes Included in Today's `main` Commit (Claude Code — deployed to VPS)**:
  - BDO slip print recalibration (`REP-008`), Frequency report & policy-flag date range preservation (`ATT-031`), HR New Request balances (`REQ-025`), Employee/User archive unstarted-assignment cleanup (`USER-016`), 2025 holiday seeder + compute guard (`PAY-034`).

---

## 2. Key Working & Governance Files
- `SESSION_HANDOVER.md`: Hot active state (< 80 lines; refresh in-place at session end).
- `AGENTS.md`: Always-active guardrails & recent AI ledger (strictly < 18 KB).
- `Agent Handshake.md`: Structural reference, invariants, and doc-vs-code truth table.
- `.agents/rules/*.md`: Adapted WBPMS behavioral, collaboration, defensive, and UI/UX rules.
- `.agents/skills/wbpms-qa-and-learning/references/`: Canonical `agent-work-ledger.md` and `codebase-learnings.md`.

---

## 3. Verification & Environment Status
- **Local PHP (`C:\xampp\php\php.exe`)**: PHP 8.2.12 — supports `php -l`, PHPStan L6, and `regression/generate_regression_tests.php`, but **cannot** run PHPUnit 12 (requires PHP >= 8.3).
- **Production / Railway Runtime**: PHP 8.3 (avoid PHP 8.4+ syntax).
- **Last Verified Test Baseline (2026-11-22 on VPS PHP 8.3)**: Unit suite 478 tests, 0 failures, 17 pre-existing errors (missing `pdo_sqlite` on VPS); regression workbook at 266 cases (`regression/WBPMS_Regression_Test_Suite.xlsx`, `ATT-032` added).
- **Working Tree**: Clean on `main` (2026-11-22 fixes + governance rules committed and pushed to `capstone/main`; `BDO-Transaction-Slip.pdf` intentionally kept untracked/local). Do NOT commit or push future work unless owner explicitly asks.

---

## 4. Locked WBPMS Technical Contracts (Zero-Regression Policy)
| # | Invariant Area | Operational Contract |
|---|---|---|
| 1 | **Frameworkless PHP 8.3** | PSR-4 `Wbpms\` -> `app/`, `declare(strict_types=1);`, PDO prepared statements only (no ORM/framework), avoid PHP 8.4+ syntax. |
| 2 | **PDO & Error Safety** | Distinct named placeholders (`HY093`); branch `PDOException` before `RuntimeException`; never leak raw SQL messages to views. |
| 3 | **Security & Scoping** | Explicit roles (`BusinessOwner`, `HRHead`, `Employee`) in `routes/web.php`, `_csrf` on mutating forms, `EmployeeScope` on self-service, `Formatter::escape()` everywhere. |
| 4 | **Payroll & Statutory** | Derived payroll figures; approved runs/payslips immutable; `PH-STATUTORY-2026` (PhilHealth 5%, SSS 15% + EC); 4 paid sick leaves; Friday-Thursday cutoff; `Cancelled` (no `Rejected`). |
| 5 | **UI & Print Hygiene** | Copper/espresso tokens (`app.css`), `Icon::svg()` only (`NoEmojiIconsTest`), `MM/DD/YY` dates, 24h text time inputs, on-page confirms (`SYS-018`), calibrated BDO slip print. |
| 6 | **Migrations & Git** | Forward-only idempotent Phinx migrations (never edit ran migrations); no commit/push/deploy/destructive DB ops without explicit owner approval. |

---

## 5. Immediate Next Steps
1. Await owner direction on next WBPMS feature or bug fix.
2. Pending real-world check from HR: one live BDO deposit slip print at `0.0 mm` toolbar offset to confirm `REP-008` recalibration.

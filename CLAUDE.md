# WBPMS Engineering Rules & Multi-Agent Protocol (Claude Code)

This file is the entry point for **Claude Code** operating in `c:\xampp\htdocs\wbpms` alongside **Antigravity (Gemini)** and **OpenAI Codex**.

---

## 1. Boot-Time Grounding (Step 0)
Before proposing or editing any code:
1. Read `SESSION_HANDOVER.md` (< 80 lines) for current active state and uncommitted peer work.
2. Read `AGENTS.md` (always-active guardrails and AI attribution ledger) and `Agent Handshake.md` (full architecture & doc-vs-code truth).
3. Run `git status --short` to inspect the working tree before touching files.
4. For deep procedures, use `.claude/skills/wbpms-qa-and-learning/SKILL.md` and `.claude/skills/ux-flow-personality/SKILL.md` (mirrored from `.agents/skills/`).

---

## 2. Tri-Agent Peer Protocol & CARE Hygiene
- **Symmetrical Ownership**: Complete the full lifecycle (grounding, surgical delta, defensive checks, CARE clean-up, verification, attribution logging). Never overwrite another agent's ledger entries.
- **CARE Phase 1 (Make It Work)**: Apply minimal, surgical deltas. Preserve existing comments, `REQ0xx` annotations, and defensive guards. Put new pure logic in `app/Domain/`, orchestration in `app/Application/`, SQL in `app/Infrastructure/Persistence/`.
- **CARE Phase 2 (Make It Right)**: Remove dead imports/debug logs/temp scripts; verify `declare(strict_types=1);`, PHP 8.3 compatibility (no PHP 8.4+ syntax), `Formatter::escape()`, `Icon::svg()` (zero emojis per `NoEmojiIconsTest`), CSRF, RBAC, `EmployeeScope`, and non-duplicate PDO placeholders (`HY093`).
- **Rule Files**: Follow the WBPMS rules in `.agents/rules/` (`grounded-precision-and-epistemic-humility.md`, `laymans-terms-communication.md`, `developer-empathy.md`, `tri-agent-collaboration-protocol.md`, `anti-happy-path-engineering.md`, plus UI/UX rules when touching views or CSS).

---

## 3. Core WBPMS Invariants
- **Stack**: Frameworkless PHP 8.3 (`Wbpms\` -> `app/`), MariaDB/MySQL via PDO prepared statements (no ORM/framework), Phinx forward-only idempotent migrations, server-rendered views in `resources/views/`, warm copper/espresso theme in `public/assets/app.css`.
- **Roles**: Exact `role.role_name` values: `BusinessOwner`, `HRHead`, `Employee`. Every non-public route in `routes/web.php` must declare roles.
- **Payroll & Requests**: Derived payroll totals; approved runs/payslips are immutable; declined requests use `Cancelled` (no `Rejected`); 4 paid sick leaves; `PH-STATUTORY-2026` (PhilHealth 5%, SSS 15% + EC); Friday-Thursday cutoff.
- **Formatting**: `Asia/Manila` timezone, `MM/DD/YY` UI dates via `Formatter`, 24-hour `<input type="text" pattern="...">` time inputs (never `type="time"`), on-page confirm dialogs (never `window.confirm`).
- **Git & Prod Safety**: NEVER commit, push, open PRs, run destructive DB commands, or deploy unless the owner explicitly asks.

---

## 4. Verification & Session Egress
1. `php -l` on all modified `.php` files (`C:\xampp\php\php.exe -l` locally).
2. PHPStan Level 6: `vendor/bin/phpstan analyse --configuration phpstan.neon`.
3. PHPUnit 12 (requires PHP >= 8.3): `--testsuite Unit` and `--testsuite Integration` (report exact `passed/failed/errors/skipped` counts or PHP >= 8.3 blocker honestly).
4. Regression workbook: update `regression/generate_regression_tests.php` and regenerate `regression/WBPMS_Regression_Test_Suite.xlsx` when behavior changes.
5. Log work in `AGENTS.md` (keep < 18 KB), `.agents/skills/wbpms-qa-and-learning/references/agent-work-ledger.md`, and refresh `SESSION_HANDOVER.md`.

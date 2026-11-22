# OpenAI Codex Instructions & Collaboration Protocol (WBPMS)

This file defines the mandatory engineering invariants, rules, and verification commands for **OpenAI Codex** operating in `c:\xampp\htdocs\wbpms` alongside **Claude Code** and **Antigravity (Gemini)**.

---

## 1. Tri-Agent Distributed Peer Protocol
1. **Boot-Time Grounding (Step 0)**: Inspect `SESSION_HANDOVER.md` (< 80 lines), `AGENTS.md`, `Agent Handshake.md`, and `git status --short` before proposing or modifying code.
2. **Distributed Peer Ownership ("You Touch It, You Own It")**: Complete the full lifecycle (grounding, surgical delta, defensive checks, CARE clean-up, verification gates, and attribution logging). Never overwrite another agent's ledger attribution.
3. **Locked WBPMS Contracts**: Never break frameworkless PHP 8.3 architecture, PDO-only queries, exact role names (`BusinessOwner`, `HRHead`, `Employee`), approved payroll immutability, `Cancelled` request status, `PH-STATUTORY-2026`, or forward-only Phinx migrations.
4. **Universal "CARE" Code-Hygiene**:
   - **Phase 1 (Make It Work)**: Apply minimal, surgical deltas. Never overwrite entire large files. Preserve comments, `REQ0xx` traceability notes, and defensive checks.
   - **Phase 2 (Make It Right)**: Prune unused imports/variables/debug logs, verify security invariants (CSRF, RBAC, `EmployeeScope`, `Formatter::escape()`, `HY093` distinct PDO placeholders), and run PHP verification checks.
5. **Session Handoff**: Record work in `AGENTS.md` (keep < 18 KB), `.agents/skills/wbpms-qa-and-learning/references/agent-work-ledger.md`, and refresh `SESSION_HANDOVER.md`.

---

## 2. UI, Surface Purity & Anti-Happy-Path Invariants
- Follow `.agents/rules/anti-ai-slop-ui-design.md`, `.agents/rules/ui-layout-principles.md`, `.agents/rules/ux-psychology.md`, and `.agents/skills/ux-flow-personality/SKILL.md`.
- **Brand Palette**: Light Diamond Enterprises CSS tokens in `public/assets/app.css` (`--bg: #f5f1ed`, `--sidebar-bg: #2c1f14`, `--accent: #c97b3f`, `--ink: #1a1410`, Montserrat font). No purple gradients, no pill action buttons, no decorative emojis.
- **Icons**: Use `Wbpms\Http\View\Icon::svg()` exclusively (`tests/Unit/NoEmojiIconsTest.php` enforces zero emoji/Unicode icons).
- **Formatting**: `MM/DD/YY` dates via `Formatter`, 24-hour `<input type="text" pattern="...">` time inputs (never `type="time"`), `tabular-nums` for currency/hours, on-page confirm dialogs (never `window.confirm`).
- **5 UI States**: Handle Ideal, Empty (with next action), Busy/Double-Submit Guard, Error/Precondition (preserving inputs, never leaking `PDOException::getMessage()`), and Overflow/Print.

---

## 3. Git, Database & Deployment Safety
- NEVER commit, push, open PRs, or trigger Railway/VPS deploys unless the owner explicitly asks.
- NEVER commit `.env`, `*.sql`, `*.zip`, `*.xls`, `storage/private/*`, or `check_*.php` / `diag_*.php` scripts.
- NEVER edit already-run Phinx migrations or run destructive DB commands against non-throwaway databases.

---

## 4. Verification Command Reference
- **PHP Syntax (local XAMPP 8.2.12)**: `C:\xampp\php\php.exe -l <path/to/File.php>`
- **PHPStan Level 6**: `C:\xampp\php\php.exe vendor/bin/phpstan analyse --configuration phpstan.neon`
- **PHPUnit 12 (requires PHP >= 8.3)**:
  - Unit: `vendor/bin/phpunit --configuration phpunit.xml --testsuite Unit`
  - Integration: `vendor/bin/phpunit --configuration phpunit.xml --testsuite Integration` (always report `passed/failed/errors/skipped`)
- **Regression Suite**: `C:\xampp\php\php.exe regression/generate_regression_tests.php`

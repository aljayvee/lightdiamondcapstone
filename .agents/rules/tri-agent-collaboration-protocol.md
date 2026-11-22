---
trigger: always_on
---

# Tri-Agent Distributed Peer Collaboration Protocol (WBPMS)

This rule defines the symmetrical peer collaboration model between **Claude Code (Anthropic)**, **OpenAI Codex**, and **Antigravity (Google / Gemini)** (and any peer assistant such as Kiro) across `c:\xampp\htdocs\wbpms`.

---

## 1. Symmetrical Peer Ownership ("You Touch It, You Own It")

1. **No Assembly-Line Silos**: Claude Code, OpenAI Codex, and Antigravity operate as full-stack peers capable of domain design, PHP/SQL implementation, view refinement, testing, and verification.
2. **End-to-End Accountability**: No agent may leave half-finished hacks, broken syntax, or unlogged changes for another agent to clean up. Whichever agent modifies code MUST complete the full lifecycle (grounding, surgical delta, defensive checks, CARE clean-up, verification gates, and attribution logging).
3. **Never Overwrite Peer Attribution**: Preserve all prior entries by other AI agents in `AGENTS.md` and `.agents/skills/wbpms-qa-and-learning/references/agent-work-ledger.md`. Always sign your own work with your exact AI name.

---

## 2. Boot-Time Grounding (Step 0)

At the start of every session before designing or editing code:
1. Read `SESSION_HANDOVER.md` (< 80 lines) for hot active state, uncommitted work, and immediate priorities.
2. Consult `AGENTS.md` (always active) and `Agent Handshake.md` (structural reference) for invariants and doc-vs-code discrepancies.
3. Run `git status --short` to inspect uncommitted changes left by the owner or peer agents so you never clobber or misattribute them.

---

## 3. The Universal "CARE" Code-Hygiene Invariant

Every agent working in WBPMS MUST follow the two-phase implementation standard:

### Phase 1: Functional Execution (Make It Work)
- Apply minimal, surgical deltas (`edit_file` or precise diffs) targeting only the necessary lines.
- NEVER overwrite entire files when modifying a subset of lines.
- Preserve existing comments, `REQ0xx` requirement references, defensive guards, and adjacent helpers.
- Put new pure business logic in `app/Domain/`, orchestration/transactions in `app/Application/`, and SQL in `app/Infrastructure/Persistence/`.

### Phase 2: The CARE Clean-Up & Verification (Make It Right)
Before declaring any task complete:
1. **C - Clean**: Prune unused `use` imports, dead variables, debug `var_dump`/`error_log` calls, and temporary `check_*.php` / `diag_*.php` scripts.
2. **A - Align**: Enforce `declare(strict_types=1);`, PHP 8.3 compatibility (no PHP 8.4+ syntax), `Formatter::escape()`, `Icon::svg()` (zero emojis per `NoEmojiIconsTest`), `MM/DD/YY` dates, and 24-hour text time inputs.
3. **R - Robustness**: Verify CSRF, RBAC route roles, `EmployeeScope`, non-duplicate PDO placeholders (`HY093`), safe `PDOException` handling, and non-happy-path edge cases.
4. **E - Empirical Verification**: Run `php -l` on touched files, PHPStan Level 6, PHPUnit Unit/Integration (or report PHP >= 8.3 blocker / skip counts honestly), and regenerate `regression/WBPMS_Regression_Test_Suite.xlsx` when adding/updating regression TCs.

---

## 4. Locked WBPMS Contracts (Zero-Regression Policy)

Unless the repository owner explicitly approves a change, treat the following as **LOCKED**:
- `[LOCKED]` Frameworkless PHP (`Wbpms\` -> `app/`, `Wbpms\Tests\` -> `tests/`), PDO prepared statements only, no ORM/framework.
- `[LOCKED]` Exact role names in `role.role_name`: `BusinessOwner`, `HRHead`, `Employee`.
- `[LOCKED]` Approved payroll runs and payslips are immutable; declined requests use status `Cancelled` (never `Rejected`).
- `[LOCKED]` Active statutory policy `PH-STATUTORY-2026` (PhilHealth 5% total, SSS 15% total + EC employer share), 4 paid sick leave days, Friday-Thursday payroll cutoff.
- `[LOCKED]` Forward-only, idempotent Phinx migrations; never edit already-run migrations.
- `[LOCKED]` No git commit, push, PR, destructive DB command, or production deploy without explicit owner confirmation.

---

## 5. Session Handoff & Async Peer Review

Before ending a session that modified files:
1. Update `AGENTS.md` Section 1 (rotate old rows into `agent-work-ledger.md` if `AGENTS.md` approaches 18 KB).
2. Append full details (files, verification counts, regression TC IDs) to `.agents/skills/wbpms-qa-and-learning/references/agent-work-ledger.md`.
3. Refresh `SESSION_HANDOVER.md` in-place (keep strictly under 80 lines) so the next agent (Claude Code, Codex, or Antigravity) can perform an async peer review and pick up cleanly without context bloat.

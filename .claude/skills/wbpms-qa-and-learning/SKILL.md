---
name: wbpms-qa-and-learning
description: >-
  Guides AI agents working on the WBPMS PHP codebase through codebase grounding,
  unit testing (PHPUnit Unit), integration testing (PHPUnit Integration against
  wbpms_test), PHPStan level 6 analysis, spreadsheet regression testing,
  continuous codebase learning, and AI-named modification/integration/fix ledger
  logging. Use whenever implementing changes, fixing bugs, running tests,
  updating regression cases, or recording multi-agent work in WBPMS.
---

# WBPMS QA, Testing, Codebase Learning & Multi-Agent Attribution Skill (Claude Mirror)

> **Canonical Source of Truth**: This skill mirrors `.agents/skills/wbpms-qa-and-learning/SKILL.md` for native Claude Code discovery. Always read and update the single canonical reference files under `.agents/skills/wbpms-qa-and-learning/references/` so Antigravity, Claude Code, and Codex never drift.

## Canonical Reference Files
- `.agents/skills/wbpms-qa-and-learning/SKILL.md` (full workflow runbook)
- `.agents/skills/wbpms-qa-and-learning/references/agent-work-ledger.md` (multi-agent chronological ledger)
- `.agents/skills/wbpms-qa-and-learning/references/codebase-learnings.md` (verified gotchas, PDO/schema pitfalls, doc-vs-code mismatches)

## Quick Execution Gates
1. **Ground first**: Read `SESSION_HANDOVER.md`, `AGENTS.md`, `git status --short`, and `.agents/skills/wbpms-qa-and-learning/references/codebase-learnings.md`.
2. **Syntax check**: Run `php -l` on every touched `.php` file (`C:\xampp\php\php.exe -l` on Windows XAMPP).
3. **PHPStan L6**: Run `vendor/bin/phpstan analyse --configuration phpstan.neon` (zero new errors in `app/`).
4. **PHPUnit 12 (requires PHP >= 8.3)**:
   - Unit: `vendor/bin/phpunit --configuration phpunit.xml --testsuite Unit`
   - Integration: `vendor/bin/phpunit --configuration phpunit.xml --testsuite Integration` (against migrated `wbpms_test`; always report `passed / failed / errors / skipped` — never hide silent skips).
   - Local XAMPP PHP is 8.2.12 and cannot run PHPUnit 12; report honestly if PHP >= 8.3 is unavailable.
5. **Regression workbook**: Update `$cases` in `regression/generate_regression_tests.php` when behavior changes and regenerate `regression/WBPMS_Regression_Test_Suite.xlsx` via `php regression/generate_regression_tests.php`.
6. **Record attribution**: Update `AGENTS.md` Section 1 (keep `AGENTS.md` < 18 KB), append details to `.agents/skills/wbpms-qa-and-learning/references/agent-work-ledger.md`, and refresh `SESSION_HANDOVER.md`.

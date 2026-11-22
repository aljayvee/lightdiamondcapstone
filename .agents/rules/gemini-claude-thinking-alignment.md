---
trigger: always_on
---

# Gemini Non-Autonomous Execution & Claude Cognitive Alignment Protocol (WBPMS)

> **ENFORCEMENT LEVEL: ABSOLUTE & UNIVERSAL**
> Active across `c:\xampp\htdocs\wbpms`.
> Applies to all Gemini models operating in Antigravity.
> Gemini models MUST NOT act autonomously or execute sweeping unilateral changes. All actions must follow the structured, consultative 6-stage thinking process below.

---

## 1. Absolute Bans on Autonomous Execution

1. **No Unilateral Architectural Decisions**:
   - NEVER add frameworks/ORMs, alter payroll cutoff rules, modify approved payroll immutability, change statutory rates, or introduce new migrations without explicit prior user consultation and alignment.
2. **No Blind Code Execution**:
   - NEVER modify or author code without first inspecting `AGENTS.md`, `SESSION_HANDOVER.md` (or `Agent Handshake.md`), `git status --short`, and the actual target PHP files, views, and migrations. Zero-assumption verification is mandatory.
3. **No Unsolicited Mass Modifications**:
   - When tasked with fixing or implementing a specific module, NEVER refactor or "clean up" unrelated controllers, views, or legacy inline SQL outside the requested scope.
4. **No Unapproved Git or Production Actions**:
   - NEVER commit, push, open PRs, run destructive DB commands (`phinx rollback`, `DROP`, `TRUNCATE`, cleanup/reseed SQL), or deploy to production without explicit owner confirmation.
5. **Stop and Consult on Ambiguity**:
   - Whenever requirements are underspecified, conflict with existing payroll/HR rules, or involve multiple architectural paths, PAUSE, present observed findings and trade-offs, and wait for user direction.

---

## 2. The 6-Stage Claude Cognitive Thinking Process

Every significant Gemini invocation MUST execute through these 6 stages:

```text
[ Stage 1: Context Grounding ] -> [ Stage 2: Hypothesis & Trade-offs ] -> [ Stage 3: User Consultation Gate ]
                                                                                     |
[ Stage 6: Empirical Verification ] <- [ Stage 5: Defensive Rigor ] <- [ Stage 4: Surgical Delta ]
```

### Stage 1: Context Grounding (Inspect Before Acting)
- Inspect `SESSION_HANDOVER.md` (if present), `AGENTS.md`, and `git status --short` to see active work by the owner or peer agents.
- Inspect the live target PHP files, `routes/web.php`, views, and `.agents/skills/wbpms-qa-and-learning/references/codebase-learnings.md`.
- Identify callers, security invariants (CSRF, RBAC, `EmployeeScope`, `Formatter::escape()`), and regression risks before drafting edits.

### Stage 2: Hypothesis & Trade-off Formation
- Formulate a clear root-cause hypothesis or design rationale grounded in code.
- Evaluate trade-offs: PHP 8.3 compatibility (Railway) vs. local PHP 8.2.12 (XAMPP), PDO `HY093` safety, backward compatibility with production data, and blast radius.
- Explain why Option A is preferred over Option B transparently.

### Stage 3: Consultative Alignment (User Gate)
- For architectural changes, business-rule changes, schema migrations, or ambiguous requests: present findings, options, and trade-offs and wait for explicit user confirmation before editing.
- For clearly specified, pre-approved surgical edits: state the planned change concisely and execute.

### Stage 4: Surgical Delta Implementation
- Apply targeted, minimal edits using surgical edit tools.
- NEVER overwrite entire large files when modifying a subset of lines.
- Preserve existing comments, `REQ0xx` traceability notes, defensive guards, and adjacent methods.

### Stage 5: Defensive & Anti-Happy-Path Rigor
- Verify CSRF tokens on forms, explicit role arrays in `routes/web.php`, `EmployeeScope` on employee endpoints, `Formatter::escape()` on all dynamic view output, distinct PDO named placeholders (`HY093`), and safe error handling (never leak `PDOException::getMessage()` to users).
- Handle empty states, invalid/reversed dates, missing holidays/schedules, duplicate submissions, and malformed input gracefully.

### Stage 6: Empirical Verification & Attribution Logging
- Never assume code works because it was written.
- Run all applicable WBPMS verification gates honestly:
  - `php -l` on every touched PHP file
  - PHPUnit Unit & Integration (report exact counts or PHP >= 8.3 blocker honestly)
  - PHPStan Level 6 on `app/`
  - Regression suite update (`regression/generate_regression_tests.php`) when behavior changes
- Record work in `AGENTS.md` (keeping it under 18 KB), `.agents/skills/wbpms-qa-and-learning/references/agent-work-ledger.md`, and refresh `SESSION_HANDOVER.md`.

---

## 3. Model-Specific Operational Calibrations

| Model Profile | Risk Profile | Mandatory Constraint |
|---|---|---|
| **Fast / Flash Models** | High speed, risk of rushing into rapid tool calls without deep verification | **Forced Deliberation**: Verify file contents and plan exact replacement chunks before editing. Never execute multi-file cascades without verifying intermediate results. |
| **High-Reasoning / Pro Models** | High capability, risk of over-architecting or taking unsolicited initiative | **Consultative Restraint**: Present architectural plans and request user review before creating new abstractions, migrations, or refactors. Respect all WBPMS invariants. |

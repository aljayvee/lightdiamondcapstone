---
name: ux-flow-personality
description: >-
  Design and audit multi-screen WBPMS workflows so HR, Business Owner, and
  Employee pages feel like one cohesive payroll system with one consistent
  voice, clear momentum, and helpful blocked states. Use whenever work spans
  more than one page of a workflow (biometric import -> attendance -> payroll
  -> approval -> payslips/BDO slips, employee onboarding/archive/rehire, or
  request submission -> review), whenever rewriting UI copy, headings, button
  labels, empty states, or flash/error text, or whenever a workflow feels
  clunky, inconsistent, or confusing across screens.
---

# UX Psychology & Voice Consistency Across Multi-Screen Flows (WBPMS)

Most UI reviews check one page at a time. HR, Business Owners, and Employees do not experience WBPMS one page at a time — they move through connected chains:
- Biometric `.xls` upload -> unmatched review -> timesheets -> payroll compute -> Owner approval -> payslips & BDO slips
- Employee creation -> schedule & salary assignment -> leave/CA requests -> archive / rehire
- Employee login -> first-login OTP -> My Requests -> My Payslips

A user arrives at screen 2 carrying the confidence (or confusion) screen 1 created. Companion to `.agents/rules/ux-psychology.md` (within-screen decisions). Read this for "do these screens feel like one dependable system?".

---

## 1. Voice Consistency & Zero Terminology Drift

A payroll system earns trust through consistent, plain language. The main failure mode is **drift**: each page written on a different day using different words for the same concept.

**Audit method: read every screen's title, subtitle, button labels, and flash messages aloud in sequence.** If it sounds like three different people wrote it, it has drifted.

Watch for three common drift patterns in WBPMS:
- **One concept, two names**: Calling the same status "Declined", "Rejected", and "Cancelled" across screens (the canonical WBPMS status is `Cancelled`), or mixing "Cutoff", "Pay Cycle", and "Payroll Period" inconsistently. Pick one canonical term per domain concept and reuse it everywhere.
- **System/DB voice leaking into user voice**: Showing `"SQLSTATE[23000]"`, `"chk_eba_period"`, `"Invalid payload"`, `"Submit"`, or raw enum strings like `SpecialNonWorking` where a human expects `"Special Non-Working Holiday"` or `"Save Employee"`.
- **Tone whiplash**: Friendly guidance on the Employee portal followed by cryptic technical warnings on HR pages. Keep one calm, clear, respectful operational voice across all three portals (`BusinessOwner`, `HRHead`, `Employee`).

**The spoken test:** Could an HR lead read this heading or flash message out loud to a coworker and have it make immediate sense?
- Passes: `"Payroll run approved. Payslips are now locked and ready to print."`
- Fails: `"Run entity state mutated to APPROVED; immutable constraint active."`

---

## 2. Momentum & State Preservation Between Screens

Between screens, the user is spending attention and trust. Every transition should preserve their context.

- **Preserve active filters & date ranges across POST-redirect-GET**: When HR evaluates attendance policy flags for `08/01/25 - 08/31/25` or generates payroll periods for a selected month, returning to the page MUST keep that chosen period/month instead of silently resetting to "today" or "last 30 days".
- **Echo what just happened in flash messages**: After an action completes, confirm the concrete scope ("Evaluated policy flags for 08/01/25 to 08/31/25: 0 new flags" or "Archived Juan Dela Cruz effective 11/24/26"), not just `"Success"`.
- **Never silently destroy form progress**: When validation fails or a prerequisite check stops a save, retain entered fields where practical and point directly to what needs attention.
- **Lead with what matters, not empty zeros**: When a list has no items, name the state clearly and provide the next action ("No attendance imported for this period yet - Upload Biometric XLS") rather than leaving a bare empty table.

---

## 3. Give Context Before Asking for Commitment

Show decision-making information **above** the action button, before the user commits:
- On **HR New Request**: display the selected employee's sick-leave credit (`x / 4.00 days`) for the request year and unpaid cash-advance balance before HR submits.
- On **Payroll Compute / Approve**: show headcount, period dates (`MM/DD/YY`), missing holiday/punch warnings, and total net pay before confirming.
- On **Employee Archive / Rehire**: clearly show any blocking conflicts or affected future assignments in plain language before applying changes.

Never label a context panel with meta-jargon ("Reassurance Info" or "Pre-Validation Metadata"); state the plain facts directly ("Leave & Cash Advance Balance").

---

## 4. Blocked States Must Point to the Fix

A disabled button or bare error flash without guidance leaves HR stuck on payday.

Prefer, in order:
1. **Explain the exact prerequisite and link/point to the fix** (e.g., "2025 has no holidays configured yet - add the 2025 holiday calendar before computing this period").
2. **Place field-level requirements right beside the input** (e.g., 24-hour time format hint `HH:MM`, daily rate limits, minimum age rule).
3. **Keep button labels outcome-oriented** ("Compute Payroll", "Create Request", "Approve Run") rather than naming the obstacle.

---

## 5. One Primary Decision Per Screen

When a page carries secondary context (like a selected branch filter, employee balance card, or active payroll period summary), style it as **quiet settled context** with a clear "Change" affordance so it supports — rather than competes with — the screen's primary task.

---

## 6. The 6-Step WBPMS Flow Audit

Run this quick audit before closing any multi-page workflow change:
1. **Read titles, buttons, and flash messages in sequence.** One consistent voice, zero terminology drift?
2. **Check domain terms.** Are statuses (`Cancelled`, `Approved`, `Draft`), dates (`MM/DD/YY`), and 24-hour times consistent across every step?
3. **Walk forward with empty data.** Does every 0-row state explain why and offer a next action?
4. **Walk backward or fail validation.** Do filters, date ranges, and form inputs survive without unexpected jumps?
5. **Trigger every blocked/guard state.** Does each guard explain *why* and *how to resolve it* without leaking raw SQL or stack traces?
6. **Check icon and escaping discipline.** Every dynamic string escaped via `Formatter::escape()`, every icon rendered via `Icon::svg()` (passing `NoEmojiIconsTest`)?

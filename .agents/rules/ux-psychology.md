---
trigger: model_decision
description: >-
  Apply UX psychology and behavioral design principles tailored to an internal
  HR, payroll, and employee self-service web app (cognitive load, smart defaults,
  draft/endowment protection, clear consequences, and trust) when designing or
  auditing WBPMS user workflows.
---

# UX Psychology Framework (WBPMS — HR, Owner & Employee Portal)

This rule governs how UX and workflow decisions are reasoned about in WBPMS. Because WBPMS is a **high-stakes internal payroll and HR system** (not a consumer e-commerce app), UX psychology here optimizes for **accuracy, speed, zero costly mistakes, and trust**.

---

## 1. Cognitive Load & Decision Clarity

- **Hick's Law**: Keep primary actions per screen to 3-4 clear choices. Move secondary configuration, archived records, and advanced filters behind clear toggles or tabs.
- **Progressive Disclosure**: Show the summary row first (employee, period, net pay, status), and reveal line-item breakdowns, attendance logs, or audit history on drill-down.
- **Visual Hierarchy & Anchoring**: Put the most decision-critical figure first (e.g., Net Pay on a payslip, remaining Sick Leave credit `x / 4.00 days` and unpaid Cash Advance right inside the HR Request form, total payroll disbursement on the Owner approval summary).
- **Zero Mystery State**: Never change a filter, clamp a date range, or skip an employee silently. If the system adjusts something (e.g., clamping a > 732-day report range or excluding an unpaid 1-punch day), show a plain-English notice explaining what happened and why.

---

## 2. Behavioral Principles Applied to Payroll & HR Workflows

### 2.1 Smart Defaults (Eliminate Blind Forms)
- Pre-populate safe, contextual defaults whenever known:
  - Current/latest payroll month or active cutoff period.
  - Default daily rate autofill when selecting a position (still editable within policy caps).
  - Remembered report filters — *only* when visibly reflected and easy to reset via a clear "Clear / Reset" control.
- Never silently pre-fill destructive choices or irreversible approvals.

### 2.2 Goal Gradient & Step Clarity
- Multi-step HR workflows (Upload Biometric `.xls` -> Review Unmatched/Anomalies -> Approve Attendance -> Compute Payroll -> Submit to Owner -> Approve -> Print Payslips / BDO Slips) MUST make the current step and remaining steps obvious.
- Use explicit status badges (`Draft`, `Submitted`, `Approved`, `Cancelled`) and clear next-action buttons whose labels name the real outcome ("Compute Payroll", "Submit for Owner Approval", "Return for Revision") instead of vague labels ("Submit", "OK", "Process").

### 2.3 Reciprocity & Transparency Before Commitment
- Before asking HR or the Business Owner to commit an irreversible or money-moving action (approving payroll, archiving an employee, approving a leave/CA request):
  - Show the live balances, affected headcount, totals, and any warnings **above** the confirm button.
  - Read-only context panels (such as showing Sick Leave credit and unpaid Cash Advance when picking an employee on the New Request form) must never mutate database state behind the scenes.

### 2.4 Endowment & Mistake Prevention (Loss Aversion Used Constructively)
- **Preserve Unsubmitted Work**: When validation fails on a form (employee creation, schedule assignment, request entry), keep the user's entered values intact and point directly to the field that needs fixing.
- **Clear Consequence Framing on Irreversible Actions**: On-page confirmation dialogs (`SYS-018`) for archiving employees, cancelling payroll runs, or approving immutable payroll MUST state the exact consequence in plain English (e.g., "Once approved, this payroll run and its payslips cannot be edited").
- **Physical Contrast Between Safe & Destructive Actions**: Pair a solid primary CTA with a visually quieter secondary action, and separate destructive actions cleanly so hurried clicks never cause accidental cancellations.

### 2.5 Trust & Employee Dignity (Ethical Boundary)
- Employees checking their payslips, leave credits, or cash advance balances are checking their livelihood. Copy must be respectful, clear, and free of raw database jargon or blaming language.
- Never use manipulative consumer dark patterns (fake urgency, hidden constraints, deceptive button labels). Trust in payroll software comes from predictable, auditable, plain-English behavior.

---

## 3. Decision Check Before Shipping a UI Change

Ask before approving any WBPMS UI/workflow change:
1. **Does it reduce HR/Owner/Employee cognitive load?**
2. **Does it prevent a real payroll or data-entry mistake?**
3. **Are consequences, balances, and system adjustments visible before the user clicks?**
4. **Does every empty, blocked, or error state point to a clear next step?**

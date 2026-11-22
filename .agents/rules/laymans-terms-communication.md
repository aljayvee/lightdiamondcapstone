---
trigger: always_on
---

# Layman's Terms & Intuitive Communication Standard (WBPMS)

This rule governs how explanations, answers, summaries, and system behaviors MUST be communicated to the developer and Light Diamond Enterprises stakeholders.

---

## 1. Core Communication Mandate

1. **Layman's Terms First**: Always explain concepts, payroll logic, attendance rules, database errors, and workflows using clear, everyday human language before diving into PHP classes or SQL mechanics.
2. **Everyday Real-World Analogies**: Anchor abstract system mechanics in tangible, familiar workplace scenarios (for example: comparing a Phinx migration to a one-way ledger entry that cannot be erased, an approved payroll run to a sealed pay envelope, a CSRF token to a stamped receipt verifying the form came from the real page, or `EmployeeScope` to a locked HR folder where each employee can only open their own file).
3. **No Unexplained Developer/DB Jargon**: Never lead with unexplained SQL error codes (`HY093`, `chk_eba_period`, `SQLSTATE[23000]`), raw DB statuses (`Superseded`, `SpecialNonWorking`), or architectural terms without first explaining what happened in plain English.

---

## 2. Structured Response Pattern

When answering questions about "how something works", "why did this happen", or troubleshooting issues:

1. **The 10-Second Executive Summary**: A plain-English sentence or analogy summarizing the core cause or purpose without jargon.
2. **Real-World Scenarios / What Actually Happens**: A clean table or short list showing concrete situations and what HR, the Business Owner, or an Employee sees.
3. **The "Why It Matters" Perspective**: Explain the human or business impact (e.g., why payroll accuracy matters on payday, why HR sees a warning, or why an employee's balance must not change silently).
4. **Technical Breakdown (Secondary)**: Only after the plain-English intuition is established, cite the exact PHP files, classes, migrations, and verification commands.

---

## 3. Contrast Examples

> **Avoid (Jargon-First):**
> *"Archive threw `SQLSTATE[23000]` on `chk_eba_period` because a future-dated `employee_branch_assignment` row had `effective_from > closed_on`, violating the period check constraint during `EmployeeLifecycleRepository` termination."*
>
> **Prefer (Layman's Terms First):**
> *"When the owner tried to archive that employee, the system blocked the save because the employee already had a scheduled future branch transfer that hadn't started yet. Trying to set that future transfer's end date to today made its end date earlier than its start date, which the database refuses. The fix removes unstarted future assignments (if no attendance or payroll uses them) before closing the active record."*

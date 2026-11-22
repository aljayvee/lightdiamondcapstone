---
trigger: model_decision
description: >-
  Apply production web layout, spatial hierarchy, tabular data clarity,
  5 mandatory UI states, and Impeccable craft-floor standards whenever designing,
  restructuring, or reviewing WBPMS HTML views and CSS layouts.
---

# Production UI Layout & Spatial Hierarchy Principles (WBPMS)

This rule defines layout, spatial rhythm, operational density, and defensive UI engineering across WBPMS (`resources/views/`, `public/assets/app.css`, `public/assets/*.js`). Every screen is an operational HR, Owner, or Employee tool and must withstand real payroll data rather than idealized mockups.

---

## 1. Anti-Happy-Path Layout Mandate

- **No Fixed-Height Text Containers**: Never hardcode fixed heights on cards, table cells, or alert banners that hold variable text. Allow natural wrapping or clean ellipsis with full text accessible.
- **Real-Data Stress Testing**: Layouts must handle long Filipino multi-part names (with middle names and suffixes), long branch/schedule names, multi-line audit/adjustment reasons, wide peso totals (`₱1,234,567.89`), 0-row tables, and 100+ row payroll runs.
- **WBPMS Format Invariants**:
  - Dates display as `MM/DD/YY` via `Formatter::date()`.
  - Times display as 24-hour via `Formatter::time()`; time inputs MUST be `<input type="text" pattern="...">`, never `<input type="time">`.
  - Currency, hours, and counts use `font-variant-numeric: tabular-nums` and right-aligned numeric table columns.

---

## 2. Visitor Mode Classification & Spatial Thesis

Before changing layout structure or CSS, declare:
1. **Primary User Goal**: What single decision or action does HR Head, Business Owner, or Employee need to complete here?
2. **Reading Order**: What must they notice first, second, and third?
3. **Visitor Mode** (WBPMS surfaces are primarily **`Operate + Read`**):
   - Predictable navigation, clear table scanning, stable form alignment, and zero decorative friction outrank visual flair.
   - Print surfaces (payslips, BDO deposit slips) follow **`Print Precision`** mode: exact millimeter calibration and clean `@media print` styling.

---

## 3. Spatial Structure, Rhythm & Action Hierarchy

### 3.1 Gestalt Proximity Over "Divider Hell"
- Use tight micro-spacing (`4-8px`) to group related elements (label + input, employee name + employee number).
- Use generous macro-spacing (`20-28px`) between distinct sections (filter bar vs. summary strip vs. data table).
- Separate sections with surface contrast (`--bg` vs. `--panel` vs. `--surface-2`) and subtle `--line` borders rather than heavy `<hr>` lines.
- **Asymmetric Heading Rhythm**: Section headings MUST have more space above than below (`margin-top >= 1.5x to 2.5x margin-bottom`) so headings visually anchor to the content they introduce.

### 3.2 Cognitive Load & 3-Tier CTA Hierarchy
- **Primary CTA**: At most **1** solid primary button (`--accent: #c97b3f`) per viewport or form footer (e.g., "Compute Payroll", "Approve Run", "Save Employee").
- **Secondary Actions**: Neutral/outlined buttons (e.g., "Export PDF", "Print Slips", "Clear Filters").
- **Tertiary / Destructive Actions**: Physically separated from primary progression buttons; destructive actions (`Archive`, `Void`, `Cancel Run`) use explicit warning/danger styling (`--bad`) and route through WBPMS's on-page confirm dialog (`SYS-018`).
- **Never place two equally weighted solid primary buttons side-by-side.**

### 3.3 The Squint Test
Blurring the page should immediately reveal:
`Page Title + Primary Action -> Active Filters / Summary Context -> Primary Table or Form -> Secondary / Destructive Controls`.
If everything competes at the same visual weight, simplify before writing more CSS.

---

## 4. The 5 Mandatory UI States

Every WBPMS list, report, dashboard, or form view must account for all 5 states:

| UI State | Mandatory WBPMS Behavior |
|---|---|
| **1. Ideal State** | Balanced table/form rhythm, clear tabular alignment, escaped output via `Formatter::escape()`. |
| **2. Empty State** | Clear explanation of why there are 0 records (e.g., no attendance imported for this range, no pending requests) plus a direct next-step CTA or "Clear Filters" action. |
| **3. Busy / Submitting State** | Prevent accidental double-submits on POST buttons (payroll compute, policy evaluate, request submit) with clear in-progress feedback. |
| **4. Error / Precondition State** | Human-readable flash or inline validation error explaining what failed and how to fix it (e.g., missing holiday calendar for year, insufficient sick leave balance), preserving form input values where practical. |
| **5. Overflow / Large-Dataset State** | Horizontal scroll wrappers on wide tables, clean sticky/scannable headers, wrapping for long notes, and pagination or bounded date ranges with visible notices when clamped. |

---

## 5. Browser Surface & Accessibility Hygiene

- Preserve clear, high-contrast keyboard focus rings (`:focus-visible`) on all inputs, buttons, links, and table actions.
- Keep clickable controls comfortably sized (at least `36-40px` height for desktop form inputs/buttons matching `--input-height: 40px`, and generous click targets on icon buttons).
- Ensure form `<label for="...">` associations, meaningful `<button type="button|submit">`, and readable line-height (`1.45-1.6`) on explanatory notes and validation banners.

---
trigger: model_decision
description: >-
  Enforce Anti-AI-Slop UI/UX hygiene, Light Diamond Enterprises palette fidelity,
  SVG icon discipline (Icon::svg / NoEmojiIconsTest), typography-first headers,
  and surface purity whenever building, modifying, or reviewing WBPMS views,
  CSS (public/assets/app.css), or progressive JS.
---

# Anti-AI-Slop UI/UX Engineering & Surface Purity Invariants (WBPMS)

This rule defines visual hygiene, brand fidelity, typography standards, surface elevation, and component ergonomics across `c:\xampp\htdocs\wbpms` (`resources/views/`, `public/assets/app.css`, `public/assets/*.js`). Superficial "AI Slop" aesthetic ornamentation is strictly forbidden.

---

## 1. The 13 Banned AI-Slop Anti-Patterns

1. **No Purple Gradients**: NEVER use generic SaaS purple, violet, or indigo gradients (`#6366F1`, `#A855F7`, `#8B5CF6`). Stay strictly within Light Diamond Enterprises' CSS tokens in `public/assets/app.css`: warm ivory canvas (`--bg: #f5f1ed`), espresso sidebar (`--sidebar-bg: #2c1f14`), copper/bronze accent (`--accent: #c97b3f`), warm ink (`--ink: #1a1410`), and semantic status colors (`--ok`, `--bad`, `--wait`, `--info-*`).
2. **No Pill-Shaped Action Buttons**: Standard action buttons, form submits, and inputs MUST NOT use `border-radius: 9999px`. Use WBPMS's restrained radius tokens (`--radius-sm: 5px`, `--radius: 8px`, `--radius-lg: 12px`). Full pills are strictly reserved for compact status badges/chips.
3. **No Fake Reviews or Testimonials**: Never render placeholder quotes, fake ratings, or filler copy.
4. **No Fake Metrics**: Never display hardcoded or simulated KPI counts, percentages, or uptime claims. Every number on a dashboard, payroll run, or report must come from live MariaDB queries.
5. **No Vague Marketing Copy**: Use direct, operational HR and payroll phrasing ("Payroll Runs", "Biometric Attendance Import", "Pending Leave & Cash Advance Requests") rather than generic SaaS filler.
6. **No Emoji or Unicode Pictograph Icons**: NEVER use emojis or Unicode icon symbols in views, controllers, or JS. Use `Wbpms\Http\View\Icon::svg()` exclusively. This is enforced automatically by `tests/Unit/NoEmojiIconsTest.php` (`SYS-027`).
7. **No Em Dashes in UI Copy**: Avoid em dashes in UI labels, buttons, headings, flash messages, and error text. Use standard hyphens (`-`), colons (`:`), parentheses, or short sentences.
8. **No Excessive Animations**: Do not add scroll-jacking, parallax, or heavy reveal animations. Payroll/HR screens must open and respond immediately.
9. **No Unsolicited Favicons or External CDNs**: Do not add unrequested favicons, external JS libraries, or build-tool dependencies. WBPMS is server-rendered PHP with native CSS and small progressive JS.
10. **No Made-with-AI Tags**: Never insert AI watermarks or credits in views, PDFs, comments, or footers.
11. **No AI Stock Illustrations**: Do not inject synthetic 3D mascots or decorative stock art into HR/owner/employee screens.
12. **No Custom Cursor Hijacking**: Preserve native browser cursors and native keyboard/focus behavior.
13. **No Fake Counters or Urgency Timers**: Never animate count-up numbers or display artificial countdowns.

---

## 2. Additional Prohibitions & Surface Hygiene

1. **No Glowing Colored Aura Shadows**: Never attach saturated colored drop-shadows or glowing halos. Use WBPMS's subtle neutral shadow tokens (`--shadow-sm`, `--shadow-md`) and crisp 1px borders (`--line: #e8ddd3`, `--line-strong: #d0c4b8`).
2. **WCAG 2.2 AA Contrast**: All body, table, badge, and form helper text MUST achieve >= 4.5:1 contrast. Never use faint low-contrast gray text on tinted or dark surfaces.
3. **No Gray Text on Colored Surfaces**: On primary copper buttons (`--accent`), espresso sidebar surfaces (`--sidebar-bg`), or status banners (`--ok-bg`, `--bad-bg`, `--wait-bg`), secondary text must use a paired foreground/tinted token (`--ok-text`, `--bad-text`, `--wait-text`, white/warm-cream), NEVER dead neutral gray.
4. **Tinted Neutrals Only**: Never use dead pure black (`#000000`) or dead gray (`#808080`). Reuse the warm espresso/taupe neutral scale (`--ink`, `--ink-2`, `--muted`, `--line`, `--surface-2`).
5. **No Native `window.confirm()` Dialogs**: Use WBPMS's on-page confirmation dialog pattern (`SYS-018`), not blocking browser `alert()` or `confirm()` popups.

---

## 3. Scaffolding Bans & Information Density

1. **No Identical Decorative Card Grids**: Do not wrap every section in identical boxes with `[Icon + Heading + Blurb]`. Prefer clean tables, structured definition rows, whitespace, and typographic hierarchy. **Never nest cards inside cards.**
2. **No Hero-Metric Clutter**: Do not turn every stat into a giant number with decorative accent bars and fake trend pills. Show operational figures cleanly with `font-variant-numeric: tabular-nums` so peso amounts and dates align vertically.
3. **No Decorative Uppercase Eyebrow Kickers**: Headings should carry hierarchy through Montserrat size, weight, and spacing alone.
4. **High Signal-to-Noise Ratio**: Prioritize scannable payroll tables, itemized deductions, clear date ranges (`MM/DD/YY`), 24-hour times, and direct row actions.
5. **Print View Isolation**: Printable pages (payslips, reports, BDO transaction slips) MUST keep `@media print` rules clean so toolbars, navigation chrome, and helper hints never bleed onto paper.

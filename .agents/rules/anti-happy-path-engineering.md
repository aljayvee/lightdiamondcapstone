---
trigger: always_on
---

# Anti-Happy-Path Engineering & Defensive Implementation Invariants (WBPMS)

This rule governs how all PHP domain logic, controllers, repositories, migrations, views, and progressive JS MUST be designed and implemented across `c:\xampp\htdocs\wbpms`. Idealized "happy-path" assumptions are strictly forbidden in production payroll software.

---

## 1. The Anti-Happy-Path Mandate

Payroll and HR systems run on real people's wages and real office printers. Every feature, calculation, form, and query MUST be verified against hostile, incomplete, and edge-case conditions before declaring it complete.

### The 4 Banned Happy-Path Fallacies
1. **Pristine Input Fallacy**: Assuming dates are valid and in order, numbers are positive, names are short, biometric `.xls` exports have complete punch pairs, or forms are submitted only once.
2. **Complete Reference-Data Fallacy**: Assuming holiday calendars, salary records, schedules, leave credits, or branch assignments always exist for the requested year or date range.
3. **Clean Transaction Fallacy**: Assuming partial writes cannot happen, double-clicks never send concurrent POSTs, or database constraints (`chk_salary_period`, `chk_eba_period`, unique keys) will never trip.
4. **Silent Failure Fallacy**: Allowing raw `PDOException::getMessage()` SQL text to leak to the UI, silently rewriting user filter dates without notice, or letting skipped integration tests look like a "green" pass.

---

## 2. Mandatory Non-Happy-Path Engineering Protocols

### 2.1 Failure-First Implementation Protocol
When building or modifying any controller action, service, or view:
1. **Define Failure & Empty States First**: Explicitly handle zero-row tables, missing prerequisite data (e.g., missing holiday calendar for a payroll year), invalid/reversed date ranges, and unauthorized access before polishing the ideal state.
2. **Preserve User Inputs on Validation Errors**: On POST validation failure, preserve user-entered form inputs where practical and display clear field-level or flash messages rather than silently discarding work or throwing HTTP 500.
3. **Actionable Recovery**: Every empty state, validation error, or precondition guard MUST tell HR/Owner/Employee *why* it stopped and *what to do next* (e.g., link to seed/configure holidays, adjust dates, or clear filters).

### 2.2 Database, PDO & Transaction Safety
- **Atomic Multi-Table Writes**: Multi-step mutations (employee creation + user provisioning, attendance import + timesheet generation, archive/rehire lifecycle) MUST run inside a PDO transaction so structural errors never leave partial records.
- **No `PDOException` SQL Leaks**: Because `PDOException` extends `RuntimeException`, always catch `\PDOException` before `\RuntimeException` (or branch on `instanceof \PDOException`). Map known constraint violations to friendly user messages; never render raw SQL error text to views.
- **Unique Named Placeholders (`HY093`)**: With `PDO::ATTR_EMULATE_PREPARES => false`, never reuse the same `:param` placeholder twice in one SQL query.
- **Null & Numeric Type Safety**: Guard nullable DB columns explicitly (`??`, strict type checks, `round(..., 2)` for currency consistent with surrounding code). Never divide by zero or pass unvalidated dates to `DateTimeImmutable`.

### 2.3 Security & Access Gatekeeping (Server-Side Always)
- **CSRF Verification**: Every mutating HTML form MUST carry `<input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">`.
- **RBAC at Route Layer**: Every non-public route in `routes/web.php` MUST declare explicit roles (`BusinessOwner`, `HRHead`, `Employee`). Never rely on hiding a button in HTML as access control.
- **Employee Scoping (IDOR/BOLA Protection)**: Every Employee portal query MUST scope through `EmployeeScope`; never trust `{id}` or query parameters from the URL.
- **Strict Output Escaping**: Escape every dynamic value in views via `Formatter::escape()`. Never treat `Formatter::date()` or `Formatter::time()` as HTML escapers.

### 2.4 Front-End & Print Resilience
- **Double-Submit Protection**: Guard critical POST actions (payroll compute/approve, attendance evaluation, request approval) against accidental double-clicks both client-side and server-side (idempotency / status checks).
- **Filter Memory & Date Bounds**: If a view clamps or adjusts a date range or restores remembered filters, surface a visible notice explaining what changed instead of silently jumping dates.
- **Responsive & Print Integrity**: Data tables must handle long employee names, zero rows, and narrow viewports cleanly; print views (payslips, BDO deposit slips) must preserve exact print geometry and never leak debug chrome.

---

## 3. Adversarial Edge-Case Checklist

Before closing any WBPMS task, verify:
- [ ] What happens when the query returns 0 rows (empty branch, new employee, empty date range)?
- [ ] What happens if dates are reversed, malformed, span > 1 year, or cross a year boundary (e.g., Dec -> Jan)?
- [ ] What happens if the user submits the form twice quickly or tampers with an ID in the URL?
- [ ] What happens if a DB unique or CHECK constraint triggers — does the user see a clear message instead of HTTP 500 or raw SQL?
- [ ] Are all new/changed PHP files free of PHP 8.4+ syntax so Railway PHP 8.3 and local checks remain safe?
- [ ] Does `NoEmojiIconsTest` still pass (zero emoji/Unicode pictographs in views, controllers, or JS)?

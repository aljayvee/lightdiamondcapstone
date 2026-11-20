# Agent Handshake — WBPMS

Read this before you change anything in this repository. It explains what the
system is, how the code is laid out, which rules you must not break, and where
the documentation no longer matches the code.

---

## 1. What this is

**WBPMS (Web-Based Payroll Management System)** is a server-rendered PHP web app
for **Light Diamond Enterprises**, a Philippine business. It handles attendance
(imported from a biometric `.xls` export), requests (leave, overtime, cash
advance), salary structures, government contributions (SSS, PhilHealth,
Pag-IBIG), payroll computation and approval, payslips, and reports.

- **Roles** (they must match `role.role_name` exactly): `BusinessOwner`, `HRHead`, `Employee`.
- **Deployed** on Railway through Nixpacks (`nixpacks.toml`). It also runs
  locally on XAMPP for Windows (`setup.bat`, `RUNME.bat`, `setup/*.bat`).
- **Remote:** `github.com/lightdiamond0123-rgb/wbpms`. Pushes to `main` can
  trigger a production deploy, and every build runs migrations. See §8.

---

## 2. Ground rules for agents

1. **Do not commit, push, or open PRs unless the repository owner asks.**
   Leave changes in the working tree for a human to review.
2. **Never commit data dumps or secrets.** The repo root holds local files with
   possibly real payroll data and personal information: `backup.sql`,
   `backup.zip`, `clean_database.sql`, `reseed.sql`,
   `cleanup-attendance-payroll.sql`. They are gitignored. Keep it that way, and
   do not read them into external tools.
3. **Never commit `.env`.** A past commit ("Security: Remove exposed .env file")
   shows this has already happened once.
4. **Do not run destructive DB commands** (`phinx rollback`, the cleanup or
   reseed SQL files, `DROP`/`TRUNCATE`) against anything except a local
   throwaway database, and only after the user confirms.
5. **Do not edit a migration that has already run.** Add a new migration
   instead. Production runs `phinx migrate -e production` on every build.
6. Diagnostic scripts named `check_*.php`, `diag_*.php`, or `read_xls*.php` are
   gitignored on purpose. You can create them for local debugging, but delete
   them when you are done.

---

## 3. Stack at a glance

| Concern | Choice |
|---|---|
| Language | PHP with `declare(strict_types=1)`. `composer.json` requires `^8.5`, but Railway builds with **php83** (see §9) |
| Framework | **None.** Frameworkless PHP. Do not add Laravel, Symfony, or an ORM |
| Autoload | PSR-4: `Wbpms\` → `app/`, `Wbpms\Tests\` → `tests/` |
| DB | MySQL 8 / MariaDB 10.4+ through **PDO prepared statements**. No ORM |
| Migrations / seeds | Phinx (`phinx.php`, `database/migrations`, `database/seeds`) |
| Env | `vlucas/phpdotenv`, with a `getenv()` fallback for Railway (`bootstrap/app.php`) |
| Excel | `phpoffice/phpspreadsheet` (the `Xls` reader only) |
| PDF | `dompdf/dompdf` behind `app/Infrastructure/Reports/PdfRenderer.php` |
| Mail | A hand-written SMTP client in `app/Infrastructure/Mail/Mailer.php` (`MAIL_DRIVER=log` or `smtp`). PHPMailer is **not** installed, despite what `docs/tech.md` says |
| Tests | PHPUnit 12 (`tests/Unit`, `tests/Integration`) |
| Static analysis | PHPStan level 6 (`phpstan.neon`, excludes `app/Infrastructure/Reports`) |
| Front end | Native PHP templates in `resources/views`, `public/assets/app.css`, and small progressive JS. No SPA, no build step |

---

## 4. Request lifecycle

```text
public/index.php            (front controller; the only web entry point; global exception handler)
  └─ bootstrap/app.php      (env load + required-var check, Asia/Manila TZ, DB session handler, builds Router)
       └─ routes/web.php    ($router->add(METHOD, path, [Controller::class, 'action'], [roles]))
            └─ Router::handle()
                 ├─ CsrfMiddleware::verify()        on POST/PUT/PATCH/DELETE
                 ├─ AuthMiddleware::requireRoles()  if roles is non-empty
                 └─ new Controller()->action($params)
                      ├─ Application service  (app/Application/*)
                      ├─ Domain calculators   (app/Domain/*, pure, no DB)
                      ├─ Repositories         (app/Infrastructure/Persistence/*)
                      └─ ViewRenderer::render('hr/payroll/index', $data, 'Title')
```

- **Routes are explicit.** Every endpoint is one `$router->add(...)` line in
  `routes/web.php`. An empty roles array makes the route public.
  `routes/developer_b.php` is a leftover route contract and is **not**
  included; `web.php` is the source of truth.
- **`{param}` segments** reach the action as `array<string,string> $params`.
- **HTML forms send PUT/PATCH/DELETE** as POST with a `_method` field.
- **Unmatched routes** redirect browsers to `/login` and return a JSON 404
  envelope to API clients.
- **Base path stripping** lets the same routes work at `/` (Railway, `php -S`)
  and at `/wbpms/public` (XAMPP).
- **Local dev server:** `php -S localhost:8000 -t public/ router.php`. The
  root `router.php` serves static files directly.

---

## 5. Directory map

```text
app/
  Application/            Use-case services (PayrollService, RequestService, UserService, ...)
    Attendance/           Workbook import orchestration (AttendanceImportService + gateway)
    EmployeePortal/       EmployeeScope: restricts an Employee to their own records
    EmployeeSetup/        Employee creation + auto user-account provisioning
  Domain/
    Attendance/           TimesheetGenerator, AttendancePolicyEvaluator, WorkSchedule
      Parsing/            LdeXlsDailyLogParser (parser version LDE_XLS_DAILY_LOG_V1), DTOs, errors
    Payroll/              HolidayPayCalculator, ApprovedOvertimeCalculator, HolidayType
  Http/
    Controllers/          One controller per module
    Middleware/           AuthMiddleware (session, RBAC), CsrfMiddleware
    Routing/Router.php    The dispatcher
    View/                 ViewRenderer (layout + flash), Formatter (escape/date/time), AppVersion
  Infrastructure/
    Database/Connection.php
    Persistence/          PDO repositories + AbstractRepository
    Session/              DatabaseSessionHandler (30-minute idle / 12-hour absolute)
    Mail/  Reports/
config/                   app.php, database.php (read $_ENV)
database/migrations/      Phinx, timestamp-named: YYYYMMDDHHMMSS_snake_case.php
database/seeds/           Roles, demo users/employees/branches, 2026 holidays, contribution tables
resources/views/          hr/, owner/, employee/, auth/, errors/, layout.php
public/                   index.php, .htaccess, assets/ (the ONLY web-accessible dir)
storage/private/          Uploads, generated files, mail.log (gitignored, never public)
tests/Unit                Pure tests (no DB)
tests/Integration         Real MySQL (wbpms_test); each test runs in a rolled-back transaction
tests/Fixtures, Support   XLS fixture builder + expected JSON
regression/               Spreadsheet-based manual regression suite + generator
scripts/                  One-off maintenance (rematch_attendance_batches.php)
setup/, *.bat             Windows/XAMPP guided setup
docs/                     product.md, requirements.md, tech.md, lifecycle spec, user manual
```

---

## 6. Invariants you must preserve

### Security
- **CSRF:** every mutating form must include
  `<input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">`.
  The router rejects missing or invalid tokens with HTTP 419.
- **RBAC:** every new non-public route needs an explicit roles array. Do not
  rely on checks inside the controller alone.
- **Employee scoping:** an `Employee` may only see their own data. Route all
  employee-portal lookups through `EmployeeScope` and do not trust
  `{payslipId}` or other IDs from the URL.
- **Output escaping:** use `Formatter::escape()` for every dynamic value in a
  view (the codebase calls it about 550 times). Use `Formatter::date()` or
  `time()` only for formatting, never as an escaper. Commit `cee0ad3` fixed
  exactly that mistake.
- **SQL:** prepared statements with bound parameters only. Watch for
  `HY093`: native PDO prepares do not allow the same named placeholder twice
  (see commit "resolve HY093 duplicate PDO parameter").
- **Passwords:** `password_hash` / `password_verify`. Sessions are stored in
  MySQL (`sessions` table, with a `user_id` column).
- Nothing generated or uploaded goes under `public/`.

### Business rules (from `docs/product.md` and `docs/requirements.md`)
- Payroll figures are **derived**: attendance + salary structure + approved
  requests + contribution brackets. Never let a user type in a computed total.
  Manual adjustments go through `PayrollAdjustmentService` and are audited.
- **Approval flow:** HR Head computes and submits payroll. The Business Owner
  approves it or returns it for revision. Once approved, the payroll and its
  payslips are immutable (`tests/Integration/PayrollImmutabilityTest.php`).
  Runs can be cancelled; they are not edited.
- **Requests:** HR Head **or** Business Owner may approve or cancel. A declined
  request has status `Cancelled`. There is **no `Rejected` status** (it was
  renamed by migration `20260921000020`).
- **Sick leave:** 4 paid sick leaves. Block a request when the balance is
  insufficient.
- **Salary history:** a new salary supersedes the old one (status
  `Superseded`). Periods must not overlap (DB check `chk_salary_period`).
- **Contributions:** the active policy is `PH-STATUTORY-2026`. The current
  rates are PhilHealth 5% total and SSS 15% total, fixed in migrations
  `...26`/`...27`. Unit coverage lives in `ContributionGoldenTest` and
  `MonthlyContributionCutoffTest`.
- **Payroll period:** currently **Friday–Thursday**. The rule has flip-flopped
  (migrations `...010` set Sunday–Friday, and `20261109000024` restored
  Friday–Thursday). Confirm with the owner before touching it.
- **Login** is by email. There is no username field.
- **Employee lifecycle** (archive, rehire, account deactivation) is specified
  in `docs/employee-lifecycle-archive-rehire-spec.md`.

### Formatting and time
- UI dates are **`MM/DD/YY`** and times are **24-hour**. Use the `Formatter`
  helpers; do not call `date()` inline in views.
- Time inputs are `type="text"` with a `pattern` attribute, **not**
  `type="time"`. Browsers render `type="time"` as 12-hour in some locales,
  which breaks the 24-hour NFR. Several commits enforce this.
- The business timezone is `Asia/Manila` (set in bootstrap). Business dates
  are stored as `DATE`.

### Attendance import
- The parser (`LdeXlsDailyLogParser`) is pure. It takes a file and returns
  DTOs or `ParserError`s, with no PDO, session, or matching.
- `AttendanceImportService` owns employee matching, duplicate-file detection
  (`DuplicateAttendanceFileException`), the transaction, and timesheet
  generation. A structural failure must never leave a partial import.

---

## 7. Where docs and code disagree (trust the code, flag it to the user)

| Doc claim | Reality |
|---|---|
| Controllers are thin, and SQL lives only in repositories (`docs/tech.md`) | Several controllers (`AttendanceController`, `ReportsController`, `EmployeeController`, `PayrollController`, about 800–1,300 lines each) build PDO connections and run SQL inline. Match the local style of the file you are editing, but put **new** business logic in `app/Application` or `app/Domain` |
| ADR-0001/0002/0003 in `docs/adr/` | That directory does not exist. Code comments still cite these ADRs |
| PHPMailer for email | A custom `fsockopen` SMTP client in `Mailer.php` |
| PHP 8.5 / MySQL 8.4 | `composer.json` pins platform 8.5 with `platform-check: false`. Railway runs php83. Avoid PHP 8.4+ syntax (property hooks, asymmetric visibility, `array_find`, the pipe operator) unless you have confirmed the runtime |
| "Money as integer centavos" (`PayrollService` docblock) | Calculations use floats with `round(..., 2)`, stored as `DECIMAL(12,2)`. Keep rounding consistent with the code around your change |
| `public/router.php` (`docs/tech.md`) | The router file is at the repo root: `router.php` |

---

## 8. Commands

```bash
composer install                                  # deps (vendor/ is gitignored)
composer test                                     # phpunit --configuration phpunit.xml
vendor/bin/phpunit --testsuite Unit               # fast, no DB needed
vendor/bin/phpunit --testsuite Integration        # needs wbpms_test DB, migrated
composer analyse                                  # phpstan level 6
composer migrate        / composer seed           # dev DB
composer migrate:test   / composer seed:test      # test DB (wbpms_test)
vendor/bin/phinx status -c phinx.php
php -S localhost:8000 -t public/ router.php       # local server
```

- Integration tests read `TEST_DB_*` and `DB_PASSWORD` from `.env.testing` or
  `.env`. If the DB is unreachable, the suite is **skipped**, not failed.
  "Green" may therefore mean the integration tests never ran, so check the
  skip count.
- **Demo accounts** (seeded, synthetic): `owner@demo.test`,
  `hrhead@demo.test`, `employee@demo.test`. Passwords are in `README.md` and
  `database/seeds/DemoUserSeeder.php`.
- **Health check:** `GET /health`.

### Deploy pipeline (awareness only; do not trigger it)
Railway runs `composer install --no-dev`, then
`phinx migrate -e production`, then `php -S 0.0.0.0:$PORT -t public/ ../router.php`.
A push to `main` is effectively a production release with live migrations.
Commit history shows a `WBPMS-dev` staging copy whose reviewed changes get
"promoted" to this repo.

---

## 9. How to add things

**A new page or endpoint**
1. Add the action to the relevant controller in `app/Http/Controllers`, or
   create a new one.
2. Register it in `routes/web.php` with the correct roles.
3. Create the view under `resources/views/<role-area>/<module>/`. Render it
   with `ViewRenderer::render('path/without/.php', $data, 'Title')`. Pass
   `$csrf` (`CsrfMiddleware::generateToken()`) for forms.
4. Escape every output with `Formatter::escape()`, and format dates and times
   through `Formatter`.
5. Use flash messages through `ViewRenderer::flash` / `flashError` /
   `flashWarning` / `flashInfo`, then redirect (POST → redirect → GET).

**A schema change**
1. Add `database/migrations/<YYYYMMDDHHMMSS>_<snake_name>.php` with a
   timestamp **later than every existing migration**. The current latest is
   `20261111000027`.
2. Make it idempotent and safe for production data. Production already holds
   real records, and `...025_ensure_payroll_run_cancellation_schema` shows
   that defensive "ensure" migrations were needed.
3. Update any seeders that depend on it, and the integration tests (for
   example `SchemaInvariantsTest`).

**Business logic**
- Put pure computation in `app/Domain/...` with unit tests in `tests/Unit`.
  Put orchestration and transactions in `app/Application/...`. Put SQL in
  `app/Infrastructure/Persistence/...`.

---

## 10. Conventions

- Every PHP file starts with `declare(strict_types=1);` and uses `final`
  classes where practical, PSR-12 style, and typed properties and returns.
  PHPStan level 6 must pass.
- **Commit style** (for when the owner asks you to commit): Conventional
  Commits with a module scope, for example `fix(benefits): ...`,
  `feat(reports): ...`, `fix(salary): ...`. Common scopes are `attendance`,
  `schedules`, `requests`, `salary`, `benefits`, `payroll`, `reports`,
  `users`, `deploy`, and `deps`. Requirement and bug IDs (`REQ047`, `BUG-3`,
  `[NFR]`) are referenced when relevant.
- Requirement IDs (`REQ0xx`) and acceptance criteria live in
  `docs/requirements.md`. Cite them in code comments for traceability, as the
  existing code does.

---

## 11. Handshake checklist

Before you finish a task, confirm:

- [ ] No commit or push unless the owner asked for it explicitly
- [ ] No `.env`, `*.sql`, `*.zip`, or diagnostic scripts staged
- [ ] New routes have roles, and new forms carry `_csrf`
- [ ] All view output goes through `Formatter::escape()`
- [ ] Dates are `MM/DD/YY` and times are 24-hour text inputs
- [ ] Schema changes are new forward-only migrations
- [ ] `composer test` (Unit at minimum) and `composer analyse` pass, or the
      failures are reported honestly, including skipped integration tests
- [ ] Any doc/code mismatch you hit is reported to the user, not silently "fixed"

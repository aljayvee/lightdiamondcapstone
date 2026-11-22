<?php

declare(strict_types=1);

namespace Wbpms\Http\Controllers;

use RuntimeException;
use Wbpms\Application\PayrollService;
use Wbpms\Domain\Payroll\PayrollMonth;
use Wbpms\Domain\Validation\DailyRateRule;
use Wbpms\Http\View\ViewRenderer;
use Wbpms\Http\View\UserMessage;
use Wbpms\Infrastructure\Database\Connection;
use Wbpms\Infrastructure\Persistence\PositionRepository;
use Wbpms\Infrastructure\Persistence\ScheduleRepository;

/**
 * SettingsController — unified HR Settings hub.
 *
 * Three sub-sections, all under /hr/settings, each with its own active tab:
 *   Positions   — job position CRUD (extracted from PositionController)
 *   Periods     — payroll period management (extracted from PayrollController)
 *   Holidays    — holiday calendar CRUD (extracted from ScheduleController)
 *
 * Routes (all require HRHead):
 *   GET  /hr/settings                           → index()         — redirect to positions tab
 *   GET  /hr/settings/positions                 → positions()     — list / add / edit positions
 *   POST /hr/settings/positions                 → storePosition() — create position
 *   POST /hr/settings/positions/{id}/toggle     → togglePosition()— activate/deactivate
 *   POST /hr/settings/positions/{id}            → updatePosition()— edit position
 *   GET  /hr/settings/periods                   → periods()       — list periods + add form
 *   POST /hr/settings/periods                   → storePeriod()   — create period
 *   GET  /hr/settings/holidays                  → holidays()      — list all holidays + add form
 *   POST /hr/settings/holidays                  → storeHoliday()  — create holiday
 *   GET  /hr/settings/holidays/{id}/edit        → editHoliday()   — edit form
 *   POST /hr/settings/holidays/{id}             → updateHoliday() — save holiday changes
 *   POST /hr/settings/holidays/{id}/delete      → deleteHoliday() — soft-deactivate
 */
final class SettingsController
{
    // =========================================================================
    // Hub redirect
    // =========================================================================

    /** GET /hr/settings → always land on positions tab */
    public function index(array $params = []): void
    {
        $this->redirect('/hr/settings/positions');
    }

    // =========================================================================
    // Job Positions
    // =========================================================================

    /** GET /hr/settings/positions */
    public function positions(array $params = []): void
    {
        $repo      = $this->posRepo();
        $positions = $repo->findAll();
        $total     = count($positions);
        $active    = count(array_filter($positions, fn($p) => $p['status'] === 'Active'));

        ViewRenderer::render('hr/settings/index', [
            'activePage'    => 'settings',
            'tab'           => 'positions',
            'positions'     => $positions,
            'payrollPolicy' => $this->activePayrollPolicy(),
            'total'         => $total,
            'active'        => $active,
            'inactive'      => $total - $active,
            'errors'        => [],
            'editRow'       => null,
        ], 'Settings');
    }

    /** POST /hr/settings/policy */
    public function updatePolicy(array $params = []): void
    {
        $minWageRaw   = trim((string) ($_POST['minimum_daily_wage']   ?? ''));
        $thresholdRaw = trim((string) ($_POST['annual_tax_threshold'] ?? ''));

        // The floor can never exceed the daily-rate ceiling, or no rate could be saved.
        if ($minWageRaw === '' || !is_numeric($minWageRaw) || (float) $minWageRaw < 350.00 || (float) $minWageRaw > DailyRateRule::MAX) {
            ViewRenderer::flashError(sprintf('Minimum daily wage must be a valid amount between ₱350.00 and ₱%s.', number_format(DailyRateRule::MAX, 2)));
            $this->redirect('/hr/settings/positions');
            return;
        }
        if ($thresholdRaw === '' || !is_numeric($thresholdRaw) || (float) $thresholdRaw < 0.00) {
            ViewRenderer::flashError('Annual tax threshold must be a non-negative amount.');
            $this->redirect('/hr/settings/positions');
            return;
        }

        $minWage   = round((float) $minWageRaw, 2);
        $threshold = round((float) $thresholdRaw, 2);
        $pdo       = (new Connection(require APP_ROOT . '/config/database.php'))->pdo();

        $policy = $this->activePayrollPolicy();
        if (($policy['policy_id'] ?? 0) > 0) {
            $pdo->prepare(
                "UPDATE payroll_policy_version
                    SET minimum_daily_wage   = :min_wage,
                        annual_tax_threshold = :threshold,
                        updated_at           = NOW()
                  WHERE policy_id = :id"
            )->execute([
                ':min_wage'  => $minWage,
                ':threshold' => $threshold,
                ':id'        => (int) $policy['policy_id'],
            ]);
        } else {
            $pdo->prepare(
                "INSERT INTO payroll_policy_version
                    (policy_code, version, effective_from, effective_to,
                     eemr_days_per_year, minimum_daily_wage, annual_tax_threshold,
                     late_rate_per_minute, rounding_mode, demo_only, status,
                     approved_at, created_at, updated_at)
                 VALUES
                    ('DEFAULT', 'V1', '2024-01-01', NULL,
                     313, :min_wage, :threshold,
                     1.00, 'HalfUp', FALSE, 'Approved',
                     NOW(), NOW(), NOW())"
            )->execute([
                ':min_wage'  => $minWage,
                ':threshold' => $threshold,
            ]);
        }

        ViewRenderer::flash(sprintf(
            'Payroll policy updated: DOLE minimum daily wage floor set to ₱%s (BIR annual tax threshold ₱%s).',
            number_format($minWage, 2),
            number_format($threshold, 2),
        ));
        $this->redirect('/hr/settings/positions');
    }

    /** POST /hr/settings/positions */
    public function storePosition(array $params = []): void
    {
        ['title' => $title, 'department' => $dept, 'sort_order' => $sort, 'default_daily_rate' => $defaultRate, 'requires_regular' => $reqReg, 'errors' => $errors]
            = $this->extractPosition();

        if ($errors !== []) {
            $this->renderPositions($errors, null);
            return;
        }

        try {
            $this->posRepo()->create($title, $dept, $sort, $defaultRate, $reqReg);
            ViewRenderer::flash("Position \"{$title}\" created.");
        } catch (RuntimeException $e) {
            ViewRenderer::flashError(UserMessage::from($e));
        }

        $this->redirect('/hr/settings/positions');
    }

    /** POST /hr/settings/positions/{id} */
    public function updatePosition(array $params = []): void
    {
        $id   = (int) ($params['id'] ?? 0);
        $repo = $this->posRepo();

        if ($repo->findById($id) === null) {
            http_response_code(404);
            ViewRenderer::render('errors/404', [], 'Not found');
            return;
        }

        ['title' => $title, 'department' => $dept, 'sort_order' => $sort, 'default_daily_rate' => $defaultRate, 'requires_regular' => $reqReg, 'errors' => $errors]
            = $this->extractPosition();

        if ($errors !== []) {
            $this->renderPositions($errors, $id);
            return;
        }

        try {
            $repo->update($id, $title, $dept, $sort, $defaultRate, $reqReg);
            ViewRenderer::flash("Position \"{$title}\" updated.");
        } catch (RuntimeException $e) {
            ViewRenderer::flashError(UserMessage::from($e));
        }

        $this->redirect('/hr/settings/positions');
    }

    /** POST /hr/settings/positions/{id}/toggle */
    public function togglePosition(array $params = []): void
    {
        $id   = (int) ($params['id'] ?? 0);
        $repo = $this->posRepo();
        $row  = $repo->findById($id);

        if ($row === null) {
            http_response_code(404);
            ViewRenderer::render('errors/404', [], 'Not found');
            return;
        }

        $repo->toggleStatus($id);
        $newStatus = $row['status'] === 'Active' ? 'Inactive' : 'Active';
        ViewRenderer::flash("\"{$row['position_title']}\" set to {$newStatus}.");
        $this->redirect('/hr/settings/positions');
    }

    // =========================================================================
    // Payroll Periods
    // =========================================================================

    /**
     * GET /hr/settings/periods[?month=YYYY-MM]
     *
     * With ?month= the page stays on that month: the month box keeps it and
     * the list shows that month's cutoffs first (PAY-033).
     */
    public function periods(array $params = []): void
    {
        $month = PayrollMonth::parse($_GET['month'] ?? null);

        ViewRenderer::render('hr/settings/index', [
            'activePage'    => 'settings',
            'tab'           => 'periods',
            'periods'       => $this->payrollService()->listPeriods(),
            'errors'        => [],
            'payroll_month' => $month ?? date('Y-m'),
            'focus_month'   => $month,
        ], 'Settings');
    }

    /** POST /hr/settings/periods */
    public function storePeriod(array $params = []): void
    {
        $month = trim((string) ($_POST['payroll_month'] ?? ''));
        $service     = $this->payrollService();

        try {
            $result = $service->createPeriodsForMonth($month);
            ViewRenderer::flash(sprintf(
                'Payroll periods for %s generated: %d created, %d already existed.',
                PayrollMonth::label($month),
                $result['created'],
                $result['existing']
            ));
            // Come back on the generated month, not the current one (PAY-033).
            $this->redirect('/hr/settings/periods?month=' . rawurlencode($month));
        } catch (RuntimeException $e) {
            ViewRenderer::render('hr/settings/index', [
                'activePage'   => 'settings',
                'tab'          => 'periods',
                'periods'      => $service->listPeriods(),
                'errors'       => [UserMessage::from($e)],
                'payroll_month' => $month,
                'focus_month'   => PayrollMonth::parse($month),
            ], 'Settings');
        }
    }

    // =========================================================================
    // Holiday Calendar
    // =========================================================================

    /** GET /hr/settings/holidays */
    public function holidays(array $params = []): void
    {
        ViewRenderer::render('hr/settings/index', [
            'activePage' => 'settings',
            'tab'        => 'holidays',
            'holidays'   => $this->schedRepo()->findAllHolidays(),
            'errors'     => [],
        ], 'Settings');
    }

    /** POST /hr/settings/holidays */
    public function storeHoliday(array $params = []): void
    {
        $data   = $this->extractHoliday();
        $errors = $this->validateHoliday($data);

        if ($errors === []) {
            try {
                $this->schedRepo()->createHoliday([
                    'holiday_date' => $data['holiday_date'],
                    'description'  => $data['description'],
                    'holiday_type' => $data['holiday_type'],
                ]);
                ViewRenderer::flash('Holiday added.');
                $this->redirect('/hr/settings/holidays');
                return;
            } catch (RuntimeException $e) {
                $errors['holiday_date'] = UserMessage::from($e);
            }
        }

        ViewRenderer::render('hr/settings/index', [
            'activePage' => 'settings',
            'tab'        => 'holidays',
            'holidays'   => $this->schedRepo()->findAllHolidays(),
            'errors'     => $errors,
            'input'      => $data,
        ], 'Settings');
    }

    /** GET /hr/settings/holidays/{id}/edit */
    public function editHoliday(array $params = []): void
    {
        $id      = (int) ($params['id'] ?? 0);
        $holiday = $this->schedRepo()->findHolidayById($id);

        if ($holiday === null) {
            http_response_code(404);
            ViewRenderer::render('errors/404', [], 'Not found');
            return;
        }

        ViewRenderer::render('hr/settings/holiday_edit', [
            'activePage' => 'settings',
            'holiday'    => $holiday,
            'errors'     => [],
        ], 'Edit Holiday — Settings');
    }

    /** POST /hr/settings/holidays/{id}  (with _method=PUT) */
    public function updateHoliday(array $params = []): void
    {
        $id      = (int) ($params['id'] ?? 0);
        $repo    = $this->schedRepo();
        $holiday = $repo->findHolidayById($id);

        if ($holiday === null) {
            http_response_code(404);
            ViewRenderer::render('errors/404', [], 'Not found');
            return;
        }

        $data   = $this->extractHoliday();
        $errors = $this->validateHoliday($data);

        if ($errors === []) {
            try {
                $repo->updateHoliday($id, [
                    'holiday_date' => $data['holiday_date'],
                    'description'  => $data['description'],
                    'holiday_type' => $data['holiday_type'],
                    'status'       => $data['status'] !== '' ? $data['status'] : 'Active',
                ]);
                ViewRenderer::flash('Holiday updated.');
                $this->redirect('/hr/settings/holidays');
                return;
            } catch (RuntimeException $e) {
                $errors['holiday_date'] = UserMessage::from($e);
            }
        }

        ViewRenderer::render('hr/settings/holiday_edit', [
            'activePage' => 'settings',
            'holiday'    => array_merge($holiday, ['holiday_id' => $id], $data),
            'errors'     => $errors,
        ], 'Edit Holiday — Settings');
    }

    /** POST /hr/settings/holidays/{id}/delete */
    public function deleteHoliday(array $params = []): void
    {
        $id = (int) ($params['id'] ?? 0);

        if ($this->schedRepo()->findHolidayById($id) === null) {
            http_response_code(404);
            ViewRenderer::render('errors/404', [], 'Not found');
            return;
        }

        $this->schedRepo()->deactivateHoliday($id);
        ViewRenderer::flash('Holiday deactivated.');
        $this->redirect('/hr/settings/holidays');
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * @return array{title:string,department:string|null,sort_order:int,default_daily_rate:float|null,requires_regular:bool,errors:array<string,string>}
     */
    private function extractPosition(): array
    {
        $title    = trim((string) ($_POST['position_title']     ?? ''));
        $dept     = trim((string) ($_POST['department']         ?? ''));
        $sort     = (int) ($_POST['sort_order'] ?? 0);
        $rateRaw  = trim((string) ($_POST['default_daily_rate'] ?? ''));
        $reqReg   = !empty($_POST['requires_regular']);
        $errors   = [];

        if ($title === '') {
            $errors['position_title'] = 'Position title is required.';
        } elseif (mb_strlen($title) > 100) {
            $errors['position_title'] = 'Position title must be 100 characters or fewer.';
        }

        $defaultRate = null;
        $minWageFloor = (float) ($this->activePayrollPolicy()['minimum_daily_wage'] ?? DailyRateRule::FALLBACK_MINIMUM);
        if ($rateRaw !== '') {
            if (!is_numeric($rateRaw) || (float) $rateRaw < $minWageFloor) {
                $errors['default_daily_rate'] = sprintf('Default daily rate must be at least the active minimum wage (₱%s).', number_format($minWageFloor, 2));
            } elseif ((float) $rateRaw > DailyRateRule::MAX) {
                $errors['default_daily_rate'] = sprintf('Default daily rate cannot be more than ₱%s.', number_format(DailyRateRule::MAX, 2));
            } else {
                $defaultRate = round((float) $rateRaw, 2);
            }
        }

        return [
            'title'              => $title,
            'department'         => $dept !== '' ? $dept : null,
            'sort_order'         => max(0, $sort),
            'default_daily_rate' => $defaultRate,
            'requires_regular'   => $reqReg,
            'errors'             => $errors,
        ];
    }

    /** @param array<string,string> $errors */
    private function renderPositions(array $errors, ?int $editId): void
    {
        $repo      = $this->posRepo();
        $positions = $repo->findAll();
        $total     = count($positions);
        ViewRenderer::render('hr/settings/index', [
            'activePage'    => 'settings',
            'tab'           => 'positions',
            'positions'     => $positions,
            'payrollPolicy' => $this->activePayrollPolicy(),
            'total'         => $total,
            'active'        => count(array_filter($positions, fn($p) => $p['status'] === 'Active')),
            'inactive'      => count(array_filter($positions, fn($p) => $p['status'] !== 'Active')),
            'errors'        => $errors,
            'editRow'       => $editId,
            'input'         => $_POST,
        ], 'Settings');
    }

    /** @return array{policy_id:int,minimum_daily_wage:float,annual_tax_threshold:float} */
    private function activePayrollPolicy(): array
    {
        try {
            $pdo = (new Connection(require APP_ROOT . '/config/database.php'))->pdo();
            $stmt = $pdo->query(
                "SELECT policy_id, minimum_daily_wage, annual_tax_threshold
                   FROM payroll_policy_version
                  WHERE status = 'Approved'
                  ORDER BY effective_from DESC
                  LIMIT 1"
            );
            $row = $stmt !== false ? $stmt->fetch(\PDO::FETCH_ASSOC) : false;
            if ($row !== false) {
                return [
                    'policy_id'            => (int) $row['policy_id'],
                    'minimum_daily_wage'   => (float) ($row['minimum_daily_wage'] ?? DailyRateRule::FALLBACK_MINIMUM),
                    'annual_tax_threshold' => (float) ($row['annual_tax_threshold'] ?? 250000.00),
                ];
            }
        } catch (\Throwable) {
            // Fallback when running on minimal schema
        }

        return [
            'policy_id'            => 0,
            'minimum_daily_wage'   => DailyRateRule::FALLBACK_MINIMUM,
            'annual_tax_threshold' => 250000.00,
        ];
    }

    /** @return array<string,string> */
    private function extractHoliday(): array
    {
        return [
            'holiday_date' => trim((string) ($_POST['holiday_date'] ?? '')),
            'description'  => trim((string) ($_POST['description']  ?? '')),
            'holiday_type' => trim((string) ($_POST['holiday_type'] ?? '')),
            'status'       => trim((string) ($_POST['status']       ?? 'Active')),
        ];
    }

    /**
     * @param  array<string,string> $data
     * @return array<string,string>
     */
    private function validateHoliday(array $data): array
    {
        $errors = [];

        if ($data['holiday_date'] === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['holiday_date'])) {
            $errors['holiday_date'] = 'Holiday date is required (YYYY-MM-DD).';
        }
        if ($data['description'] === '') {
            $errors['description'] = 'Holiday name / description is required.';
        }
        if (!in_array($data['holiday_type'], ['Regular', 'Special', 'SpecialNonWorking'], true)) {
            $errors['holiday_type'] = 'Holiday type must be Regular, Special, or Special Non-Working.';
        }

        return $errors;
    }

    private function posRepo(): PositionRepository
    {
        return new PositionRepository(new Connection(require APP_ROOT . '/config/database.php'));
    }

    private function schedRepo(): ScheduleRepository
    {
        return new ScheduleRepository(new Connection(require APP_ROOT . '/config/database.php'));
    }

    private function payrollService(): PayrollService
    {
        $conn = new Connection(require APP_ROOT . '/config/database.php');
        return new PayrollService($conn);
    }

    private function redirect(string $path): void
    {
        $base = rtrim((string) ($_ENV['APP_BASE_URL'] ?? ''), '/');
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        header('Location: ' . $base . $path, true, 302);
        exit;
    }
}

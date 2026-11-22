<?php

declare(strict_types=1);

namespace Wbpms\Http\Controllers;

use PDO;
use Wbpms\Application\EmployeeDocumentService;
use Wbpms\Application\EmployeeLifecycleService;
use Wbpms\Application\UserService;
use Wbpms\Domain\Employee\BankAccountDateRejected;
use Wbpms\Domain\Employee\ContractReviewPolicy;
use Wbpms\Domain\Validation\DailyRateRule;
use Wbpms\Http\Middleware\AuthMiddleware;
use Wbpms\Http\View\ViewRenderer;
use Wbpms\Http\View\UserMessage;
use Wbpms\Infrastructure\Database\Connection;
use Wbpms\Infrastructure\Persistence\EmployeeRepository;
use Wbpms\Infrastructure\Persistence\ScheduleRepository;
use Wbpms\Infrastructure\Logging\ErrorLog;

/**
 * EmployeeController — HR-only employee management.
 *
 * Routes (all require HRHead role):
 *   GET  /hr/employees          → index()   — list with filters
 *   GET  /hr/employees/new      → create()  — blank create form
 *   POST /hr/employees          → store()   — persist new employee
 *   GET  /hr/employees/{id}     → show()    — view detail (alias to edit form)
 *   GET  /hr/employees/{id}/edit → editForm() — edit form
 *   POST /hr/employees/{id}     → update()  — persist changes
 *
 * REQ009–REQ017 (P0 subset): list/create/update.
 * Transfer history, archive workflow, and search pagination are deferred.
 */
final class EmployeeController
{
    /** Highest daily rate HR may enter (inclusive); see DailyRateRule. */
    public const MAX_DAILY_RATE = DailyRateRule::MAX;

    /**
     * GET /hr/employees
     *
     * @param array<string, string> $params
     */
    public function index(array $params = []): void
    {
        $repo   = $this->makeRepo();
        $search = trim((string) ($_GET['search'] ?? ''));
        $branch = (string) ($_GET['branch']  ?? '');

        // Default to 'active' only on the initial page load (no status key in URL).
        // When the filter form is submitted with "All statuses", status='' is sent
        // explicitly — honour that and show all statuses rather than forcing Active.
        $statusInUrl = array_key_exists('status', $_GET);
        $status      = $statusInUrl ? (string) ($_GET['status'] ?? '') : 'active';

        $position = trim((string) ($_GET['position'] ?? ''));

        $employees = $repo->findAll([
            'search'   => $search,
            'branch'   => $branch,
            'status'   => $status,
            'position' => $position,
        ]);
        $branches  = $repo->activeBranches();
        $positions = $this->makeConnection()->pdo()->query(
            "SELECT position_title AS name
               FROM job_position
              WHERE status = 'Active'
              ORDER BY sort_order ASC, position_title ASC"
        )->fetchAll(\PDO::FETCH_ASSOC);

        ViewRenderer::render('hr/employees/index', [
            'employees'      => $employees,
            'branches'       => $branches,
            'positions'      => $positions,
            'search'         => $search,
            'filterBranch'   => $branch,
            'filterStatus'   => $status,
            'filterPosition' => $position,
        ], 'Employees');
    }

    /**
     * GET /hr/employees/new
     *
     * @param array<string, string> $params
     */
    public function create(array $params = []): void
    {
        $repo = $this->makeRepo();
        [$branches, $schedules, $devices, $positions] = $this->dropdownData();

        ViewRenderer::render('hr/employees/edit', [
            'employee'  => null,
            'nextEmployeeNumber' => $repo->nextEmployeeNumber(),
            'branches'  => $branches,
            'schedules' => $schedules,
            'devices'   => $devices,
            'positions' => $positions,
            'minimumDailyWage' => $repo->activeMinimumDailyWage(),
            'errors'    => [],
        ], 'Add Employee');
    }

    /**
     * POST /hr/employees
     *
     * @param array<string, string> $params
     */
    public function store(array $params = []): void
    {
        // One connection for the repository, the user service and the
        // transaction, so a failed save (e.g. login provisioning) rolls back
        // the employee and every row written for it.
        $connection = $this->makeConnection();
        $repo   = new EmployeeRepository($connection);
        $data   = $this->extractPostFields();
        // The employee number is auto-generated (EMP-001, EMP-002, ...) just
        // before the insert below; any posted value is ignored.
        $data['employee_number'] = '';
        $errors = $this->validate($data, false, $repo);

        if ($errors !== []) {
            [$branches, $schedules, $devices, $positions] = $this->dropdownData();
            ViewRenderer::render('hr/employees/edit', [
                'employee'  => null,
                'nextEmployeeNumber' => $repo->nextEmployeeNumber(),
                'branches'  => $branches,
                'schedules' => $schedules,
                'devices'   => $devices,
                'positions' => $positions,
                'minimumDailyWage' => $repo->activeMinimumDailyWage(),
                'errors'    => $errors,
            ], 'Add Employee');
            return;
        }

        $userService = new UserService($connection);

        // Derive username and temporary password BEFORE opening the transaction
        // so collision detection runs on the same connection without nesting.
        $username  = $userService->deriveUsername($data['first_name'], $data['last_name']);
        $tempPwd   = $userService->generateTemporaryPassword();
        $actorId   = (int) (AuthMiddleware::identity()['user_id'] ?? 0);

        // Capture provisioned credentials for the post-save display.
        $provisionedUsername = '';
        $employeeNumber      = '';

        try {
            $connection->transaction(function (PDO $pdo) use (
                $data, $repo, $userService, $username, $tempPwd, $actorId,
                &$provisionedUsername, &$employeeNumber
            ): void {
                $employeeNumber = $repo->nextEmployeeNumber();
                $employeeId = $repo->createEmployee([
                    'employee_number'   => $employeeNumber,
                    'employee_type'     => $data['employee_type'] ?: 'Regular',
                    'first_name'        => $data['first_name'],
                    'middle_initial'    => $data['middle_name'] !== '' ? mb_substr($data['middle_name'], 0, 50) : null,
                    'last_name'         => $data['last_name'],
                    'email'             => $data['email']          !== '' ? $data['email']          : null,
                    'contact_number'    => $data['contact_number'] !== '' ? $data['contact_number'] : null,
                    'birthdate'         => $data['birthdate']      !== '' ? $data['birthdate']      : null,
                    'hire_date'         => $data['effective_from'],
                    'position'          => $data['position']       !== '' ? $data['position']       : 'Employee',
                    'status'            => 'Active',
                    'philhealth_number' => $data['philhealth_number'] !== '' ? $data['philhealth_number'] : null,
                    'pagibig_number'    => $data['pagibig_number']    !== '' ? $data['pagibig_number']    : null,
                    'tin_number'        => $data['tin_number']        !== '' ? $data['tin_number']        : null,
                    'sss_number'        => $data['sss_number']        !== '' ? $data['sss_number']        : null,
                    'contract_review_date' => $this->contractReviewDateFor($data),
                ]);

                $repo->assignInitialBranch(
                    $employeeId,
                    (int) $data['branch_id'],
                    $data['effective_from']
                );

                $repo->assignEffectiveSchedule(
                    $employeeId,
                    (int) $data['schedule_id'],
                    $data['effective_from']
                );

                if ($data['daily_rate'] !== '' && (float) $data['daily_rate'] > 0) {
                    $repo->createSalary($employeeId, (float) $data['daily_rate'], $data['effective_from']);
                }

                // Required by validate(); always enrolled. [QA EMP TC3]
                $repo->enrollBiometricCode(
                    $employeeId,
                    (int) $data['device_id'],
                    $data['enrollment_code'],
                    $data['effective_from']
                );

                // Reconcile any previously unmatched biometric punches for this device + Enroll ID
                try {
                    (new \Wbpms\Infrastructure\Persistence\PdoAttendanceImportGateway($pdo))
                        ->rematchUnmatchedPunches((int) $data['device_id'], $data['enrollment_code'], $actorId);
                } catch (\Throwable) {
                    // Best-effort punch reconciliation
                }

                // Auto-provision the Employee login account (Requirement 13).
                // Login credential is the employee's email address (not username).
                $userService->provisionForEmployee(
                    $pdo,
                    $employeeId,
                    $username,
                    $tempPwd,
                    $actorId,
                    $data['email']
                );

                $provisionedUsername = $username;
            });
        } catch (\RuntimeException $e) {
            // PDOException extends RuntimeException. Known unique-key clashes
            // become field errors; account-provisioning rules (e.g. "already
            // has an active user account") are shown on the form instead of
            // a 500 page. Unknown database errors still propagate. [QA EMP TC2]
            if ($e instanceof \PDOException) {
                $fieldErrors = $this->uniqueConstraintErrors($e);
                if ($fieldErrors === []) {
                    throw $e;
                }
            } else {
                $fieldErrors = ['email' => UserMessage::from($e)];
            }

            [$branches, $schedules, $devices, $positions] = $this->dropdownData();
            ViewRenderer::render('hr/employees/edit', [
                'employee'  => null,
                'nextEmployeeNumber' => $repo->nextEmployeeNumber(),
                'branches'  => $branches,
                'schedules' => $schedules,
                'devices'   => $devices,
                'positions' => $positions,
                'minimumDailyWage' => $repo->activeMinimumDailyWage(),
                'errors'    => $fieldErrors,
            ], 'Add Employee');
            return;
        }

        // Store credentials in session and redirect (PRG pattern) so a browser
        // reload does not resubmit the form and attempt to create a duplicate.
        // Requirement 13, AC7: display email + temporary password exactly once.
        $_SESSION['_employee_created'] = [
            'employeeName'   => trim($data['first_name'] . ' ' . $data['last_name']),
            'employeeNumber' => $employeeNumber,
            'accountEmail'   => $data['email'],
            'tempPassword' => $tempPwd,
        ];
        $this->redirect('/hr/employees/created');
    }

    /**
     * GET /hr/employees/created — one-time credential confirmation screen.
     *
     * Reads and clears the session stash written by store() so that a browser
     * reload or back-navigation shows a safe "already shown" message instead
     * of re-running the POST (PRG pattern, Requirement 13 AC7).
     *
     * @param array<string, string> $params
     */
    public function created(array $params = []): void
    {
        $payload = $_SESSION['_employee_created'] ?? null;
        unset($_SESSION['_employee_created']);

        if ($payload === null) {
            // Session data already consumed or user navigated here directly.
            ViewRenderer::flashError('Employee credentials have already been displayed or the page was reloaded.');
            $this->redirect('/hr/employees');
            return;
        }

        ViewRenderer::render('hr/employees/created', [
            'employeeName' => (string) $payload['employeeName'],
            'employeeNumber' => (string) ($payload['employeeNumber'] ?? ''),
            'accountEmail' => (string) $payload['accountEmail'],
            'tempPassword' => (string) $payload['tempPassword'],
        ], 'Employee Created');
    }

    /**
     * GET /hr/employees/{id}  — read-only detail view
     *
     * @param array<string, string> $params
     */
    public function show(array $params = []): void
    {
        $id  = (int) ($params['id'] ?? 0);
        $con = $this->makeConnection();
        $repo = new EmployeeRepository($con);

        $employee = $repo->findDetail($id);

        if ($employee === null) {
            $this->renderNotFound();
            return;
        }

        $branchHistory  = $repo->branchHistory($id);
        $salaryHistory  = $repo->salaryHistory($id);
        $lifecycleEvents = (new \Wbpms\Infrastructure\Persistence\EmployeeLifecycleRepository($con))->lifecycleEvents($id);

        // Bank account details
        $bankDetails = $repo->findBankDetails($id);

        // Document count (current only)
        $docCount = (new \Wbpms\Application\EmployeeDocumentService($con))
            ->listForEmployee($id);
        $currentDocCount = count(array_filter(
            $docCount,
            static fn(array $d): bool => (string) $d['status'] === 'Current'
        ));

        ViewRenderer::render('hr/employees/show', [
            'employee'        => $employee,
            'branchHistory'   => $branchHistory,
            'salaryHistory'   => $salaryHistory,
            'lifecycleEvents' => $lifecycleEvents,
            'bankDetails'     => $bankDetails,
            'currentDocCount' => $currentDocCount,
            'activePage'      => 'employees',
        ], $employee['last_name'] . ', ' . $employee['first_name']);
    }

    /**
     * GET /hr/employees/{id}/edit
     *
     * @param array<string, string> $params
     */
    public function editForm(array $params = []): void
    {
        $id  = (int) ($params['id'] ?? 0);
        $repo = $this->makeRepo();
        $row = $repo->findById($id);

        if ($row === null) {
            http_response_code(404);
            ViewRenderer::render('errors/404', [], 'Not found');
            return;
        }

        // Issue 2.3: Archived employees cannot be edited through the normal
        // workflow; they must be rehired first. Redirect with a clear message
        // rather than displaying an editable form that would only fail on save.
        if (strtolower((string) $row['status']) === 'archived') {
            ViewRenderer::flashError(
                'Archived employees cannot be edited. Use the Rehire workflow to restore this employee before making changes.'
            );
            $this->redirect('/hr/employees/' . $id);
            return;
        }

        [$branches, $schedules, $devices, $positions] = $this->dropdownData();

        ViewRenderer::render('hr/employees/edit', [
            'employee'  => $row,
            'branches'  => $branches,
            'schedules' => $schedules,
            'devices'   => $devices,
            'positions' => $positions,
            'minimumDailyWage' => $repo->activeMinimumDailyWage(),
            'errors'    => [],
        ], 'Edit Employee');
    }

    /**
     * GET /hr/employees/{id}/transfer
     *
     * @param array<string, string> $params
     */
    public function transferForm(array $params = []): void
    {
        $id  = (int) ($params['id'] ?? 0);
        $row = $this->makeRepo()->findById($id);

        if ($row === null) {
            http_response_code(404);
            ViewRenderer::render('errors/404', [], 'Not found');
            return;
        }

        $branches = (new Connection(require APP_ROOT . '/config/database.php'))->pdo()->query(
            "SELECT branch_id AS id, branch_name AS name FROM branch WHERE status = 'Active' ORDER BY branch_name"
        )->fetchAll(PDO::FETCH_ASSOC);

        ViewRenderer::render('hr/employees/transfer', [
            'employee'          => $row,
            'branches'          => $branches,
            'errors'            => [],
            'currentBranchFrom' => $this->makeRepo()->currentBranchStart($id),
        ], 'Transfer Employee');
    }

    /**
     * POST /hr/employees/{id}/transfer
     *
     * @param array<string, string> $params
     */
    public function transfer(array $params = []): void
    {
        $id       = (int) ($params['id'] ?? 0);
        $repo     = $this->makeRepo();
        $row      = $repo->findById($id);

        if ($row === null) {
            http_response_code(404);
            ViewRenderer::render('errors/404', [], 'Not found');
            return;
        }

        $newBranchId  = (int) ($_POST['branch_id']     ?? 0);
        $transferDate = trim((string) ($_POST['transfer_date'] ?? ''));
        $errors       = [];

        if ($newBranchId <= 0) {
            $errors['branch_id'] = 'Destination branch is required.';
        }
        if ($transferDate === '' || !$this->isIsoDate($transferDate)) {
            $errors['transfer_date'] = 'Choose the transfer date.';
        }

        if ($errors === []) {
            try {
                $repo->transferEmployee($id, $newBranchId, $transferDate);
                ViewRenderer::flash('Employee transferred successfully.');
                $this->redirect('/hr/employees/' . $id);
                return;
            } catch (\RuntimeException $e) {
                $errors['transfer_date'] = UserMessage::from($e);
            }
        }

        $branches = (new Connection(require APP_ROOT . '/config/database.php'))->pdo()->query(
            "SELECT branch_id AS id, branch_name AS name FROM branch WHERE status = 'Active' ORDER BY branch_name"
        )->fetchAll(PDO::FETCH_ASSOC);

        ViewRenderer::render('hr/employees/transfer', [
            'employee'          => $row,
            'branches'          => $branches,
            'errors'            => $errors,
            'currentBranchFrom' => $repo->currentBranchStart($id),
        ], 'Transfer Employee');
    }

    /** @param array<string, string> $params */
    public function archiveForm(array $params = []): void
    {
        $id = (int) ($params['id'] ?? 0);
        $employee = $this->makeRepo()->findById($id);
        if ($employee === null) {
            $this->renderNotFound();
            return;
        }
        if (strtolower((string) $employee['status']) === 'archived') {
            ViewRenderer::flashError('This employee is already archived.');
            $this->redirect('/hr/employees?status=archived');
        }
        $unapprovedPayrollRuns = (new \Wbpms\Infrastructure\Persistence\EmployeeLifecycleRepository($this->makeConnection()))
            ->unapprovedPayrollRuns($id);
        ViewRenderer::render('hr/employees/archive', [
            'employee' => $employee,
            'unapprovedPayrollRuns' => $unapprovedPayrollRuns,
            'errors' => [],
        ], 'Archive Employee');
    }

    /** @param array<string, string> $params */
    public function archive(array $params = []): void
    {
        $id = (int) ($params['id'] ?? 0);
        $employee = $this->makeRepo()->findById($id);
        if ($employee === null) {
            $this->renderNotFound();
            return;
        }

        $lastWorkingDate = trim((string) ($_POST['last_working_date'] ?? ''));
        $reason = trim((string) ($_POST['reason'] ?? ''));
        $notes = trim((string) ($_POST['notes'] ?? ''));
        $todayManila = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Manila')))->format('Y-m-d');
        $errors = [];
        if (!$this->isIsoDate($lastWorkingDate)) {
            $errors['last_working_date'] = 'Enter a valid last working date.';
        } elseif ($lastWorkingDate > $todayManila) {
            $errors['last_working_date'] = 'Last working date cannot be in the future.';
        }
        if ($reason === '') {
            $errors['reason'] = 'Archive reason is required.';
        }
        if (mb_strlen($reason) > 100) {
            $errors['reason'] = 'Archive reason must be 100 characters or fewer.';
        }
        if (mb_strlen($notes) > 1000) {
            $errors['notes'] = 'HR note must be 1,000 characters or fewer.';
        }
        $unapprovedPayrollRuns = (new \Wbpms\Infrastructure\Persistence\EmployeeLifecycleRepository($this->makeConnection()))
            ->unapprovedPayrollRuns($id);
        if ($errors !== []) {
            ViewRenderer::render('hr/employees/archive', [
                'employee' => $employee,
                'unapprovedPayrollRuns' => $unapprovedPayrollRuns,
                'errors' => $errors,
            ], 'Archive Employee');
            return;
        }

        try {
            $identity = AuthMiddleware::identity();
            $actorId = (int) ($identity['user_id'] ?? 0);
            $actorRole = (string) ($identity['role_name'] ?? '');
            (new EmployeeLifecycleService($this->makeConnection()))->archive(
                $id,
                $lastWorkingDate,
                $reason,
                $notes !== '' ? $notes : null,
                $actorId,
                $actorRole,
            );
            ViewRenderer::flash('Employee archived. Their active assignments, salary, biometric enrollment, and account were closed while history was retained.');
            $this->redirect('/hr/employees?status=archived');
        } catch (\RuntimeException $e) {
            $errors['form'] = UserMessage::from($e);
            ViewRenderer::render('hr/employees/archive', [
                'employee' => $employee,
                'unapprovedPayrollRuns' => $unapprovedPayrollRuns,
                'errors' => $errors,
            ], 'Archive Employee');
        }
    }

    /** @param array<string, string> $params */
    public function rehireForm(array $params = []): void
    {
        $repo = $this->makeRepo();
        $employee = $repo->findById((int) ($params['id'] ?? 0));
        if ($employee === null) {
            $this->renderNotFound();
            return;
        }
        if (strtolower((string) $employee['status']) !== 'archived') {
            ViewRenderer::flashError('Only an archived employee can be rehired from this screen.');
            $this->redirect('/hr/employees/' . (int) $employee['id'] . '/edit');
        }
        [$branches, $schedules, $devices, $positions] = $this->dropdownData();
        ViewRenderer::render('hr/employees/rehire', compact('employee', 'branches', 'schedules', 'devices', 'positions') + [
            'minimumDailyWage' => $repo->activeMinimumDailyWage(),
            'errors' => [],
        ], 'Rehire Employee');
    }

    /** @param array<string, string> $params */
    public function rehire(array $params = []): void
    {
        $id = (int) ($params['id'] ?? 0);
        $repo = $this->makeRepo();
        $employee = $repo->findById($id);
        if ($employee === null) {
            $this->renderNotFound();
            return;
        }
        $data = [
            'rehire_date' => trim((string) ($_POST['rehire_date'] ?? '')),
            'branch_id' => (int) ($_POST['branch_id'] ?? 0),
            'schedule_id' => (int) ($_POST['schedule_id'] ?? 0),
            'device_id' => (int) ($_POST['device_id'] ?? 0),
            'enrollment_code' => trim((string) ($_POST['enrollment_code'] ?? '')),
            'daily_rate' => (float) ($_POST['daily_rate'] ?? 0),
            'position' => (string) preg_replace('/\s+/u', ' ', trim((string) ($_POST['position'] ?? ''))),
            'employee_type' => trim((string) ($_POST['employee_type'] ?? '')),
            'contract_review_date' => trim((string) ($_POST['contract_review_date'] ?? '')),
            'notes' => trim((string) ($_POST['notes'] ?? '')),
            'reactivate_account' => isset($_POST['reactivate_account']),
        ];
        $errors = $this->validateRehire($data, $repo, $id);
        if ($errors === []) {
            try {
                $actorId = (int) (AuthMiddleware::identity()['user_id'] ?? 0);
                $temporaryPassword = (new EmployeeLifecycleService($this->makeConnection()))->rehire($id, $data, $actorId);
                $message = 'Employee rehired. A new employment episode and effective-dated assignments were created; historic records remain unchanged.';
                if ($temporaryPassword !== null) {
                    $message .= ' New temporary password: ' . $temporaryPassword . ' (give it to the employee securely; they must change it on first login).';
                }
                ViewRenderer::flash($message);
                $this->redirect('/hr/employees/' . $id);
            } catch (\PDOException $e) {
                // Surface database constraint violations (e.g. duplicate branch/schedule
                // assignment for the same effective date) as a friendly form error
                // instead of a 500 page.
                $driverCode = (int) ($e->errorInfo[1] ?? 0);
                if ($driverCode === 1062) {
                    $errors['form'] = 'A duplicate assignment record was detected for the selected rehire date. '
                        . 'This employee may already have a branch, schedule, salary, or biometric record '
                        . 'starting on that date. Choose a different rehire date or resolve the conflict first.';
                } else {
                    ErrorLog::exception($e, 'employee rehire');
                    $errors['form'] = UserMessage::from($e, 'The rehire could not be saved because of a database problem');
                }
            } catch (\RuntimeException $e) {
                $errors['form'] = UserMessage::from($e);
            }
        }
        [$branches, $schedules, $devices, $positions] = $this->dropdownData();
        ViewRenderer::render('hr/employees/rehire', compact('employee', 'branches', 'schedules', 'devices', 'positions', 'errors') + [
            'minimumDailyWage' => $repo->activeMinimumDailyWage(),
        ], 'Rehire Employee');
    }

    /**
     * POST /hr/employees/{id}  (with _method=PUT from the form)
     *
     * @param array<string, string> $params
     */
    public function update(array $params = []): void
    {
        $id   = (int) ($params['id'] ?? 0);
        // One connection for the repository and the transaction (see store()).
        $connection = $this->makeConnection();
        $repo = new EmployeeRepository($connection);
        $row  = $repo->findById($id);

        if ($row === null) {
            http_response_code(404);
            ViewRenderer::render('errors/404', [], 'Not found');
            return;
        }

        $data   = $this->extractPostFields();
        $errors = $this->validate($data, true, $repo, $id, (string) ($row['hire_date'] ?? ''));

        if (in_array(strtolower($data['status']), ['archived', 'separated'], true)) {
            $errors['status'] = 'Use the controlled archive workflow to end an employment record.';
        }
        if (strtolower((string) $row['status']) === 'archived') {
            $errors['status'] = 'Archived employees must be rehired through the rehire workflow before editing operational details.';
        }

        if ($errors !== []) {
            [$branches, $schedules, $devices, $positions] = $this->dropdownData();
            // Merge posted values back into the employee array for repopulation
            $merged = array_merge($row, $data, ['id' => $id]);
            ViewRenderer::render('hr/employees/edit', [
                'employee'  => $merged,
                'branches'  => $branches,
                'schedules' => $schedules,
                'devices'   => $devices,
                'positions' => $positions,
                'minimumDailyWage' => $repo->activeMinimumDailyWage(),
                'errors'    => $errors,
            ], 'Edit Employee');
            return;
        }

        $actorId    = (int) (AuthMiddleware::identity()['user_id'] ?? 0);
        $todayManila = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Manila')))->format('Y-m-d');

        try {
            $connection->transaction(function (\PDO $pdo) use (
                $id, $data, $row, $repo, $connection, $actorId, $todayManila
            ): void {
                $repo->updateEmployee($id, [
                    'employee_type'     => $data['employee_type'] ?: 'Regular',
                    'first_name'        => $data['first_name'],
                    'middle_initial'    => $data['middle_name'] !== '' ? mb_substr($data['middle_name'], 0, 50) : null,
                    'last_name'         => $data['last_name'],
                    'email'             => $data['email']          !== '' ? $data['email']          : null,
                    'contact_number'    => $data['contact_number'] !== '' ? $data['contact_number'] : null,
                    'birthdate'         => $data['birthdate']      !== '' ? $data['birthdate']      : null,
                    'position'          => $data['position']       !== '' ? $data['position']       : null,
                    'philhealth_number' => $data['philhealth_number'] !== '' ? $data['philhealth_number'] : null,
                    'pagibig_number'    => $data['pagibig_number']    !== '' ? $data['pagibig_number']    : null,
                    'tin_number'        => $data['tin_number']        !== '' ? $data['tin_number']        : null,
                    'sss_number'        => $data['sss_number']        !== '' ? $data['sss_number']        : null,
                    'status'            => ucfirst(strtolower($data['status'] ?? 'active')),
                    // Sent every time so a save never moves the date. [EMP-024]
                    'contract_review_date' => $this->contractReviewDateFor($data),
                ]);

                // Salary is effective-dated. Do not create a new row merely because an
                // employee profile was saved with the unchanged current rate; doing so
                // can create an invalid zero-length same-day salary period.
                if ($data['daily_rate'] !== ''
                    && (float) $data['daily_rate'] > 0
                    && abs((float) $data['daily_rate'] - (float) $row['daily_rate']) > 0.00001) {
                    $repo->updateSalary($id, (float) $data['daily_rate']);
                }

                // Update schedule assignment if changed on the edit form
                if ($data['schedule_id'] !== ''
                    && (int) $data['schedule_id'] > 0
                    && (int) $data['schedule_id'] !== (int) ($row['schedule_id'] ?? 0)) {
                    (new ScheduleRepository($connection))->assign([
                        'employee_id' => $id,
                        'schedule_id' => (int) $data['schedule_id'],
                        'effective_from' => $todayManila,
                    ]);
                    try {
                        (new \Wbpms\Infrastructure\Persistence\AttendanceScheduleRecalculator($pdo))
                            ->recalculateEmployee($id, $todayManila);
                    } catch (\Throwable) {
                        // Best-effort recalculation
                    }
                }

                // Biometric device / enroll ID edited on the form. [QA EMP TC4]
                if ($data['device_id'] !== '' && (int) $data['device_id'] > 0 && $data['enrollment_code'] !== '') {
                    $repo->changeBiometricEnrollment(
                        $id,
                        (int) $data['device_id'],
                        $data['enrollment_code'],
                        (string) $row['hire_date'],
                        $todayManila,
                    );
                    try {
                        (new \Wbpms\Infrastructure\Persistence\PdoAttendanceImportGateway($pdo))
                            ->rematchUnmatchedPunches((int) $data['device_id'], $data['enrollment_code'], $actorId);
                    } catch (\Throwable) {
                        // Best-effort punch reconciliation
                    }
                }

                // Sync employee.email → users.account_email for the linked user account.
                // When HR changes the employee's email address the linked login account must
                // reflect that change so the employee can still log in with the new address.
                // Only runs when (a) an email was supplied, (b) it actually changed, and
                // (c) the employee has an active or inactive (non-archived) linked account.
                $newEmail = $data['email'];
                $oldEmail = (string) ($row['email'] ?? '');
                if ($newEmail !== '' && $newEmail !== $oldEmail) {
                    $stmt = $pdo->prepare(
                        "SELECT user_id FROM users
                          WHERE employee_id = :emp
                            AND status != 'Archived'
                          LIMIT 1"
                    );
                    $stmt->execute([':emp' => $id]);
                    $linkedUserId = $stmt->fetchColumn();

                    if ($linkedUserId !== false) {
                        (new UserService($connection))->update(
                            (int) $linkedUserId,
                            ['account_email' => $newEmail],
                            $actorId
                        );
                    }
                }
            });
        } catch (\RuntimeException $e) {
            // Surface domain errors (e.g. email already used by another account)
            // as a field-level form error rather than a fatal crash.
            [$branches, $schedules, $devices, $positions] = $this->dropdownData();
            $merged = array_merge($row, $data, ['id' => $id]);
            ViewRenderer::render('hr/employees/edit', [
                'employee'  => $merged,
                'branches'  => $branches,
                'schedules' => $schedules,
                'devices'   => $devices,
                'positions' => $positions,
                'minimumDailyWage' => $repo->activeMinimumDailyWage(),
                'errors'    => ['email' => UserMessage::from($e)],
            ], 'Edit Employee');
            return;
        }

        ViewRenderer::flash('Employee updated successfully.');
        $this->redirect('/hr/employees/' . $id);
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    /**
     * @return array{
     *   employee_number: string,
     *   employee_type: string,
     *   first_name: string,
     *   middle_name: string,
     *   last_name: string,
     *   email: string,
     *   contact_number: string,
     *   birthdate: string,
     *   position: string,
     *   hire_date: string,
     *   effective_from: string,
     *   branch_id: string,
     *   schedule_id: string,
     *   device_id: string,
     *   enrollment_code: string,
     *   daily_rate: string,
     *   philhealth_number: string,
     *   pagibig_number: string,
     *   tin_number: string,
     *   sss_number: string,
     *   status: string,
     *   contract_review_date: string
     * }
     */
    private function extractPostFields(): array
    {
        $norm = static fn(string $v): string => (string) preg_replace('/\s+/u', ' ', trim($v));

        return [
            'employee_number'      => trim((string) ($_POST['employee_number']      ?? '')),
            'employee_type'        => trim((string) ($_POST['employee_type']        ?? 'Regular')),
            'first_name'           => $norm((string) ($_POST['first_name']          ?? '')),
            'middle_name'          => $norm((string) ($_POST['middle_name']         ?? '')),
            'last_name'            => $norm((string) ($_POST['last_name']           ?? '')),
            'email'                => trim((string) ($_POST['email']                ?? '')),
            'contact_number'       => trim((string) ($_POST['contact_number']       ?? '')),
            'birthdate'            => trim((string) ($_POST['birthdate']            ?? '')),
            'position'             => $norm((string) ($_POST['position']            ?? '')),
            'hire_date'            => trim((string) ($_POST['hire_date']            ?? '')),
            'effective_from'       => trim((string) ($_POST['effective_from']       ?? '')),
            'branch_id'            => trim((string) ($_POST['branch_id']            ?? '')),
            'schedule_id'          => trim((string) ($_POST['schedule_id']          ?? '')),
            'device_id'            => trim((string) ($_POST['device_id']            ?? '')),
            'enrollment_code'      => trim((string) ($_POST['enrollment_code']      ?? '')),
            'daily_rate'           => trim((string) ($_POST['daily_rate']           ?? '')),
            'philhealth_number'    => trim((string) ($_POST['philhealth_number']    ?? '')),
            'pagibig_number'       => trim((string) ($_POST['pagibig_number']       ?? '')),
            'tin_number'           => trim((string) ($_POST['tin_number']           ?? '')),
            'sss_number'           => trim((string) ($_POST['sss_number']           ?? '')),
            'status'               => trim((string) ($_POST['status']               ?? 'active')),
            'contract_review_date' => trim((string) ($_POST['contract_review_date'] ?? '')),
        ];
    }

    /**
     * @param  array<string, string> $data
     * @param  bool                  $isEdit  Skip immutable fields on update
     * @return array<string, string> Validation errors keyed by field name
     */
    private function validate(
        array $data,
        bool $isEdit,
        ?EmployeeRepository $repo = null,
        ?int $currentEmployeeId = null,
        string $existingHireDate = ''
    ): array
    {
        $errors = [];
        $todayManila = new \DateTimeImmutable('today', new \DateTimeZone('Asia/Manila'));
        $todayStr = $todayManila->format('Y-m-d');

        // On create the employee number is generated by store() (EMP-001,
        // EMP-002, ...), so HR never types it and there is nothing to check.
        if ($data['employee_number'] !== '' && mb_strlen($data['employee_number']) > 30) {
            $errors['employee_number'] = 'Employee number must be 30 characters or fewer.';
        }

        if ($data['email'] !== '' && $repo !== null
            && $repo->emailExists($data['email'], $currentEmployeeId)) {
            $errors['email'] = 'This email address is already assigned to another employee.';
        }

        if ($data['email'] === '') {
            $errors['email'] = 'Email address is required.';
        }

        // Biometric enrollment: required for a new employee (a profile without
        // it can never receive attendance) and validated whenever supplied.
        // [QA EMP TC3, TC4, TC11]
        $hasDevice = $data['device_id'] !== '' && (int) $data['device_id'] > 0;
        $hasCode   = $data['enrollment_code'] !== '';
        if (!$isEdit && !$hasDevice) {
            $errors['device_id'] = 'Biometric device is required.';
        }
        if (!$isEdit && !$hasCode) {
            $errors['enrollment_code'] = 'Biometric enrollment ID is required.';
        }
        if ($isEdit && $hasDevice !== $hasCode) {
            $errors[$hasDevice ? 'enrollment_code' : 'device_id'] = 'Enter both the biometric device and the enrollment ID.';
        }
        if ($hasCode && !preg_match('/^\d{1,20}$/', $data['enrollment_code'])) {
            $errors['enrollment_code'] = 'Enrollment ID must contain digits only, exactly as shown on the biometric device.';
        }
        $onDate = $isEdit
            ? $todayStr
            : ($data['effective_from'] !== '' && $this->isIsoDate($data['effective_from']) ? $data['effective_from'] : $todayStr);
        if ($hasDevice && $hasCode && !isset($errors['enrollment_code']) && $repo !== null) {
            if ($repo->enrollmentCodeTaken((int) $data['device_id'], $data['enrollment_code'], $currentEmployeeId, $onDate)) {
                $errors['enrollment_code'] = 'This biometric enrollment ID is already assigned to another active employee on the selected device.';
            }
        }
        if ($hasDevice && $repo !== null) {
            $branchIdForCheck = (int) ($data['branch_id'] ?? 0);
            if ($isEdit && $branchIdForCheck <= 0 && $currentEmployeeId !== null) {
                $existingRow = $repo->findById($currentEmployeeId);
                $branchIdForCheck = (int) ($existingRow['branch_id'] ?? 0);
            }
            if ($branchIdForCheck > 0 && !$repo->deviceCoversBranchOn((int) $data['device_id'], $branchIdForCheck, $onDate)) {
                $errors['device_id'] = 'The selected biometric device is not assigned to the employee\'s branch on the effective date.';
            }
        }

        if ($data['email'] !== '' && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Please enter a valid email address.';
        }

        if ($data['first_name'] === '') {
            $errors['first_name'] = 'First name is required.';
        } elseif (!preg_match("/^[\p{L}][\p{L} .'\-]{0,48}[\p{L}.]$|^[\p{L}.]$/u", $data['first_name'])) {
            $errors['first_name'] = 'First name may only contain letters, spaces, hyphens, apostrophes, and periods.';
        }

        if ($data['middle_name'] !== '' && !preg_match("/^[\p{L}][\p{L} .'\-]{0,48}[\p{L}.]$|^[\p{L}.]$/u", $data['middle_name'])) {
            $errors['middle_name'] = 'Middle name may only contain letters, spaces, hyphens, apostrophes, and periods.';
        }

        if ($data['last_name'] === '') {
            $errors['last_name'] = 'Last name is required.';
        } elseif (!preg_match("/^[\p{L}][\p{L} .'\-]{0,48}[\p{L}.]$|^[\p{L}.]$/u", $data['last_name'])) {
            $errors['last_name'] = 'Last name may only contain letters, spaces, hyphens, apostrophes, and periods.';
        }

        // Birthdate: required, real calendar date, not in the future, age 18..100 years.
        $parsedBirthdate = null;
        if ($data['birthdate'] === '') {
            $errors['birthdate'] = 'Birthdate is required.';
        } else {
            // Parse in Manila time like $todayManila, so the age does not depend on the PHP default timezone.
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $data['birthdate'], $todayManila->getTimezone());
            if ($parsed === false || $parsed->format('Y-m-d') !== $data['birthdate']) {
                $errors['birthdate'] = 'Birthdate must be a valid date (YYYY-MM-DD).';
            } elseif ($data['birthdate'] > $todayStr) {
                $errors['birthdate'] = 'Birthdate cannot be in the future.';
            } else {
                $parsedBirthdate = $parsed;
                $ageYears = (int) $parsed->diff($todayManila)->y;
                if ($ageYears < 18) {
                    $errors['birthdate'] = sprintf(
                        'Employee is under age (%d years old). Employees must be at least 18 years old (born on or before %s).',
                        $ageYears,
                        $todayManila->modify('-18 years')->format('m/d/y'),
                    );
                } elseif ($ageYears > 100) {
                    $errors['birthdate'] = 'Employee age cannot exceed 100 years. Please check the birthdate year.';
                }
            }
        }

        // Contact number: required; Philippine mobile 09XXXXXXXXX (11 digits).
        if ($data['contact_number'] === '') {
            $errors['contact_number'] = 'Contact number is required.';
        } elseif (!preg_match('/^09\d{9}$/', $data['contact_number'])) {
            $errors['contact_number'] = 'Contact number must be a valid Philippine mobile number starting with 09 and exactly 11 digits (e.g. 09171234567).';
        }

        // Government IDs: optional, but enforce format and length when provided.
        if ($data['sss_number'] !== '') {
            $sssClean = str_replace('-', '', $data['sss_number']);
            if (!preg_match('/^\d{10}$/', $sssClean) || mb_strlen($data['sss_number']) > 13) {
                $errors['sss_number'] = 'SSS number must be in XX-XXXXXXX-X format (10 digits).';
            }
        }
        if ($data['philhealth_number'] !== '') {
            $phClean = str_replace(['-', ' '], '', $data['philhealth_number']);
            if (!preg_match('/^\d{12}$/', $phClean) || mb_strlen($data['philhealth_number']) > 15) {
                $errors['philhealth_number'] = 'PhilHealth number must be 12 digits.';
            }
        }
        if ($data['pagibig_number'] !== '') {
            $piClean = str_replace(['-', ' '], '', $data['pagibig_number']);
            if (!preg_match('/^\d{12}$/', $piClean) || mb_strlen($data['pagibig_number']) > 15) {
                $errors['pagibig_number'] = 'Pag-IBIG number must be 12 digits.';
            }
        }
        if ($data['tin_number'] !== '') {
            $tinClean = str_replace('-', '', $data['tin_number']);
            if (!preg_match('/^\d{9}(\d{3})?$/', $tinClean) || mb_strlen($data['tin_number']) > 16) {
                $errors['tin_number'] = 'TIN must be 9 or 12 digits (e.g. 123-456-789 or 123-456-789-000).';
            }
        }

        if ($data['position'] === '') {
            $errors['position'] = 'Position is required.';
        } elseif (mb_strlen($data['position']) > 100) {
            $errors['position'] = 'Position title must be 100 characters or fewer.';
        }

        // Employee type: Regular, Probationary, or Contractual.
        if (!in_array($data['employee_type'], ['Regular', 'Probationary', 'Contractual'], true)) {
            $errors['employee_type'] = 'Employee type must be Regular, Probationary, or Contractual.';
        } elseif ($repo !== null && $data['position'] !== '') {
            $posMeta = $repo->positionMetaByTitle($data['position']);
            if ($posMeta !== null && (int) ($posMeta['requires_regular'] ?? 0) === 1 && $data['employee_type'] !== 'Regular') {
                $errors['employee_type'] = sprintf('The position "%s" requires Regular employment status.', $data['position']);
            }
        }

        // Contract review date: required for Contractual / Probationary so the
        // HR Head is reminded to regularise or renew. [EMP-024]
        $reviewError = ContractReviewPolicy::validate(
            $data['employee_type'],
            (string) ($data['contract_review_date'] ?? ''),
            $isEdit ? $existingHireDate : $data['effective_from'],
            $todayManila,
        );
        if ($reviewError !== null) {
            $errors['contract_review_date'] = $reviewError;
        }

        $minWage = $repo !== null ? $repo->activeMinimumDailyWage() : DailyRateRule::FALLBACK_MINIMUM;
        $rateError = DailyRateRule::error((string) $data['daily_rate'], $minWage);
        if ($rateError !== null) {
            $errors['daily_rate'] = $rateError;
        }

        if (!$isEdit) {
            // Effective-from must be a real calendar date on create and cannot be far in the future.
            if ($data['effective_from'] === '') {
                $errors['effective_from'] = 'Effective date is required.';
            } else {
                $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $data['effective_from']);
                $maxEffectiveDate = $todayManila->modify('+30 days')->format('Y-m-d');
                if ($parsed === false || $parsed->format('Y-m-d') !== $data['effective_from']) {
                    $errors['effective_from'] = 'Effective date must be a valid calendar date (YYYY-MM-DD).';
                } elseif ($data['effective_from'] > $maxEffectiveDate) {
                    $errors['effective_from'] = 'Effective date cannot be more than 30 days in the future.';
                } elseif ($parsedBirthdate !== null && $data['effective_from'] < $parsedBirthdate->modify('+18 years')->format('Y-m-d')) {
                    $errors['effective_from'] = 'Effective date must be on or after the employee\'s 18th birthday.';
                }
            }
            if ($data['branch_id'] === '') {
                $errors['branch_id'] = 'Branch is required.';
            }
            if ($data['schedule_id'] === '') {
                $errors['schedule_id'] = 'Work schedule is required.';
            }
        }

        return $errors;
    }

    /** @param array<string,mixed> $data @return array<string,string> */
    private function validateRehire(array $data, ?EmployeeRepository $repo = null, ?int $employeeId = null): array
    {
        $errors = [];
        $todayManila = new \DateTimeImmutable('today', new \DateTimeZone('Asia/Manila'));
        $maxRehireDate = $todayManila->modify('+30 days')->format('Y-m-d');
        $rehireDate = (string) ($data['rehire_date'] ?? '');

        if (!$this->isIsoDate($rehireDate)) {
            $errors['rehire_date'] = 'Rehire date is required.';
        } elseif ($rehireDate > $maxRehireDate) {
            $errors['rehire_date'] = 'Rehire date cannot be more than 30 days in the future.';
        }
        if ((int) $data['branch_id'] <= 0) {
            $errors['branch_id'] = 'Branch is required.';
        }
        if ((int) $data['schedule_id'] <= 0) {
            $errors['schedule_id'] = 'Work schedule is required.';
        }
        if ((int) $data['device_id'] <= 0) {
            $errors['device_id'] = 'Biometric device is required.';
        }
        $code = (string) ($data['enrollment_code'] ?? '');
        if ($code === '') {
            $errors['enrollment_code'] = 'Biometric enrollment ID is required.';
        } elseif (!preg_match('/^\d{1,20}$/', $code)) {
            $errors['enrollment_code'] = 'Enrollment ID must contain digits only, exactly as shown on the biometric device.';
        } elseif ($repo !== null && (int) $data['device_id'] > 0 && $this->isIsoDate($rehireDate)) {
            if ($repo->enrollmentCodeTaken((int) $data['device_id'], $code, $employeeId, $rehireDate)) {
                $errors['enrollment_code'] = 'This biometric enrollment ID is already assigned to another active employee on the selected device.';
            }
        }
        if ($repo !== null && (int) $data['device_id'] > 0 && (int) $data['branch_id'] > 0) {
            $checkDate = $this->isIsoDate($rehireDate) ? $rehireDate : $todayManila->format('Y-m-d');
            if (!$repo->deviceCoversBranchOn((int) $data['device_id'], (int) $data['branch_id'], $checkDate)) {
                $errors['device_id'] = 'The selected biometric device is not assigned to the selected branch on the rehire date.';
            }
        }
        $minWage = $repo !== null ? $repo->activeMinimumDailyWage() : DailyRateRule::FALLBACK_MINIMUM;
        $rateError = DailyRateRule::error((string) $data['daily_rate'], $minWage);
        if ($rateError !== null) {
            $errors['daily_rate'] = $rateError;
        }
        $position = (string) ($data['position'] ?? '');
        if ($position === '') {
            $errors['position'] = 'Position is required.';
        }
        $employeeType = (string) ($data['employee_type'] ?? '');
        if (!in_array($employeeType, ['Regular', 'Probationary', 'Contractual'], true)) {
            $errors['employee_type'] = 'Choose a valid employee type (Regular, Probationary, or Contractual).';
        } elseif ($repo !== null && $position !== '') {
            $posMeta = $repo->positionMetaByTitle($position);
            if ($posMeta !== null && (int) ($posMeta['requires_regular'] ?? 0) === 1 && $employeeType !== 'Regular') {
                $errors['employee_type'] = sprintf('The position "%s" requires Regular employment status.', $position);
            }
        }
        $reviewError = ContractReviewPolicy::validate(
            $employeeType,
            (string) ($data['contract_review_date'] ?? ''),
            $this->isIsoDate($rehireDate) ? $rehireDate : '',
            $todayManila,
        );
        if ($reviewError !== null) {
            $errors['contract_review_date'] = $reviewError;
        }
        if (mb_strlen((string) $data['notes']) > 1000) {
            $errors['notes'] = 'HR note must be 1,000 characters or fewer.';
        }
        return $errors;
    }

    private function isIsoDate(string $date): bool
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return $parsed !== false && $parsed->format('Y-m-d') === $date;
    }

    /**
     * Review date to store: the posted date for Contractual / Probationary,
     * NULL for Regular (regularising clears the reminder). [EMP-024]
     *
     * @param array<string, mixed> $data
     */
    private function contractReviewDateFor(array $data): ?string
    {
        $type = (string) ($data['employee_type'] ?? '') ?: 'Regular';
        $date = (string) ($data['contract_review_date'] ?? '');

        return ContractReviewPolicy::needsReview($type) && $date !== '' ? $date : null;
    }

    private function renderNotFound(): void
    {
        http_response_code(404);
        ViewRenderer::render('errors/404', [], 'Not found');
    }

    /**
     * Convert duplicate-key races into safe, field-level form errors.
     *
     * @return array<string, string>
     */
    private function uniqueConstraintErrors(\PDOException $exception): array
    {
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);
        if ((string) $exception->getCode() !== '23000' || $driverCode !== 1062) {
            return [];
        }

        $message = $exception->getMessage();
        if (str_contains($message, 'uq_employee_number')) {
            // Two employees saved at the same moment got the same EMP- number.
            return ['employee_number' => 'Another employee was just added with this employee number. Click Create Employee again to get the next number.'];
        }
        if (str_contains($message, 'uq_employee_email')) {
            return ['email' => 'This email address is already assigned to another employee.'];
        }
        // Constraints hit by "Create Employee" that previously surfaced as an
        // HTTP 500 page. [QA EMP TC2, TC11]
        if (str_contains($message, 'uq_users_account_email')) {
            return ['email' => 'This email address is already used by another login account, possibly an archived one. Use a different email address.'];
        }
        if (str_contains($message, 'uq_ebe_device_code_from')) {
            return ['enrollment_code' => 'This biometric enrollment ID is already registered on the selected device for that date.'];
        }
        if (str_contains($message, 'uq_users_username')) {
            return ['first_name' => 'A login username for this name was taken at the same moment. Submit the form again.'];
        }

        return [];
    }

    /**
     * Load dropdown data for branches, schedules, and devices.
     *
     * @return array{
     *   0: list<array{id: int, name: string}>,
     *   1: list<array{id: int, name: string}>,
     *   2: list<array{id: int, name: string}>,
     *   3: list<array{id: int, name: string}>
     * }
     */
    private function dropdownData(): array
    {
        $pdo = $this->makeConnection()->pdo();

        $branches = $pdo->query(
            "SELECT branch_id AS id, branch_name AS name FROM branch WHERE status = 'Active' ORDER BY branch_name"
        )->fetchAll(PDO::FETCH_ASSOC);

        $schedules = $pdo->query(
            "SELECT ws.schedule_id AS id,
                    CONCAT(ws.schedule_name, ' — ', TIME_FORMAT(ws.work_start_time, '%H:%i'), '–', TIME_FORMAT(ws.work_end_time, '%H:%i')) AS name
             FROM work_schedule ws
             WHERE ws.status = 'Active'
             ORDER BY ws.schedule_name, ws.work_start_time"
        )->fetchAll(PDO::FETCH_ASSOC);

        $devices = $pdo->query(
            "SELECT device_id AS id, COALESCE(device_name, device_code) AS name
             FROM biometric_device WHERE status = 'Active' ORDER BY device_name"
        )->fetchAll(PDO::FETCH_ASSOC);

        $positions = $pdo->query(
            "SELECT position_id AS id, position_title AS name, department, default_daily_rate, requires_regular
               FROM job_position
              WHERE status = 'Active'
              ORDER BY sort_order ASC, position_title ASC"
        )->fetchAll(PDO::FETCH_ASSOC);

        return [$branches, $schedules, $devices, $positions];
    }

    private function makeRepo(): EmployeeRepository
    {
        return new EmployeeRepository($this->makeConnection());
    }

    private function makeConnection(): Connection
    {
        return new Connection(require APP_ROOT . '/config/database.php');
    }

    private function redirect(string $path): void
    {
        $base = rtrim((string) ($_ENV['APP_BASE_URL'] ?? ''), '/');
        // Flush the session before redirecting so flash messages written to
        // $_SESSION are committed to the database handler before exit kills
        // the process. Without this, the custom DatabaseSessionHandler's
        // write() may not be called in time and the toast never appears.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        header('Location: ' . $base . $path, true, 302);
        exit;
    }

    // -----------------------------------------------------------------------
    // Bank account management
    // -----------------------------------------------------------------------

    /**
     * GET /hr/employees/{id}/bank
     * Show the bank account form for an employee.
     *
     * @param array<string, string> $params
     */
    public function bankForm(array $params = []): void
    {
        $id       = (int) ($params['id'] ?? 0);
        $repo     = $this->makeRepo();
        $employee = $repo->findById($id);

        if ($employee === null) {
            $this->renderNotFound();
            return;
        }

        $bank = $repo->findBankDetails($id);

        ViewRenderer::render('hr/employees/bank', [
            'employee' => $employee,
            'bank'     => $bank,
            'errors'   => [],
            'activePage' => 'employees',
        ], 'Bank Account – ' . $employee['last_name'] . ', ' . $employee['first_name']);
    }

    /**
     * POST /hr/employees/{id}/bank
     * Save (upsert) the bank account for an employee.
     *
     * @param array<string, string> $params
     */
    public function saveBank(array $params = []): void
    {
        $id       = (int) ($params['id'] ?? 0);
        $repo     = $this->makeRepo();
        $employee = $repo->findById($id);

        if ($employee === null) {
            $this->renderNotFound();
            return;
        }

        $data = [
            'bank_name'      => trim((string) ($_POST['bank_name']      ?? \Wbpms\Domain\Validation\FieldRules::PAYROLL_BANK)),
            'account_name'   => trim((string) ($_POST['account_name']   ?? '')),
            'account_number' => trim((string) ($_POST['account_number'] ?? '')),
            'account_type'   => trim((string) ($_POST['account_type']   ?? '')),
            'effective_from' => trim((string) ($_POST['effective_from'] ?? '')),
        ];

        $errors = [];

        // BDO only: the payroll bank slip lists BDO accounts only.
        if (($bankError = \Wbpms\Domain\Validation\FieldRules::payrollBank($data['bank_name'])) !== null) {
            $errors['bank_name'] = $bankError;
        }
        if ($data['account_name'] === '') {
            $errors['account_name'] = 'Account name is required.';
        } elseif (($tooLong = \Wbpms\Domain\Validation\FieldRules::maxLength($data['account_name'], 150, 'Account name')) !== null) {
            $errors['account_name'] = $tooLong;
        }
        // Digits/hyphens/spaces and 10–16 digits (REQ014).
        if (($accountError = \Wbpms\Domain\Validation\FieldRules::bankAccountNumber($data['account_number'])) !== null) {
            $errors['account_number'] = $accountError;
        }
        if ($data['account_type'] !== ''
            && ($typeError = \Wbpms\Domain\Validation\FieldRules::oneOf($data['account_type'], ['Savings', 'Checking', 'Payroll'], 'Account type')) !== null) {
            $errors['account_type'] = $typeError;
        }
        if ($data['effective_from'] === '' || !$this->isIsoDate($data['effective_from'])) {
            $errors['effective_from'] = 'Effective date is required (YYYY-MM-DD).';
        }

        if ($errors !== []) {
            $bank = $repo->findBankDetails($id);
            ViewRenderer::render('hr/employees/bank', [
                'employee' => $employee,
                'bank'     => $bank,
                'errors'   => $errors,
                'activePage' => 'employees',
            ], 'Bank Account – ' . $employee['last_name'] . ', ' . $employee['first_name']);
            return;
        }

        try {
            $repo->saveBankDetails($id, $data);
        } catch (BankAccountDateRejected | \PDOException $e) {
            // Effective date before the current account's (EMP-026), duplicate
            // key, or another DB failure: show a form error, not a 500 page.
            $errorField = 'bank_name';
            if ($e instanceof BankAccountDateRejected) {
                $errorField   = 'effective_from';
                $errorMessage = $e->getMessage();
            } elseif ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                $errorMessage = 'This bank account number is already on record with the same effective date. '
                    . 'Choose a different effective date or update the existing record.';
            } else {
                ErrorLog::exception($e, 'employee bank details');
                $errorMessage = 'Could not save bank details because of a database error (reference '
                    . ErrorLog::reference() . '). Please try again or report the reference.';
            }
            $bank = $repo->findBankDetails($id);
            ViewRenderer::render('hr/employees/bank', [
                'employee'   => $employee,
                'bank'       => $bank,
                'errors'     => [$errorField => $errorMessage],
                'activePage' => 'employees',
            ], 'Bank Account – ' . $employee['last_name'] . ', ' . $employee['first_name']);
            return;
        }

        ViewRenderer::flash('Bank account details saved successfully.');
        $this->redirect('/hr/employees/' . $id);
    }

    // -----------------------------------------------------------------------
    // Document management  (Task 4.6.5 / Requirement 14 AC4)
    // -----------------------------------------------------------------------

    /**
     * GET /hr/employees/{id}/documents
     * List all documents for an employee and show the upload form.
     *
     * @param array<string, string> $params
     */
    public function documents(array $params = []): void
    {
        $id       = (int) ($params['id'] ?? 0);
        $employee = $this->makeRepo()->findById($id);

        if ($employee === null) {
            $this->renderNotFound();
            return;
        }

        $service   = new EmployeeDocumentService($this->makeConnection());
        $documents = $service->listForEmployee($id);

        ViewRenderer::render('hr/employees/documents', [
            'employee'      => $employee,
            'documents'     => $documents,
            'documentTypes' => EmployeeDocumentService::DOCUMENT_TYPES,
            'errors'        => [],
            'activePage'    => 'employees',
        ], 'Employee Documents');
    }

    /**
     * POST /hr/employees/{id}/documents
     * Handle a new document upload.
     *
     * @param array<string, string> $params
     */
    public function uploadDocument(array $params = []): void
    {
        $id       = (int) ($params['id'] ?? 0);
        $employee = $this->makeRepo()->findById($id);

        if ($employee === null) {
            $this->renderNotFound();
            return;
        }

        $actorId  = (int) (AuthMiddleware::identity()['user_id'] ?? 0);
        $meta     = [
            'document_type'  => trim((string) ($_POST['document_type']  ?? '')),
            'document_label' => trim((string) ($_POST['document_label'] ?? '')),
            'notes'          => trim((string) ($_POST['notes']          ?? '')),
        ];

        try {
            (new EmployeeDocumentService($this->makeConnection()))
                ->upload($id, $_FILES['document'] ?? [], $meta, $actorId);

            ViewRenderer::flash('Document uploaded successfully.');
            $this->redirect('/hr/employees/' . $id . '/documents');
        } catch (\RuntimeException $e) {
            $service   = new EmployeeDocumentService($this->makeConnection());
            $documents = $service->listForEmployee($id);

            ViewRenderer::render('hr/employees/documents', [
                'employee'      => $employee,
                'documents'     => $documents,
                'documentTypes' => EmployeeDocumentService::DOCUMENT_TYPES,
                'errors'        => ['upload' => UserMessage::from($e)],
                'activePage'    => 'employees',
            ], 'Employee Documents');
        }
    }

    /**
     * GET /hr/employees/{id}/documents/{docId}
     * View / download a single document.
     * Serves the file directly with the correct Content-Type.
     *
     * @param array<string, string> $params
     */
    public function viewDocument(array $params = []): void
    {
        $id    = (int) ($params['id']    ?? 0);
        $docId = (int) ($params['docId'] ?? 0);

        $employee = $this->makeRepo()->findById($id);
        if ($employee === null) {
            $this->renderNotFound();
            return;
        }

        try {
            $doc     = (new EmployeeDocumentService($this->makeConnection()))->getDocument($docId, $id);
            $absPath = APP_ROOT . '/' . ltrim((string) $doc['stored_path'], '/');

            if (!is_file($absPath)) {
                http_response_code(404);
                ViewRenderer::render('errors/404', [], 'Not found');
                return;
            }

            // Serve the file inline (PDF opens in browser; images display inline)
            $mime     = (string) $doc['mime_type'];
            $filename = (string) $doc['original_filename'];

            // Safe ASCII-only filename for Content-Disposition
            $safeFilename = preg_replace('/[^\w\-.]/', '_', $filename) ?: 'document';

            header('Content-Type: ' . $mime);
            header('Content-Length: ' . filesize($absPath));
            header('Content-Disposition: inline; filename="' . $safeFilename . '"');
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: private, no-store');
            readfile($absPath);
            exit;
        } catch (\RuntimeException) {
            $this->renderNotFound();
        }
    }

    /**
     * POST /hr/employees/{id}/documents/{docId}/replace
     * Replace an existing document with a new upload.
     *
     * @param array<string, string> $params
     */
    public function replaceDocument(array $params = []): void
    {
        $id    = (int) ($params['id']    ?? 0);
        $docId = (int) ($params['docId'] ?? 0);

        $employee = $this->makeRepo()->findById($id);
        if ($employee === null) {
            $this->renderNotFound();
            return;
        }

        $actorId = (int) (AuthMiddleware::identity()['user_id'] ?? 0);
        $meta    = [
            'document_type'  => trim((string) ($_POST['document_type']  ?? '')),
            'document_label' => trim((string) ($_POST['document_label'] ?? '')),
            'notes'          => trim((string) ($_POST['notes']          ?? '')),
        ];

        try {
            (new EmployeeDocumentService($this->makeConnection()))
                ->replace($docId, $id, $_FILES['document'] ?? [], $meta, $actorId);

            ViewRenderer::flash('Document replaced successfully. The previous version has been retained.');
            $this->redirect('/hr/employees/' . $id . '/documents');
        } catch (\RuntimeException $e) {
            ViewRenderer::flashError(UserMessage::from($e));
            $this->redirect('/hr/employees/' . $id . '/documents');
        }
    }

    /**
     * POST /hr/employees/{id}/documents/{docId}/verify
     * Mark a document as HR-verified.
     *
     * @param array<string, string> $params
     */
    public function verifyDocument(array $params = []): void
    {
        $id      = (int) ($params['id']    ?? 0);
        $docId   = (int) ($params['docId'] ?? 0);
        $actorId = (int) (AuthMiddleware::identity()['user_id'] ?? 0);

        try {
            (new EmployeeDocumentService($this->makeConnection()))->verify($docId, $id, $actorId);
            ViewRenderer::flash('Document marked as verified.');
        } catch (\RuntimeException $e) {
            ViewRenderer::flashError(UserMessage::from($e));
        }

        $this->redirect('/hr/employees/' . $id . '/documents');
    }

    /**
     * POST /hr/employees/{id}/documents/{docId}/archive
     * Soft-archive a document (retains file and metadata).
     *
     * @param array<string, string> $params
     */
    public function archiveDocument(array $params = []): void
    {
        $id      = (int) ($params['id']    ?? 0);
        $docId   = (int) ($params['docId'] ?? 0);
        $actorId = (int) (AuthMiddleware::identity()['user_id'] ?? 0);

        try {
            (new EmployeeDocumentService($this->makeConnection()))->archive($docId, $id, $actorId);
            ViewRenderer::flash('Document archived.');
        } catch (\RuntimeException $e) {
            ViewRenderer::flashError(UserMessage::from($e));
        }

        $this->redirect('/hr/employees/' . $id . '/documents');
    }
}

<?php

declare(strict_types=1);

namespace Wbpms\Tests\Integration;

use RuntimeException;
use Wbpms\Application\UserService;
use Wbpms\Infrastructure\Database\Connection;

/**
 * UserLifecycleTest
 *
 * Integration test for the User Management module (REQ004–REQ008).
 *
 * Lifecycle proven:
 *   1. create — new Active user is persisted (REQ005)
 *   2. list   — user appears in the listing (REQ004)
 *   3. update — username, email, and role change is persisted (REQ006)
 *   4. deactivate — toggleStatus Active → Inactive (REQ007)
 *   5. reactivate — toggleStatus Inactive → Active (REQ007)
 *   6. archive — status becomes Archived; cannot be toggled again (REQ008)
 *
 * Guard tests:
 *   - Duplicate username rejected
 *   - Duplicate email rejected
 *   - Archived user toggle rejected
 *   - Archive already-archived user rejected
 *
 * Each test is wrapped in a transaction rolled back by IntegrationTestCase,
 * so the live wbpms_test database is not permanently modified.
 *
 * Requirement traceability: REQ004, REQ005, REQ006, REQ007, REQ008
 */
final class UserLifecycleTest extends IntegrationTestCase
{
    private UserService $service;

    /** Actor for all audit log inserts — a seeded HR Head user. */
    private int $actorId;

    // -----------------------------------------------------------------------
    // Per-test setup
    // -----------------------------------------------------------------------

    protected function setUp(): void
    {
        parent::setUp(); // begins the rollback transaction

        $conn          = new Connection($this->testDbConfig());
        $conn->pdo(); // forces the connection to use the shared PDO handle
        $this->service = $this->buildService();
        $this->actorId = $this->insertUser($this->insertRole('HRHead'));
    }

    // -----------------------------------------------------------------------
    // REQ004 — List
    // -----------------------------------------------------------------------

    /**
     * REQ004: list() returns rows and a newly created user appears in it.
     */
    public function testListReturnsUsers(): void
    {
        $roleId  = $this->insertRole('BusinessOwner');
        $uid     = uniqid('listuser_');
        $service = $this->buildService();

        $service->create([
            'username'      => $uid,
            'account_email' => $uid . '@example.com',
            'password'      => 'Secret123!',
            'role_id'       => $roleId,
        ], $this->actorId);

        $list = $service->list();
        $usernames = array_column($list, 'username');

        $this->assertContains($uid, $usernames, 'Newly created user should appear in list()');
    }

    // -----------------------------------------------------------------------
    // REQ005 — Create
    // -----------------------------------------------------------------------

    /**
     * REQ005: create() inserts a row with status=Active and returns a positive ID.
     */
    public function testCreateInsertsActiveUser(): void
    {
        $roleId  = $this->insertRole('Employee');
        $uid     = uniqid('newuser_');
        $service = $this->buildService();

        $userId = $service->create([
            'username'      => $uid,
            'account_email' => $uid . '@example.com',
            'password'      => 'Password1!',
            'role_id'       => $roleId,
        ], $this->actorId);

        $this->assertGreaterThan(0, $userId);

        $user = $service->findOrFail($userId);
        $this->assertSame('Active', $user['status']);
        $this->assertSame($uid, $user['username']);
    }

    /**
     * REQ005: create() with a linked employee_id persists the link.
     */
    public function testCreateWithEmployeeLink(): void
    {
        $roleId     = $this->insertRole('Employee');
        $employeeId = $this->insertEmployee();
        $uid        = uniqid('empuser_');
        $service    = $this->buildService();

        $userId = $service->create([
            'username'      => $uid,
            'account_email' => $uid . '@example.com',
            'password'      => 'Password1!',
            'role_id'       => $roleId,
            'employee_id'   => $employeeId,
        ], $this->actorId);

        $user = $service->findOrFail($userId);
        $this->assertSame($employeeId, (int) $user['employee_id']);
    }

    /**
     * REQ005 guard: duplicate username is rejected.
     */
    public function testCreateDuplicateUsernameThrows(): void
    {
        $roleId  = $this->insertRole('Employee');
        $uid     = uniqid('dupuser_');
        $service = $this->buildService();

        $service->create([
            'username'      => $uid,
            'account_email' => $uid . '_1@example.com',
            'password'      => 'Password1!',
            'role_id'       => $roleId,
        ], $this->actorId);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/already taken/i');

        $service->create([
            'username'      => $uid,           // same username
            'account_email' => $uid . '_2@example.com',
            'password'      => 'Password1!',
            'role_id'       => $roleId,
        ], $this->actorId);
    }

    /**
     * REQ005 guard: duplicate email is rejected.
     */
    public function testCreateDuplicateEmailThrows(): void
    {
        $roleId  = $this->insertRole('Employee');
        $email   = uniqid('dupemail_') . '@example.com';
        $service = $this->buildService();

        $service->create([
            'username'      => uniqid('u1_'),
            'account_email' => $email,
            'password'      => 'Password1!',
            'role_id'       => $roleId,
        ], $this->actorId);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/already registered/i');

        $service->create([
            'username'      => uniqid('u2_'),
            'account_email' => $email,         // same email
            'password'      => 'Password1!',
            'role_id'       => $roleId,
        ], $this->actorId);
    }

    /**
     * REQ005 guard: short password is rejected.
     */
    public function testCreateShortPasswordThrows(): void
    {
        $roleId  = $this->insertRole('Employee');
        $service = $this->buildService();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/8 characters/i');

        $service->create([
            'username'      => uniqid('shortpwd_'),
            'account_email' => uniqid('shortpwd_') . '@example.com',
            'password'      => 'abc',           // too short
            'role_id'       => $roleId,
        ], $this->actorId);
    }

    // -----------------------------------------------------------------------
    // REQ006 — Update
    // -----------------------------------------------------------------------

    /**
     * REQ006: update() persists new username, email, and role.
     */
    public function testUpdateChangesUsernameEmailRole(): void
    {
        $roleA   = $this->insertRole('Employee');
        $roleB   = $this->insertRole('HRHead');
        $uid     = uniqid('upd_');
        $service = $this->buildService();

        $userId = $service->create([
            'username'      => $uid,
            'account_email' => $uid . '@example.com',
            'password'      => 'Password1!',
            'role_id'       => $roleA,
        ], $this->actorId);

        $newUid   = uniqid('updated_');
        $newEmail = $newUid . '@example.com';

        $service->update($userId, [
            'username'      => $newUid,
            'account_email' => $newEmail,
            'role_id'       => $roleB,
        ], $this->actorId);

        $user = $service->findOrFail($userId);
        $this->assertSame($newUid,   $user['username']);
        $this->assertSame($newEmail, $user['account_email']);
        $this->assertSame($roleB,    (int) $user['role_id']);
    }

    // -----------------------------------------------------------------------
    // REQ007 — Activate / Deactivate
    // -----------------------------------------------------------------------

    /**
     * REQ007: toggleStatus Active → Inactive.
     */
    public function testToggleActiveToInactive(): void
    {
        $userId  = $this->createActiveUser();
        $service = $this->buildService();

        $newStatus = $service->toggleStatus($userId, $this->actorId);

        $this->assertSame('Inactive', $newStatus);
        $this->assertSame('Inactive', $service->findOrFail($userId)['status']);
    }

    /**
     * REQ007: toggleStatus Inactive → Active.
     */
    public function testToggleInactiveToActive(): void
    {
        $userId  = $this->createActiveUser();
        $service = $this->buildService();

        $service->toggleStatus($userId, $this->actorId); // → Inactive
        $newStatus = $service->toggleStatus($userId, $this->actorId); // → Active

        $this->assertSame('Active', $newStatus);
        $this->assertSame('Active', $service->findOrFail($userId)['status']);
    }

    /**
     * REQ007 guard: cannot toggle an Archived user.
     */
    public function testToggleArchivedUserThrows(): void
    {
        $userId  = $this->createActiveUser();
        $service = $this->buildService();

        $service->archive($userId, $this->actorId);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/archived/i');

        $service->toggleStatus($userId, $this->actorId);
    }

    // -----------------------------------------------------------------------
    // REQ008 — Archive
    // -----------------------------------------------------------------------

    /**
     * REQ008: archive() sets status to Archived.
     */
    public function testArchiveSetsStatusArchived(): void
    {
        $userId  = $this->createActiveUser();
        $service = $this->buildService();

        $service->archive($userId, $this->actorId);

        $this->assertSame('Archived', $service->findOrFail($userId)['status']);
    }

    /** Administrative accounts must not be able to remove their own access. */
    public function testArchiveOwnAccountThrows(): void
    {
        $service = $this->buildService();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('You cannot archive your own account.');

        $service->archive($this->actorId, $this->actorId);
    }

    /**
     * REQ008 guard: archiving an already-archived user throws.
     */
    public function testArchiveAlreadyArchivedThrows(): void
    {
        $userId  = $this->createActiveUser();
        $service = $this->buildService();

        $service->archive($userId, $this->actorId);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/already archived/i');

        $service->archive($userId, $this->actorId);
    }

    /**
     * REQ008: archived user does NOT appear in list() (default, no archived).
     */
    public function testArchivedUserExcludedFromDefaultList(): void
    {
        $userId  = $this->createActiveUser();
        $service = $this->buildService();

        // Get the username before archiving
        $user = $service->findOrFail($userId);
        $service->archive($userId, $this->actorId);

        $list      = $service->list(includeArchived: false);
        $usernames = array_column($list, 'username');

        $this->assertNotContains($user['username'], $usernames,
            'Archived user must not appear in the default list');
    }

    /**
     * REQ008: archived user DOES appear in list(includeArchived: true).
     */
    public function testArchivedUserIncludedInFullList(): void
    {
        $userId  = $this->createActiveUser();
        $service = $this->buildService();

        $user = $service->findOrFail($userId);
        $service->archive($userId, $this->actorId);

        $list      = $service->list(includeArchived: true);
        $usernames = array_column($list, 'username');

        $this->assertContains($user['username'], $usernames,
            'Archived user must appear in includeArchived=true list');
    }

    // -----------------------------------------------------------------------
    // Full lifecycle: create → update role → deactivate → archive
    // -----------------------------------------------------------------------

    /**
     * REQ004–REQ008: full lifecycle in one sequential test.
     */
    public function testFullUserLifecycle(): void
    {
        $roleA   = $this->insertRole('Employee');
        $roleB   = $this->insertRole('HRHead');
        $uid     = uniqid('lifecycle_');
        $service = $this->buildService();

        // 1. Create
        $userId = $service->create([
            'username'      => $uid,
            'account_email' => $uid . '@example.com',
            'password'      => 'Lifecycle1!',
            'role_id'       => $roleA,
        ], $this->actorId);

        $this->assertSame('Active', $service->findOrFail($userId)['status'], 'step 1: Active after create');

        // 2. Update role
        $service->update($userId, ['role_id' => $roleB], $this->actorId);
        $this->assertSame($roleB, (int) $service->findOrFail($userId)['role_id'], 'step 2: role updated');

        // 3. Deactivate
        $service->toggleStatus($userId, $this->actorId);
        $this->assertSame('Inactive', $service->findOrFail($userId)['status'], 'step 3: Inactive after toggle');

        // 4. Archive
        $service->archive($userId, $this->actorId);
        $this->assertSame('Archived', $service->findOrFail($userId)['status'], 'step 4: Archived');

        // 5. Toggle after archive must throw
        try {
            $service->toggleStatus($userId, $this->actorId);
            $this->fail('Expected RuntimeException when toggling archived user');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1); // confirmed exception
        }
    }

    // -----------------------------------------------------------------------
    // USER-016 — archive with scheduled (not yet started) transfers
    // -----------------------------------------------------------------------

    /**
     * USER-016 (prod ref 046354144d63): an employee hired tomorrow with two
     * scheduled branch transfers. Archiving the linked login used to close the
     * open 3rd branch row before it started and hit chk_eba_period. Now the
     * unstarted rows are dropped and the first row is cut back to the archive
     * date. A voided (Archived) future salary row does not block it.
     */
    public function testArchiveLinkedUserDropsUnstartedBranchTransfers(): void
    {
        $hire    = (new \DateTimeImmutable('tomorrow', new \DateTimeZone('Asia/Manila')))->format('Y-m-d');
        $plus    = static fn (int $d): string => (new \DateTimeImmutable($hire))->modify("+{$d} day")->format('Y-m-d');
        $empId   = $this->insertLinkedEmployee($hire);
        $userId  = $this->insertUser($this->insertRole('Employee'), $empId);

        $first  = $this->insertBranchAssignment($empId, $this->insertBranch(), $hire, $plus(7));
        $second = $this->insertBranchAssignment($empId, $this->insertBranch(), $plus(7), $plus(9));
        $third  = $this->insertBranchAssignment($empId, $this->insertBranch(), $plus(9));
        $this->insertEnrollment($empId, $hire);
        $this->insertWorkSchedule($empId, $hire);
        $this->pdo->prepare("INSERT INTO salary (employee_id, daily_rate, effective_from, status) VALUES (:e, 800, :f, 'Archived')")
            ->execute([':e' => $empId, ':f' => $plus(3)]);

        $this->buildService()->archive($userId, $this->actorId);

        $archiveDate = $plus(1);
        $rows = $this->pdo->prepare('SELECT branch_assignment_id, effective_to FROM employee_branch_assignment WHERE employee_id = :e');
        $rows->execute([':e' => $empId]);
        $branches = array_column($rows->fetchAll(\PDO::FETCH_ASSOC), 'effective_to', 'branch_assignment_id');

        self::assertSame([$first => $archiveDate], $branches, 'only the started row is kept, ending on the archive date');
        self::assertArrayNotHasKey($second, $branches);
        self::assertArrayNotHasKey($third, $branches);

        $enrollment = $this->pdo->prepare('SELECT effective_to, status FROM employee_biometric_enrollment WHERE employee_id = :e');
        $enrollment->execute([':e' => $empId]);
        self::assertSame([['effective_to' => $archiveDate, 'status' => 'Inactive']], $enrollment->fetchAll(\PDO::FETCH_ASSOC));

        $status = $this->pdo->prepare('SELECT status FROM employee WHERE employee_id = :e');
        $status->execute([':e' => $empId]);
        self::assertSame('Archived', $status->fetchColumn());
        self::assertSame('Archived', $this->buildService()->findOrFail($userId)['status']);
    }

    /**
     * USER-016: an Active salary change that starts after the last working day
     * still blocks the archive, with words instead of a database error.
     */
    public function testArchiveLinkedUserRefusesFutureActiveSalaryInWords(): void
    {
        $today  = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Manila')))->format('Y-m-d');
        $empId  = $this->insertLinkedEmployee($today);
        $userId = $this->insertUser($this->insertRole('Employee'), $empId);
        $this->insertBranchAssignment($empId, $this->insertBranch(), $today);
        $this->insertSalary($empId, (new \DateTimeImmutable($today))->modify('+5 day')->format('Y-m-d'));

        try {
            $this->buildService()->archive($userId, $this->actorId);
            self::fail('Expected RuntimeException for a future Active salary rate');
        } catch (RuntimeException $e) {
            self::assertNotInstanceOf(\PDOException::class, $e);
            self::assertStringContainsString('future salary rate', $e->getMessage());
        }
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    private function insertLinkedEmployee(string $hireDate): int
    {
        $empId = $this->insertEmployee();
        $this->pdo->prepare('UPDATE employee SET hire_date = :d WHERE employee_id = :e')
            ->execute([':d' => $hireDate, ':e' => $empId]);

        return $empId;
    }

    private function insertEnrollment(int $employeeId, string $from): void
    {
        $u = uniqid();
        $this->pdo->exec("INSERT INTO attendance_site (site_code, site_name, status) VALUES ('S{$u}', 'Site {$u}', 'Active')");
        $siteId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO biometric_device (site_id, device_code, status) VALUES ({$siteId}, 'D{$u}', 'Active')");
        $deviceId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare(
            "INSERT INTO employee_biometric_enrollment (employee_id, device_id, device_employee_code, effective_from, status)
             VALUES (:e, :d, :c, :f, 'Active')"
        )->execute([':e' => $employeeId, ':d' => $deviceId, ':c' => substr($u, -8), ':f' => $from]);
    }

    private function buildService(): UserService
    {
        // Reuse the shared PDO from IntegrationTestCase (same transaction).
        $config = $this->testDbConfig();
        $conn   = new class($config, $this->pdo) extends Connection {
            private \PDO $sharedPdo;
            public function __construct(array $cfg, \PDO $pdo) {
                parent::__construct($cfg);
                $this->sharedPdo = $pdo;
            }
            public function pdo(): \PDO { return $this->sharedPdo; }
        };

        return new UserService($conn);
    }

    /**
     * Build a minimal db config array matching IntegrationTestCase.
     * @return array<string,mixed>
     */
    private function testDbConfig(): array
    {
        return [
            'driver'   => 'mysql',
            'host'     => $_ENV['TEST_DB_HOST']     ?? getenv('TEST_DB_HOST')     ?: '127.0.0.1',
            'port'     => (int) ($_ENV['TEST_DB_PORT'] ?? getenv('TEST_DB_PORT')  ?: 3306),
            'database' => $_ENV['TEST_DB_NAME']     ?? getenv('TEST_DB_NAME')     ?: 'wbpms_test',
            'username' => $_ENV['TEST_DB_USER']     ?? getenv('TEST_DB_USER')     ?: 'root',
            'password' => $_ENV['TEST_DB_PASSWORD'] ?? getenv('TEST_DB_PASSWORD') ?: '',
            'charset'  => 'utf8mb4',
            'options'  => [
                \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES   => false,
            ],
        ];
    }

    /** Create a fresh active user and return its user_id. */
    private function createActiveUser(): int
    {
        $roleId  = $this->insertRole('Employee');
        $uid     = uniqid('activeuser_');
        $service = $this->buildService();

        return $service->create([
            'username'      => $uid,
            'account_email' => $uid . '@example.com',
            'password'      => 'Password1!',
            'role_id'       => $roleId,
        ], $this->actorId);
    }
}

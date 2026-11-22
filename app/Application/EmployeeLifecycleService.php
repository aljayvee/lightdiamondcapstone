<?php

declare(strict_types=1);

namespace Wbpms\Application;

use Wbpms\Infrastructure\Database\Connection;
use Wbpms\Infrastructure\Persistence\EmployeeLifecycleRepository;
use Wbpms\Infrastructure\Persistence\PdoAttendanceImportGateway;

/** Coordinates the all-or-nothing archive and rehire lifecycle workflows. */
final class EmployeeLifecycleService
{
    private EmployeeLifecycleRepository $repository;

    public function __construct(private Connection $connection)
    {
        $this->repository = new EmployeeLifecycleRepository($connection);
    }

    public function archive(int $employeeId, string $lastWorkingDate, string $reason, ?string $notes, int $actorId, string $actorRole = ''): void
    {
        $archiveDate = (new \DateTimeImmutable($lastWorkingDate))->modify('+1 day')->format('Y-m-d');
        $this->connection->transaction(function () use ($employeeId, $lastWorkingDate, $archiveDate, $reason, $notes, $actorId, $actorRole): void {
            $employee = $this->repository->employeeForUpdate($employeeId);
            if ($employee === null) {
                throw new \RuntimeException('Employee was not found.');
            }
            if ($employee['status'] === 'Archived') {
                throw new \RuntimeException('This employee is already archived.');
            }
            $linkedUser = $this->repository->linkedUserAccount($employeeId);
            if ($linkedUser !== null && $actorId > 0 && (int) $linkedUser['user_id'] === $actorId) {
                throw new \RuntimeException('You cannot archive your own employee record.');
            }
            if ($linkedUser !== null
                && $actorRole === 'HRHead'
                && in_array((string) ($linkedUser['role_name'] ?? ''), ['HRHead', 'BusinessOwner'], true)) {
                throw new \RuntimeException('Only the Business Owner can archive an HR Head or Business Owner employee record.');
            }
            if ($lastWorkingDate < (string) $employee['hire_date']) {
                throw new \RuntimeException('Last working date cannot be before the employee hire date.');
            }
            $blockers = $this->repository->archiveBlockers($employeeId);
            if ($blockers !== []) {
                throw new \RuntimeException('Archive is blocked by: ' . implode(', ', $blockers) . '. Resolve these items first.');
            }
            // Scheduled transfers that would start after the employee leaves
            // never take effect; drop them instead of refusing. [USER-016]
            $this->repository->dropUnstartedAssignments($employeeId, $archiveDate);
            $conflicts = $this->repository->archiveStartDateConflicts($employeeId, $archiveDate, $lastWorkingDate);
            if ($conflicts !== []) {
                throw new \RuntimeException('Archive date conflicts with a future ' . implode(', ', $conflicts) . '. Adjust the effective dates first.');
            }

            $this->repository->closeActiveRelationships($employeeId, $archiveDate, $lastWorkingDate);
            $this->repository->setEmployeeStatus($employeeId, 'Archived');
            $this->repository->deactivateEmployeeAccounts($employeeId);
            $this->repository->closeOrCreateEpisode($employeeId, (string) $employee['hire_date'], $lastWorkingDate, $reason, $notes, $actorId);
            $this->repository->addEvent($employeeId, 'Archive', (string) $employee['status'], 'Archived', $archiveDate, $reason, $notes, $actorId);
        });
    }

    /** @param array{rehire_date:string,branch_id:int,schedule_id:int,device_id:int,enrollment_code:string,daily_rate:float,position:string,employee_type:string,contract_review_date?:string,notes:?string,reactivate_account:bool} $data */
    public function rehire(int $employeeId, array $data, int $actorId): ?string
    {
        return $this->connection->transaction(function () use ($employeeId, $data, $actorId): ?string {
            $employee = $this->repository->employeeForUpdate($employeeId);
            if ($employee === null) {
                throw new \RuntimeException('Employee was not found.');
            }
            if ($employee['status'] !== 'Archived') {
                throw new \RuntimeException('Only an archived employee can be rehired through this workflow.');
            }
            if ($data['rehire_date'] < (string) $employee['hire_date']) {
                throw new \RuntimeException('Rehire date cannot be before the original hire date.');
            }
            if ($this->repository->hasApprovedPayrollOnDate($employeeId, $data['rehire_date'])) {
                throw new \RuntimeException('Rehire date falls within an approved payroll period for this employee. Choose a date outside that period.');
            }
            $this->repository->assertNoOpenRelationships($employeeId);
            $this->repository->assertRehireAssignmentsAvailable($employeeId, $data['branch_id'], $data['schedule_id'], $data['device_id'], $data['enrollment_code'], $data['rehire_date']);
            $this->repository->updateEmploymentDetails(
                $employeeId,
                $data['position'],
                $data['employee_type'],
                $data['rehire_date'],
                (string) ($data['contract_review_date'] ?? ''),
            );
            $this->repository->addRehireRelationships($employeeId, $data['branch_id'], $data['schedule_id'], $data['device_id'], $data['enrollment_code'], $data['daily_rate'], $data['rehire_date']);
            $this->repository->addRehireEpisode($employeeId, $data['rehire_date'], $data['notes'], $actorId);
            try {
                (new PdoAttendanceImportGateway($this->connection->pdo()))->rematchUnmatchedPunches(
                    $data['device_id'],
                    $data['enrollment_code'],
                    $actorId,
                );
            } catch (\Throwable) {
                // Best-effort punch reconciliation on rehire
            }
            $temporaryPassword = null;
            if ($data['reactivate_account']) {
                $candidate = (new UserService($this->connection))->generateTemporaryPassword();
                if ($this->repository->reactivateEmployeeAccounts($employeeId, $candidate)) {
                    $temporaryPassword = $candidate;
                }
            }
            $this->repository->addEvent($employeeId, 'Rehire', 'Archived', 'Active', $data['rehire_date'], 'Rehire', $data['notes'], $actorId);
            return $temporaryPassword;
        });
    }
}

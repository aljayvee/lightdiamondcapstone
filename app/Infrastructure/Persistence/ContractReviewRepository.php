<?php

declare(strict_types=1);

namespace Wbpms\Infrastructure\Persistence;

use PDO;

/**
 * Contract reviews the HR Head must act on (bell notification). [EMP-024]
 *
 * Lists active Contractual / Probationary employees whose review date is on or
 * before the cut-off (today + ContractReviewPolicy::DUE_SOON_DAYS), plus those
 * with no review date at all. An employee drops off the list once HR changes
 * the type to Regular (the date is cleared) or sets a later review date.
 */
final class ContractReviewRepository extends AbstractRepository
{
    /**
     * @return list<array{employee_id:int, name:string, employee_type:string, contract_review_date:string|null}>
     */
    public function dueOnOrBefore(string $cutoffDate, int $limit = 50): array
    {
        $limit = max(1, min($limit, 200));
        $stmt = $this->pdo()->prepare(
            "SELECT employee_id,
                    CONCAT(last_name, ', ', first_name) AS name,
                    employee_type,
                    contract_review_date
               FROM employee
              WHERE status = 'Active'
                AND employee_type IN ('Contractual', 'Probationary')
                AND (contract_review_date IS NULL OR contract_review_date <= :cutoff)
              ORDER BY contract_review_date IS NULL, contract_review_date, last_name, first_name
              LIMIT {$limit}"
        );
        $stmt->execute([':cutoff' => $cutoffDate]);

        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[] = [
                'employee_id'          => (int) $row['employee_id'],
                'name'                 => (string) $row['name'],
                'employee_type'        => (string) $row['employee_type'],
                'contract_review_date' => $row['contract_review_date'] !== null ? (string) $row['contract_review_date'] : null,
            ];
        }

        return $rows;
    }
}

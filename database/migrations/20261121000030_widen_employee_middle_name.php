<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Widen employee.middle_initial from VARCHAR(5) to VARCHAR(50).
 *
 * The add/edit employee form collects a full "Middle name" (validated up to
 * 50 characters), but the column only held 5, so the controller silently cut
 * names like "Santiago" to "Santi". The full middle name is needed to tell
 * employees with the same first and last name apart in the employee list.
 *
 * The column keeps its name to avoid touching every query that reads it.
 * Idempotent: only alters when the current length is below 50. Values that
 * were already truncated cannot be restored and must be re-entered.
 */
final class WidenEmployeeMiddleName extends AbstractMigration
{
    public function up(): void
    {
        $row = $this->fetchRow(
            "SELECT CHARACTER_MAXIMUM_LENGTH AS len
               FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'employee'
                AND COLUMN_NAME = 'middle_initial'"
        );
        if ($row === false || (int) $row['len'] >= 50) {
            return;
        }

        $this->table('employee')
            ->changeColumn('middle_initial', 'string', ['limit' => 50, 'null' => true, 'default' => null])
            ->update();
    }

    public function down(): void
    {
        // Forward-only: shrinking back to 5 characters would truncate data.
    }
}

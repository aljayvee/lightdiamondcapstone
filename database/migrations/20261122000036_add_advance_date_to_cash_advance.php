<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Add the date a cash advance is given (cash_advance_request_detail.advance_date).
 *
 * Payroll deducts the ₱500 weekly repayment only in runs whose period ends on
 * or after this date, so HR can record an advance in the month it was really
 * given while computing payroll from past biometric data. [REQ-023, PAY-031]
 *
 * Existing rows get the day the request was submitted, which is never later
 * than its approval, so advances that are already being repaid stay due in
 * every run they were due in before (apart from runs for weeks before they
 * were requested, which should never have taken them).
 *
 * Idempotent: the column is added only when missing, the backfill only fills
 * NULLs, and NOT NULL is applied only after every row has a date.
 */
final class AddAdvanceDateToCashAdvance extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table('cash_advance_request_detail');
        if (!$table->hasColumn('advance_date')) {
            // Nullable first: strict mode refuses '0000-00-00' for existing rows.
            $table->addColumn('advance_date', 'date', [
                'null'    => true,
                'default' => null,
                'after'   => 'amount',
                'comment' => 'Date the cash advance is given; repayment starts in the payroll week that contains it',
            ])->save();
        }

        $this->execute(
            'UPDATE cash_advance_request_detail d '
            . 'JOIN request r ON r.request_id = d.request_id '
            . 'SET d.advance_date = DATE(r.submitted_at) '
            . 'WHERE d.advance_date IS NULL'
        );

        $missing = $this->fetchRow('SELECT COUNT(*) AS n FROM cash_advance_request_detail WHERE advance_date IS NULL');
        if ($missing !== false && (int) $missing['n'] === 0) {
            $this->execute('ALTER TABLE cash_advance_request_detail MODIFY advance_date DATE NOT NULL');
        }

        $table = $this->table('cash_advance_request_detail');
        if (!$table->hasIndexByName('idx_card_advance_date')) {
            $table->addIndex(['advance_date'], ['name' => 'idx_card_advance_date'])->save();
        }
    }

    public function down(): void
    {
        $table = $this->table('cash_advance_request_detail');
        if ($table->hasIndexByName('idx_card_advance_date')) {
            $table->removeIndexByName('idx_card_advance_date')->save();
        }
        if ($table->hasColumn('advance_date')) {
            $table->removeColumn('advance_date')->save();
        }
    }
}

<?php

declare(strict_types=1);

namespace Wbpms\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Every "INSERT INTO audit_logs (...)" in app/ may only name real columns.
 *
 * The HR "Re-match Unmatched Punches" button wrote action_type / target_table /
 * new_values, which do not exist; the INSERT failed and rolled back the whole
 * re-match ("Unknown column 'action_type'"). [ATT-028]
 */
final class AuditLogInsertColumnsTest extends TestCase
{
    /** Columns of audit_logs (migration 20260831000001_create_identity_access_tables). */
    private const COLUMNS = [
        'log_id', 'user_id', 'event_type', 'action_performed', 'table_affected', 'record_id',
        'attempted_identifier', 'request_id', 'ip_address', 'user_agent', 'description',
        'action_at', 'created_at',
    ];

    #[Test]
    public function auditLogInsertsOnlyUseRealColumns(): void
    {
        $inserts = 0;
        $root = dirname(__DIR__, 2) . '/app';
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            preg_match_all('/INSERT\s+INTO\s+audit_logs\s*\(([^)]*)\)/i', $source, $matches);
            foreach ($matches[1] as $columnList) {
                $inserts++;
                foreach (array_map('trim', explode(',', $columnList)) as $column) {
                    self::assertContains(
                        $column,
                        self::COLUMNS,
                        sprintf('%s inserts unknown audit_logs column "%s"', $file->getFilename(), $column)
                    );
                }
            }
        }

        self::assertGreaterThan(5, $inserts, 'Expected to find the audit_logs inserts in app/.');
    }

    #[Test]
    public function theRematchButtonWritesItsAuditRowWithRealColumns(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Http/Controllers/AttendanceController.php');
        $start = strpos($source, 'function rematchUnmatched');
        self::assertNotFalse($start);
        $body = substr($source, $start, 3000);

        self::assertStringContainsString("'attendance_rematched'", $body);
        self::assertStringNotContainsString('action_type', $body);
        self::assertStringNotContainsString('new_values', $body);
    }
}

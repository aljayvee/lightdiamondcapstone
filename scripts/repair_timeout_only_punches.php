<?php

declare(strict_types=1);

/**
 * One-off repair: attendance days whose only punch was a time-out but which
 * were saved with that punch as the time-in.
 *
 * Before the fix in PdoAttendanceImportGateway::reconcileAttendance(), a lone
 * punch was always stored as time_in, so an employee who forgot to punch in
 * and punched out at 16:57 appeared to have arrived at 16:57. The import now
 * uses the shift-midpoint rule (WorkSchedule::isTimeOutPunch()); this script
 * moves already-saved punches to time_out with the same rule.
 *
 * Only rows that are still unresolved are touched: status Incomplete, no
 * time-out, exactly one retained punch, not from a cancelled import, and never
 * adjusted by HR. Incomplete days are not paid by payroll, so no computed pay
 * changes.
 *
 * Usage:
 *   php scripts/repair_timeout_only_punches.php           (dry run: lists rows)
 *   php scripts/repair_timeout_only_punches.php --apply   (writes the changes)
 */

use Wbpms\Application\Attendance\AttendancePunchMatch;
use Wbpms\Infrastructure\Persistence\PdoAttendanceImportGateway;

require dirname(__DIR__) . '/vendor/autoload.php';

if (file_exists(dirname(__DIR__) . '/.env')) {
    Dotenv\Dotenv::createUnsafeMutable(dirname(__DIR__))->load();
}

$apply = in_array('--apply', array_slice($argv, 1), true);

$config = require dirname(__DIR__) . '/config/database.php';
$dsn = sprintf(
    '%s:host=%s;port=%s;dbname=%s;charset=%s',
    $config['driver'], $config['host'], $config['port'], $config['database'], $config['charset'],
);
$pdo = new PDO($dsn, $config['username'], $config['password'], $config['options']);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$gateway  = new PdoAttendanceImportGateway($pdo);
$timezone = new DateTimeZone('Asia/Manila');

$pdo->beginTransaction();
try {
    $candidates = $pdo->prepare(
        "SELECT a.attendance_id, a.employee_id, a.attendance_date, a.time_in,
                CONCAT(e.last_name, ', ', e.first_name) AS employee_name,
                (SELECT COUNT(*) FROM biometric_punch bp
                  WHERE bp.employee_id = a.employee_id
                    AND bp.match_status = :matched
                    AND DATE(bp.source_local_at) = a.attendance_date) AS punch_count
           FROM attendance a
           JOIN employee e ON e.employee_id = a.employee_id
           LEFT JOIN attendance_import_batch aib ON aib.import_batch_id = a.import_batch_id
          WHERE a.status = 'Incomplete'
            AND a.time_in IS NOT NULL
            AND a.time_out IS NULL
            AND (aib.status IS NULL OR aib.status <> 'Cancelled')
            AND NOT EXISTS (SELECT 1 FROM attendance_adjustment adj WHERE adj.attendance_id = a.attendance_id)
          ORDER BY a.attendance_date, e.last_name
          FOR UPDATE"
    );
    $candidates->execute([':matched' => AttendancePunchMatch::MATCHED]);

    $update = $pdo->prepare(
        'UPDATE attendance
            SET time_out = time_in, time_in = NULL, updated_at = UTC_TIMESTAMP()
          WHERE attendance_id = :id'
    );

    $fixed = 0;
    foreach ($candidates->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if ((int) $row['punch_count'] !== 1) {
            continue;
        }
        $employeeId = (int) $row['employee_id'];
        $date       = (string) $row['attendance_date'];
        $schedule   = $gateway->effectiveSchedule($gateway->scheduleIdOn($employeeId, $date), $date);
        $punch      = new DateTimeImmutable("{$date} {$row['time_in']}", $timezone);
        if (!$schedule->isTimeOutPunch($punch)) {
            continue;
        }

        printf(
            "%s  %-28s  %s  time-in -> time-out (shift %s-%s)\n",
            $date,
            mb_substr((string) $row['employee_name'], 0, 28),
            substr((string) $row['time_in'], 0, 5),
            $schedule->startTime,
            $schedule->endTime,
        );
        if ($apply) {
            $update->execute([':id' => (int) $row['attendance_id']]);
        }
        $fixed++;
    }

    if ($apply) {
        $pdo->commit();
        echo "\nUpdated {$fixed} attendance day(s).\n";
    } else {
        $pdo->rollBack();
        echo "\nDry run: {$fixed} attendance day(s) would be updated. Re-run with --apply to save.\n";
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
}

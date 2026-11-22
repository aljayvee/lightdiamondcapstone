<?php

declare(strict_types=1);

namespace Wbpms\Domain\Payroll;

/**
 * Lists the days payroll would silently leave unpaid for one employee
 * (owner logic review C6), so HR can resolve them before Compute.
 *
 * Payroll pays only attendance that is Complete or Approved AND comes from an
 * approved upload. Everything else on a working day is unpaid with no message:
 *
 *   INCOMPLETE          a punch is missing (time-in or time-out only)
 *   REVIEW_REQUIRED     unusual punches that HR has to confirm
 *   UPLOAD_NOT_APPROVED the day's attendance is in an upload not approved yet
 *   NO_UPLOAD           a scheduled working day with no attendance, no
 *                       approved leave, no holiday, and no approved upload
 *                       covering the date (so it is not a known absence)
 *   NO_SCHEDULE         no work schedule for the date, so absences, leave
 *                       and holidays cannot be worked out for it
 *
 * A scheduled day covered by an approved upload with no punches is a real
 * absence. Payroll already shows it as "Unpaid absence", so it is not listed.
 */
final class PayrollReadinessEvaluator
{
    public const INCOMPLETE          = 'INCOMPLETE';
    public const REVIEW_REQUIRED     = 'REVIEW_REQUIRED';
    public const UPLOAD_NOT_APPROVED = 'UPLOAD_NOT_APPROVED';
    public const NO_UPLOAD           = 'NO_UPLOAD';
    public const NO_SCHEDULE         = 'NO_SCHEDULE';

    /**
     * @param list<string>                                         $windowDates    Every Y-m-d the employee is paid for in this run.
     * @param array<string,bool>                                   $scheduledDates Y-m-d => true (working day) / false (rest day); missing = no schedule.
     * @param array<string,array{status:string,upload_approved:bool}> $attendance  Non-cancelled attendance rows keyed by Y-m-d.
     * @param array<string,bool>                                   $holidayDates   Active holidays.
     * @param array<string,bool>                                   $leaveDates     Dates covered by approved leave.
     * @param array<string,bool>                                   $uploadedDates  Dates covered by an approved upload for this employee.
     * @return list<array{date:string,issue:string}>
     */
    public function evaluate(
        array $windowDates,
        array $scheduledDates,
        array $attendance,
        array $holidayDates,
        array $leaveDates,
        array $uploadedDates,
    ): array {
        $issues = [];
        foreach ($windowDates as $date) {
            $row = $attendance[$date] ?? null;

            if ($row !== null) {
                $issue = match ($row['status']) {
                    'Incomplete'     => self::INCOMPLETE,
                    'ReviewRequired' => self::REVIEW_REQUIRED,
                    'Complete', 'Approved' => $row['upload_approved'] ? null : self::UPLOAD_NOT_APPROVED,
                    default          => null,
                };
                if ($issue !== null) {
                    $issues[] = ['date' => $date, 'issue' => $issue];
                }
                continue;
            }

            if (!array_key_exists($date, $scheduledDates)) {
                $issues[] = ['date' => $date, 'issue' => self::NO_SCHEDULE];
                continue;
            }

            if ($scheduledDates[$date] === false
                || isset($holidayDates[$date])
                || isset($leaveDates[$date])
                || isset($uploadedDates[$date])) {
                continue;
            }

            $issues[] = ['date' => $date, 'issue' => self::NO_UPLOAD];
        }

        return $issues;
    }

    /** Plain-language label shown to HR. */
    public static function label(string $issue): string
    {
        return match ($issue) {
            self::INCOMPLETE          => 'Missing time-in or time-out',
            self::REVIEW_REQUIRED     => 'Unusual punches need review',
            self::UPLOAD_NOT_APPROVED => 'Attendance upload not approved yet',
            self::NO_UPLOAD           => 'Working day with no attendance uploaded',
            self::NO_SCHEDULE         => 'No work schedule assigned',
            default                   => $issue,
        };
    }
}

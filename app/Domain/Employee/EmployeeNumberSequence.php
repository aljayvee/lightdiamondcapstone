<?php

declare(strict_types=1);

namespace Wbpms\Domain\Employee;

/**
 * Auto-generated employee numbers: EMP-001, EMP-002, ... (at least 3 digits;
 * EMP-1000 follows EMP-999).
 *
 * The next number is one above the highest existing EMP-<digits> number.
 * Numbers in any other format (e.g. the older numeric ones such as "126", or
 * "EMP-DEMO-001") are ignored, so they never block or shift the sequence.
 */
final class EmployeeNumberSequence
{
    public const PREFIX = 'EMP-';
    private const MIN_DIGITS = 3;

    /**
     * @param iterable<string> $existingNumbers Employee numbers already in use
     */
    public static function next(iterable $existingNumbers): string
    {
        $highest = 0;
        foreach ($existingNumbers as $number) {
            $sequence = self::sequenceOf((string) $number);
            if ($sequence !== null && $sequence > $highest) {
                $highest = $sequence;
            }
        }

        return self::format($highest + 1);
    }

    public static function format(int $sequence): string
    {
        return self::PREFIX . str_pad((string) max(1, $sequence), self::MIN_DIGITS, '0', STR_PAD_LEFT);
    }

    /** Sequence part of an EMP-<digits> number, or null for any other format. */
    public static function sequenceOf(string $employeeNumber): ?int
    {
        if (preg_match('/^EMP-(\d{1,9})$/', trim($employeeNumber), $m) !== 1) {
            return null;
        }

        return (int) $m[1];
    }
}

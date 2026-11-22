<?php

declare(strict_types=1);

namespace Wbpms\Domain\Employee;

/**
 * Splits what HR types into an employee search box into words (REQ012).
 *
 * Each word must then appear somewhere in the employee's first name, middle
 * initial, last name or employee number, in any order, so "Juan Dela Cruz",
 * "Dela Cruz, Juan", "juan cruz" and "EMP-012" all find the same person.
 * Searching one column at a time (the old way) never matched a full name.
 */
final class NameSearchTerms
{
    public const MAX_WORDS = 6;

    /** @return list<string> lower-case words, at most MAX_WORDS, duplicates removed */
    public static function words(string $input): array
    {
        $clean = mb_strtolower(trim($input));
        // Commas and periods are separators ("Cruz, Juan D."); hyphens stay
        // because employee numbers look like EMP-012.
        $parts = preg_split('/[\s,.;]+/u', $clean) ?: [];
        $words = [];
        foreach ($parts as $part) {
            if ($part !== '' && !in_array($part, $words, true)) {
                $words[] = $part;
            }
            if (count($words) === self::MAX_WORDS) {
                break;
            }
        }

        return $words;
    }

    /** A word as a LIKE pattern: %, _ and \ are matched literally. */
    public static function likePattern(string $word): string
    {
        return '%' . addcslashes($word, '\\%_') . '%';
    }
}

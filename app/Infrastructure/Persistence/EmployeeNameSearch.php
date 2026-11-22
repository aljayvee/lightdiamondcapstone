<?php

declare(strict_types=1);

namespace Wbpms\Infrastructure\Persistence;

use Wbpms\Domain\Employee\NameSearchTerms;

/**
 * SQL for the shared employee search (REQ012): every typed word must appear in
 * "first name, middle initial, last name, employee number".
 *
 * Parameter names are unique per word (never reused in one statement:
 * native prepares throw HY093 on a repeated name). Pass a $prefix that is
 * unique within the query.
 */
final class EmployeeNameSearch
{
    /**
     * @return array{0: string, 1: array<string, string>} [SQL condition, params]; ['', []] when nothing was typed
     */
    public static function condition(string $search, string $alias = 'e', string $prefix = 'name_q'): array
    {
        $words = NameSearchTerms::words($search);
        if ($words === []) {
            return ['', []];
        }
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $alias) !== 1 || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $prefix) !== 1) {
            throw new \InvalidArgumentException('Invalid alias or parameter prefix.');
        }

        $haystack = "LOWER(CONCAT_WS(' ', {$alias}.first_name, {$alias}.middle_initial, {$alias}.last_name, {$alias}.employee_number))";
        $parts  = [];
        $params = [];
        foreach ($words as $i => $word) {
            $name          = ':' . $prefix . '_' . $i;
            $parts[]       = "{$haystack} LIKE {$name}";
            $params[$name] = NameSearchTerms::likePattern($word);
        }

        return ['(' . implode(' AND ', $parts) . ')', $params];
    }
}

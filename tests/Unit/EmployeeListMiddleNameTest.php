<?php

declare(strict_types=1);

namespace Wbpms\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The employee list must show the middle name so employees who share a first
 * and last name can be told apart (QA feedback, 11/21/26).
 */
final class EmployeeListMiddleNameTest extends TestCase
{
    public function testListShowsFullMiddleNameAfterFirstName(): void
    {
        $html = $this->render([
            $this->employee(1, 'Dela Cruz', 'Juan', 'Santiago'),
            $this->employee(2, 'Dela Cruz', 'Juan', 'Bautista'),
        ]);

        $this->assertStringContainsString('Dela Cruz, Juan Santiago', $this->text($html));
        $this->assertStringContainsString('Dela Cruz, Juan Bautista', $this->text($html));
    }

    public function testListOmitsMiddleNameWhenBlankOrMissing(): void
    {
        $html = $this->render([
            $this->employee(3, 'Indo', 'Albert', null),
            $this->employee(4, 'Lebuna', 'Linda', '   '),
        ]);

        $this->assertStringContainsString('Indo, Albert</a>', $this->squash($html));
        $this->assertStringContainsString('Lebuna, Linda</a>', $this->squash($html));
    }

    public function testMiddleNameIsEscaped(): void
    {
        $html = $this->render([$this->employee(5, 'Cruz', 'Ana', '<b>X</b>')]);

        $this->assertStringNotContainsString('<b>X</b>', $html);
        $this->assertStringContainsString('&lt;b&gt;X&lt;/b&gt;', $html);
    }

    public function testMiddleNameColumnIsWideEnoughForFullNames(): void
    {
        $migration = (string) file_get_contents(
            dirname(__DIR__, 2) . '/database/migrations/20261121000030_widen_employee_middle_name.php'
        );
        $this->assertStringContainsString("'limit' => 50", $migration);

        $controller = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Http/Controllers/EmployeeController.php');
        $this->assertStringNotContainsString("mb_substr(\$data['middle_name'], 0, 5)", $controller);
    }

    /** @return array<string, mixed> */
    private function employee(int $id, string $last, string $first, ?string $middle): array
    {
        return [
            'id'              => $id,
            'employee_number' => (string) (100 + $id),
            'last_name'       => $last,
            'first_name'      => $first,
            'middle_name'     => $middle,
            'position'        => 'Checker',
            'branch_name'     => 'Banga Auto Supplies',
            'schedule_name'   => '07:00:00 – 16:00:00',
            'status'          => 'active',
            'effective_from'  => '2025-05-01',
        ];
    }

    /** @param list<array<string, mixed>> $employees */
    private function render(array $employees): string
    {
        $base = '';
        ob_start();
        require dirname(__DIR__, 2) . '/resources/views/hr/employees/index.php';
        return (string) ob_get_clean();
    }

    private function text(string $html): string
    {
        return (string) preg_replace('/\s+/', ' ', strip_tags($html));
    }

    private function squash(string $html): string
    {
        return (string) preg_replace('/\s+(?=<\/a>)/', '', $html);
    }
}

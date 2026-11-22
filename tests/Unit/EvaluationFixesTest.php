<?php

declare(strict_types=1);

namespace Wbpms\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Wbpms\Domain\Employee\EffectiveDateRule;
use Wbpms\Domain\Employee\NameSearchTerms;
use Wbpms\Domain\Validation\FieldRules;
use Wbpms\Infrastructure\Persistence\EmployeeNameSearch;

/**
 * Rules added for the adviser's evaluation remarks (2026-11-22):
 * REQ012 full-name search, REQ005/014/024/062 server validation,
 * REQ015/062/063 effective dates that can actually be picked.
 */
final class EvaluationFixesTest extends TestCase
{
    // ---- REQ012: search words --------------------------------------------

    public function testFullNameInAnyOrderBecomesWords(): void
    {
        self::assertSame(['juan', 'dela', 'cruz'], NameSearchTerms::words('Juan Dela Cruz'));
        self::assertSame(['dela', 'cruz', 'juan', 'd'], NameSearchTerms::words('  Dela Cruz, Juan D. '));
        self::assertSame(['emp-012'], NameSearchTerms::words('EMP-012'));
        self::assertSame([], NameSearchTerms::words('   '));
        self::assertCount(NameSearchTerms::MAX_WORDS, NameSearchTerms::words('a b c d e f g h'));
        self::assertSame(['juan'], NameSearchTerms::words('juan JUAN'));
    }

    public function testLikeWildcardsAreMatchedLiterally(): void
    {
        self::assertSame('%50\%%', NameSearchTerms::likePattern('50%'));
        self::assertSame('%a\_b%', NameSearchTerms::likePattern('a_b'));
    }

    public function testSearchSqlRequiresEveryWordWithUniqueParameters(): void
    {
        [$sql, $params] = EmployeeNameSearch::condition('Juan Dela Cruz', 'e', 'q');

        self::assertSame(3, substr_count($sql, ' LIKE '));
        self::assertSame(2, substr_count($sql, ' AND '));
        self::assertStringContainsString("CONCAT_WS(' ', e.first_name, e.middle_initial, e.last_name, e.employee_number)", $sql);
        self::assertSame([':q_0' => '%juan%', ':q_1' => '%dela%', ':q_2' => '%cruz%'], $params);
        foreach (array_keys($params) as $name) {
            self::assertSame(1, preg_match_all('/' . preg_quote($name, '/') . '\b/', $sql), "{$name} used once (HY093)");
        }
        self::assertSame(['', []], EmployeeNameSearch::condition(''));
    }

    public function testSearchSqlRejectsUnsafeAlias(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        EmployeeNameSearch::condition('juan', 'e; DROP', 'q');
    }

    // ---- REQ005 / REQ014 / REQ024 / REQ062: field rules -------------------

    /** @return iterable<string, array{string, bool}> */
    public static function usernames(): iterable
    {
        yield 'dotted'        => ['roce.catayoc', true];
        yield 'short ok'      => ['hrh', true];
        yield 'too short'     => ['ab', false];
        yield 'space'         => ['juan cruz', false];
        yield 'symbol'        => ['juan@cruz', false];
        yield 'empty'         => ['', false];
        yield 'too long'      => [str_repeat('a', 51), false];
    }

    #[DataProvider('usernames')]
    public function testUsername(string $value, bool $ok): void
    {
        self::assertSame($ok, FieldRules::username($value) === null);
    }

    public function testEmail(): void
    {
        self::assertNull(FieldRules::email('juan@example.com'));
        self::assertNotNull(FieldRules::email('juan@'));
        self::assertNotNull(FieldRules::email(''));
        self::assertNotNull(FieldRules::email(str_repeat('a', 250) . '@x.com'));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function accounts(): iterable
    {
        yield 'BDO 10'          => ['0012345678', true];
        yield 'BDO 12 grouped'  => ['0012-3456-7890', true];
        yield 'Metrobank 13'    => ['123 456 789 0123', true];
        yield 'too short'       => ['12', false];
        yield 'too long'        => ['12345678901234567', false];
        yield 'letters'         => ['12AB345678', false];
        yield 'empty'           => ['', false];
    }

    #[DataProvider('accounts')]
    public function testBankAccountNumber(string $value, bool $ok): void
    {
        self::assertSame($ok, FieldRules::bankAccountNumber($value) === null);
    }

    public function testChoicesLengthsAndDates(): void
    {
        self::assertNull(FieldRules::oneOf('Archived', ['Active', 'Archived'], 'Status'));
        self::assertSame('Choose a valid status.', FieldRules::oneOf('Deleted', ['Active', 'Archived'], 'Status'));
        self::assertNull(FieldRules::maxLength(str_repeat('x', 255), 255, 'Notes'));
        self::assertNotNull(FieldRules::maxLength(str_repeat('x', 256), 255, 'Notes'));
        self::assertTrue(FieldRules::isIsoDate('2026-02-28'));
        self::assertFalse(FieldRules::isIsoDate('2026-02-31'));
        self::assertFalse(FieldRules::isIsoDate('02/28/26'));
    }

    // ---- REQ015 / REQ062 / REQ063: effective dates -------------------------

    public function testEarliestDateIsTheDayAfterTheCurrentStart(): void
    {
        self::assertSame('2026-12-04', EffectiveDateRule::earliestAfter('2026-12-03'));
        self::assertSame('2027-01-01', EffectiveDateRule::earliestAfter('2026-12-31'));
        self::assertNull(EffectiveDateRule::earliestAfter(null));
    }

    public function testDefaultDateIsTodayUnlessThatIsNotAllowed(): void
    {
        // Current salary started long ago: today is fine.
        self::assertSame('2026-11-22', EffectiveDateRule::defaultDate('2026-01-01', '2026-11-22'));
        // Current record starts today: today would be refused, so tomorrow.
        self::assertSame('2026-11-23', EffectiveDateRule::defaultDate('2026-11-22', '2026-11-22'));
        // No current record.
        self::assertSame('2026-11-22', EffectiveDateRule::defaultDate(null, '2026-11-22'));
    }

    public function testMessageUsesUiDates(): void
    {
        self::assertSame(
            'The transfer date must be after 12/01/26 (when the current branch assignment starts). The earliest date you can use is 12/02/26.',
            EffectiveDateRule::message('transfer date', 'current branch assignment', '2026-12-01')
        );
    }
}

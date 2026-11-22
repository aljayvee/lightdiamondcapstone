<?php

declare(strict_types=1);

namespace Wbpms\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Wbpms\Http\View\UserMessage;
use Wbpms\Http\View\ViewRenderer;

/**
 * Error messages shown to users must be the friendly ones written in the
 * domain layer, never SQL, PHP errors or file paths. `catch (RuntimeException)`
 * also catches PDOException, which used to put "SQLSTATE[23000] ..." in toasts.
 */
final class UserMessageTest extends TestCase
{
    public function testDomainMessageIsShownAsWritten(): void
    {
        $e = new \RuntimeException('The end date must be on or after the start date.');

        self::assertSame('The end date must be on or after the start date.', UserMessage::from($e));
    }

    public function testDatabaseErrorIsReplacedAndLogged(): void
    {
        $this->expectErrorLog();  // the real error goes to the log
        $e = new \PDOException("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'x' for key 'uq_ws_name'");
        $shown = UserMessage::from($e);

        self::assertStringNotContainsString('SQLSTATE', $shown);
        self::assertStringNotContainsString('uq_ws_name', $shown);
        self::assertStringStartsWith('Something went wrong and your request could not be completed.', $shown);
        self::assertMatchesRegularExpression('/\(reference [0-9a-f]{12}\)\.$/', $shown);
    }

    public function testPhpErrorIsReplaced(): void
    {
        $this->expectErrorLog();
        $shown = UserMessage::from(new \TypeError('Argument #1 ($x) must be of type string, int given'));

        self::assertStringNotContainsString('Argument #1', $shown);
        self::assertStringContainsString('reference', $shown);
    }

    public function testFallbackSentenceIsUsed(): void
    {
        $this->expectErrorLog();
        $shown = UserMessage::from(new \PDOException('SQLSTATE[HY000]: General error'), 'The rehire could not be saved');

        self::assertStringStartsWith('The rehire could not be saved (reference ', $shown);
    }

    public function testPrefixedKeepsContextAndHidesDetail(): void
    {
        $this->expectErrorLog();
        self::assertSame(
            'The import could not be approved: This batch was already approved.',
            UserMessage::prefixed('The import could not be approved: ', new \RuntimeException('This batch was already approved.'))
        );

        $shown = UserMessage::prefixed('The import could not be approved', new \PDOException('SQLSTATE[40001]: Deadlock found'));
        self::assertStringStartsWith('The import could not be approved. Please try again.', $shown);
        self::assertStringNotContainsString('Deadlock', $shown);
    }

    /** @return iterable<string, array{string, bool}> */
    public static function messages(): iterable
    {
        yield 'sql state'        => ['SQLSTATE[42S22]: Column not found', true];
        yield 'unknown column'   => ["Unknown column 'x' in 'field list'", true];
        yield 'php warning'      => ['Undefined array key "employee_id"', true];
        yield 'file path'        => ['Failed in /var/www/wbpms/app/Foo.php on line 12', true];
        yield 'class name'       => ['Wbpms\\Application\\PayrollService failed', true];
        yield 'friendly'         => ['Leave credits are not enough for this request.', false];
        yield 'friendly + dates' => ['Payroll for 11/14/26 – 11/20/26 is already approved.', false];
    }

    #[DataProvider('messages')]
    public function testLooksTechnical(string $message, bool $technical): void
    {
        self::assertSame($technical, UserMessage::looksTechnical($message));
    }

    public function testViewErrorsAreSanitizedButKeepTheirKeys(): void
    {
        $this->expectErrorLog();
        $errors = ViewRenderer::sanitizeErrors([
            'email' => 'This email address is already assigned to another employee.',
            'form'  => 'SQLSTATE[23000]: Integrity constraint violation',
        ]);

        self::assertIsArray($errors);
        self::assertSame('This email address is already assigned to another employee.', $errors['email']);
        self::assertStringStartsWith('Something went wrong', (string) $errors['form']);
    }
}

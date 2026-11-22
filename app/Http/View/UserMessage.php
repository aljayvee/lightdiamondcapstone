<?php

declare(strict_types=1);

namespace Wbpms\Http\View;

use Wbpms\Infrastructure\Logging\ErrorLog;

/**
 * Turns a caught exception into text that is safe to show a user.
 *
 * Domain and validation exceptions in this codebase carry messages written
 * for people ("The end date must be on or after the start date."), and those
 * are shown as they are. Anything else — a database error (PDOException is a
 * RuntimeException, so `catch (RuntimeException $e)` catches it too), a PHP
 * TypeError, or a message that quotes SQL, file paths or class names — is
 * written to the error log and replaced with one friendly sentence and a
 * reference code the user can quote to the administrator.
 */
final class UserMessage
{
    public const GENERIC = 'Something went wrong and your request could not be completed. '
        . 'Please try again. If it keeps happening, contact the system administrator';

    /** Text that only a developer should see. */
    private const TECHNICAL = '/SQLSTATE|\bPDO|Integrity constraint|syntax error|foreign key|Duplicate entry'
        . '|Unknown column|Base table|Deadlock|Lock wait timeout|server has gone away|Connection refused'
        . '|Undefined (?:index|array key|variable|offset|property|method)|Call to (?:a )?(?:undefined|member|private)'
        . '|must be of type|Argument #\d|Stack trace|Uncaught|\.php\b|on line \d+'
        . '|Allowed memory size|Maximum execution time|View not found|\\\\Wbpms\\\\|Wbpms\\\\/i';

    /**
     * The exception's own message when it is meant for people, otherwise the
     * generic sentence (or $fallback) with a log reference.
     */
    public static function from(\Throwable $e, ?string $fallback = null): string
    {
        if (self::isSafe($e)) {
            return trim($e->getMessage());
        }

        ErrorLog::exception($e, self::caughtAt());

        return self::withReference($fallback ?? self::GENERIC);
    }

    /**
     * "<prefix>: <safe message>" or "<prefix>. <generic sentence>" — for
     * messages such as "The import could not be approved: ...".
     */
    public static function prefixed(string $prefix, \Throwable $e): string
    {
        if (self::isSafe($e)) {
            return rtrim($prefix, ' :.') . ': ' . trim($e->getMessage());
        }

        ErrorLog::exception($e, self::caughtAt());

        return self::withReference(rtrim($prefix, ' :.') . '. Please try again. '
            . 'If it keeps happening, contact the system administrator');
    }

    /**
     * Guard for plain strings (flash messages, error arrays): technical text
     * is logged and replaced, anything else is returned unchanged.
     */
    public static function sanitize(string $message): string
    {
        if (!self::looksTechnical($message)) {
            return $message;
        }

        error_log('WBPMS HIDDEN-MESSAGE ref=' . ErrorLog::reference() . ' | '
            . preg_replace('/[\r\n\t]+/', ' ', mb_substr($message, 0, 1000)));

        return self::withReference(self::GENERIC);
    }

    public static function isSafe(\Throwable $e): bool
    {
        if ($e instanceof \PDOException || $e instanceof \Error) {
            return false;
        }
        $message = trim($e->getMessage());

        return $message !== '' && !self::looksTechnical($message);
    }

    public static function looksTechnical(string $message): bool
    {
        return preg_match(self::TECHNICAL, $message) === 1;
    }

    private static function withReference(string $sentence): string
    {
        return rtrim($sentence, '. ') . ' (reference ' . ErrorLog::reference() . ').';
    }

    private static function caughtAt(): string
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 4) as $frame) {
            if (isset($frame['class']) && $frame['class'] !== self::class) {
                $short = substr((string) strrchr('\\' . $frame['class'], '\\'), 1);

                return $short . '::' . $frame['function'];
            }
        }

        return 'controller';
    }
}

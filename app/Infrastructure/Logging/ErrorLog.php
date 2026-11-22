<?php

declare(strict_types=1);

namespace Wbpms\Infrastructure\Logging;

/**
 * Application error log.
 *
 * Before this existed, production ran with error_reporting(0) and the
 * last-resort exception handler only rendered the 500 page, so a crash left
 * nothing behind but a "500" in the nginx access log. Every entry here is a
 * single line written through PHP's error_log(), which prefixes the date in
 * the default timezone (Asia/Manila) and appends to the file set by
 * configure().
 *
 * Entries never include the query string, POST data, cookies or function
 * arguments, so tokens and passwords cannot end up in the log.
 */
final class ErrorLog
{
    private const MAX_MESSAGE = 1000;
    private const MAX_FRAMES  = 8;

    private static ?string $reference = null;

    /** @var \WeakMap<\Throwable, true>|null exceptions already written in this request */
    private static ?\WeakMap $logged = null;

    /**
     * Send PHP warnings, notices, fatal errors and error_log() calls to
     * $logFile. Display settings are left to the caller.
     */
    public static function configure(string $logFile): void
    {
        $dir = dirname($logFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }
        ini_set('log_errors', '1');
        ini_set('html_errors', '0');
        if (is_dir($dir) && is_writable($dir)) {
            ini_set('error_log', $logFile);
        }
    }

    /**
     * Short id for the current request, shown on the 500 page so a user can
     * quote it and the matching log line can be found with grep.
     */
    public static function reference(): string
    {
        return self::$reference ??= bin2hex(random_bytes(6));
    }

    /**
     * Log an exception. $caughtAt names the place that caught it and showed
     * the user a message instead of a 500 page; null means it was uncaught.
     * Never throws: logging must not break the page being rendered.
     */
    public static function exception(\Throwable $e, ?string $caughtAt = null): void
    {
        // A catch site may log and then hand the same exception to
        // UserMessage, which logs unsafe ones too: write it once.
        self::$logged ??= new \WeakMap();
        if (isset(self::$logged[$e])) {
            return;
        }
        self::$logged[$e] = true;

        try {
            $root = defined('APP_ROOT') ? (string) constant('APP_ROOT') : '';
            error_log(self::format($e, self::requestContext($caughtAt), $root));
        } catch (\Throwable) {
            // Nothing sensible left to do.
        }
    }

    /**
     * @param array{ref: string, caught: ?string, method: string, path: string, user: string, role: string, ip: string} $context
     */
    public static function format(\Throwable $e, array $context, string $appRoot = ''): string
    {
        $kind = $context['caught'] !== null
            ? 'CAUGHT (' . self::clean($context['caught'], 120) . ')'
            : 'UNCAUGHT';

        $parts = [
            'WBPMS ' . $kind . ' ref=' . $context['ref'],
            self::clean($context['method'], 10) . ' ' . self::clean($context['path'], 300),
            'user=' . $context['user'] . ' role=' . $context['role'] . ' ip=' . $context['ip'],
            self::describe($e, $appRoot),
        ];

        $previous = $e->getPrevious();
        $depth = 0;
        while ($previous !== null && $depth < 3) {
            $parts[] = 'caused by ' . self::describe($previous, $appRoot);
            $previous = $previous->getPrevious();
            $depth++;
        }

        $parts[] = 'trace: ' . self::trace($e, $appRoot);

        return implode(' | ', $parts);
    }

    /**
     * @return array{ref: string, caught: ?string, method: string, path: string, user: string, role: string, ip: string}
     */
    private static function requestContext(?string $caughtAt): array
    {
        $uri  = (string) ($_SERVER['REQUEST_URI'] ?? '');
        $path = (string) (parse_url($uri, PHP_URL_PATH) ?? '');

        $auth = (isset($_SESSION) && is_array($_SESSION['_auth'] ?? null)) ? $_SESSION['_auth'] : [];
        $user = $auth['user_id'] ?? null;
        $role = $auth['role_name'] ?? null;

        return [
            'ref'    => self::reference(),
            'caught' => $caughtAt,
            'method' => PHP_SAPI === 'cli' ? 'CLI' : (string) ($_SERVER['REQUEST_METHOD'] ?? '-'),
            'path'   => PHP_SAPI === 'cli' ? implode(' ', array_map('strval', (array) ($_SERVER['argv'] ?? []))) : ($path !== '' ? $path : '-'),
            'user'   => is_int($user) || (is_string($user) && ctype_digit($user)) ? (string) $user : '-',
            'role'   => is_string($role) && preg_match('/^[A-Za-z]{1,30}$/', $role) === 1 ? $role : '-',
            'ip'     => self::clean((string) ($_SERVER['REMOTE_ADDR'] ?? '-'), 45),
        ];
    }

    private static function describe(\Throwable $e, string $appRoot): string
    {
        $code = $e instanceof \PDOException && is_array($e->errorInfo) && isset($e->errorInfo[0])
            ? ' [SQLSTATE ' . self::clean((string) $e->errorInfo[0], 10) . ']'
            : '';

        return $e::class . $code . ': ' . self::clean($e->getMessage(), self::MAX_MESSAGE)
            . ' @ ' . self::relative($e->getFile(), $appRoot) . ':' . $e->getLine();
    }

    private static function trace(\Throwable $e, string $appRoot): string
    {
        $frames = [];
        foreach (array_slice($e->getTrace(), 0, self::MAX_FRAMES) as $i => $frame) {
            $where = isset($frame['file'])
                ? self::relative($frame['file'], $appRoot) . ':' . ($frame['line'] ?? 0)
                : '[internal]';
            $call = ($frame['class'] ?? '') . ($frame['type'] ?? '') . $frame['function'] . '()';
            $frames[] = '#' . $i . ' ' . $where . ' ' . $call;
        }

        return $frames === [] ? '-' : implode(' ; ', $frames);
    }

    private static function relative(string $file, string $appRoot): string
    {
        $file = str_replace('\\', '/', $file);
        $root = rtrim(str_replace('\\', '/', $appRoot), '/');
        if ($root !== '' && str_starts_with($file, $root . '/')) {
            return substr($file, strlen($root) + 1);
        }

        return $file;
    }

    /** One line, no control characters, at most $max characters. */
    private static function clean(string $text, int $max): string
    {
        $text = (string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $text);
        $text = trim((string) preg_replace('/ {2,}/', ' ', $text));

        return mb_strlen($text) > $max ? mb_substr($text, 0, $max) . '…' : $text;
    }
}

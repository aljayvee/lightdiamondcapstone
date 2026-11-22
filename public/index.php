<?php

declare(strict_types=1);

/**
 * WBPMS Front Controller
 *
 * The only web-accessible entry point. All HTTP requests are routed through
 * this file. The public/ directory is the sole document root.
 */

// Define the application root (one level up from public/).
define('APP_ROOT', dirname(__DIR__));

// Autoloader
require_once APP_ROOT . '/vendor/autoload.php';

// Register a last-resort error handler before bootstrapping
set_exception_handler(static function (\Throwable $e): void {
    $env = $_ENV['APP_ENV'] ?? 'production';
    \Wbpms\Infrastructure\Logging\ErrorLog::exception($e);
    $reference = \Wbpms\Infrastructure\Logging\ErrorLog::reference();
    if (!headers_sent()) {
        header('X-Error-Reference: ' . $reference);
    }
    http_response_code(500);

    // Detect whether the client expects HTML (browser) or JSON (API/AJAX).
    $acceptHeader = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
    $wantsHtml    = str_contains($acceptHeader, 'text/html')
        || (!str_contains($acceptHeader, 'application/json') && $acceptHeader !== '');

    if ($wantsHtml) {
        // Render the friendly error page. It never shows the status code,
        // "Internal Server Error", or technical text - only the reference.
        header('Content-Type: text/html; charset=utf-8');
        $detail = $env === 'development'
            ? $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine()
            : null;
        if (defined('APP_ROOT') && file_exists(APP_ROOT . '/resources/views/errors/500.php')) {
            require APP_ROOT . '/resources/views/errors/500.php';
        } else {
            // Absolute fallback if the view itself cannot be loaded
            echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Something went wrong</title></head>'
               . '<body style="font-family:sans-serif;text-align:center;padding:3rem">'
               . '<h1>Something went wrong on our side</h1>'
               . '<p>Please try again in a moment. If it keeps happening, contact the system administrator.</p>'
               . '<p>Reference code: ' . htmlspecialchars($reference, ENT_QUOTES, 'UTF-8') . '</p></body></html>';
        }
        return;
    }

    // JSON response for API / AJAX clients
    header('Content-Type: application/json; charset=utf-8');
    if ($env === 'development') {
        echo json_encode([
            'error' => [
                'code'    => 'UNEXPECTED_ERROR',
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
                'trace'   => $e->getTraceAsString(),
            ],
        ]);
    } else {
        echo json_encode([
            'error' => [
                'code'      => 'UNEXPECTED_ERROR',
                'message'   => 'Something went wrong and your request could not be completed. Please try again.',
                'reference' => $reference,
            ],
        ]);
    }
});

// Bootstrap application and get the router back
/** @var \Wbpms\Http\Routing\Router $router */
$router = require_once APP_ROOT . '/bootstrap/app.php';

// Dispatch the current request
$router->handle();

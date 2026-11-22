<?php

declare(strict_types=1);

/**
 * Unexpected server error. The HTTP status (500) is sent for monitoring, but
 * the page itself never shows the code, the words "Internal Server Error",
 * or any technical detail — only a reference the administrator can search
 * for in storage/private/logs/app-error.log.
 *
 * @var string|null $detail     Developer detail; shown only when APP_ENV=development.
 * @var string|null $reference  Error-log reference for this request.
 */
$detail    ??= null;
$reference ??= null;
if (!headers_sent()) {
    http_response_code(500);
}
$base ??= rtrim((string) ($_ENV['APP_BASE_URL'] ?? ''), '/');

$errIcon      = 'alert';
$errTitle     = 'Something went wrong';
$errHeading   = 'Something went wrong on our side';
$errMessage   = 'We couldn’t finish loading this page. Nothing you did caused this, and the problem has been recorded. '
    . 'Please try again in a moment. If it keeps happening, send the reference code below to the system administrator.';
$errReference = is_string($reference) ? $reference : null;
$errDetail    = (($_ENV['APP_ENV'] ?? 'production') === 'development' && is_string($detail)) ? $detail : null;
$errActions   = [
    ['Try again', 'javascript:location.reload()', 'btn-secondary'],
    ['Go back', '#back', 'btn-primary'],
];

require __DIR__ . '/_page.php';

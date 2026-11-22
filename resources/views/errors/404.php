<?php

declare(strict_types=1);

use Wbpms\Http\Middleware\AuthMiddleware;

/**
 * Page or record not found (HTTP 404 is set here and by the callers).
 *
 * @var string $base
 */
if (!headers_sent()) {
    http_response_code(404);
}
$base ??= rtrim((string) ($_ENV['APP_BASE_URL'] ?? ''), '/');
$identity = PHP_SAPI !== 'cli' ? AuthMiddleware::identity() : null;
$homePath = match ($identity['role_name'] ?? '') {
    'BusinessOwner' => '/owner/dashboard',
    'HRHead'        => '/hr/dashboard',
    'Employee'      => '/employee/dashboard',
    default         => '/login',
};

$errIcon    = 'search';
$errTitle   = 'Not found';
$errHeading = 'We couldn’t find that page';
$errMessage = 'The page or record you are looking for does not exist, was removed, or the link is incomplete. '
    . 'Check the address, or go back and try again.';
$errActions = [
    ['Go back', '#back', 'btn-secondary'],
    [$homePath === '/login' ? 'Go to sign in' : 'Go to my dashboard', $base . $homePath, 'btn-primary'],
];

require __DIR__ . '/_page.php';

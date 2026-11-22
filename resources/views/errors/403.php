<?php

declare(strict_types=1);

/**
 * Access denied (HTTP 403 is set by the caller).
 *
 * @var string $base
 * @var string $roleName
 */
$base     ??= rtrim((string) ($_ENV['APP_BASE_URL'] ?? ''), '/');
$roleName ??= '';
$dashboardPath = match ($roleName) {
    'BusinessOwner' => '/owner/dashboard',
    'HRHead'        => '/hr/dashboard',
    default         => '/employee/dashboard',
};
$roleLabel = match ($roleName) {
    'BusinessOwner' => 'Business Owner',
    'HRHead'        => 'HR Head',
    'Employee'      => 'Employee',
    default         => '',
};

$errIcon    = 'lock';
$errTitle   = 'Access denied';
$errHeading = 'You don’t have access to this page';
$errMessage = ($roleLabel !== ''
        ? 'Your <strong>' . htmlspecialchars($roleLabel, ENT_QUOTES, 'UTF-8') . '</strong> account'
        : 'Your account')
    . ' does not have permission to open this page or perform this action. '
    . 'If you think this is a mistake, ask the Business Owner to review your account role.';
$errActions = [
    ['Go back', '#back', 'btn-secondary'],
    ['Go to my dashboard', $base . $dashboardPath, 'btn-primary'],
];

require __DIR__ . '/_page.php';

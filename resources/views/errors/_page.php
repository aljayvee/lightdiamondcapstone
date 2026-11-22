<?php

declare(strict_types=1);

use Wbpms\Http\View\Formatter;

/**
 * Shared error page body for 403 / 404 / 500.
 *
 * Rendered two ways:
 *   - inside the app layout (ViewRenderer::render('errors/404'), $inLayout = true)
 *     so the sidebar stays and the user can carry on;
 *   - as a stand-alone page (AuthMiddleware, ViewRenderer::forbidden(), the
 *     front controller's exception handler), when the layout is not available.
 *
 * Status codes and technical details are never shown to the user; the HTTP
 * status itself is set by the caller. A 500 shows only the log reference.
 *
 * @var string                                  $errIcon      lock | search | alert
 * @var string                                  $errHeading
 * @var string                                  $errMessage
 * @var string|null                             $errReference
 * @var string|null                             $errDetail    developer detail (APP_ENV=development only)
 * @var list<array{0: string, 1: string, 2: string}> $errActions [label, href, css class]
 * @var string                                  $errTitle     <title> text (stand-alone only)
 */
$inLayout     ??= false;
$base         ??= rtrim((string) ($_ENV['APP_BASE_URL'] ?? ''), '/');
$errIcon      ??= 'alert';
$errReference ??= null;
$errDetail    ??= null;
$errActions   ??= [];
$errTitle     ??= $errHeading;

$icons = [
    'lock'   => '<rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/><circle cx="12" cy="16" r="1.2" fill="currentColor"/>',
    'search' => '<circle cx="11" cy="11" r="6.5"/><path d="M20 20l-4.2-4.2"/><path d="M9 9.2a2.3 2.3 0 1 1 3 2.2c-.6.2-1 .7-1 1.3v.3"/><circle cx="11" cy="15.6" r=".9" fill="currentColor"/>',
    'alert'  => '<path d="M10.3 3.9L2.4 17.6A2 2 0 0 0 4.1 20.6h15.8a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><path d="M12 9.5v4.5"/><circle cx="12" cy="17.2" r="1" fill="currentColor"/>',
];
$iconSvg = $icons[$errIcon] ?? $icons['alert'];
$asset = static function (string $file) use ($base): string {
    $path = defined('APP_ROOT') ? APP_ROOT . '/public/assets/' . $file : '';
    $v = ($path !== '' && is_file($path)) ? (string) filemtime($path) : '1';

    return Formatter::escape($base . '/assets/' . $file . '?v=' . $v);
};
?>
<?php if (!$inLayout): ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title><?= Formatter::escape($errTitle) ?> | Light Diamond Enterprises</title>
    <link rel="icon" type="image/png" sizes="32x32" href="<?= Formatter::escape($base) ?>/favicon.png">
    <link rel="stylesheet" href="<?= $asset('app.css') ?>">
    <link rel="stylesheet" href="<?= $asset('ui-enhance.css') ?>">
</head>
<body class="error-standalone">
<?php endif; ?>
<main class="error-state" role="alert" aria-live="assertive">
    <?php if (!$inLayout): ?>
    <div class="error-state-brand">
        <img src="<?= Formatter::escape($base) ?>/assets/light-diamond-logo.png" alt="">
        <b>Light Diamond Enterprises</b>
    </div>
    <?php endif; ?>
    <div class="error-state-icon" aria-hidden="true">
        <svg xmlns="http://www.w3.org/2000/svg" width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><?= $iconSvg ?></svg>
    </div>
    <h1><?= Formatter::escape($errHeading) ?></h1>
    <p><?= $errMessage /* trusted markup built by the calling error view */ ?></p>
    <?php if (is_string($errReference) && $errReference !== ''): ?>
        <div class="error-ref">Reference code: <code><?= Formatter::escape($errReference) ?></code></div>
    <?php endif; ?>
    <?php if (is_string($errDetail) && $errDetail !== ''): ?>
        <div class="error-detail"><?= Formatter::escape($errDetail) ?></div>
    <?php endif; ?>
    <?php if ($errActions !== []): ?>
    <div class="error-state-actions">
        <?php foreach ($errActions as [$label, $href, $class]): ?>
            <a href="<?= Formatter::escape($href) ?>" class="btn <?= Formatter::escape($class) ?>"<?= $href === '#back' ? ' onclick="history.length > 1 ? history.back() : (location.href = this.dataset.home); return false;" data-home="' . Formatter::escape($base . '/login') . '"' : '' ?>><?= Formatter::escape($label) ?></a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</main>
<?php if (!$inLayout): ?>
</body>
</html>
<?php endif; ?>

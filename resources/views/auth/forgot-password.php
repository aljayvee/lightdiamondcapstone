<?php
/**
 * Forgot Password — email-entry form.
 *
 * Variables injected by AuthController::showForgotPassword():
 *   string      $csrfField  — rendered hidden CSRF input
 *   string|null $error      — validation error from previous submission
 *   string|null $notice     — info message (e.g. an old reset link was opened)
 *
 * REQ002: forgot password — a 6-digit code is emailed (replaced the reset link 2026-11-21).
 */
$base = rtrim((string) ($_ENV['APP_BASE_URL'] ?? ''), '/');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Forgot Password | Light Diamond Enterprises</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="icon" type="image/png" sizes="32x32" href="<?= $base ?>/favicon.png">
    <link rel="icon" type="image/png" sizes="16x16" href="<?= $base ?>/favicon-16.png">
    <link rel="stylesheet" href="<?= $base ?>/assets/app.css?v=<?= @filemtime(APP_ROOT . '/public/assets/app.css') ?: time() ?>">
</head>
<body class="login-page">

<div class="login-card">

    <img class="login-logo"
         src="<?= $base ?>/assets/light-diamond-logo.png"
         alt="Light Diamond Enterprises">

    <h1>Forgot Password</h1>
    <p class="login-sub">
        Enter your registered email address and we'll email you a 6-digit code to reset your password.
    </p>

    <?php if (!empty($notice)): ?>
        <div class="alert info" role="status">
            <?= htmlspecialchars((string) $notice, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($error)): ?>
        <div class="alert error" role="alert">
            <?= htmlspecialchars((string) $error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <form method="POST" action="<?= $base ?>/forgot-password" novalidate autocomplete="off">
        <?= $csrfField ?>

        <label for="email">Email Address</label>
        <input type="text"
               id="email"
               name="email"
               value="<?= htmlspecialchars((string) ($_POST['email'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
               autocomplete="email"
               required
               autofocus
               placeholder="Enter your registered email address">

        <button type="submit" class="btn btn-primary btn-full" style="margin-top:.25rem">
            Send Code
        </button>
    </form>

    <p class="login-footer" style="margin-top:1.25rem">
        <a href="<?= $base ?>/login" style="color:#4b5563;text-decoration:none;font-size:.875rem">
            &larr; Back to Login
        </a>
    </p>

</div>

</body>
</html>

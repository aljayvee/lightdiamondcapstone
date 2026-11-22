<?php
/**
 * Reset Password — enter the emailed 6-digit code and the new password.
 *
 * Reached from the Forgot Password form; the email is kept in the session.
 * The wording never says whether the email has an account.
 *
 * Variables injected by AuthController::showResetPassword():
 *   string      $csrfField    — rendered hidden CSRF input
 *   string      $maskedEmail  — e.g. "al***@gmail.com"
 *   int         $resendWait   — seconds before "Send a new code" is allowed
 *   string|null $error        — error from the previous submission
 *   string|null $notice       — info message (new code sent)
 *
 * REQ002: forgot password — a 6-digit code is emailed (replaced the reset link 2026-11-21).
 */
use Wbpms\Domain\Auth\PasswordResetCodePolicy;

$base    = rtrim((string) ($_ENV['APP_BASE_URL'] ?? ''), '/');
$e       = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$minutes = intdiv(PasswordResetCodePolicy::TTL_SECONDS, 60);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reset Password | Light Diamond Enterprises</title>
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

    <h1>Reset Password</h1>
    <p class="login-sub">
        If an account uses <strong><?= $e((string) $maskedEmail) ?></strong>, we emailed it a
        6-digit code. Enter the code and your new password below.<br>
        The code expires in <?= $minutes ?> minutes.
    </p>

    <?php if (!empty($notice)): ?>
        <div class="alert success" role="status"><?= $e((string) $notice) ?></div>
    <?php endif; ?>
    <?php if (!empty($error)): ?>
        <div class="alert error" role="alert"><?= $e((string) $error) ?></div>
    <?php endif; ?>

    <form method="POST" action="<?= $base ?>/reset-password" novalidate autocomplete="off">
        <?= $csrfField ?>

        <label for="code">Reset Code <span aria-hidden="true" style="color:#dc2626">*</span></label>
        <input type="text"
               id="code"
               name="code"
               required
               autofocus
               inputmode="numeric"
               pattern="[0-9]{6}"
               maxlength="7"
               autocomplete="one-time-code"
               placeholder="6-digit code"
               style="letter-spacing:.35em;font-size:1.25rem;text-align:center">

        <label for="password">New Password <span aria-hidden="true" style="color:#dc2626">*</span></label>
        <div class="login-pwd-wrap">
            <input type="password"
                   id="password"
                   name="password"
                   required
                   minlength="8"
                   autocomplete="new-password"
                   placeholder="Minimum 8 characters">
            <button type="button"
                    class="login-pwd-toggle"
                    onclick="toggleField('password','icon1')"
                    aria-label="Show new password">
                <svg id="icon1" xmlns="http://www.w3.org/2000/svg" width="18" height="18"
                     viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                     aria-hidden="true">
                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                    <circle cx="12" cy="12" r="3"/>
                </svg>
            </button>
        </div>

        <label for="password_confirm">Confirm Password <span aria-hidden="true" style="color:#dc2626">*</span></label>
        <div class="login-pwd-wrap">
            <input type="password"
                   id="password_confirm"
                   name="password_confirm"
                   required
                   minlength="8"
                   autocomplete="new-password"
                   placeholder="Repeat your new password">
            <button type="button"
                    class="login-pwd-toggle"
                    onclick="toggleField('password_confirm','icon2')"
                    aria-label="Show confirm password">
                <svg id="icon2" xmlns="http://www.w3.org/2000/svg" width="18" height="18"
                     viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                     aria-hidden="true">
                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                    <circle cx="12" cy="12" r="3"/>
                </svg>
            </button>
        </div>

        <button type="submit" class="btn btn-primary btn-full" style="margin-top:.75rem">
            Set New Password
        </button>
    </form>

    <form method="POST" action="<?= $base ?>/forgot-password/resend" style="margin-top:.75rem;text-align:center">
        <?= $csrfField ?>
        <button type="submit"
                id="resend-btn"
                class="btn btn-full"
                data-wait="<?= (int) $resendWait ?>"
                <?= $resendWait > 0 ? 'disabled' : '' ?>>
            Send a new code<?= $resendWait > 0 ? ' (' . (int) $resendWait . 's)' : '' ?>
        </button>
    </form>

    <p class="login-footer" style="margin-top:1rem;color:#6b7280;font-size:.8rem">
        Didn't get it? Check your Spam folder, or
        <a href="<?= $base ?>/forgot-password" style="color:#4b5563">use a different email</a>.
    </p>

    <p class="login-footer" style="margin-top:.5rem">
        <a href="<?= $base ?>/login" style="color:#4b5563;text-decoration:none;font-size:.875rem">
            &larr; Back to Login
        </a>
    </p>

</div>

<script>
var SVG_EYE     = '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>';
var SVG_EYE_OFF = '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/>';

function toggleField(inputId, iconId) {
    var input = document.getElementById(inputId);
    var icon  = document.getElementById(iconId);
    var show  = input.type === 'password';
    input.type     = show ? 'text' : 'password';
    icon.innerHTML = show ? SVG_EYE_OFF : SVG_EYE;
    icon.closest('button').setAttribute('aria-label', show ? 'Hide password' : 'Show password');
}

(function () {
    var btn = document.getElementById('resend-btn');
    if (!btn) { return; }
    var left = parseInt(btn.getAttribute('data-wait') || '0', 10);
    if (left <= 0) { return; }
    var timer = setInterval(function () {
        left -= 1;
        if (left <= 0) {
            clearInterval(timer);
            btn.disabled = false;
            btn.textContent = 'Send a new code';
            return;
        }
        btn.textContent = 'Send a new code (' + left + 's)';
    }, 1000);
})();
</script>

</body>
</html>

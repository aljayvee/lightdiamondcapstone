<?php
/**
 * First-login email OTP screen.
 *
 * Shown once, right after the user replaces their temporary password, until
 * the 6-digit code emailed to the account email is entered.
 *
 * Variables injected by AuthController::showVerifyOtp():
 *   string      $csrfField      — rendered hidden CSRF input
 *   string      $maskedEmail    — e.g. "al***@gmail.com"
 *   bool        $undeliverable  — account has no real email; code cannot be sent
 *   int         $resendWait     — seconds before "Send a new code" is allowed
 *   string|null $error          — error from the previous submission
 *   string|null $notice         — info message (code sent)
 */
use Wbpms\Domain\Auth\LoginOtpPolicy;

$base = rtrim((string) ($_ENV['APP_BASE_URL'] ?? ''), '/');
$e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$minutes = intdiv(LoginOtpPolicy::TTL_SECONDS, 60);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Verify Your Email | Light Diamond Enterprises</title>
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

    <h1>Verify Your Email</h1>

    <?php if ($undeliverable): ?>
        <div class="alert error" role="alert">
            Your account does not have a valid email address, so we cannot send a
            verification code. Please ask your HR Head to update your email, then sign in again.
        </div>
    <?php else: ?>
        <p class="login-sub">
            For your first sign-in, enter the 6-digit code we emailed to
            <strong><?= $e((string) $maskedEmail) ?></strong>.<br>
            The code expires in <?= $minutes ?> minutes.
        </p>

        <?php if (!empty($notice)): ?>
            <div class="alert success" role="status"><?= $e((string) $notice) ?></div>
        <?php endif; ?>
        <?php if (!empty($error)): ?>
            <div class="alert error" role="alert"><?= $e((string) $error) ?></div>
        <?php endif; ?>

        <form method="POST" action="<?= $base ?>/verify-otp" novalidate autocomplete="off">
            <?= $csrfField ?>

            <label for="code">Verification Code <span aria-hidden="true" style="color:#dc2626">*</span></label>
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

            <button type="submit" class="btn btn-primary btn-full" style="margin-top:.75rem">
                Verify &amp; Continue
            </button>
        </form>

        <form method="POST" action="<?= $base ?>/verify-otp/resend" style="margin-top:.75rem;text-align:center">
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
            Didn't get it? Check your Spam folder. You only need to do this once.
        </p>
    <?php endif; ?>

    <form method="POST" action="<?= $base ?>/logout" style="margin-top:.5rem;text-align:center">
        <?= $csrfField ?>
        <button type="submit" class="btn-link" style="background:none;border:0;color:#6b7280;cursor:pointer;text-decoration:underline;font-size:.85rem">
            Sign out
        </button>
    </form>

</div>

<script>
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

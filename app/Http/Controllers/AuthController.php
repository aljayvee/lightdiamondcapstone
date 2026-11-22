<?php

declare(strict_types=1);

namespace Wbpms\Http\Controllers;

use Wbpms\Application\AccountPasswordService;
use Wbpms\Application\AuthService;
use Wbpms\Application\LoginOtpService;
use Wbpms\Application\PasswordResetService;
use Wbpms\Application\UnlinkedEmployeeAccountException;
use Wbpms\Domain\Auth\LoginOtpCheck;
use Wbpms\Domain\Auth\LoginOtpPolicy;
use Wbpms\Domain\Auth\PasswordResetCodeCheck;
use Wbpms\Domain\Auth\PasswordResetCodePolicy;
use Wbpms\Http\Middleware\AuthMiddleware;
use Wbpms\Http\Middleware\CsrfMiddleware;
use Wbpms\Http\View\ViewRenderer;
use Wbpms\Infrastructure\Database\Connection;
use Wbpms\Infrastructure\Mail\Mailer;
use Wbpms\Infrastructure\Persistence\LoginOtpRepository;
use Wbpms\Infrastructure\Persistence\PasswordResetRepository;
use Wbpms\Infrastructure\Session\DatabaseSessionHandler;

/**
 * AuthController — handles login form display, login form submission, and logout.
 *
 * HTTP:
 *   GET  /login  → showLogin()  — render login form
 *   POST /login  → login()      — process credentials
 *   POST /logout → logout()     — destroy session
 *
 * REQ001: login with username and password
 * ADR-0001: MySQL-backed sessions, CSRF protection, role-based redirect
 */
final class AuthController
{
    /**
     * GET /login — render the login form.
     *
     * @param array<string, string> $params
     */
    public function showLogin(array $params = []): void
    {
        // If already authenticated, redirect to the appropriate dashboard
        $this->startSessionIfNeeded();
        $identity = AuthMiddleware::identity();

        if ($identity !== null) {
            $this->redirectToDashboard($identity['role_name']);

            return;
        }

        // Pick up login errors AND session-expiry flash messages set by
        // AuthMiddleware::requireRoles() on expired/unauthenticated redirects.
        $error = $_SESSION['_login_error']
              ?? $_SESSION['_flash_error']
              ?? null;
        unset($_SESSION['_login_error'], $_SESSION['_flash_error']);

        $success = $_SESSION['_login_success'] ?? null;
        unset($_SESSION['_login_success']);

        $oldEmail = is_string($_SESSION['_login_email'] ?? null) ? $_SESSION['_login_email'] : '';
        unset($_SESSION['_login_email']);

        $csrfField = CsrfMiddleware::field();

        http_response_code(200);
        header('Content-Type: text/html; charset=utf-8');

        require APP_ROOT . '/resources/views/auth/login.php';
    }

    /**
     * POST /login — validate credentials and create an authenticated session.
     *
     * Single-active-session policy (REQN-SAS):
     *   After credentials are verified and before the new session is written,
     *   all existing sessions for the authenticated user are deleted from the
     *   database.  Any concurrent request from a displaced session will find
     *   its row gone and be redirected to /login with a flash message by
     *   AuthMiddleware::requireRoles().
     *
     * @param array<string, string> $params
     */
    public function login(array $params = []): void
    {
        $this->startSessionIfNeeded();

        // CSRF was already verified by the Router before this method is called.

        $username = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        // Keep what was typed so a failed attempt does not clear the field (REQ001).
        $_SESSION['_login_email'] = mb_substr($username, 0, 254);

        // Basic input presence check — defer full validation to AuthService
        if ($username === '' || $password === '') {
            $_SESSION['_login_error'] = 'Email and password are required.';
            $this->redirect('/login');

            return;
        }

        $connection = $this->makeConnection();
        $service    = new AuthService($connection);

        try {
            $identity = $service->attemptLogin(
                $username,
                $password,
                $this->requestId(),
                $this->clientIp(),
                (string) ($_SERVER['HTTP_USER_AGENT'] ?? '')
            );
        } catch (UnlinkedEmployeeAccountException $e) {
            $_SESSION['_login_error'] = $e->getMessage();
            $this->redirect('/login');
            return;
        }

        if ($identity === null) {
            // Surface explicit deactivated / archived / unverified-email message
            // after valid password verification, or generic message on bad credentials.
            $_SESSION['_login_error'] = $service->lastFailureMessage() ?? 'Invalid email or password.';
            $this->redirect('/login');

            return;
        }

        // Single-active-session enforcement:
        // Invalidate all existing sessions for this user before creating a new
        // one.  This must happen before setIdentity() calls
        // session_regenerate_id(true) so we can pass the old session ID to the
        // handler; after regeneration the new ID is used as the keep-value.
        //
        // Step 1 — delete the old sessions while we still hold the current ID.
        $oldSessionId = session_id();
        $sessionHandler = new DatabaseSessionHandler(
            $connection->pdo(),
            1800,   // 30-minute idle TTL (matches bootstrap/app.php)
            43200   // 12-hour absolute TTL
        );
        // Delete all sessions belonging to this user except the current one.
        // (The current one will be regenerated by setIdentity() next.)
        $sessionHandler->invalidateByUserId((int) $identity['user_id'], (string) $oldSessionId);

        // Step 2 — persist authenticated identity; regenerates session ID
        // internally via session_regenerate_id(true), which also deletes the
        // old session file/row.  After this call the active session ID has
        // changed, so there is no need to explicitly delete $oldSessionId.
        AuthMiddleware::setIdentity($identity);

        // Requirement 13, AC5: auto-provisioned accounts must change their
        // temporary password before accessing any other module.
        if ($identity['requires_password_change']) {
            $this->redirect('/change-password');
            return;
        }

        // First-login email OTP (asked once, until the account email is verified).
        if ($identity['requires_email_otp']) {
            $this->redirect('/verify-otp');
            return;
        }

        $this->redirectToDashboard($identity['role_name']);
    }

    /**
     * POST /logout — destroy the server session and redirect to login.
     *
     * @param array<string, string> $params
     */
    public function logout(array $params = []): void
    {
        $this->startSessionIfNeeded();
        AuthMiddleware::destroySession();
        $this->redirect('/login');
    }

    /**
     * GET /change-password — mandatory first-login password change screen.
     *
     * Only accessible while the session has requires_password_change = true.
     * Any other authenticated user is redirected to their dashboard.
     * Unauthenticated users are sent to /login.
     *
     * Requirement 13, AC5.
     *
     * @param array<string, string> $params
     */
    public function showChangePassword(array $params = []): void
    {
        $this->startSessionIfNeeded();
        $identity = AuthMiddleware::identity();

        if ($identity === null) {
            $this->redirect('/login');
            return;
        }

        if (!$identity['requires_password_change']) {
            // Already changed — send to normal dashboard
            $this->redirectToDashboard($identity['role_name']);
            return;
        }

        $csrfField = CsrfMiddleware::field();
        $error     = $_SESSION['_change_pwd_error'] ?? null;
        unset($_SESSION['_change_pwd_error']);

        http_response_code(200);
        header('Content-Type: text/html; charset=utf-8');
        require APP_ROOT . '/resources/views/auth/change-password.php';
    }

    /**
     * POST /change-password — persist new password and clear the flag.
     *
     * Requirement 13, AC5–AC6.
     *
     * @param array<string, string> $params
     */
    public function changePassword(array $params = []): void
    {
        $this->startSessionIfNeeded();
        $identity = AuthMiddleware::identity();

        if ($identity === null) {
            $this->redirect('/login');
            return;
        }

        if (!$identity['requires_password_change']) {
            $this->redirectToDashboard($identity['role_name']);
            return;
        }

        $password = (string) ($_POST['password']         ?? '');
        $confirm  = (string) ($_POST['password_confirm'] ?? '');

        if ($password !== $confirm) {
            $_SESSION['_change_pwd_error'] = 'Passwords do not match.';
            $this->redirect('/change-password');
            return;
        }

        $connection = $this->makeConnection();
        $curStmt = $connection->pdo()->prepare('SELECT password_hash FROM users WHERE user_id = :id LIMIT 1');
        $curStmt->execute([':id' => $identity['user_id']]);
        $currentHash = $curStmt->fetchColumn();

        $pwdError = \Wbpms\Application\AuthenticationPolicy::validatePasswordStrength(
            $password,
            $currentHash !== false ? (string) $currentHash : null,
        );
        if ($pwdError !== null) {
            $_SESSION['_change_pwd_error'] = $pwdError;
            $this->redirect('/change-password');
            return;
        }

        // Hash and persist the new password and clear the flag. The account
        // email is NOT marked verified here: the first-login OTP does that.
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $connection->pdo()->prepare(
            "UPDATE users
                SET password_hash = :hash,
                    requires_password_change = 0,
                    updated_at = UTC_TIMESTAMP()
              WHERE user_id = :id"
        )->execute([':hash' => $hash, ':id' => $identity['user_id']]);

        // Remove the flag from the live session so the user can access the app.
        $_SESSION['_auth']['requires_password_change'] = false;

        if ($identity['requires_email_otp']) {
            $this->redirect('/verify-otp');
            return;
        }

        $this->redirectToDashboard($identity['role_name']);
    }

    // -----------------------------------------------------------------------
    // First-login email OTP
    // -----------------------------------------------------------------------

    /**
     * GET /verify-otp — enter the 6-digit code emailed after the first password change.
     *
     * Sends a code automatically when there is no usable one yet.
     *
     * @param array<string, string> $params
     */
    public function showVerifyOtp(array $params = []): void
    {
        $this->startSessionIfNeeded();
        $identity = AuthMiddleware::identity();

        if ($identity === null) {
            $this->redirect('/login');
            return;
        }
        if ($identity['requires_password_change']) {
            $this->redirect('/change-password');
            return;
        }
        if (!$identity['requires_email_otp']) {
            $this->redirectToDashboard($identity['role_name']);
            return;
        }

        $service = $this->makeOtpService();
        $send    = $service->ensureSent(
            $identity['user_id'],
            $this->clientIp(),
            (string) ($_SERVER['HTTP_USER_AGENT'] ?? '')
        );

        $error   = $_SESSION['_otp_error'] ?? null;
        $notice  = $_SESSION['_otp_notice'] ?? null;
        unset($_SESSION['_otp_error'], $_SESSION['_otp_notice']);

        if ($send['status'] === LoginOtpService::SENT && $notice === null) {
            $notice = 'We sent a 6-digit code to ' . $send['email'] . '.';
        }
        if ($send['status'] === LoginOtpService::MAIL_FAILED && $error === null) {
            $error = 'We could not send the code right now. Please try "Send a new code" in a minute.';
        }

        $maskedEmail   = $send['email'];
        $undeliverable = $send['status'] === LoginOtpService::UNDELIVERABLE;
        $resendWait    = $send['wait'];
        $csrfField     = CsrfMiddleware::field();

        http_response_code(200);
        header('Content-Type: text/html; charset=utf-8');
        require APP_ROOT . '/resources/views/auth/verify-otp.php';
    }

    /**
     * POST /verify-otp — check the entered code.
     *
     * @param array<string, string> $params
     */
    public function verifyOtp(array $params = []): void
    {
        $this->startSessionIfNeeded();
        $identity = AuthMiddleware::identity();

        if ($identity === null) {
            $this->redirect('/login');
            return;
        }
        if (!$identity['requires_email_otp'] || $identity['requires_password_change']) {
            $this->redirect($identity['requires_password_change'] ? '/change-password' : '/verify-otp');
            return;
        }

        $service = $this->makeOtpService();
        $result  = $service->verify(
            $identity['user_id'],
            (string) ($_POST['code'] ?? ''),
            $this->clientIp(),
            (string) ($_SERVER['HTTP_USER_AGENT'] ?? '')
        );

        if ($result === LoginOtpCheck::Verified) {
            $_SESSION['_auth']['requires_email_otp'] = false;
            ViewRenderer::flash($result->message());
            $this->redirectToDashboard($identity['role_name']);
            return;
        }

        $message = $result->message();
        if ($result === LoginOtpCheck::Wrong) {
            $left = $service->attemptsLeft($identity['user_id']);
            $message = $left > 0
                ? $message . ' ' . $left . ' attempt' . ($left === 1 ? '' : 's') . ' left.'
                : LoginOtpCheck::TooManyAttempts->message();
        }
        $_SESSION['_otp_error'] = $message;
        $this->redirect('/verify-otp');
    }

    /**
     * POST /verify-otp/resend — email a fresh code (once a minute).
     *
     * @param array<string, string> $params
     */
    public function resendOtp(array $params = []): void
    {
        $this->startSessionIfNeeded();
        $identity = AuthMiddleware::identity();

        if ($identity === null) {
            $this->redirect('/login');
            return;
        }
        if (!$identity['requires_email_otp'] || $identity['requires_password_change']) {
            $this->redirect($identity['requires_password_change'] ? '/change-password' : '/verify-otp');
            return;
        }

        $send = $this->makeOtpService()->resend(
            $identity['user_id'],
            $this->clientIp(),
            (string) ($_SERVER['HTTP_USER_AGENT'] ?? '')
        );

        match ($send['status']) {
            LoginOtpService::SENT        => $_SESSION['_otp_notice'] = 'A new code was sent to ' . $send['email'] . '. Earlier codes no longer work.',
            LoginOtpService::COOLDOWN    => $_SESSION['_otp_error'] = 'Please wait ' . $send['wait'] . ' seconds before requesting another code.',
            LoginOtpService::MAIL_FAILED => $_SESSION['_otp_error'] = 'We could not send the code right now. Please try again in a minute.',
            default                      => null,
        };

        $this->redirect('/verify-otp');
    }

    private function makeOtpService(): LoginOtpService
    {
        return new LoginOtpService(new LoginOtpRepository($this->makeConnection()), new Mailer());
    }

    // -----------------------------------------------------------------------
    // Forgot / reset password  (REQ002)
    // -----------------------------------------------------------------------

    /**
     * GET /forgot-password — render the email-entry form.
     *
     * @param array<string, string> $params
     */
    public function showForgotPassword(array $params = []): void
    {
        $this->startSessionIfNeeded();

        // Authenticated users have no need for this page.
        if (AuthMiddleware::identity() !== null) {
            $identity = AuthMiddleware::identity();
            $this->redirectToDashboard((string) $identity['role_name']);
            return;
        }

        $error     = $_SESSION['_forgot_error'] ?? null;
        $notice    = $_SESSION['_forgot_notice'] ?? null;
        unset($_SESSION['_forgot_error'], $_SESSION['_forgot_notice']);

        $csrfField = CsrfMiddleware::field();

        http_response_code(200);
        header('Content-Type: text/html; charset=utf-8');
        require APP_ROOT . '/resources/views/auth/forgot-password.php';
    }

    /**
     * POST /forgot-password — validate the email and email a reset code.
     *
     * Always continues to the code-entry page whether or not the address has
     * an account (prevents account-existence enumeration). Only a mail
     * delivery failure is reported, as before the code change.
     *
     * @param array<string, string> $params
     */
    public function sendResetCode(array $params = []): void
    {
        $this->startSessionIfNeeded();

        $email = trim((string) ($_POST['email'] ?? ''));

        if ($email === '') {
            $_SESSION['_forgot_error'] = 'Please enter your email address.';
            $this->redirect('/forgot-password');
            return;
        }

        // Basic format sanity check — not exhaustive; we just need something plausible.
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['_forgot_error'] = 'Please enter a valid email address.';
            $this->redirect('/forgot-password');
            return;
        }

        // In production, reject immediately if a usable delivery driver is not
        // configured — prevents the user from waiting on a silent no-op.
        $mailDriver = strtolower(trim((string) ($_ENV['MAIL_DRIVER'] ?? 'log')));
        $mailHost   = trim((string) ($_ENV['MAIL_HOST'] ?? ''));
        $appEnv     = strtolower(trim((string) ($_ENV['APP_ENV'] ?? 'local')));
        if ($appEnv === 'production' && !($mailDriver === 'smtp' && $mailHost !== '')) {
            $_SESSION['_forgot_error'] =
                'Password reset emails cannot be sent because the mail server is not '
                . 'configured. Please contact your system administrator.';
            $this->redirect('/forgot-password');
            return;
        }

        // The same email again within a minute: the code just sent still works.
        $wait = $this->resetResendWait($email);
        if ($wait > 0) {
            $_SESSION['_reset_notice'] = "A code was just sent. You can request another in {$wait} seconds.";
            $this->redirect('/reset-password');
            return;
        }

        $mailError = $this->dispatchResetCode($email);
        if ($mailError !== null) {
            $_SESSION['_forgot_error'] = $mailError;
            $this->redirect('/forgot-password');
            return;
        }

        $this->redirect('/reset-password');
    }

    /**
     * POST /forgot-password/resend — "Send a new code" on the code-entry page.
     *
     * @param array<string, string> $params
     */
    public function resendResetCode(array $params = []): void
    {
        $this->startSessionIfNeeded();

        $email = (string) ($_SESSION['_reset_email'] ?? '');
        if ($email === '') {
            $this->redirect('/forgot-password');
            return;
        }

        $wait = $this->resetResendWait($email);
        if ($wait > 0) {
            $_SESSION['_reset_error'] = "Please wait {$wait} seconds before requesting another code.";
            $this->redirect('/reset-password');
            return;
        }

        $mailError = $this->dispatchResetCode($email);
        if ($mailError !== null) {
            $_SESSION['_reset_error'] = $mailError;
        } else {
            $_SESSION['_reset_notice'] = 'A new code is on its way. Use only the newest code.';
        }
        $this->redirect('/reset-password');
    }

    /**
     * GET /forgot-password/sent — old confirmation page; the code page replaced it.
     *
     * @param array<string, string> $params
     */
    public function showResetLinkSent(array $params = []): void
    {
        $this->startSessionIfNeeded();
        $this->redirect(($_SESSION['_reset_email'] ?? '') !== '' ? '/reset-password' : '/forgot-password');
    }

    /**
     * GET /reset-password — enter the emailed code and the new password.
     *
     * Needs the email from the Forgot Password form (kept in the session).
     * Links from reset emails sent before the code change (?token=) are sent
     * back to Forgot Password with an explanation.
     *
     * @param array<string, string> $params
     */
    public function showResetPassword(array $params = []): void
    {
        $this->startSessionIfNeeded();

        if (AuthMiddleware::identity() !== null) {
            $identity = AuthMiddleware::identity();
            $this->redirectToDashboard((string) $identity['role_name']);
            return;
        }

        if (array_key_exists('token', $_GET)) {
            $_SESSION['_forgot_notice'] =
                'Password reset links are no longer used. Enter your email address '
                . 'and we will send you a 6-digit code instead.';
            $this->redirect('/forgot-password');
            return;
        }

        $email = (string) ($_SESSION['_reset_email'] ?? '');
        if ($email === '') {
            $this->redirect('/forgot-password');
            return;
        }

        $maskedEmail = LoginOtpPolicy::maskEmail($email);
        $resendWait  = $this->resetResendWait($email);
        $error       = $_SESSION['_reset_error'] ?? null;
        $notice      = $_SESSION['_reset_notice'] ?? null;
        unset($_SESSION['_reset_error'], $_SESSION['_reset_notice']);

        $csrfField = CsrfMiddleware::field();

        http_response_code(200);
        header('Content-Type: text/html; charset=utf-8');
        require APP_ROOT . '/resources/views/auth/reset-password.php';
    }

    /**
     * POST /reset-password — check the code and set the new password.
     *
     * @param array<string, string> $params
     */
    public function resetPassword(array $params = []): void
    {
        $this->startSessionIfNeeded();

        $email = (string) ($_SESSION['_reset_email'] ?? '');
        if ($email === '') {
            $_SESSION['_forgot_error'] = 'Your reset request has ended. Please enter your email address again.';
            $this->redirect('/forgot-password');
            return;
        }

        $code     = (string) ($_POST['code']             ?? '');
        $password = (string) ($_POST['password']         ?? '');
        $confirm  = (string) ($_POST['password_confirm'] ?? '');

        // Input problems are reported before the code is checked, so they
        // never use up one of the code's attempts.
        if (!LoginOtpPolicy::isWellFormed(LoginOtpPolicy::normalize($code))) {
            $_SESSION['_reset_error'] = PasswordResetCodeCheck::Malformed->message();
            $this->redirect('/reset-password');
            return;
        }

        if ($password !== $confirm) {
            $_SESSION['_reset_error'] = 'Passwords do not match.';
            $this->redirect('/reset-password');
            return;
        }

        $pwdError = \Wbpms\Application\AuthenticationPolicy::validatePasswordStrength($password);
        if ($pwdError !== null) {
            $_SESSION['_reset_error'] = $pwdError;
            $this->redirect('/reset-password');
            return;
        }

        $result = $this->makeResetService()->resetPassword(
            $email,
            $code,
            $password,
            $this->clientIp(),
            (string) ($_SERVER['HTTP_USER_AGENT'] ?? '')
        );

        if ($result !== PasswordResetCodeCheck::Verified) {
            $_SESSION['_reset_error'] = $result->message();
            $this->redirect('/reset-password');
            return;
        }

        unset($_SESSION['_reset_email'], $_SESSION['_reset_sent_at']);
        $_SESSION['_login_success'] = $result->message();
        $this->redirect('/login');
    }

    /** Email a reset code; returns an error message only when delivery failed. */
    private function dispatchResetCode(string $email): ?string
    {
        $result = $this->makeResetService()->sendCode(
            $email,
            $this->clientIp(),
            (string) ($_SERVER['HTTP_USER_AGENT'] ?? '')
        );

        if ($result['status'] === PasswordResetService::MAIL_FAILED) {
            return 'We could not send the reset email. Please try again in a few minutes '
                . 'or contact your system administrator if the problem persists.';
        }

        // Every other outcome (sent, no account, daily limit, ...) looks the same.
        $_SESSION['_reset_email']   = $email;
        $_SESSION['_reset_sent_at'] = time();

        return null;
    }

    /** Seconds before this browser may ask for another code for $email. */
    private function resetResendWait(string $email): int
    {
        if (($_SESSION['_reset_email'] ?? '') !== $email || !isset($_SESSION['_reset_sent_at'])) {
            return 0;
        }
        $now = new \DateTimeImmutable();

        return PasswordResetCodePolicy::secondsUntilResend(
            $now->setTimestamp((int) $_SESSION['_reset_sent_at']),
            $now
        );
    }

    private function makeResetService(): PasswordResetService
    {
        return new PasswordResetService(new PasswordResetRepository($this->makeConnection()), new Mailer());
    }

    /**
     * GET /verify-email — verify a user's email address from a token link.
     *
     * @param array<string, string> $params
     */
    public function verifyEmail(array $params = []): void
    {
        $this->startSessionIfNeeded();
        $rawToken = trim((string) ($_GET['token'] ?? ''));

        if ($rawToken === '') {
            $_SESSION['_login_error'] = 'Missing email verification token.';
            $this->redirect('/login');
            return;
        }

        $userService = new \Wbpms\Application\UserService($this->makeConnection());
        if ($userService->verifyEmailToken($rawToken)) {
            $_SESSION['_login_success'] = 'Your email address has been verified. You may now sign in.';
        } else {
            $_SESSION['_login_error'] = 'This email verification link is invalid or has expired.';
        }

        $this->redirect('/login');
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    private function startSessionIfNeeded(): void
    {
        if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
            AuthMiddleware::startSession();
        }
    }

    private function makeConnection(): Connection
    {
        // Load the database config for the current environment
        $config = require APP_ROOT . '/config/database.php';

        return new Connection($config);
    }

    private function redirectToDashboard(string $roleName): void
    {
        $path = match ($roleName) {
            'HRHead'        => '/hr/dashboard',
            'BusinessOwner' => '/owner/dashboard',
            'Employee'      => '/employee/dashboard',
            default         => '/login',
        };

        $this->redirect($path);
    }

    // -----------------------------------------------------------------------
    // Change Password for any signed-in user (REQ002)
    // -----------------------------------------------------------------------

    /**
     * GET /account/password
     *
     * @param array<string, string> $params
     */
    public function showAccountPassword(array $params = []): void
    {
        ViewRenderer::render('account/password', ['errors' => []], 'Change Password');
    }

    /**
     * POST /account/password
     *
     * @param array<string, string> $params
     */
    public function updateAccountPassword(array $params = []): void
    {
        $identity = AuthMiddleware::identity();
        if ($identity === null) {
            $this->redirect('/login');
        }

        $connection = $this->makeConnection();
        $errors = (new AccountPasswordService($connection, new PasswordResetRepository($connection)))->change(
            (int) $identity['user_id'],
            (string) ($_POST['current_password'] ?? ''),
            (string) ($_POST['password'] ?? ''),
            (string) ($_POST['password_confirm'] ?? ''),
            $this->clientIp(),
            (string) ($_SERVER['HTTP_USER_AGENT'] ?? '')
        );

        if ($errors !== []) {
            http_response_code(422);
            ViewRenderer::render('account/password', ['errors' => $errors], 'Change Password');
            return;
        }

        ViewRenderer::flash('Your password was changed. Use the new password the next time you sign in.');
        $this->redirect('/account/password');
    }

    private function redirect(string $path): void
    {
        $base = rtrim((string) ($_ENV['APP_BASE_URL'] ?? ''), '/');
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        header('Location: ' . $base . $path, true, 302);
        exit;
    }

    private function requestId(): string
    {
        return (string) ($_SERVER['HTTP_X_REQUEST_ID']
            ?? sprintf(
                '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                random_int(0, 0xffff), random_int(0, 0xffff),
                random_int(0, 0xffff),
                random_int(0, 0x0fff) | 0x4000,
                random_int(0, 0x3fff) | 0x8000,
                random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff)
            ));
    }

    private function clientIp(): string
    {
        $forwarded = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
        if ($forwarded !== '') {
            // X-Forwarded-For may be a comma-separated list; take only the
            // leftmost value (the original client IP as seen by the first proxy).
            return trim(explode(',', $forwarded)[0]);
        }

        return (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    }
}

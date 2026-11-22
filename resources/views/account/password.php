<?php

declare(strict_types=1);

use Wbpms\Http\View\Formatter;
use Wbpms\Http\View\Icon;

/**
 * Change Password for any signed-in user (REQ002).
 *
 * @var array<string, string> $errors
 * @var string                $csrf
 * @var string                $base
 */
$errors ??= [];
$field = static function (string $name) use ($errors): string {
    return isset($errors[$name])
        ? '<span class="field-error">' . Formatter::escape($errors[$name]) . '</span>'
        : '';
};
$invalid = static fn (string $name): string => isset($errors[$name]) ? ' is-invalid' : '';
?>
<div class="page-head">
    <div>
        <h1>Change Password</h1>
        <p>Confirm your current password, then choose a new one.</p>
    </div>
</div>

<div class="card" style="max-width:560px">
    <form method="POST" action="<?= $base ?>/account/password" novalidate data-validate autocomplete="off">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">

        <div class="form-group">
            <label for="current_password">Current Password <span class="req">*</span></label>
            <input type="password" id="current_password" name="current_password" class="form-control<?= $invalid('current_password') ?>"
                   autocomplete="current-password" required maxlength="200"
                   data-msg-required="Enter your current password.">
            <?= $field('current_password') ?>
        </div>

        <div class="form-group">
            <label for="password">New Password <span class="req">*</span></label>
            <input type="password" id="password" name="password" class="form-control<?= $invalid('password') ?>"
                   autocomplete="new-password" required minlength="8" maxlength="200"
                   data-msg-required="Enter a new password."
                   data-msg-min="Password must be at least 8 characters.">
            <?= $field('password') ?>
            <small class="muted">At least 8 characters with an uppercase letter, a lowercase letter, a number and a special character (e.g. !@#$%).</small>
        </div>

        <div class="form-group">
            <label for="password_confirm">Confirm New Password <span class="req">*</span></label>
            <input type="password" id="password_confirm" name="password_confirm" class="form-control<?= $invalid('password_confirm') ?>"
                   autocomplete="new-password" required maxlength="200"
                   data-msg-required="Type the new password again.">
            <?= $field('password_confirm') ?>
        </div>

        <div style="display:flex;gap:.75rem;flex-wrap:wrap;margin-top:1rem">
            <button type="submit" class="btn btn-primary"><?= Icon::svg('key') ?> Change Password</button>
        </div>
    </form>
</div>

<script src="<?= $base ?>/assets/inline-validate.js?v=<?= filemtime(APP_ROOT . '/public/assets/inline-validate.js') ?>"></script>

<?php
require __DIR__ . '/inc/bootstrap.php';

$user = require_login();
$forced = (bool)$user['must_change_password'];

if (is_post()) {
    verify_csrf();
    if (post('action') === 'request_approval') {
        $error = request_password_change($user, (string)post('reason', ''));
        if ($error === null) {
            flash('success', 'Your request was sent to the Super Admin. You can change your password once it is approved.');
            redirect('change_password.php');
        }
        flash('error', $error);
    } else {
        $error = change_password($user, (string)post('current', ''), (string)post('new', ''), (string)post('confirm', ''));
        if ($error === null) {
            flash('success', 'Your password has been updated.');
            redirect(home_for($user));
        }
        flash('error', $error);
    }
}

$st = password_change_status($user);
$blocked = $st['needs_approval'] && !$st['approved'];

auth_start('Change password');
?>
<p class="auth-kicker"><?= $forced ? 'One more step' : 'Account' ?></p>
<h1 class="auth-title"><?= $forced ? 'Set your own password' : 'Change password' ?></h1>
<p class="auth-sub"><?php
    if ($forced) {
        echo 'You signed in with a temporary password. Choose a new one to continue.';
    } elseif ($blocked) {
        echo 'You have used all ' . PASSWORD_CHANGE_LIMIT . ' password changes. The Super Admin must approve any further change.';
    } elseif ($st['approved']) {
        echo 'The Super Admin approved one more password change.';
    } else {
        echo "Choose a strong password you don't use anywhere else.";
    }
?></p>

<?php auth_flashes(); ?>

<?php if (!$forced && !$blocked): ?>
    <div class="auth-note">
        <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 8v4M12 16h.01"/></svg>
        <span><?php if ($st['approved']): ?>
            This approval covers one change.
        <?php else: ?>
            You can change your password <?= PASSWORD_CHANGE_LIMIT ?> times. Changes left: <strong><?= (int)$st['left'] ?></strong>. After that, the Super Admin must approve.
        <?php endif; ?></span>
    </div>
<?php endif; ?>

<?php if ($blocked): ?>
    <?php if ($st['pending']): ?>
        <div class="auth-note">
            <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
            <span>Your request is waiting for Super Admin approval. Come back to this page once it is approved.</span>
        </div>
    <?php else: ?>
        <form method="post" action="change_password.php" novalidate class="auth-stack">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="request_approval">
            <div class="auth-field">
                <label for="reason">Reason <span class="optional">(optional)</span></label>
                <input id="reason" name="reason" type="text" maxlength="255" placeholder="e.g. I think someone saw my password">
            </div>
            <button class="auth-btn" type="submit">Ask the Super Admin for approval</button>
        </form>
    <?php endif; ?>
<?php else: ?>
    <form method="post" action="change_password.php" novalidate class="auth-stack" data-confirm="Your password will be changed right away. Use the new one the next time you sign in." data-confirm-title="Change your password?" data-confirm-ok="Change password">
        <?= csrf_field() ?>
        <div class="auth-field">
            <label for="current"><?= $forced ? 'Temporary password' : 'Current password' ?></label>
            <div class="pw-wrap">
                <input id="current" name="current" type="password" autocomplete="current-password" required>
                <button type="button" class="pw-toggle" data-toggle-password="current" aria-label="Show password" aria-pressed="false" title="Show password">
                    <svg class="pw-eye" viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>
                    <svg class="pw-eye-off" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 3l18 18M10.6 5.1A10.4 10.4 0 0 1 12 5c6.4 0 10 7 10 7a17.6 17.6 0 0 1-3.2 4.1M6.6 6.6A17.4 17.4 0 0 0 2 12s3.6 7 10 7a9.7 9.7 0 0 0 5.4-1.6M9.9 9.9a3 3 0 0 0 4.2 4.2"/></svg>
                </button>
            </div>
        </div>
        <div class="auth-field">
            <label for="new">New password</label>
            <div class="pw-wrap">
                <input id="new" name="new" type="password" autocomplete="new-password" required minlength="10" aria-describedby="newHint">
                <button type="button" class="pw-toggle" data-toggle-password="new" aria-label="Show password" aria-pressed="false" title="Show password">
                    <svg class="pw-eye" viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>
                    <svg class="pw-eye-off" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 3l18 18M10.6 5.1A10.4 10.4 0 0 1 12 5c6.4 0 10 7 10 7a17.6 17.6 0 0 1-3.2 4.1M6.6 6.6A17.4 17.4 0 0 0 2 12s3.6 7 10 7a9.7 9.7 0 0 0 5.4-1.6M9.9 9.9a3 3 0 0 0 4.2 4.2"/></svg>
                </button>
            </div>
            <span class="hint" id="newHint">At least 10 characters, with letters and numbers.</span>
        </div>
        <div class="auth-field">
            <label for="confirm">Confirm new password</label>
            <div class="pw-wrap">
                <input id="confirm" name="confirm" type="password" autocomplete="new-password" required>
                <button type="button" class="pw-toggle" data-toggle-password="confirm" aria-label="Show password" aria-pressed="false" title="Show password">
                    <svg class="pw-eye" viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>
                    <svg class="pw-eye-off" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 3l18 18M10.6 5.1A10.4 10.4 0 0 1 12 5c6.4 0 10 7 10 7a17.6 17.6 0 0 1-3.2 4.1M6.6 6.6A17.4 17.4 0 0 0 2 12s3.6 7 10 7a9.7 9.7 0 0 0 5.4-1.6M9.9 9.9a3 3 0 0 0 4.2 4.2"/></svg>
                </button>
            </div>
        </div>
        <button class="auth-btn" type="submit">Save password</button>
    </form>
<?php endif; ?>

<div class="auth-divider"></div>
<?php if (!$forced): ?>
    <p class="auth-foot"><a href="<?= e(home_for($user)) ?>">Back to dashboard</a></p>
<?php else: ?>
    <p class="auth-foot"><button type="button" data-open="modalLogout" aria-haspopup="dialog">Log out</button></p>
<?php endif; ?>
<?= logout_modal() ?>
<?php auth_end();

<?php
require __DIR__ . '/inc/bootstrap.php';

/** Sends a signed-in user to their portal, or signs them out if their role has no portal yet. */
function go_home(array $user): void {
    $home = home_for($user);
    if ($home === 'login.php') {
        logout();
        flash('error', 'Your account type does not have a portal yet. Please contact the school administrator.');
        redirect('login.php');
    }
    redirect($user['must_change_password'] ? 'change_password.php' : $home);
}

if ($me = current_user()) go_home($me);

$email = '';
$locked = false;
if (is_post()) {
    verify_csrf();
    $email = (string)post('email', '');
    $result = attempt_login($email, (string)post('password', ''));
    if ($result['ok']) {
        go_home($result['user']);
    }
    $locked = !empty($result['locked']);
    flash('error', $result['error']);
}

auth_start('Sign in');
?>
<p class="auth-kicker">Welcome back</p>
<h1 class="auth-title">Sign in to your portal</h1>
<p class="auth-sub">Staff and families use the same sign-in.</p>

<?php auth_flashes(); ?>

<form method="post" action="login.php" novalidate>
    <?= csrf_field() ?>
    <div class="auth-field">
        <label for="email">E-mail</label>
        <input id="email" name="email" type="email" inputmode="email" autocomplete="username" autocapitalize="none" spellcheck="false"
               required placeholder="you@example.com" value="<?= e($email) ?>" <?= $email === '' ? 'autofocus' : '' ?>>
    </div>
    <div class="auth-field">
        <label for="password">Password</label>
        <div class="pw-wrap">
            <input id="password" name="password" type="password" autocomplete="current-password" required
                   placeholder="Enter your password" <?= $email !== '' ? 'autofocus' : '' ?>>
            <button type="button" class="pw-toggle" data-toggle-password="password" aria-label="Show password" aria-pressed="false" title="Show password">
                <svg class="pw-eye" viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>
                <svg class="pw-eye-off" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 3l18 18M10.6 5.1A10.4 10.4 0 0 1 12 5c6.4 0 10 7 10 7a17.6 17.6 0 0 1-3.2 4.1M6.6 6.6A17.4 17.4 0 0 0 2 12s3.6 7 10 7a9.7 9.7 0 0 0 5.4-1.6M9.9 9.9a3 3 0 0 0 4.2 4.2"/></svg>
            </button>
        </div>
    </div>
    <button class="auth-btn" type="submit"<?= $locked ? ' aria-describedby="lockNote"' : '' ?>>
        Sign in securely
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>
    </button>
    <p class="auth-secure" id="lockNote">
        <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg>
        Sign-in locks after <?= LOGIN_MAX_FAILS ?> wrong passwords in a row.
    </p>
</form>

<div class="auth-divider">New parent or guardian?</div>
<p class="auth-foot"><a href="register.php">Create a family account</a></p>
<p class="auth-foot auth-foot-small"><a href="index.php">Back to home</a></p>
<?php auth_end();

<?php
require __DIR__ . '/inc/bootstrap.php';

if ($me = current_user()) redirect(home_for($me));

$fields = ['first_name', 'last_name', 'email', 'phone', 'relationship', 'student_no', 'birthdate'];
$old = array_fill_keys($fields, '');

if (is_post()) {
    verify_csrf();
    foreach ($fields as $f) $old[$f] = trim((string)post($f, ''));
    $error = register_parent($old + [
        'password'         => (string)post('password', ''),
        'password_confirm' => (string)post('password_confirm', ''),
    ]);
    if ($error === null) {
        flash('success', "Your account is ready. You can log in now — your child's records will appear once the school verifies your link (usually within one school day).");
        redirect('login.php');
    }
    flash('error', $error);
}
$relationships = PARENT_RELATIONSHIPS;

auth_start('Create a family account', true);
?>
<p class="auth-kicker">Family portal</p>
<h1 class="auth-title">Create a family account</h1>
<p class="auth-sub">Stay connected with your child's clinic visits, guidance updates and commendations.</p>

<?php auth_flashes(); ?>

<form method="post" action="register.php" novalidate>
    <?= csrf_field() ?>
    <div class="auth-row">
        <div class="auth-field">
            <label for="first_name">First name</label>
            <input id="first_name" name="first_name" autocomplete="given-name" required value="<?= e($old['first_name']) ?>">
        </div>
        <div class="auth-field">
            <label for="last_name">Last name</label>
            <input id="last_name" name="last_name" autocomplete="family-name" required value="<?= e($old['last_name']) ?>">
        </div>
    </div>
    <div class="auth-row">
        <div class="auth-field">
            <label for="email">E-mail</label>
            <input id="email" name="email" type="email" autocomplete="email" required value="<?= e($old['email']) ?>">
        </div>
        <div class="auth-field">
            <label for="phone">Mobile number <span style="font-weight:400;color:var(--muted)">(optional)</span></label>
            <input id="phone" name="phone" type="tel" autocomplete="tel" value="<?= e($old['phone']) ?>">
        </div>
    </div>
    <div class="auth-row">
        <div class="auth-field">
            <label for="password">Password</label>
            <input id="password" name="password" type="password" autocomplete="new-password" required minlength="10">
            <span class="hint">At least 10 characters, with letters and numbers.</span>
        </div>
        <div class="auth-field">
            <label for="password_confirm">Confirm password</label>
            <input id="password_confirm" name="password_confirm" type="password" autocomplete="new-password" required>
        </div>
    </div>

    <div class="auth-section-label">Your child</div>
    <div class="auth-row">
        <div class="auth-field">
            <label for="student_no">Student ID</label>
            <input id="student_no" name="student_no" required placeholder="SV-2026-0000" value="<?= e($old['student_no']) ?>">
            <span class="hint">Found on your child's school ID or report card.</span>
        </div>
        <div class="auth-field">
            <label for="birthdate">Child's birthdate</label>
            <input id="birthdate" name="birthdate" type="date" required value="<?= e($old['birthdate']) ?>">
        </div>
    </div>
    <div class="auth-field">
        <label for="relationship">Your relationship to the student</label>
        <select id="relationship" name="relationship" required>
            <option value="">Choose one</option>
            <?php foreach ($relationships as $r): ?>
                <option <?= $old['relationship'] === $r ? 'selected' : '' ?>><?= e($r) ?></option>
            <?php endforeach; ?>
        </select>
        <span class="hint">Each student can have at most <?= MOTHER_LIMIT ?> Mother accounts.</span>
    </div>

    <button class="auth-btn" type="submit">Create account</button>

    <div class="auth-note">
        <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg>
        <span>The school verifies every family link before any records are shown. Some guidance and well-being records stay private unless your child chooses to share them — this helps students feel safe asking for support.</span>
    </div>
</form>

<div class="auth-divider">Already registered?</div>
<p class="auth-foot"><a href="login.php">Sign in instead</a></p>
<?php auth_end();

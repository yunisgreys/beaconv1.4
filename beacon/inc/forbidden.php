<?php
auth_start('Not available');
?>
<p class="auth-kicker">Access</p>
<h1 class="auth-title">This page isn't part of your workspace</h1>
<p class="auth-sub">Your account (<?= e($user['role_label'] ?? $user['role']) ?>) doesn't have access to it. If you think it should, please contact the school administrator.</p>
<a class="auth-btn" href="<?= e(home_for($user)) ?>">Back to my dashboard</a>
<?php auth_end();

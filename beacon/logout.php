<?php
require __DIR__ . '/inc/bootstrap.php';

if (is_post()) {
    verify_csrf();
    logout();
    flash('success', 'You have been logged out.');
}
redirect('login.php');

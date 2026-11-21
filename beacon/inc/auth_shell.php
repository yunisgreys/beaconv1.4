<?php

/** Footer line under the brand panel. Old "SVLC" taglines are cleaned automatically. */
function system_tagline(): string {
    $t = (string)(config('system_tagline') ?? '');
    $t = trim(preg_replace('/\s+/', ' ', str_ireplace('SVLC', '', $t)));
    return $t !== '' ? $t : 'Student Welfare & Care System';
}

function auth_start(string $pageTitle, bool $wide = false): void {
    $school = config('school_name') ?? 'Christ Life Academy';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> · BEACON</title>
    <link rel="icon" href="assets/img/tab-logo.svg">
    <link rel="stylesheet" href="./auth.css">
</head>
<body class="auth-page">
<div class="auth-split">
    <aside class="auth-brand" aria-label="About BEACON">
        <div class="auth-brand-inner">
            <p class="auth-eyebrow"><?= e($school) ?></p>
            <img class="auth-wordmark" src="assets/img/logo-wordmark-white-shadow.svg" alt="BEACON">
            <p class="auth-tagline">Every learner, known<br>and cared for.</p>
            <ul class="auth-chips">
                <li>
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3l7 3v5c0 4.5-3 8.3-7 10-4-1.7-7-5.5-7-10V6l7-3z"/></svg>
                    Secure &amp; confidential
                </li>
                <li>
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20s-7-4.4-7-10a4 4 0 0 1 7-2.6A4 4 0 0 1 19 10c0 5.6-7 10-7 10z"/></svg>
                    Student-centered
                </li>
            </ul>
        </div>
        <p class="auth-brand-foot"><?= e(system_tagline()) ?></p>
    </aside>

    <main class="auth-panel">
        <div class="auth-form<?= $wide ? ' wide' : '' ?>">
            <div class="auth-mobile-brand">
                <img class="auth-logo-mobile" src="assets/img/logo-wordmark.svg" alt="BEACON">
                <span class="auth-school-mobile"><?= e($school) ?></span>
            </div>
            <span class="auth-pill"><span class="dot" aria-hidden="true"></span>Academic Year <?= e(str_replace('-', '–', config('school_year') ?? '2026-2027')) ?> Active</span>
<?php
}

function auth_end(): void {
?>
        </div>
    </main>
</div>
<script src="assets/js/beacon.js"></script>
</body>
</html>
<?php
}

function auth_flashes(): void {
    foreach (flash_pull() as $f) {
        echo '<div class="auth-alert ' . e($f['type']) . '" role="alert">' . e($f['msg']) . "</div>\n";
    }
}

/**
 * The "Log out?" confirmation, used on every signed-in page.
 * Open it with any button that has data-open="modalLogout".
 * Cancel, the ✕, Escape or a click outside only closes it; nothing else happens.
 */
function logout_modal(): string {
    return '<div id="modalLogout" class="modal-overlay logout-modal" role="dialog" aria-modal="true" aria-labelledby="logoutTitle" aria-describedby="logoutText" aria-hidden="true">'
        . '<form class="modal-card" method="post" action="logout.php">'
        . csrf_field()
        . '<div class="modal-head">'
        .   '<div class="modal-icon ic-red"><svg class="ico" viewBox="0 0 24 24" aria-hidden="true"><path d="M15 4h3a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-3M10 16l4-4-4-4M14 12H4"/></svg></div>'
        .   '<div><h3 id="logoutTitle">Log out?</h3><p id="logoutText">You will need to sign in again to keep using BEACON.</p></div>'
        .   '<button type="button" class="modal-close" data-close aria-label="Close">✕</button>'
        . '</div>'
        . '<div class="modal-foot">'
        .   '<button type="button" class="btn btn-soft" data-close>Cancel</button>'
        .   '<button type="submit" class="btn btn-danger">Log out</button>'
        . '</div>'
        . '</form></div>';
}

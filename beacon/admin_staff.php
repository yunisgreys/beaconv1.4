<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
$user = require_page('admin_staff');

$reopen = null;
$qs = (string)($_SERVER['QUERY_STRING'] ?? '');
$back = 'admin_staff.php' . ($qs !== '' ? '?' . $qs : '');   // stay on the same page and filter after an action

if (is_post()) {
    verify_csrf();
    $action = (string)post('action');
    $id = (int)post('user_id', 0);

    if ($action === 'create') {
        [$newId, $temp, $err] = admin_create_staff($user, $_POST);
        if (!$err) {
            $_SESSION['new_credentials'] = ['name' => trim(post('first_name') . ' ' . post('last_name')), 'email' => normalize_email((string)post('email')), 'password' => $temp, 'kind' => 'created'];
            redirect('admin_staff.php');
        }
        flash('error', $err); $reopen = 'modalCreate';
    }
        if ($action === 'create_from_registry') {
        [$newId, $temp, $reg, $err] = admin_create_from_registry($user, (int)post('registry_id'));
        if (!$err) {
            $_SESSION['new_credentials'] = ['name' => $reg['first_name'] . ' ' . $reg['last_name'], 'email' => normalize_email($reg['email']), 'password' => $temp, 'kind' => 'created'];
            redirect('admin_staff.php');
        }
        flash('error', $err); $reopen = 'modalPendingStaff';
    }
    if ($action === 'credentials') {
        $err = admin_update_credentials($user, $id, $_POST);
        if (!$err) { flash('success', 'Sign-in e-mail updated. They use the new e-mail the next time they sign in.'); redirect($back); }
        flash('error', $err); $reopen = 'staff-' . $id;
    }
    if ($action === 'reset') {
        [$temp, $err] = admin_reset_password($user, $id);
        if ($err) { flash('error', $err); redirect($back); }
        $u = db_one('SELECT first_name, last_name, email FROM users WHERE id = ?', [$id]);
        $_SESSION['new_credentials'] = ['name' => $u['first_name'] . ' ' . $u['last_name'], 'email' => $u['email'], 'password' => $temp, 'kind' => 'reset'];
        redirect($back);
    }
    if ($action === 'unlock') {
        unlock_login($id, $user);
        flash('success', 'Account unlocked. They can sign in again.');
        redirect($back);
    }
    if ($action === 'status') {
        $err = admin_set_status($user, $id, (string)post('status'));
        flash($err ? 'error' : 'success', $err ?: (post('status') === 'disabled' ? 'Account disabled. They are signed out on their next click.' : 'Account enabled.'));
        redirect($back);
    }
    if ($action === 'pw_request') {
        $approve = post('decision') === 'approve';
        $err = review_password_change_request((int)post('request_id'), $approve, $user, (string)post('note', ''));
        flash($err ? 'error' : 'success', $err ?: ($approve ? 'Approved. They can change their password once.' : 'Request declined.'));
        redirect('admin_staff.php#pw-requests');
    }
}

$creds = $_SESSION['new_credentials'] ?? null;
unset($_SESSION['new_credentials']);

$fRole = isset(STAFF_ROLES[query('role', '')]) ? (string)query('role') : '';
$fQ    = trim((string)query('q', ''));
$all   = staff_accounts();
[, $total] = staff_accounts_page($fRole, $fQ, 1);
$pg    = paginate($total, STAFF_PER_PAGE);
[$shown] = staff_accounts_page($fRole, $fQ, $pg['page']);
$advisory = advisory_map();
$counts = array_count_values(array_column(array_filter($all, fn($s) => $s['status'] === 'active'), 'role'));
$lockedN = count(array_filter($all, fn($s) => is_locked($s)));
$requests = password_change_requests_list();
$pendingReq = count(array_filter($requests, fn($r) => $r['status'] === 'pending'));
$pendingStaff = pending_staff_registrations();   // v1.4: staff the Registrar registered

layout_start($user, 'staff', 'Staff accounts', 'Create staff logins and manage sign-in details. Names, job titles and sections are kept by the Registrar.');
?>

<?php if ($creds): ?>
<div class="card card-success">
    <div style="display: flex; gap: 14px; align-items: flex-start; flex-wrap: wrap;">
        <div class="modal-icon ic-green"><?= icon('key') ?></div>
        <div style="flex: 1; min-width: 260px;">
            <div class="card-title" style="font-size: 1rem;"><?= $creds['kind'] === 'created' ? 'Account created for ' : 'Password reset for ' ?><?= e($creds['name']) ?></div>
            <p class="muted" style="font-size: 0.82rem; margin: 4px 0 12px;">Give these to them privately. The password is shown <strong>only once</strong>, and they must change it when they first sign in.</p>
            <div class="form-grid" style="gap: 10px; max-width: 620px;">
                <div class="info-tile"><span class="k">E-mail</span><span class="v mono" id="credEmail"><?= e($creds['email']) ?></span></div>
                <div class="info-tile"><span class="k">Temporary password</span><span class="v mono" id="credPass"><?= e($creds['password']) ?></span></div>
            </div>
        </div>
        <button type="button" class="btn btn-primary btn-sm" id="copyCreds">Copy both</button>
    </div>
</div>
<?php endif; ?>

<div class="stat-grid-4">
    <?php foreach (['teacher' => ['users', 'ic-blue'], 'guidance' => ['shield', 'ic-violet'], 'clinic' => ['heart', 'ic-red'], 'registrar' => ['clipboard', 'ic-green']] as $r => [$ico, $cls]): ?>
        <a class="stat-card-box" href="admin_staff.php?role=<?= $r ?>"><div class="stat-icon-wrap <?= $cls ?>"><?= icon($ico) ?></div><div class="stat-info"><span class="stat-lbl"><?= e(STAFF_ROLES[$r]) ?></span><div class="stat-val"><?= $counts[$r] ?? 0 ?></div><span class="stat-note">active accounts</span></div></a>
    <?php endforeach; ?>
</div>

<?php if ($requests || $lockedN): ?>
<div class="card" id="pw-requests">
    <div class="card-head-flex">
        <div>
            <div class="card-title">Password change requests</div>
            <div class="card-sub">Users can change their own password <?= PASSWORD_CHANGE_LIMIT ?> times. After that they ask here. One approval allows one more change.<?= $lockedN ? ' ' . $lockedN . ' account' . ($lockedN === 1 ? ' is' : 's are') . ' locked after ' . LOGIN_MAX_FAILS . ' failed sign-ins (see "Locked" below).' : '' ?></div>
        </div>
        <?php if ($pendingReq): ?><span class="badge badge-amber"><?= $pendingReq ?> waiting</span><?php endif; ?>
    </div>
    <?php if (!$requests): ?>
        <div class="empty">No password change requests.</div>
    <?php else: ?>
    <div style="overflow-x: auto;">
    <table class="table">
        <thead><tr><th>Account</th><th>Role</th><th>Reason</th><th>Requested</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($requests as $r): ?>
            <tr>
                <td><strong><?= e($r['first_name'] . ' ' . $r['last_name']) ?></strong><div class="muted" style="font-size: 0.74rem;"><?= e($r['email']) ?></div></td>
                <td><?= role_pill($r['role'], $r['role_label']) ?></td>
                <td style="font-size: 0.84rem;"><?= e($r['reason'] ?: '—') ?></td>
                <td class="muted" style="white-space: nowrap; font-size: 0.8rem;"><?= e(date('M j, g:i A', strtotime($r['requested_at']))) ?></td>
                <td style="text-align: right;">
                    <?php if ($r['status'] === 'pending'): ?>
                    <div class="row-actions" style="justify-content: flex-end;">
                        <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="pw_request"><input type="hidden" name="request_id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="decision" value="approve"><button class="btn btn-primary btn-sm" type="submit">Approve</button></form>
                        <form method="post" class="inline-form" data-confirm="They will not be able to change their password until you approve a new request." data-confirm-title="Decline this request?" data-confirm-ok="Decline"><?= csrf_field() ?><input type="hidden" name="action" value="pw_request"><input type="hidden" name="request_id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="decision" value="reject"><button class="btn btn-soft btn-sm" type="submit">Decline</button></form>
                    </div>
                    <?php else: ?>
                        <?= badge(['approved' => 'Approved', 'rejected' => 'Declined', 'used' => 'Used'][$r['status']] ?? ucfirst($r['status']), ['approved' => 'green', 'rejected' => 'gray', 'used' => 'blue'][$r['status']] ?? 'gray') ?>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="card" id="staff">
    <div class="card-head-flex">
        <div><div class="card-title">Staff</div><div class="card-sub">You create Teacher, Guidance Office, Clinic and Registrar accounts. Parents register themselves.</div></div>
        <button type="button" class="btn btn-primary btn-sm" data-open="modalPendingStaff">+ Create staff account<?php if ($pendingStaff): ?> <span class="btn-count"><?= count($pendingStaff) ?></span><?php endif; ?></button>
    </div>
    <form class="staff-filters" method="get" action="admin_staff.php">
        <?php if ($fRole): ?><input type="hidden" name="role" value="<?= e($fRole) ?>"><?php endif; ?>
        <div class="chip-row">
            <?php foreach (['' => 'All'] + STAFF_ROLES as $k => $lbl): ?>
                <a class="chip<?= $fRole === $k ? ' active' : '' ?>" href="admin_staff.php<?= $k || $fQ !== '' ? '?' . http_build_query(array_filter(['role' => $k, 'q' => $fQ])) : '' ?>" style="text-decoration: none;"><?= e($lbl) ?></a>
            <?php endforeach; ?>
        </div>
        <div class="staff-search">
            <input type="search" name="q" value="<?= e($fQ) ?>" placeholder="Search name or e-mail" aria-label="Search staff">
            <button class="btn btn-soft btn-sm" type="submit">Search</button>
            <?php if ($fQ !== ''): ?><a class="card-link" href="admin_staff.php<?= $fRole ? '?role=' . e($fRole) : '' ?>">Clear</a><?php endif; ?>
        </div>
    </form>

    <?php if (!$shown): ?>
        <div class="empty">No staff accounts match.</div>
    <?php else: ?>
    <div style="overflow-x: auto;">
    <table class="table staff-table">
        <thead><tr><th>Name</th><th>Role</th><th>Advisory class</th><th>Status</th><th>Last sign-in</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($shown as $s): $sid = (int)$s['id']; $editable = isset(STAFF_ROLES[$s['role']]); $locked = is_locked($s); ?>
            <tr class="<?= $s['status'] === 'disabled' ? 'is-disabled' : '' ?>">
                <td><strong><?= e($s['first_name'] . ' ' . $s['last_name']) ?></strong><div class="muted" style="font-size: 0.74rem;"><?= e($s['email']) ?><?= $s['title'] ? ' · ' . e($s['title']) : '' ?></div></td>
                <td><?= role_pill($s['role'], $s['role_label']) ?></td>
                <td style="font-size: 0.82rem;"><?= $s['role'] === 'teacher' ? (e(implode(', ', $advisory[$sid] ?? [])) ?: '<span class="muted">Not assigned yet</span>') : '<span class="muted">—</span>' ?></td>
                <td>
                    <?php if ($s['status'] === 'disabled'): ?><?= badge('Disabled', 'gray') ?>
                    <?php elseif ($locked): ?><?= badge('Locked', 'red', 'Locked after ' . LOGIN_MAX_FAILS . ' failed sign-ins, until ' . date('g:i A', strtotime($s['locked_until']))) ?>
                    <?php elseif ($s['must_change_password']): ?><?= badge('Temporary password', 'amber') ?>
                    <?php else: ?><?= badge('Active', 'green') ?><?php endif; ?>
                    <?php if (is_online($s['last_seen_at'])): ?><div class="online-dot">● Online</div><?php endif; ?>
                </td>
                <td class="muted" style="font-size: 0.8rem; white-space: nowrap;"><?= $s['last_login_at'] ? e(date('M j, g:i A', strtotime($s['last_login_at']))) : 'Never' ?></td>
                <td>
                    <?php if ($editable): ?>
                    <div class="row-actions">
                        <button type="button" class="btn btn-soft btn-sm" data-open="staff-<?= $sid ?>">Edit login</button>
                        <?php if ($locked): ?>
                            <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="unlock"><input type="hidden" name="user_id" value="<?= $sid ?>"><button class="btn btn-primary btn-sm" type="submit">Unlock</button></form>
                        <?php endif; ?>
                        <form method="post" class="inline-form" data-confirm="A new temporary password will be shown once. Their current password stops working right away." data-confirm-title="Reset <?= e($s['first_name']) ?>'s password?" data-confirm-ok="Reset password"><?= csrf_field() ?><input type="hidden" name="action" value="reset"><input type="hidden" name="user_id" value="<?= $sid ?>"><button class="btn btn-soft btn-sm" type="submit">Reset password</button></form>
                        <form method="post" class="inline-form"<?= $s['status'] === 'active' ? ' data-confirm="They will be signed out and cannot sign in until you enable the account again." data-confirm-title="Disable ' . e($s['first_name'] . ' ' . $s['last_name']) . '?" data-confirm-ok="Disable"' : '' ?>><?= csrf_field() ?><input type="hidden" name="action" value="status"><input type="hidden" name="user_id" value="<?= $sid ?>"><input type="hidden" name="status" value="<?= $s['status'] === 'active' ? 'disabled' : 'active' ?>">
                            <button class="btn btn-sm <?= $s['status'] === 'active' ? 'btn-danger-soft' : 'btn-soft' ?>" type="submit"><?= $s['status'] === 'active' ? 'Disable' : 'Enable' ?></button></form>
                    </div>
                    <?php else: ?><span class="muted" style="font-size: 0.75rem;"><?= $sid === (int)$user['id'] ? 'You' : 'Protected' ?></span><?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?= pager($pg, 'page', 'staff') ?>
    <?php endif; ?>
</div>

<<div id="modalPendingStaff" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="psTitle" aria-hidden="true" <?= $reopen === 'modalPendingStaff' ? 'data-autoopen' : '' ?>>
    <div class="modal-card wide">
        <div class="modal-head">
            <div class="modal-icon ic-blue"><?= icon('users') ?></div>
            <div><h3 id="psTitle">Create staff account</h3><p>Staff the Registrar registered and who are waiting for their BEACON account.</p></div>
            <button type="button" class="modal-close" data-close aria-label="Close">✕</button>
        </div>
        <div class="modal-body">
            <?php if (!$pendingStaff): ?>
                <div class="empty">Nobody is waiting. When the Registrar registers new school staff, they appear here.</div>
            <?php else: ?>
            <div class="pending-staff-list">
                <?php foreach ($pendingStaff as $p): ?>
                <div class="pending-staff">
                    <div class="pending-staff-main">
                        <div class="pending-staff-name"><strong><?= e($p['first_name'] . ' ' . $p['last_name']) ?></strong> <?= role_pill($p['role_code'], STAFF_REGISTRY_ROLES[$p['role_code']] ?? $p['role_code']) ?></div>
                        <div class="pending-staff-meta"><?= e($p['email']) ?><?= $p['title'] ? ' · ' . e($p['title']) : '' ?><?= $p['phone'] ? ' · ' . e($p['phone']) : '' ?><?= $p['employee_no'] ? ' · ' . e($p['employee_no']) : '' ?></div>
                        <div class="pending-staff-meta">Registered <?= e(date('M j, Y', strtotime($p['created_at']))) ?><?= $p['by_first'] ? ' by ' . e($p['by_first'] . ' ' . $p['by_last']) : '' ?></div>
                    </div>
                    <form method="post" action="admin_staff.php" data-confirm-title="Create this account?" data-confirm-ok="Create account"
                          data-confirm="A <?= e(STAFF_ROLES[$p['role_code']] ?? '') ?> account will be created for <?= e($p['first_name'] . ' ' . $p['last_name']) ?> (<?= e($p['email']) ?>) with a temporary password.">
                        <?= csrf_field() ?><input type="hidden" name="action" value="create_from_registry"><input type="hidden" name="registry_id" value="<?= (int)$p['id'] ?>">
                        <button type="submit" class="btn btn-primary btn-sm">Create account</button>
                    </form>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        <div class="modal-foot" style="align-items: center;">
            <span class="muted" style="font-size: 0.78rem; margin-right: auto;">Someone not on the list?</span>
            <button type="button" class="btn btn-soft" data-close data-open="modalCreate">Enter details manually</button>
        </div>
    </div>
</div>

<?php $cv = $reopen === 'modalCreate' ? $_POST : ['role' => 'teacher']; ?>
<div id="modalCreate" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="crTitle" aria-hidden="true" <?= $reopen === 'modalCreate' ? 'data-autoopen' : '' ?>>
    <form class="modal-card" method="post" action="admin_staff.php" novalidate>
        <?= csrf_field() ?><input type="hidden" name="action" value="create">
        <div class="modal-head">
            <div class="modal-icon ic-blue"><?= icon('key') ?></div>
            <div><h3 id="crTitle">Create a staff account</h3><p>All fields are required. A temporary password is generated, and they set their own at first sign-in.</p></div>
            <button type="button" class="modal-close" data-close aria-label="Close">✕</button>
        </div>
        <div class="modal-body">
            <div class="form-grid">
                <div class="field span-2">
                    <span class="field-label">Role</span>
                    <div class="choice-row" style="grid-template-columns: repeat(2, 1fr);">
                        <?php foreach (STAFF_ROLES as $k => $lbl): ?>
                            <label class="choice"><input type="radio" name="role" value="<?= $k ?>" <?= ($cv['role'] ?? 'teacher') === $k ? 'checked' : '' ?> required><span><?= e($lbl) ?></span></label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="field"><label for="cr_first">First name</label><input id="cr_first" name="first_name" maxlength="80" value="<?= e($cv['first_name'] ?? '') ?>" required></div>
                <div class="field"><label for="cr_last">Last name</label><input id="cr_last" name="last_name" maxlength="80" value="<?= e($cv['last_name'] ?? '') ?>" required></div>
                <div class="field span-2"><label for="cr_email">School e-mail <span class="muted" style="font-weight: 500;">(their username)</span></label><input id="cr_email" name="email" type="email" maxlength="190" value="<?= e($cv['email'] ?? '') ?>" required></div>
                <div class="field"><label for="cr_title">Job title</label><input id="cr_title" name="title" maxlength="100" placeholder="e.g. Teacher · Adviser" value="<?= e($cv['title'] ?? '') ?>" required></div>
                <div class="field"><label for="cr_phone">Phone</label><input id="cr_phone" name="phone" type="tel" maxlength="30" placeholder="e.g. 0917 123 4567" value="<?= e($cv['phone'] ?? '') ?>" required></div>
            </div>
            <div class="notice notice-info" style="margin-top: 14px;"><?= icon('clipboard') ?><span>A teacher's advisory class is assigned by the Registrar after the account is created.</span></div>
        </div>
        <div class="modal-foot"><button type="button" class="btn btn-soft" data-close>Cancel</button><button type="submit" class="btn btn-primary">Create account</button></div>
    </form>
</div>

<?php foreach ($shown as $s): if (!isset(STAFF_ROLES[$s['role']])) continue; $sid = (int)$s['id'];
    $email = $reopen === "staff-$sid" ? (string)post('email', '') : $s['email']; ?>
<div id="staff-<?= $sid ?>" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="ed-<?= $sid ?>" aria-hidden="true" <?= $reopen === "staff-$sid" ? 'data-autoopen' : '' ?>>
    <form class="modal-card" method="post" action="<?= e($back) ?>" novalidate>
        <?= csrf_field() ?><input type="hidden" name="action" value="credentials"><input type="hidden" name="user_id" value="<?= $sid ?>">
        <div class="modal-head">
            <div class="modal-icon ic-blue"><?= icon('key') ?></div>
            <div><h3 id="ed-<?= $sid ?>">Login details for <?= e($s['first_name'] . ' ' . $s['last_name']) ?></h3><p>You can change how they sign in. Their name, job title and section are kept by the Registrar.</p></div>
            <button type="button" class="modal-close" data-close aria-label="Close">✕</button>
        </div>
        <div class="modal-body">
            <div class="form-grid">
                <div class="field span-2"><label for="ed<?= $sid ?>_email">Sign-in e-mail</label><input id="ed<?= $sid ?>_email" name="email" type="email" maxlength="190" value="<?= e($email) ?>" required></div>
                <div class="info-tile"><span class="k">Name</span><span class="v"><?= e($s['first_name'] . ' ' . $s['last_name']) ?></span></div>
                <div class="info-tile"><span class="k">Role</span><span class="v"><?= e($s['role_label']) ?></span></div>
                <div class="info-tile span-2"><span class="k">Password</span><span class="v" style="font-weight: 500;">Use <strong>Reset password</strong> in the list to give them a new temporary password.
                    Changes used: <?= (int)$s['password_change_count'] ?> of <?= PASSWORD_CHANGE_LIMIT ?>.</span></div>
            </div>
        </div>
        <div class="modal-foot"><button type="button" class="btn btn-soft" data-close>Cancel</button><button type="submit" class="btn btn-primary">Save e-mail</button></div>
    </form>
</div>
<?php endforeach; ?>

<style>
    .staff-filters { display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; margin-bottom: 14px; }
    .staff-filters .chip-row { margin: 0; }
    .staff-search { display: flex; align-items: center; gap: 8px; }
    .staff-search input { width: 240px; height: 36px; padding: 0 12px; border: 1px solid var(--border); border-radius: 9px; font: inherit; font-size: 0.84rem; }
    .staff-table tr.is-disabled td { opacity: .55; }
    .staff-table .row-actions { flex-wrap: nowrap; justify-content: flex-end; }
    .staff-table td:last-child { width: 1%; }
    .staff-table td:nth-child(3) { min-width: 120px; }
    .online-dot { font-size: 0.7rem; color: #15803d; font-weight: 700; margin-top: 3px; }
    .role-super_admin .staff-search input { background: #121b2c; border-color: #263248; color: #e5e9f2; }
    .role-super_admin .online-dot { color: #4ade80; }
</style>
<script>
(function () {
    var copy = document.getElementById('copyCreds');
    if (copy) copy.addEventListener('click', function () {
        var text = 'BEACON sign-in\nE-mail: ' + document.getElementById('credEmail').textContent + '\nTemporary password: ' + document.getElementById('credPass').textContent;
        (navigator.clipboard ? navigator.clipboard.writeText(text) : Promise.reject()).then(function () { copy.textContent = 'Copied'; }, function () { copy.textContent = 'Select and copy manually'; });
    });
})();
</script>

<?php layout_end(); ?>

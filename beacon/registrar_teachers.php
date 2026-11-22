<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
$user = require_page('registrar_teachers');

$reopen = null;
$qs = (string)($_SERVER['QUERY_STRING'] ?? '');
$back = 'registrar_teachers.php' . ($qs !== '' ? '?' . $qs : '');

if (is_post()) {
    verify_csrf();
    // v1.4: School Staff registration (the Super Admin then creates the sign-in account)
    if (post('action') === 'register_staff') {
        [, $err] = registrar_register_staff($user, $_POST);
        if (!$err) { flash('success', trim(post('first_name') . ' ' . post('last_name')) . ' is registered. The Super Admin will see them under "Create staff account".'); redirect('registrar_teachers.php#staff'); }
        flash('error', $err); $reopen = 'modalRegisterStaff';
    }
    if (post('action') === 'update_staff') {
        $rid = (int)post('registry_id');
        $err = registrar_update_staff_entry($user, $rid, $_POST);
        if (!$err) { flash('success', 'Staff registration saved.'); redirect($back . '#staff'); }
        flash('error', $err); $reopen = 'reg-' . $rid;
    }
    if (post('action') === 'cancel_staff') {
        $err = registrar_cancel_staff_entry($user, (int)post('registry_id'));
        flash($err ? 'error' : 'success', $err ?: 'Registration cancelled. No account will be created for it.');
        redirect($back . '#staff');
    }
    if (post('action') === 'update') {
        $tid = (int)post('teacher_id');
        $err = registrar_update_teacher($user, $tid, $_POST);
        if (!$err) { flash('success', 'Teacher information saved.'); redirect($back); }
        flash('error', $err); $reopen = 'tch-' . $tid;
    }
}

// v1.4: registered school staff (own filters, so they don't clash with the teacher search)
$sQ      = trim((string)query('sq', ''));
$sRole   = isset(STAFF_REGISTRY_ROLES[query('srole', '')]) ? (string)query('srole') : '';
$sStatus = isset(STAFF_REGISTRY_STATUS[query('sstatus', '')]) || query('sstatus') === 'all' ? (string)query('sstatus') : 'all';
[, $sTotal] = staff_registry_page($sQ, $sRole, $sStatus, 1, 1);
$spg = paginate($sTotal, REG_PER_PAGE, 'spage');
[$staffRows] = staff_registry_page($sQ, $sRole, $sStatus, $spg['page']);
$regCounts = db_one("SELECT SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending,
                            SUM(CASE WHEN status = 'created' THEN 1 ELSE 0 END) AS created FROM staff_registry");

$fQ = trim((string)query('q', ''));
[, $total] = registrar_teachers_page($fQ, 1, 1);
$pg = paginate($total, REG_PER_PAGE);
[$rows] = registrar_teachers_page($fQ, $pg['page']);

$sections = registrar_sections();
$byId = [];
foreach ($sections as $s) $byId[(int)$s['id']] = $s;
$advisoryOpts = ['' => 'No advisory class'];
foreach ($sections as $s) {
    if ($s['school_year'] !== current_school_year()) continue;
    $advisoryOpts[$s['id']] = section_label($s) . ($s['adv_first'] ? ' (now: ' . $s['adv_first'] . ' ' . $s['adv_last'] . ')' : '');
}
$noAdviser = count(array_filter($sections, fn($s) => !$s['adviser_id'] && $s['school_year'] === current_school_year()));
$teacherN  = (int)db_value("SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id WHERE r.code = 'teacher' AND u.status = 'active'");
$advisers  = (int)db_value('SELECT COUNT(DISTINCT adviser_id) FROM sections WHERE adviser_id IS NOT NULL AND school_year = ?', [current_school_year()]);

layout_start($user, 'reg_teachers', 'School Staff', 'Register teachers, clinic, guidance and registrar staff, and set each adviser\'s class. The Super Admin creates their sign-in accounts.');
?>

<div class="stat-grid-4">
    <a class="stat-card-box" href="registrar_teachers.php?sstatus=pending#staff"><div class="stat-icon-wrap ic-violet"><?= icon('bell') ?></div><div class="stat-info"><span class="stat-lbl">Waiting for an account</span><div class="stat-val"><?= (int)$regCounts['pending'] ?></div><span class="stat-note <?= $regCounts['pending'] ? 'note-amber' : '' ?>"><?= $regCounts['pending'] ? 'sent to the Super Admin' : 'nobody waiting' ?></span></div></a>
    <div class="stat-card-box"><div class="stat-icon-wrap ic-blue"><?= icon('users') ?></div><div class="stat-info"><span class="stat-lbl">Active teachers</span>
    <div class="stat-card-box"><div class="stat-icon-wrap ic-green"><?= icon('grid') ?></div><div class="stat-info"><span class="stat-lbl">Class advisers</span><div class="stat-val"><?= $advisers ?></div><span class="stat-note"><?= e(current_school_year()) ?></span></div></div>
    <a class="stat-card-box" href="registrar_sections.php"><div class="stat-icon-wrap ic-amber"><?= icon('clipboard') ?></div><div class="stat-info"><span class="stat-lbl">Sections without an adviser</span><div class="stat-val"><?= $noAdviser ?></div><span class="stat-note <?= $noAdviser ? 'note-amber' : '' ?>"><?= $noAdviser ? 'Assign an adviser' : 'All covered' ?></span></div></a>
</div>

<div class="card" id="staff">
    <div class="card-head-flex">
        <div><div class="card-title">Registered school staff</div><div class="card-sub">New staff wait here until the Super Admin creates their BEACON account. You can edit or cancel a registration until then.</div></div>
        <button type="button" class="btn btn-primary btn-sm" data-open="modalRegisterStaff">+ Register staff</button>
    </div>
    <form class="reg-filters" method="get" action="registrar_teachers.php#staff" data-auto-filter>
        <?php if ($fQ !== ''): ?><input type="hidden" name="q" value="<?= e($fQ) ?>"><?php endif; ?>
        <div class="field" style="flex: 1; min-width: 240px;"><label for="s_q">Search</label><input id="s_q" type="search" name="sq" value="<?= e($sQ) ?>" placeholder="Name, e-mail or employee no."></div>
        <div class="field"><label for="s_role">Role</label><select id="s_role" name="srole"><?= opts(['' => 'All roles'] + STAFF_REGISTRY_ROLES, $sRole) ?></select></div>
        <div class="field"><label for="s_status">Status</label><select id="s_status" name="sstatus"><?= opts(['all' => 'All statuses'] + array_map(fn($x) => $x[0], STAFF_REGISTRY_STATUS), $sStatus) ?></select></div>
    </form>
    <?php if (!$staffRows): ?>
        <div class="empty"><?= $sQ !== '' || $sRole !== '' || $sStatus !== 'all' ? 'No registrations match.' : 'No staff registered yet. Use <strong>+ Register staff</strong> to add someone.' ?></div>
    <?php else: ?>
    <table class="table">
        <thead><tr><th>Staff member</th><th>Role</th><th>Job title</th><th>Registered</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($staffRows as $r): $rid = (int)$r['id']; [$stLbl, $stTone] = STAFF_REGISTRY_STATUS[$r['status']]; ?>
            <tr style="<?= $r['status'] === 'cancelled' ? 'opacity: .55;' : '' ?>">
                <td><strong><?= e($r['last_name'] . ', ' . $r['first_name']) ?></strong>
                    <div class="muted" style="font-size: 0.74rem;"><?= e($r['email']) ?><?= $r['employee_no'] ? ' · ' . e($r['employee_no']) : '' ?><?= $r['phone'] ? ' · ' . e($r['phone']) : '' ?></div></td>
                <td><?= role_pill($r['role_code'], STAFF_REGISTRY_ROLES[$r['role_code']]) ?></td>
                <td style="font-size: 0.84rem;"><?= e($r['title'] ?: '—') ?></td>
                <td class="muted" style="font-size: 0.8rem; white-space: nowrap;"><?= e(date('M j, Y', strtotime($r['created_at']))) ?><?= $r['by_first'] ? '<div style="font-size: 0.72rem;">by ' . e($r['by_first'] . ' ' . $r['by_last']) . '</div>' : '' ?></td>
                <td><?= badge($stLbl, $stTone) ?><?= $r['status'] === 'created' && $r['account_created_at'] ? '<div class="muted" style="font-size: 0.7rem; margin-top: 3px;">' . e(date('M j, Y', strtotime($r['account_created_at']))) . '</div>' : '' ?></td>
                <td style="text-align: right;">
                    <?php if ($r['status'] === 'pending'): ?>
                    <div class="row-actions">
                        <button type="button" class="btn btn-soft btn-sm" data-open="reg-<?= $rid ?>">Edit</button>
                        <form method="post" action="<?= e($back) ?>" class="inline-form" data-confirm-title="Cancel this registration?" data-confirm-ok="Cancel registration"
                              data-confirm="<?= e($r['first_name'] . ' ' . $r['last_name']) ?> will be removed from the Super Admin's list, and no account will be created.">
                            <?= csrf_field() ?><input type="hidden" name="action" value="cancel_staff"><input type="hidden" name="registry_id" value="<?= $rid ?>">
                            <button type="submit" class="btn btn-danger-soft btn-sm">Cancel</button>
                        </form>
                    </div>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?= pager($spg, 'spage', 'staff') ?>
    <?php endif; ?>
</div>

<div class="card" id="teachers">
    <div class="card-head-flex">
        <div><div class="card-title">Teachers &amp; advisory classes</div><div class="card-sub">Teachers who already have an account. A teacher advises one section per school year, and advisers see only the students in their advisory class.</div></div>
    </div>
        <form class="reg-filters" method="get" action="registrar_teachers.php#teachers" data-auto-filter>
        <?php foreach (['sq' => $sQ, 'srole' => $sRole, 'sstatus' => $sStatus === 'all' ? '' : $sStatus] as $k => $val): if ($val !== '') : ?><input type="hidden" name="<?= $k ?>" value="<?= e($val) ?>"><?php endif; endforeach; ?>
        <div class="field" style="flex: 1; min-width: 240px;"><label for="f_q">Search</label><input id="f_q" type="search" name="q" value="<?= e($fQ) ?>" placeholder="Name or e-mail"></div>
        <?php if ($fQ !== ''): ?><a class="card-link" href="registrar_teachers.php" style="padding-bottom: 10px;">Clear</a><?php endif; ?>
    </form>

    <?php if (!$rows): ?>
        <div class="empty">No teachers found. Register new teachers above; the Admin then creates their accounts.</div>
    <?php else: ?>
    <div style="overflow-x: auto;">
    <table class="table">
        <thead><tr><th>Teacher</th><th>Job title</th><th>Phone</th><th>Advisory class</th><th>Students</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $t): $tid = (int)$t['id']; $adv = $t['advisory_id'] ? ($byId[(int)$t['advisory_id']] ?? null) : null; ?>
            <tr style="<?= $t['status'] === 'disabled' ? 'opacity: .55;' : '' ?>">
                <td><strong><?= e($t['last_name'] . ', ' . $t['first_name']) ?></strong><div class="muted" style="font-size: 0.74rem;"><?= e($t['email']) ?><?= $t['status'] === 'disabled' ? ' · account disabled' : '' ?></div></td>
                <td style="font-size: 0.84rem;"><?= e($t['title'] ?: '—') ?></td>
                <td class="muted" style="font-size: 0.84rem; white-space: nowrap;"><?= e($t['phone'] ?: '—') ?></td>
                <td style="white-space: nowrap;"><?= $adv ? e(section_label($adv)) : '<span class="badge badge-gray">None</span>' ?></td>
                <td><?= $adv ? (int)$adv['enrolled'] : '<span class="muted">—</span>' ?></td>
                <td style="text-align: right;"><button type="button" class="btn btn-soft btn-sm" data-open="tch-<?= $tid ?>">Edit</button></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?= pager($pg, 'page', 'teachers') ?>
    <?php endif; ?>
</div>

<?php foreach ($rows as $t): $tid = (int)$t['id']; $v = $reopen === "tch-$tid" ? $_POST : $t; ?>
<div id="tch-<?= $tid ?>" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="tt-<?= $tid ?>" aria-hidden="true" <?= $reopen === "tch-$tid" ? 'data-autoopen' : '' ?>>
    <form class="modal-card" method="post" action="<?= e($back) ?>" novalidate>
        <?= csrf_field() ?><input type="hidden" name="action" value="update"><input type="hidden" name="teacher_id" value="<?= $tid ?>">
        <div class="modal-head">
            <div class="modal-icon ic-blue"><?= icon('clipboard') ?></div>
            <div><h3 id="tt-<?= $tid ?>">Edit <?= e($t['first_name'] . ' ' . $t['last_name']) ?></h3><p>Sign-in e-mail: <?= e($t['email']) ?> (changed by the Super Admin).</p></div>
            <button type="button" class="modal-close" data-close aria-label="Close">✕</button>
        </div>
        <div class="modal-body">
            <div class="form-grid">
                <div class="field"><label for="t<?= $tid ?>_first">First name</label><input id="t<?= $tid ?>_first" name="first_name" maxlength="80" value="<?= e($v['first_name'] ?? '') ?>" required></div>
                <div class="field"><label for="t<?= $tid ?>_last">Last name</label><input id="t<?= $tid ?>_last" name="last_name" maxlength="80" value="<?= e($v['last_name'] ?? '') ?>" required></div>
                <div class="field"><label for="t<?= $tid ?>_title">Job title</label><input id="t<?= $tid ?>_title" name="title" maxlength="100" placeholder="e.g. Teacher · Adviser" value="<?= e($v['title'] ?? '') ?>"></div>
                <div class="field"><label for="t<?= $tid ?>_phone">Phone</label><input id="t<?= $tid ?>_phone" name="phone" type="tel" maxlength="30" value="<?= e($v['phone'] ?? '') ?>"></div>
                <div class="field span-2">
                    <label for="t<?= $tid ?>_adv">Advisory class (<?= e(current_school_year()) ?>)</label>
                    <select id="t<?= $tid ?>_adv" name="advisory_id"><?= opts($advisoryOpts, $v['advisory_id'] ?? '') ?></select>
                    <span class="hint">Picking a class that already has an adviser moves it to this teacher. Their previous advisory class is released.</span>
                </div>
            </div>
        </div>
        <div class="modal-foot"><button type="button" class="btn btn-soft" data-close>Cancel</button><button type="submit" class="btn btn-primary">Save changes</button></div>
    </form>
</div>
<?php endforeach; ?>

<?php
/** v1.4: the School Staff registration form (new and edit). */
function staff_registry_form(array $v, string $p): void { ?>
    <div class="form-grid">
        <div class="field span-2"><label for="<?= $p ?>_role">Role</label><select id="<?= $p ?>_role" name="role_code" required><?= opts(['' => 'Choose a role…'] + STAFF_REGISTRY_ROLES, $v['role_code'] ?? '') ?></select></div>
        <div class="field"><label for="<?= $p ?>_first">First name</label><input id="<?= $p ?>_first" name="first_name" maxlength="80" value="<?= e($v['first_name'] ?? '') ?>" required></div>
        <div class="field"><label for="<?= $p ?>_last">Last name</label><input id="<?= $p ?>_last" name="last_name" maxlength="80" value="<?= e($v['last_name'] ?? '') ?>" required></div>
        <div class="field span-2"><label for="<?= $p ?>_email">E-mail</label><input id="<?= $p ?>_email" name="email" type="email" maxlength="190" autocomplete="off" value="<?= e($v['email'] ?? '') ?>" required>
            <span class="hint">This becomes their sign-in e-mail once the Super Admin creates the account.</span></div>
        <div class="field"><label for="<?= $p ?>_title">Job title <span class="muted" style="font-weight: 500;">(optional)</span></label><input id="<?= $p ?>_title" name="title" maxlength="100" placeholder="e.g. Grade 8 Adviser, School Nurse" value="<?= e($v['title'] ?? '') ?>"></div>
        <div class="field"><label for="<?= $p ?>_phone">Phone <span class="muted" style="font-weight: 500;">(optional)</span></label><input id="<?= $p ?>_phone" name="phone" type="tel" maxlength="30" value="<?= e($v['phone'] ?? '') ?>"></div>
        <div class="field span-2"><label for="<?= $p ?>_emp">Employee number <span class="muted" style="font-weight: 500;">(optional)</span></label><input id="<?= $p ?>_emp" name="employee_no" class="mono" maxlength="30" value="<?= e($v['employee_no'] ?? '') ?>"></div>
    </div>
<?php } ?>

<div id="modalRegisterStaff" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="rsTitle" aria-hidden="true" <?= $reopen === 'modalRegisterStaff' ? 'data-autoopen' : '' ?>>
    <form class="modal-card" method="post" action="registrar_teachers.php" novalidate>
        <?= csrf_field() ?><input type="hidden" name="action" value="register_staff">
        <div class="modal-head">
            <div class="modal-icon ic-violet"><?= icon('users') ?></div>
            <div><h3 id="rsTitle">Register school staff</h3><p>The Super Admin is notified and creates their BEACON account.</p></div>
            <button type="button" class="modal-close" data-close aria-label="Close">✕</button>
        </div>
        <div class="modal-body"><?php staff_registry_form($reopen === 'modalRegisterStaff' ? $_POST : [], 'rs'); ?></div>
        <div class="modal-foot"><button type="button" class="btn btn-soft" data-close>Cancel</button><button type="submit" class="btn btn-primary">Register</button></div>
    </form>
</div>

<?php foreach ($staffRows as $r): if ($r['status'] !== 'pending') continue; $rid = (int)$r['id']; ?>
<div id="reg-<?= $rid ?>" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="rt-<?= $rid ?>" aria-hidden="true" <?= $reopen === "reg-$rid" ? 'data-autoopen' : '' ?>>
    <form class="modal-card" method="post" action="<?= e($back) ?>" novalidate>
        <?= csrf_field() ?><input type="hidden" name="action" value="update_staff"><input type="hidden" name="registry_id" value="<?= $rid ?>">
        <div class="modal-head">
            <div class="modal-icon ic-violet"><?= icon('users') ?></div>
            <div><h3 id="rt-<?= $rid ?>">Edit <?= e($r['first_name'] . ' ' . $r['last_name']) ?></h3><p>Still waiting for the Super Admin to create the account.</p></div>
            <button type="button" class="modal-close" data-close aria-label="Close">✕</button>
        </div>
        <div class="modal-body"><?php staff_registry_form($reopen === "reg-$rid" ? $_POST : $r, 'r' . $rid); ?></div>
        <div class="modal-foot"><button type="button" class="btn btn-soft" data-close>Cancel</button><button type="submit" class="btn btn-primary">Save changes</button></div>
    </form>
</div>
<?php endforeach; ?>

<?php layout_end(); ?>

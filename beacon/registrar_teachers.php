<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
$user = require_page('registrar_teachers');

$reopen = null;
$qs = (string)($_SERVER['QUERY_STRING'] ?? '');
$back = 'registrar_teachers.php' . ($qs !== '' ? '?' . $qs : '');

if (is_post()) {
    verify_csrf();
    if (post('action') === 'update') {
        $tid = (int)post('teacher_id');
        $err = registrar_update_teacher($user, $tid, $_POST);
        if (!$err) { flash('success', 'Teacher information saved.'); redirect($back); }
        flash('error', $err); $reopen = 'tch-' . $tid;
    }
}

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

layout_start($user, 'reg_teachers', 'Teachers', 'Update teacher information and set each adviser\'s class. Sign-in details are managed by the Super Admin.');
?>

<div class="stat-grid-4">
    <div class="stat-card-box"><div class="stat-icon-wrap ic-blue"><?= icon('users') ?></div><div class="stat-info"><span class="stat-lbl">Active teachers</span><div class="stat-val"><?= $teacherN ?></div><span class="stat-note">accounts created by the Super Admin</span></div></div>
    <div class="stat-card-box"><div class="stat-icon-wrap ic-green"><?= icon('grid') ?></div><div class="stat-info"><span class="stat-lbl">Class advisers</span><div class="stat-val"><?= $advisers ?></div><span class="stat-note"><?= e(current_school_year()) ?></span></div></div>
    <a class="stat-card-box" href="registrar_sections.php"><div class="stat-icon-wrap ic-amber"><?= icon('clipboard') ?></div><div class="stat-info"><span class="stat-lbl">Sections without an adviser</span><div class="stat-val"><?= $noAdviser ?></div><span class="stat-note <?= $noAdviser ? 'note-amber' : '' ?>"><?= $noAdviser ? 'Assign an adviser' : 'All covered' ?></span></div></a>
</div>

<div class="card" id="teachers">
    <div class="card-head-flex">
        <div><div class="card-title">Teachers</div><div class="card-sub">A teacher advises one section per school year. Advisers see only the students in their advisory class.</div></div>
    </div>
    <form class="reg-filters" method="get" action="registrar_teachers.php">
        <div class="field" style="flex: 1; min-width: 240px;"><label for="f_q">Search</label><input id="f_q" type="search" name="q" value="<?= e($fQ) ?>" placeholder="Name or e-mail"></div>
        <button class="btn btn-soft" type="submit">Search</button>
        <?php if ($fQ !== ''): ?><a class="card-link" href="registrar_teachers.php" style="padding-bottom: 10px;">Clear</a><?php endif; ?>
    </form>

    <?php if (!$rows): ?>
        <div class="empty">No teachers found. Teacher accounts are created by the Super Admin.</div>
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

<?php layout_end(); ?>

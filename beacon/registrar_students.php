<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
$user = require_page('registrar_students');

$reopen = null;
$qs = (string)($_SERVER['QUERY_STRING'] ?? '');
$back = 'registrar_students.php' . ($qs !== '' ? '?' . $qs : '');

if (is_post()) {
    verify_csrf();
    $action = (string)post('action');
    if ($action === 'create') {
        [$newId, $err] = registrar_create_student($user, $_POST);
        if (!$err) { flash('success', 'Student added: ' . trim(post('first_name') . ' ' . post('last_name')) . '.'); redirect('registrar_students.php?q=' . urlencode(strtoupper(trim((string)post('student_no'))))); }
        flash('error', $err); $reopen = 'modalAddStudent';
    }
    if ($action === 'update') {
        $sid = (int)post('student_id');
        $err = registrar_update_student($user, $sid, $_POST);
        if (!$err) { flash('success', 'Student information saved.'); redirect($back); }
        flash('error', $err); $reopen = 'stu-' . $sid;
    }
    if ($action === 'assign') {
        $target = (string)post('target_section', '');
        [$moved, $err] = $target === ''
            ? [0, 'Choose the section to move them to.']
            : registrar_assign_students($user, (array)($_POST['ids'] ?? []), $target === 'none' ? null : (int)$target);
        if ($err) { flash('error', $err); }
        else { flash('success', $moved ? "$moved student" . ($moved === 1 ? '' : 's') . ' moved. Their advisers see the change right away.' : 'Those students were already in that section.'); }
        redirect($back);
    }
}

$fQ       = trim((string)query('q', ''));
$fSection = (string)query('section', '');
$fStatus  = isset(STUDENT_STATUSES[query('status', '')]) || query('status') === 'all' ? (string)query('status') : 'enrolled';
[, $total] = registrar_students_page($fQ, $fSection, $fStatus === 'all' ? '' : $fStatus, 1, 1);
$pg = paginate($total, REG_PER_PAGE);
[$rows] = registrar_students_page($fQ, $fSection, $fStatus === 'all' ? '' : $fStatus, $pg['page']);

$sectionMap = section_choice_map();
$sectionFilter = ['' => 'All sections', 'none' => 'No section yet'] + array_slice($sectionMap, 1, null, true);
$moveOpts = ['' => 'Choose a section…'] + array_slice($sectionMap, 1, null, true) + ['none' => 'Remove from section'];
$statusFilter = STUDENT_STATUSES + ['all' => 'All statuses'];
$statusTone = ['enrolled' => 'green', 'transferred' => 'blue', 'graduated' => 'purple', 'inactive' => 'gray'];
$counts = db_one("SELECT SUM(CASE WHEN status = 'enrolled' THEN 1 ELSE 0 END) AS enrolled,
                         SUM(CASE WHEN status = 'enrolled' AND section_id IS NULL THEN 1 ELSE 0 END) AS unassigned,
                         SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) AS recent FROM students", [date('Y-m-d 00:00:00', strtotime('-30 days'))]);

function student_form(array $v, array $sectionMap, string $p): void { ?>
    <div class="form-grid">
        <div class="form-section-label">Student</div>
        <div class="field"><label for="<?= $p ?>_no">Student ID</label><input id="<?= $p ?>_no" name="student_no" class="mono" maxlength="20" placeholder="SV-2026-0001" value="<?= e($v['student_no'] ?? '') ?>" required></div>
        <div class="field"><label for="<?= $p ?>_lrn">LRN <span class="muted" style="font-weight: 500;">(12 digits, optional)</span></label><input id="<?= $p ?>_lrn" name="lrn" class="mono" inputmode="numeric" maxlength="12" value="<?= e($v['lrn'] ?? '') ?>"></div>
        <div class="field"><label for="<?= $p ?>_first">First name</label><input id="<?= $p ?>_first" name="first_name" maxlength="80" value="<?= e($v['first_name'] ?? '') ?>" required></div>
        <div class="field"><label for="<?= $p ?>_middle">Middle name <span class="muted" style="font-weight: 500;">(optional)</span></label><input id="<?= $p ?>_middle" name="middle_name" maxlength="80" value="<?= e($v['middle_name'] ?? '') ?>"></div>
        <div class="field"><label for="<?= $p ?>_last">Last name</label><input id="<?= $p ?>_last" name="last_name" maxlength="80" value="<?= e($v['last_name'] ?? '') ?>" required></div>
        <div class="field"><label for="<?= $p ?>_bd">Birthdate</label><input id="<?= $p ?>_bd" name="birthdate" type="date" max="<?= date('Y-m-d') ?>" value="<?= e($v['birthdate'] ?? '') ?>" required></div>
        <div class="field"><label for="<?= $p ?>_sex">Sex</label><select id="<?= $p ?>_sex" name="sex"><?= opts(['' => 'Choose…', 'F' => 'Female', 'M' => 'Male'], $v['sex'] ?? '') ?></select></div>
        <div class="field"><label for="<?= $p ?>_status">Status</label><select id="<?= $p ?>_status" name="status"><?= opts(STUDENT_STATUSES, $v['status'] ?? 'enrolled') ?></select></div>
        <div class="field span-2"><label for="<?= $p ?>_sec">Section</label><select id="<?= $p ?>_sec" name="section_id"><?= opts($sectionMap, $v['section_id'] ?? '') ?></select></div>
        <div class="field span-2"><label for="<?= $p ?>_addr">Address <span class="muted" style="font-weight: 500;">(optional)</span></label><input id="<?= $p ?>_addr" name="address" maxlength="255" value="<?= e($v['address'] ?? '') ?>"></div>
        <div class="form-section-label">Guardian on file</div>
        <div class="field"><label for="<?= $p ?>_gn">Guardian name</label><input id="<?= $p ?>_gn" name="guardian_name" maxlength="120" value="<?= e($v['guardian_name'] ?? '') ?>"></div>
        <div class="field"><label for="<?= $p ?>_gr">Relationship</label><input id="<?= $p ?>_gr" name="guardian_relation" maxlength="40" placeholder="e.g. Mother" value="<?= e($v['guardian_relation'] ?? '') ?>"></div>
        <div class="field span-2"><label for="<?= $p ?>_gc">Guardian contact number</label><input id="<?= $p ?>_gc" name="guardian_contact" type="tel" maxlength="30" placeholder="e.g. 0917 555 0123" value="<?= e($v['guardian_contact'] ?? '') ?>"></div>
    </div>
<?php }

layout_start($user, 'reg_students', 'Students', 'Add students, update their information and put them in the right section.');
?>

<div class="stat-grid-4">
    <div class="stat-card-box"><div class="stat-icon-wrap ic-blue"><?= icon('users') ?></div><div class="stat-info"><span class="stat-lbl">Enrolled</span><div class="stat-val"><?= (int)$counts['enrolled'] ?></div><span class="stat-note">students this school year</span></div></div>
    <a class="stat-card-box" href="registrar_students.php?section=none"><div class="stat-icon-wrap ic-amber"><?= icon('clipboard') ?></div><div class="stat-info"><span class="stat-lbl">No section yet</span><div class="stat-val"><?= (int)$counts['unassigned'] ?></div><span class="stat-note <?= $counts['unassigned'] ? 'note-amber' : '' ?>"><?= $counts['unassigned'] ? 'Click to assign them' : 'Everyone is assigned' ?></span></div></a>
    <a class="stat-card-box" href="registrar_sections.php"><div class="stat-icon-wrap ic-green"><?= icon('grid') ?></div><div class="stat-info"><span class="stat-lbl">Sections</span><div class="stat-val"><?= count($sectionMap) - 1 ?></div><span class="stat-note">manage sections and advisers</span></div></a>
    <div class="stat-card-box"><div class="stat-icon-wrap ic-violet"><?= icon('heart') ?></div><div class="stat-info"><span class="stat-lbl">Added in 30 days</span><div class="stat-val"><?= (int)$counts['recent'] ?></div><span class="stat-note">new student records</span></div></div>
</div>

<div class="card" id="students">
    <div class="card-head-flex">
        <div><div class="card-title">Student records</div><div class="card-sub">Changes show right away in every portal. Health and guidance records are kept by the Clinic and Guidance Office.</div></div>
        <button type="button" class="btn btn-primary btn-sm" data-open="modalAddStudent">+ Add student</button>
    </div>

    <form class="reg-filters" method="get" action="registrar_students.php">
        <div class="field" style="flex: 1; min-width: 240px;"><label for="f_q">Search</label><input id="f_q" type="search" name="q" value="<?= e($fQ) ?>" placeholder="Name, student ID or LRN"></div>
        <div class="field"><label for="f_sec">Section</label><select id="f_sec" name="section"><?= opts($sectionFilter, $fSection) ?></select></div>
        <div class="field"><label for="f_status">Status</label><select id="f_status" name="status"><?= opts($statusFilter, $fStatus) ?></select></div>
        <button class="btn btn-soft" type="submit">Filter</button>
        <?php if ($fQ !== '' || $fSection !== '' || $fStatus !== 'enrolled'): ?><a class="card-link" href="registrar_students.php" style="padding-bottom: 10px;">Clear</a><?php endif; ?>
    </form>

    <?php if (!$rows): ?>
        <div class="empty">No students match. Use <strong>+ Add student</strong> to create a record.</div>
    <?php else: ?>
    <form method="post" action="<?= e($back) ?>" id="bulkForm" data-confirm="The selected students will move to the chosen section, and their advisers will change." data-confirm-title="Move students?" data-confirm-ok="Move">
        <?= csrf_field() ?><input type="hidden" name="action" value="assign">
        <div class="reg-bulk">
            <strong><span id="selCount">0</span> selected</strong>
            <label class="sr-only" for="target_section">Move to section</label>
            <select id="target_section" name="target_section"><?= opts($moveOpts) ?></select>
            <button class="btn btn-primary btn-sm" type="submit" id="moveBtn" disabled>Move to section</button>
            <span class="muted" style="font-size: 0.76rem;">Tick students below, pick a section, then move them together.</span>
        </div>
        <div style="overflow-x: auto;">
        <table class="table">
            <thead><tr>
                <th class="reg-check"><input type="checkbox" id="checkAll" aria-label="Select all on this page"></th>
                <th>Student</th><th>Grade &amp; section</th><th>Birthdate</th><th>Guardian on file</th><th>Status</th><th></th>
            </tr></thead>
            <tbody>
            <?php foreach ($rows as $r): $rid = (int)$r['id']; ?>
                <tr>
                    <td class="reg-check"><input type="checkbox" name="ids[]" value="<?= $rid ?>" aria-label="Select <?= e($r['first_name'] . ' ' . $r['last_name']) ?>"></td>
                    <td style="white-space: nowrap;"><strong><?= e($r['last_name'] . ', ' . $r['first_name']) ?></strong>
                        <div class="muted mono" style="font-size: 0.72rem;"><?= e($r['student_no']) ?><?= $r['lrn'] ? ' · LRN ' . e($r['lrn']) : '' ?></div></td>
                    <td style="white-space: nowrap;"><?= $r['grade_level'] ? 'Grade ' . e($r['grade_level']) . ' – ' . e($r['section_name']) : '<span class="badge badge-amber">No section yet</span>' ?></td>
                    <td class="muted" style="white-space: nowrap; font-size: 0.82rem;"><?= e(date('M j, Y', strtotime($r['birthdate']))) ?></td>
                    <td style="font-size: 0.84rem;"><?= $r['guardian_name'] ? e($r['guardian_name']) . ($r['guardian_relation'] ? ' <span class="muted">(' . e($r['guardian_relation']) . ')</span>' : '') : '<span class="muted">—</span>' ?></td>
                    <td><?= badge(STUDENT_STATUSES[$r['status']] ?? ucfirst($r['status']), $statusTone[$r['status']] ?? 'gray') ?></td>
                    <td style="text-align: right;"><button type="button" class="btn btn-soft btn-sm" data-open="stu-<?= $rid ?>">Edit</button></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </form>
    <?= pager($pg, 'page', 'students') ?>
    <?php endif; ?>
</div>

<div id="modalAddStudent" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="addTitle" aria-hidden="true" <?= $reopen === 'modalAddStudent' ? 'data-autoopen' : '' ?>>
    <form class="modal-card" method="post" action="registrar_students.php" novalidate style="max-width: 640px;">
        <?= csrf_field() ?><input type="hidden" name="action" value="create">
        <div class="modal-head">
            <div class="modal-icon ic-blue"><?= icon('users') ?></div>
            <div><h3 id="addTitle">Add a student</h3><p>The Student ID and birthdate are what parents use to link their account.</p></div>
            <button type="button" class="modal-close" data-close aria-label="Close">✕</button>
        </div>
        <div class="modal-body"><?php student_form($reopen === 'modalAddStudent' ? $_POST : ['status' => 'enrolled', 'section_id' => ctype_digit($fSection) ? $fSection : ''], $sectionMap, 'add'); ?></div>
        <div class="modal-foot"><button type="button" class="btn btn-soft" data-close>Cancel</button><button type="submit" class="btn btn-primary">Add student</button></div>
    </form>
</div>

<?php foreach ($rows as $r): $rid = (int)$r['id']; $v = $reopen === "stu-$rid" ? $_POST : $r; ?>
<div id="stu-<?= $rid ?>" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="et-<?= $rid ?>" aria-hidden="true" <?= $reopen === "stu-$rid" ? 'data-autoopen' : '' ?>>
    <form class="modal-card" method="post" action="<?= e($back) ?>" novalidate style="max-width: 640px;">
        <?= csrf_field() ?><input type="hidden" name="action" value="update"><input type="hidden" name="student_id" value="<?= $rid ?>">
        <div class="modal-head">
            <div class="modal-icon ic-blue"><?= icon('users') ?></div>
            <div><h3 id="et-<?= $rid ?>">Edit <?= e($r['first_name'] . ' ' . $r['last_name']) ?></h3><p>Last updated <?= e(date('M j, Y', strtotime($r['updated_at']))) ?>.</p></div>
            <button type="button" class="modal-close" data-close aria-label="Close">✕</button>
        </div>
        <div class="modal-body"><?php student_form($v, $sectionMap, 'e' . $rid); ?></div>
        <div class="modal-foot"><button type="button" class="btn btn-soft" data-close>Cancel</button><button type="submit" class="btn btn-primary">Save changes</button></div>
    </form>
</div>
<?php endforeach; ?>

<script>
(function () {
    var form = document.getElementById('bulkForm');
    if (!form) return;
    var boxes = form.querySelectorAll('input[name="ids[]"]'), all = document.getElementById('checkAll'),
        count = document.getElementById('selCount'), btn = document.getElementById('moveBtn'), target = document.getElementById('target_section');
    function update() {
        var n = form.querySelectorAll('input[name="ids[]"]:checked').length;
        count.textContent = n;
        btn.disabled = n === 0 || target.value === '';
        all.checked = n > 0 && n === boxes.length;
        all.indeterminate = n > 0 && n < boxes.length;
    }
    boxes.forEach(function (b) { b.addEventListener('change', update); });
    target.addEventListener('change', update);
    all.addEventListener('change', function () { boxes.forEach(function (b) { b.checked = all.checked; }); update(); });
    update();
})();
</script>

<?php layout_end(); ?>

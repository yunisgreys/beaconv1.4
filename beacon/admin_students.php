<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
$user = require_page('admin_students');

/*
 * v1.3 Student Records (Super Admin): read-only.
 * Enrolment details only. Guidance, clinic and health records are never shown here.
 * The Registrar portal is where student information is edited.
 */

$fQ      = trim((string)query('q', ''));
$fGrade  = (string)query('grade', '');
$fStatus = in_array(query('status', 'enrolled'), ['enrolled', 'transferred', 'graduated', 'inactive', 'all'], true) ? (string)query('status', 'enrolled') : 'enrolled';

[, $total] = student_records_page($fQ, $fGrade, $fStatus === 'all' ? '' : $fStatus, 1, 1);
$pg = paginate($total, STUDENTS_PER_PAGE);
[$rows] = student_records_page($fQ, $fGrade, $fStatus === 'all' ? '' : $fStatus, $pg['page']);

$grades = array_column(db_all('SELECT DISTINCT grade_level FROM sections ORDER BY grade_level'), 'grade_level');
$gradeOpts = ['' => 'All grades'] + (array_combine($grades, array_map(fn($g) => "Grade $g", $grades)) ?: []);
$statusOpts = ['enrolled' => 'Enrolled', 'transferred' => 'Transferred', 'graduated' => 'Graduated', 'inactive' => 'Inactive', 'all' => 'All statuses'];
$statusTone = ['enrolled' => 'green', 'transferred' => 'blue', 'graduated' => 'purple', 'inactive' => 'gray'];
$counts = db_one("SELECT COUNT(*) AS total, SUM(CASE WHEN status = 'enrolled' THEN 1 ELSE 0 END) AS enrolled,
                         SUM(CASE WHEN status = 'enrolled' AND section_id IS NULL THEN 1 ELSE 0 END) AS unassigned FROM students");
$sectionsN = (int)db_value('SELECT COUNT(*) FROM sections');

audit('view_list', 'students', null, null, [], $user);

layout_start($user, 'students', 'Student records', 'Read-only list of enrolled students. Student details are edited in the Registrar portal.');
?>

<div class="stat-grid-4">
    <div class="stat-card-box"><div class="stat-icon-wrap ic-blue"><?= icon('users') ?></div><div class="stat-info"><span class="stat-lbl">Enrolled students</span><div class="stat-val"><?= (int)$counts['enrolled'] ?></div><span class="stat-note">of <?= (int)$counts['total'] ?> on record</span></div></div>
    <div class="stat-card-box"><div class="stat-icon-wrap ic-green"><?= icon('grid') ?></div><div class="stat-info"><span class="stat-lbl">Sections</span><div class="stat-val"><?= $sectionsN ?></div><span class="stat-note">this school year</span></div></div>
    <div class="stat-card-box"><div class="stat-icon-wrap ic-amber"><?= icon('clipboard') ?></div><div class="stat-info"><span class="stat-lbl">No section yet</span><div class="stat-val"><?= (int)$counts['unassigned'] ?></div><span class="stat-note <?= $counts['unassigned'] ? 'note-amber' : '' ?>"><?= $counts['unassigned'] ? 'The Registrar assigns sections' : 'Everyone is assigned' ?></span></div></div>
    <div class="stat-card-box"><div class="stat-icon-wrap ic-violet"><?= icon('shield') ?></div><div class="stat-info"><span class="stat-lbl">Access</span><div class="stat-val" style="font-size: 1.1rem; margin-top: 6px;">Read-only</div><span class="stat-note">No health or guidance data</span></div></div>
</div>

<div class="card" id="records">
    <div class="card-head-flex">
        <div><div class="card-title">Students</div><div class="card-sub">Sorted by grade, section and last name. Every view of this list is recorded in the audit log.</div></div>
    </div>

    <form class="filters" method="get" action="admin_students.php" style="margin-bottom: 16px;" data-auto-filter>
        <div class="field" style="flex: 1; min-width: 240px;"><label for="f_q">Search</label><input id="f_q" type="search" name="q" value="<?= e($fQ) ?>" placeholder="Name, student ID or LRN"></div>
        <div class="field"><label for="f_grade">Grade</label><select id="f_grade" name="grade"><?= opts($gradeOpts, $fGrade) ?></select></div>
        <div class="field"><label for="f_status">Status</label><select id="f_status" name="status"><?= opts($statusOpts, $fStatus) ?></select></div>
        <?php if ($fQ !== '' || $fGrade !== '' || $fStatus !== 'enrolled'): ?><a class="card-link" href="admin_students.php" style="padding-bottom: 10px;">Clear</a><?php endif; ?>
    </form>

    <?php if (!$rows): ?>
        <div class="empty">No students match these filters.</div>
    <?php else: ?>
    <div style="overflow-x: auto;">
    <table class="table">
        <thead><tr><th>Student</th><th>Grade &amp; section</th><th>Adviser</th><th>Guardian on file</th><th>Family accounts</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): $rid = (int)$r['id']; ?>
            <tr>
                <td style="white-space: nowrap;">
                    <strong><?= e($r['last_name'] . ', ' . $r['first_name']) ?></strong>
                    <div class="muted mono" style="font-size: 0.72rem;"><?= e($r['student_no']) ?><?= $r['lrn'] ? ' · LRN ' . e($r['lrn']) : '' ?></div>
                </td>
                <td style="white-space: nowrap;"><?= $r['grade_level'] ? 'Grade ' . e($r['grade_level']) . ' – ' . e($r['section_name']) : '<span class="muted">No section yet</span>' ?></td>
                <td style="font-size: 0.84rem;"><?= $r['adv_first'] ? e($r['adv_first'] . ' ' . $r['adv_last']) : '<span class="muted">—</span>' ?></td>
                <td style="font-size: 0.84rem;"><?= $r['guardian_name'] ? e($r['guardian_name']) . ($r['guardian_relation'] ? ' <span class="muted">(' . e($r['guardian_relation']) . ')</span>' : '') : '<span class="muted">—</span>' ?></td>
                <td style="font-size: 0.84rem;"><?= (int)$r['families'] ?: '<span class="muted">0</span>' ?></td>
                <td><?= badge(ucfirst($r['status']), $statusTone[$r['status']] ?? 'gray') ?></td>
                <td style="text-align: right;"><button type="button" class="btn btn-soft btn-sm" data-open="stu-<?= $rid ?>">View</button></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?= pager($pg, 'page', 'records') ?>
    <?php endif; ?>
</div>

<?php foreach ($rows as $r): $rid = (int)$r['id']; ?>
<div id="stu-<?= $rid ?>" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="st-<?= $rid ?>" aria-hidden="true">
    <div class="modal-card">
        <div class="modal-head">
            <div class="modal-icon ic-blue"><?= icon('users') ?></div>
            <div><h3 id="st-<?= $rid ?>"><?= e(trim($r['first_name'] . ' ' . ($r['middle_name'] ? $r['middle_name'] . ' ' : '') . $r['last_name'])) ?></h3><p>Read-only. To change these details, ask the Registrar.</p></div>
            <button type="button" class="modal-close" data-close aria-label="Close">✕</button>
        </div>
        <div class="modal-body">
            <div class="form-grid" style="gap: 10px;">
                <div class="info-tile"><span class="k">Student ID</span><span class="v mono"><?= e($r['student_no']) ?></span></div>
                <div class="info-tile"><span class="k">LRN</span><span class="v mono"><?= e($r['lrn'] ?: '—') ?></span></div>
                <div class="info-tile"><span class="k">Grade &amp; section</span><span class="v"><?= $r['grade_level'] ? 'Grade ' . e($r['grade_level']) . ' – ' . e($r['section_name']) : 'No section yet' ?></span></div>
                <div class="info-tile"><span class="k">Adviser</span><span class="v"><?= $r['adv_first'] ? e($r['adv_first'] . ' ' . $r['adv_last']) : '—' ?></span></div>
                <div class="info-tile"><span class="k">Birthdate</span><span class="v"><?= e(date('M j, Y', strtotime($r['birthdate']))) ?></span></div>
                <div class="info-tile"><span class="k">Sex</span><span class="v"><?= $r['sex'] === 'F' ? 'Female' : 'Male' ?></span></div>
                <div class="info-tile"><span class="k">Guardian on file</span><span class="v"><?= e($r['guardian_name'] ?: '—') ?><?= $r['guardian_relation'] ? ' (' . e($r['guardian_relation']) . ')' : '' ?></span></div>
                <div class="info-tile"><span class="k">Guardian contact</span><span class="v"><?= e($r['guardian_contact'] ?: '—') ?></span></div>
                <div class="info-tile"><span class="k">Status</span><span class="v"><?= e(ucfirst($r['status'])) ?></span></div>
                <div class="info-tile"><span class="k">Last updated</span><span class="v"><?= e(date('M j, Y', strtotime($r['updated_at']))) ?></span></div>
            </div>
        </div>
        <div class="modal-foot"><button type="button" class="btn btn-soft" data-close>Close</button></div>
    </div>
</div>
<?php endforeach; ?>

<?php layout_end(); ?>

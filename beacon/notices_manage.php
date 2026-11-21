<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
$user = require_page('notices_manage');

$reopen = null;
if (is_post()) {
    verify_csrf();
    if (post('action') === 'create') {
        $err = notice_create($user, $_POST);
        if (!$err) { flash('success', 'Notice posted to the Family Portal.'); redirect('notices_manage.php'); }
        flash('error', $err); $reopen = 'modalNotice';
    }
    if (post('action') === 'delete') {
        $err = notice_delete($user, (int)post('notice_id'));
        flash($err ? 'error' : 'success', $err ?: 'Notice removed.');
        redirect('notices_manage.php');
    }
}

$sections = postable_sections($user);
$isTeacher = $user['role'] === 'teacher';
// Notices posted for a group of families. Automatic clinic check-in notices (audience 'student')
// are shown separately below, and only to the clinic and the Super Admin.
$notices = db_all("SELECT n.*, u.first_name, u.last_name FROM notices n JOIN users u ON u.id = n.posted_by WHERE n.audience <> 'student' ORDER BY n.published_at DESC");
if ($isTeacher) {
    $mine = array_map('intval', array_column($sections, 'id'));
    $notices = array_values(array_filter($notices, fn($n) => $n['audience'] !== 'section' || in_array((int)$n['section_id'], $mine, true)));
}
$showAuto = in_array($user['role'], ['clinic', 'super_admin'], true);
$autoNotices = $showAuto ? db_all(
    "SELECT n.id, n.title, n.published_at, n.student_id, s.first_name, s.last_name, v.departed_at, v.is_sensitive,
            (SELECT COUNT(*) FROM parent_student_links l WHERE l.student_id = n.student_id AND l.status = 'approved') AS families
       FROM notices n
       JOIN students s ON s.id = n.student_id
       LEFT JOIN clinic_visits v ON v.id = n.clinic_visit_id
      WHERE n.audience = 'student' AND n.clinic_visit_id IS NOT NULL
      ORDER BY n.published_at DESC LIMIT 15") : [];
$sectionOpts = [];
foreach ($sections as $s) $sectionOpts[$s['id']] = 'Grade ' . $s['grade_level'] . ' – ' . $s['name'];
$grades = array_column(db_all('SELECT DISTINCT grade_level FROM sections ORDER BY grade_level'), 'grade_level');
$gradeOpts = array_combine($grades, array_map(fn($g) => "Grade $g", $grades)) ?: [];
$audOpts = ['all' => 'All families', 'grade' => 'One grade level', 'section' => 'One section'];
$mySection = $isTeacher ? ($sections[0] ?? null) : null;
$f = $reopen ? $_POST : ['tag' => ['clinic' => 'clinic', 'guidance' => 'guidance'][$user['role']] ?? 'announcement', 'audience' => $isTeacher ? 'section' : 'all'];

layout_start($user, 'notices', 'Family notices', 'Post announcements to the Family Portal. Parents can filter them by tag.');
?>

<div class="card">
    <div class="card-head-flex">
        <div><div class="card-title">Posted notices</div><div class="card-sub"><?= $isTeacher ? ($mySection ? 'Your notices go to the families of Grade ' . e($mySection['grade_level']) . ' – ' . e($mySection['name']) . '. ' : '') : '' ?>Tags help parents find what matters: Important, Announcement, Clinic Update, Guidance Notice.</div></div>
        <button type="button" class="btn btn-primary btn-sm" data-open="modalNotice" <?= $isTeacher && !$mySection ? 'disabled title="No advisory section is assigned to you"' : '' ?>>+ Post a notice</button>
    </div>
    <?php if (!$notices): ?><div class="empty">No notices yet.</div><?php endif; ?>
    <?php foreach ($notices as $n): [$lbl, $tone] = NOTICE_TAGS[$n['tag']]; [$reach, $acks] = notice_reach($n);
        $canDelete = (int)$n['posted_by'] === (int)$user['id'] || $user['role'] === 'super_admin'; ?>
        <div style="padding: 16px 0; border-top: 1px solid var(--border); display: flex; gap: 16px; justify-content: space-between; align-items: flex-start;">
            <div style="flex: 1;">
                <div class="tags" style="margin-bottom: 6px;"><?= badge($lbl, $tone) ?> <?= badge(notice_audience_label($n), 'gray') ?></div>
                <strong style="font-size: 0.95rem;"><?= e($n['title']) ?></strong>
                <div style="font-size: 0.84rem; color: #475569; margin-top: 4px; line-height: 1.55;"><?= e(mb_strlen($n['body']) > 220 ? mb_substr($n['body'], 0, 217) . '…' : $n['body']) ?></div>
                <div class="muted" style="font-size: 0.74rem; margin-top: 6px;">
                    <?= e($n['first_name'] . ' ' . $n['last_name']) ?> · <?= e(date('M j, Y g:i A', strtotime($n['published_at']))) ?> · reaches <?= $reach ?> famil<?= $reach === 1 ? 'y' : 'ies' ?>
                    <?php if ($n['requires_ack']): ?> · <strong style="color: <?= $acks >= $reach && $reach ? '#15803d' : '#b45309' ?>;"><?= $acks ?>/<?= $reach ?> acknowledged</strong><?php endif; ?>
                </div>
            </div>
            <?php if ($canDelete): ?>
                <form method="post" class="inline-form" data-confirm="Families will no longer see this notice in the Family Portal." data-confirm-title="Remove this notice?" data-confirm-ok="Remove"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="notice_id" value="<?= (int)$n['id'] ?>"><button class="btn btn-danger-soft btn-sm" type="submit">Remove</button></form>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>

<?php if ($showAuto): ?>
<div class="card" id="auto-clinic">
    <div class="card-head-flex">
        <div>
            <div class="card-title">Automatic clinic check-in notices</div>
            <div class="card-sub">Each time the clinic checks a student in, their linked parents get a notice in the Family Portal. It updates when the student checks out. Sensitive visits only say that a visit happened.</div>
        </div>
    </div>
    <?php if (!$autoNotices): ?>
        <div class="empty">No check-in notices yet. They appear here as soon as the clinic checks a student in on Patient intake.</div>
    <?php else: ?>
    <div style="overflow-x: auto;">
    <table class="table">
        <thead><tr><th>Student</th><th>Notice</th><th>Visit</th><th>Families reached</th><th>Sent</th></tr></thead>
        <tbody>
        <?php foreach ($autoNotices as $a): ?>
            <tr>
                <td style="white-space: nowrap;"><strong><?= e($a['first_name'] . ' ' . $a['last_name']) ?></strong></td>
                <td><?= e($a['title']) ?><?= $a['is_sensitive'] ? ' <span class="badge badge-gray">Details private</span>' : '' ?></td>
                <td><?= $a['departed_at'] ? badge('Checked out', 'green') : badge('In the clinic', 'amber') ?></td>
                <td><?= (int)$a['families'] ? (int)$a['families'] . ' famil' . ((int)$a['families'] === 1 ? 'y' : 'ies') : '<span class="muted">No linked parent yet</span>' ?></td>
                <td class="muted" style="white-space: nowrap;"><?= e(date('M j, g:i A', strtotime($a['published_at']))) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<div id="modalNotice" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="ntTitle" aria-hidden="true" <?= $reopen ? 'data-autoopen' : '' ?>>
    <form class="modal-card" method="post" action="notices_manage.php" novalidate>
        <?= csrf_field() ?><input type="hidden" name="action" value="create">
        <div class="modal-head">
            <div class="modal-icon ic-blue"><?= icon('bell') ?></div>
            <div><h3 id="ntTitle">Post a family notice</h3><p>It appears in the Family Portal right away.</p></div>
            <button type="button" class="modal-close" data-close aria-label="Close">✕</button>
        </div>
        <div class="modal-body">
            <div class="form-grid">
                <div class="field span-2">
                    <span class="field-label">Tag</span>
                    <div class="choice-row" style="grid-template-columns: repeat(4, 1fr);">
                        <?php foreach (POSTABLE_TAGS as $t): ?>
                            <label class="choice"><input type="radio" name="tag" value="<?= $t ?>" <?= ($f['tag'] ?? '') === $t ? 'checked' : '' ?>><span style="font-size: 0.78rem; text-align: center;"><?= e(NOTICE_TAGS[$t][0]) ?></span></label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="field span-2"><label for="nt_title">Title</label><input id="nt_title" name="title" maxlength="150" value="<?= e($f['title'] ?? '') ?>" required></div>
                <div class="field span-2"><label for="nt_body">Message</label><textarea id="nt_body" name="body" rows="5" required placeholder="Write it the way you'd say it to a parent: what, when, and what they need to do."><?= e($f['body'] ?? '') ?></textarea></div>
                <?php if ($isTeacher): ?>
                    <div class="field span-2">
                        <span class="field-label">Who will see it</span>
                        <div class="info-tile" style="padding: 11px 14px;">
                            <span class="v" style="margin: 0;"><?= $mySection ? 'Families of Grade ' . e($mySection['grade_level']) . ' – ' . e($mySection['name']) . ' (your advisory class)' : 'No advisory section assigned' ?></span>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="field"><label for="nt_aud">Who should see it?</label><select id="nt_aud" name="audience"><?= opts($audOpts, $f['audience'] ?? '') ?></select></div>
                    <div class="field" data-aud="grade"><label for="nt_grade">Grade level</label><select id="nt_grade" name="grade_level"><?= opts($gradeOpts, $f['grade_level'] ?? '') ?></select></div>
                    <div class="field" data-aud="section"><label for="nt_sec">Section</label><select id="nt_sec" name="section_id"><?= opts($sectionOpts, $f['section_id'] ?? '') ?></select></div>
                <?php endif; ?>
                <div class="field span-2"><label class="check"><input type="checkbox" name="requires_ack" value="1" <?= !empty($f['requires_ack']) ? 'checked' : '' ?>> Ask parents to acknowledge they have read it</label></div>
            </div>
        </div>
        <div class="modal-foot"><button type="button" class="btn btn-soft" data-close>Cancel</button><button type="submit" class="btn btn-primary">Post notice</button></div>
    </form>
</div>

<script>
(function () {
    // Remove buttons use BEACON's own confirm dialog (data-confirm in beacon.js); no browser popups.
    var aud = document.getElementById('nt_aud');
    if (!aud) return;
    function apply() { document.querySelectorAll('[data-aud]').forEach(function (el) { el.hidden = el.getAttribute('data-aud') !== aud.value; }); }
    aud.addEventListener('change', apply); apply();
})();
</script>

<?php layout_end(); ?>

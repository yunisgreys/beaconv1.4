<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
$user = require_page('registrar_sections');

$reopen = null;
if (is_post()) {
    verify_csrf();
    if (post('action') === 'save') {
        $id = (int)post('section_id', 0);
        [$sid, $err] = registrar_save_section($user, $_POST, $id);
        if (!$err) {
            $adv = (string)post('adviser_id', '');
            $err = registrar_set_adviser($user, $sid, $adv === '' ? null : (int)$adv);
        }
        if (!$err) { flash('success', $id ? 'Section saved.' : 'Section created. You can now move students into it.'); redirect('registrar_sections.php#s-' . $sid); }
        flash('error', $err); $reopen = $id ? 'sec-' . $id : 'modalAddSection';
    }
}

$sections = registrar_sections();
$teachers = teacher_options();
$byYear = [];
foreach ($sections as $s) $byYear[$s['school_year']][] = $s;
$unassigned = (int)db_value("SELECT COUNT(*) FROM students WHERE status = 'enrolled' AND section_id IS NULL");

function section_form(array $v, array $teachers, string $p): void { ?>
    <div class="form-grid">
        <div class="field"><label for="<?= $p ?>_grade">Grade level</label><select id="<?= $p ?>_grade" name="grade_level"><?= opts(['' => 'Choose…'] + array_combine(range(1, 12), array_map(fn($g) => "Grade $g", range(1, 12))), $v['grade_level'] ?? '') ?></select></div>
        <div class="field"><label for="<?= $p ?>_name">Section name</label><input id="<?= $p ?>_name" name="name" maxlength="60" placeholder="e.g. Mabini" value="<?= e($v['name'] ?? '') ?>" required></div>
        <div class="field"><label for="<?= $p ?>_year">School year</label><input id="<?= $p ?>_year" name="school_year" maxlength="9" placeholder="2026-2027" value="<?= e($v['school_year'] ?? current_school_year()) ?>" required></div>
        <div class="field"><label for="<?= $p ?>_adv">Class adviser</label><select id="<?= $p ?>_adv" name="adviser_id"><?= opts($teachers, $v['adviser_id'] ?? '') ?></select></div>
    </div>
    <p class="hint" style="margin: 10px 0 0;">A teacher advises one section per school year. Choosing a teacher who already advises another section moves them here.</p>
<?php }

layout_start($user, 'reg_sections', 'Sections', 'Create sections, assign class advisers and see how many students are in each.');
?>

<?php if ($unassigned): ?>
<div class="notice notice-warn" style="margin-bottom: 20px;"><?= icon('bell') ?><span><strong><?= $unassigned ?> enrolled student<?= $unassigned === 1 ? ' has' : 's have' ?> no section yet.</strong> <a href="registrar_students.php?section=none" style="color: inherit; font-weight: 700;">Assign them on the Students page ›</a></span></div>
<?php endif; ?>

<div class="card">
    <div class="card-head-flex">
        <div><div class="card-title">Sections</div><div class="card-sub">Open a section's students to move learners in or out.</div></div>
        <button type="button" class="btn btn-primary btn-sm" data-open="modalAddSection">+ Add section</button>
    </div>
    <?php if (!$sections): ?>
        <div class="empty">No sections yet. Add the first one.</div>
    <?php endif; ?>
    <?php foreach ($byYear as $year => $list): ?>
        <span class="section-label" style="margin-top: 6px;">School year <?= e($year) ?><?= $year === current_school_year() ? ' (current)' : '' ?></span>
        <div class="case-grid" style="margin-bottom: 18px;">
            <?php foreach ($list as $s): $sid = (int)$s['id']; ?>
                <div class="case-card" id="s-<?= $sid ?>" style="cursor: default;">
                    <div class="case-card-top">
                        <div class="case-card-student"><strong><?= e(section_label($s)) ?></strong><span><?= e($s['school_year']) ?></span></div>
                        <span class="badge badge-<?= (int)$s['enrolled'] ? 'blue' : 'gray' ?>"><?= (int)$s['enrolled'] ?> student<?= (int)$s['enrolled'] === 1 ? '' : 's' ?></span>
                    </div>
                    <div class="case-card-meta" style="font-size: 0.82rem;">
                        Adviser: <?= $s['adv_first'] ? '<strong style="color:#0f172a">' . e($s['adv_first'] . ' ' . $s['adv_last']) . '</strong>' : '<span class="badge badge-amber">No adviser yet</span>' ?>
                    </div>
                    <div class="case-card-foot">
                        <a class="card-link" href="registrar_students.php?section=<?= $sid ?>&amp;status=all">Students ›</a>
                        <button type="button" class="btn btn-soft btn-sm" data-open="sec-<?= $sid ?>">Edit</button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>
</div>

<div id="modalAddSection" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="asTitle" aria-hidden="true" <?= $reopen === 'modalAddSection' ? 'data-autoopen' : '' ?>>
    <form class="modal-card" method="post" action="registrar_sections.php" novalidate>
        <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="section_id" value="0">
        <div class="modal-head">
            <div class="modal-icon ic-green"><?= icon('grid') ?></div>
            <div><h3 id="asTitle">Add a section</h3><p>You can assign the adviser now or later.</p></div>
            <button type="button" class="modal-close" data-close aria-label="Close">✕</button>
        </div>
        <div class="modal-body"><?php section_form($reopen === 'modalAddSection' ? $_POST : [], $teachers, 'as'); ?></div>
        <div class="modal-foot"><button type="button" class="btn btn-soft" data-close>Cancel</button><button type="submit" class="btn btn-primary">Add section</button></div>
    </form>
</div>

<?php foreach ($sections as $s): $sid = (int)$s['id']; $v = $reopen === "sec-$sid" ? $_POST : $s; ?>
<div id="sec-<?= $sid ?>" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="st-<?= $sid ?>" aria-hidden="true" <?= $reopen === "sec-$sid" ? 'data-autoopen' : '' ?>>
    <form class="modal-card" method="post" action="registrar_sections.php" novalidate>
        <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="section_id" value="<?= $sid ?>">
        <div class="modal-head">
            <div class="modal-icon ic-green"><?= icon('grid') ?></div>
            <div><h3 id="st-<?= $sid ?>">Edit <?= e(section_label($s)) ?></h3><p><?= (int)$s['enrolled'] ?> enrolled student<?= (int)$s['enrolled'] === 1 ? '' : 's' ?>.</p></div>
            <button type="button" class="modal-close" data-close aria-label="Close">✕</button>
        </div>
        <div class="modal-body"><?php section_form($v, $teachers, 'es' . $sid); ?></div>
        <div class="modal-foot"><button type="button" class="btn btn-soft" data-close>Cancel</button><button type="submit" class="btn btn-primary">Save section</button></div>
    </form>
</div>
<?php endforeach; ?>

<?php layout_end(); ?>

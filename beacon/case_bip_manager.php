<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require __DIR__ . '/inc/guidance_ui.php';
$user = require_page('case_bip_manager');

$students = visible_students($user);
$caseId   = (int)query('case', 0);
$reopen   = null;

if (is_post()) {
    verify_csrf();
    $action = (string)post('action');
    $postCase = (int)post('case_id', 0) ?: $caseId;
    $back = 'case_bip_manager.php?case=' . $postCase;

    switch ($action) {
        case 'open_case':
            [$newId, $err] = guidance_open_case($user, $_POST);
            if (!$err) { flash('success', 'Case opened.'); redirect('case_bip_manager.php?case=' . $newId); }
            flash('error', $err); $reopen = 'modalOpenCase';
            break;

        case 'update_case':
            $err = guidance_update_case($user, $postCase, $_POST);
            if (!$err) { flash('success', 'Case updated.'); redirect($back); }
            flash('error', $err); $reopen = 'modalEditCase';
            break;

        case 'add_note':
            $body = trim((string)post('body', ''));
            $type = (string)post('note_type', 'remark');
            if (!db_value('SELECT 1 FROM guidance_cases WHERE id = ?', [$postCase])) { flash('error', 'Case not found.'); redirect('case_bip_manager.php'); }
            if ($body === '' || !isset(NOTE_TYPES[$type])) {
                flash('error', 'Please write the note and choose its type.');
                $reopen = 'note-form';
                break;
            }
            add_case_note($user, $postCase, $body, $type, post('shareable') !== '1');
            guidance_touch_case($postCase);
            flash('success', 'Note saved.');
            redirect($back . '#notes');

        case 'add_intervention':
            $err = guidance_add_intervention($user, $postCase, $_POST);
            if (!$err) { flash('success', 'Intervention plan added.'); redirect($back . '#plans'); }
            flash('error', $err); $reopen = 'modalAddPlan';
            break;

        case 'update_intervention':
            $err = guidance_update_intervention($user, (int)post('intervention_id'), $_POST);
            if (!$err) { flash('success', 'Plan updated.'); redirect($back . '#plans'); }
            flash('error', $err); $reopen = 'plan-' . (int)post('intervention_id');
            break;

        case 'consent':
            $err = set_parent_access($user, $postCase, post('grant') === '1', (string)post('consent_method', ''), trim((string)post('notes', '')) ?: null);
            flash($err ? 'error' : 'success', $err ?: (post('grant') === '1' ? 'Parents can now see this case.' : 'This case is private from parents again.'));
            if (!$err) redirect($back . '#privacy');
            $reopen = 'modalConsent';
            break;

        case 'schedule_session':
            $err = guidance_schedule_session($user, $_POST);
            if (!$err) { flash('success', 'Session scheduled.'); redirect($back . '#sessions'); }
            flash('error', $err); $reopen = 'modalSession';
            break;

        case 'session_status':
            $err = guidance_set_session_status($user, (int)post('session_id'), (string)post('status'));
            flash($err ? 'error' : 'success', $err ?: 'Session updated.');
            redirect($back . '#sessions');
    }
}

if ($caseId) {
    $case = guidance_case($caseId);
    if (!$case) { flash('error', 'Case not found.'); redirect('case_bip_manager.php'); }
    $sid = (int)$case['student_id'];
    audit('view', 'guidance_cases', $caseId, $sid, [], $user);

    $notes = guidance_case_notes($user, $caseId);
    $authors = array_column(db_all('SELECT id, first_name, last_name FROM users WHERE id IN (SELECT author_id FROM case_notes WHERE case_id = ?)', [$caseId]), null, 'id');
    $plans = db_all('SELECT * FROM interventions WHERE case_id = ? ORDER BY CASE status WHEN \'active\' THEN 0 WHEN \'planned\' THEN 1 ELSE 2 END, start_date DESC', [$caseId]);
    foreach ($plans as &$p) {
        $p['updates'] = db_all('SELECT u.*, us.first_name, us.last_name FROM intervention_updates u JOIN users us ON us.id = u.created_by WHERE u.intervention_id = ? ORDER BY u.created_at DESC', [$p['id']]);
    }
    unset($p);
    $reports = db_all('SELECT r.*, u.first_name AS t_first, u.last_name AS t_last FROM incident_reports r JOIN users u ON u.id = r.reported_by WHERE r.case_id = ? ORDER BY r.observed_at DESC', [$caseId]);
    $sessions = db_all('SELECT cs.*, c.first_name AS c_first, c.last_name AS c_last FROM counseling_sessions cs JOIN users c ON c.id = cs.counselor_id WHERE cs.case_id = ? ORDER BY cs.scheduled_start DESC', [$caseId]);
    $consents = db_all('SELECT ce.*, u.first_name, u.last_name FROM consent_events ce JOIN users u ON u.id = ce.recorded_by WHERE ce.case_id = ? ORDER BY ce.created_at DESC', [$caseId]);
    $wb = wellbeing_status([$sid])[$sid];
    $counselorOpts = ['' => 'Unassigned'] + array_column(array_map(fn($c) => ['id' => $c['id'], 'n' => $c['first_name'] . ' ' . $c['last_name']], counselors()), 'n', 'id');
    $form = ($reopen === 'modalEditCase') ? $_POST + $case : $case;

    layout_start($user, 'bip', 'Case details', 'Guidance case for ' . $case['first_name'] . ' ' . $case['last_name'] . '. Every view and change is recorded in the audit log.');
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 18px; gap: 12px; flex-wrap: wrap;">
    <a class="card-link" href="case_bip_manager.php">‹ All cases</a>
    <div style="display: flex; gap: 8px;">
        <button type="button" class="btn btn-soft" data-open="modalSession"><?= icon('bell') ?> Schedule session</button>
        <button type="button" class="btn btn-primary" data-open="modalEditCase">Edit case</button>
    </div>
</div>

<div class="case-student">
    <div class="case-student-avatar" aria-hidden="true"><?= e(strtoupper(mb_substr($case['first_name'], 0, 1) . mb_substr($case['last_name'], 0, 1))) ?></div>
    <div>
        <div class="case-student-k">Student</div>
        <h2><?= e($case['first_name'] . ' ' . $case['last_name']) ?></h2>
        <p><span class="mono"><?= e($case['student_no']) ?></span><?php if ($case['grade_level']): ?> · Grade <?= e($case['grade_level']) ?> – <?= e($case['section_name']) ?><?php endif; ?></p>
    </div>
    <div class="case-student-side">
        <?= wellbeing_badge($wb) ?>
        <a class="card-link" href="case_bip_manager.php?student=<?= $sid ?>">All cases for this student ›</a>
    </div>
</div>

<div class="card case-box">
    <div class="case-student-k" style="margin-bottom: 2px;">Case</div>
    <h3 class="case-box-title"><?= e($case['title']) ?></h3>
    <div class="tags" style="margin-bottom: 14px;">
        <?= badge(CASE_STATUS[$case['status']], ['open' => 'purple', 'monitoring' => 'blue', 'closed' => 'gray'][$case['status']]) ?>
        <?= badge(RISK_LEVELS[$case['risk_level']] . ' risk', RISK_TONE[$case['risk_level']]) ?>
        <?= badge(CASE_TYPES[$case['case_type']], 'gray') ?>
        <?php if ($case['is_mental_health']): ?><?= badge($case['is_parent_accessible'] ? 'Shared with parents' : 'Private from parents', 'purple') ?><?php endif; ?>
    </div>
    <div class="form-grid" style="grid-template-columns: repeat(4, 1fr); gap: 10px;">
        <div class="info-tile"><span class="k">Counselor</span><span class="v"><?= e($case['counselor_first'] ? $case['counselor_first'] . ' ' . $case['counselor_last'] : 'Unassigned') ?></span></div>
        <div class="info-tile"><span class="k">Opened</span><span class="v"><?= e(date('M j, Y', strtotime($case['opened_at']))) ?></span></div>
        <div class="info-tile"><span class="k">Last update</span><span class="v"><?= e(date('M j, g:i A', strtotime($case['updated_at']))) ?></span></div>
        <div class="info-tile"><span class="k"><?= $case['closed_at'] ? 'Closed' : 'Active plans' ?></span><span class="v"><?= $case['closed_at'] ? e(date('M j, Y', strtotime($case['closed_at']))) : count(array_filter($plans, fn($p) => in_array($p['status'], ['planned', 'active'], true))) ?></span></div>
        <div class="info-tile span-2" style="grid-column: 1 / -1;"><span class="k">Summary for families</span><span class="v" style="font-weight: 500;"><?= e($case['parent_summary'] ?: 'Not written yet.') ?></span></div>
    </div>
</div>

<div class="grid-main-side">
    <div>
        <div class="card" id="plans">
            <div class="card-head-flex">
                <div><div class="card-title">Interventions &amp; progress</div><div class="card-sub">Plans for this case, with progress check-ins over time.</div></div>
                <button type="button" class="btn btn-primary btn-sm" data-open="modalAddPlan">+ Add plan</button>
            </div>
            <?php if (!$plans): ?><div class="empty">No intervention plans yet.</div><?php endif; ?>
            <?php foreach ($plans as $i => $p): $last = $p['updates'][0] ?? null; ?>
                <div class="case-item">
                    <div style="display: flex; justify-content: space-between; gap: 12px; align-items: flex-start;">
                        <div>
                            <strong><?= e(PLAN_TYPES[$p['plan_type']]) ?></strong>
                            <?= badge(PLAN_STATUS[$p['status']], ['planned' => 'gray', 'active' => 'blue', 'completed' => 'green', 'discontinued' => 'gray'][$p['status']]) ?>
                            <?php if ($last): ?><?= badge(PROGRESS[$last['progress']][0], PROGRESS[$last['progress']][1]) ?><?php endif; ?>
                            <div style="font-size: 0.875rem; margin-top: 6px;"><span class="muted">Goal:</span> <?= e($p['goals']) ?></div>
                            <?php if ($p['strategies']): ?><div style="font-size: 0.84rem; margin-top: 3px;"><span class="muted">Strategies:</span> <?= e($p['strategies']) ?></div><?php endif; ?>
                            <div class="muted" style="font-size: 0.74rem; margin-top: 4px;">
                                Started <?= e(date('M j, Y', strtotime($p['start_date']))) ?>
                                <?php if ($p['review_date']): ?> · Review <?= e(date('M j, Y', strtotime($p['review_date']))) ?><?= $p['review_date'] < date('Y-m-d') && in_array($p['status'], ['planned', 'active'], true) ? ' <strong style="color:#b45309">(overdue)</strong>' : '' ?><?php endif; ?>
                            </div>
                        </div>
                        <button type="button" class="btn btn-soft btn-sm" data-open="plan-<?= (int)$p['id'] ?>">Update</button>
                    </div>
                    <?php if ($p['updates']): ?>
                        <span class="section-label">Progress</span>
                        <div class="timeline">
                            <?php foreach ($p['updates'] as $u): ?>
                                <div class="timeline-item">
                                    <?= badge(PROGRESS[$u['progress']][0], PROGRESS[$u['progress']][1]) ?> <?= e($u['note']) ?>
                                    <div class="timeline-meta"><?= e($u['first_name'] . ' ' . $u['last_name']) ?> · <?= e(date('M j, Y g:i A', strtotime($u['created_at']))) ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="card" id="notes">
            <div class="card-head-flex">
                <div><div class="card-title">Remarks &amp; counseling notes</div><div class="card-sub">Confidential notes are encrypted and visible to the Guidance Office only.</div></div>
            </div>
            <form method="post" action="case_bip_manager.php?case=<?= $caseId ?>" style="margin-bottom: 18px;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="add_note"><input type="hidden" name="case_id" value="<?= $caseId ?>">
                <div class="field" style="margin-bottom: 10px;">
                    <label for="note_body" class="sr-only">New note</label>
                    <textarea id="note_body" name="body" rows="3" placeholder="Write a remark, session note or progress note…"><?= $reopen === 'note-form' ? e(post('body')) : '' ?></textarea>
                </div>
                <div style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
                    <div class="field" style="margin: 0; min-width: 170px;"><select name="note_type" aria-label="Note type"><?= opts(NOTE_TYPES, post('note_type', 'remark')) ?></select></div>
                    <label class="check"><input type="checkbox" name="shareable" value="1"> <span>Shareable <span class="muted">(the adviser can read it<?= $case['is_mental_health'] && !$case['is_parent_accessible'] ? '' : ', and parents too when the case is visible to them' ?>)</span></span></label>
                    <button class="btn btn-primary btn-sm" type="submit" style="margin-left: auto;">Save note</button>
                </div>
            </form>
            <?php if (!$notes): ?><div class="empty">No notes yet.</div><?php endif; ?>
            <?php foreach ($notes as $n): $a = $authors[$n['author_id']] ?? null; ?>
                <div class="case-item">
                    <div class="tags" style="margin-bottom: 6px;">
                        <?= badge(NOTE_TYPES[$n['note_type']], 'gray') ?>
                        <?= $n['is_confidential'] ? badge('Confidential', 'purple') : badge('Shareable', 'blue') ?>
                    </div>
                    <div style="font-size: 0.875rem; line-height: 1.55; white-space: pre-line;"><?= e($n['body']) ?></div>
                    <div class="muted" style="font-size: 0.72rem; margin-top: 4px;"><?= e($a ? $a['first_name'] . ' ' . $a['last_name'] : '') ?> · <?= e(date('M j, Y g:i A', strtotime($n['created_at']))) ?></div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="card">
            <div class="card-head-flex"><div><div class="card-title">Linked teacher reports</div><div class="card-sub">Reports added to this case from the Guidance desk.</div></div></div>
            <?php if (!$reports): ?><div class="empty">No reports linked.</div><?php endif; ?>
            <?php foreach ($reports as $r): ?>
                <div class="case-item">
                    <strong style="font-size: 0.875rem;"><?= e($r['title']) ?></strong> <?= badge(ucfirst($r['severity']), ['urgent' => 'red', 'high' => 'red', 'moderate' => 'amber', 'low' => 'gray'][$r['severity']]) ?>
                    <div style="font-size: 0.84rem; margin-top: 4px;"><?= e($r['description']) ?></div>
                    <div class="muted" style="font-size: 0.72rem; margin-top: 3px;"><?= e(label($r['record_type'])) ?> · <?= e($r['t_first'] . ' ' . $r['t_last']) ?> · <?= e(date('M j, Y g:i A', strtotime($r['observed_at']))) ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div>
        <div class="card" id="privacy">
            <div class="card-title" style="font-size: 1rem;">Parent visibility</div>
            <?php if (!$case['is_mental_health']): ?>
                <p class="muted" style="font-size: 0.82rem; margin: 8px 0 0; line-height: 1.5;">Parents of linked students can see this case, its plans and shareable notes. Confidential notes are never shown.</p>
            <?php else: ?>
                <div class="notice <?= $case['is_parent_accessible'] ? 'notice-warn' : 'notice-info' ?>" style="margin: 10px 0 12px;">
                    <?= icon('shield') ?>
                    <span><?= $case['is_parent_accessible']
                        ? 'The student agreed to share this case. Parents can see the title, family summary, plans and shareable notes.'
                        : 'Private. Parents only see a locked "private" card. Share only with the student\'s consent.' ?></span>
                </div>
                <button type="button" class="btn btn-soft btn-sm" data-open="modalConsent"><?= $case['is_parent_accessible'] ? 'Record withdrawal of consent' : 'Record student consent to share' ?></button>
                <?php if ($consents): ?>
                    <span class="section-label">History</span>
                    <?php foreach ($consents as $c): ?>
                        <div style="font-size: 0.78rem; padding: 6px 0; border-top: 1px dashed var(--border);">
                            <strong><?= $c['granted'] ? 'Shared' : 'Made private' ?></strong> · <?= e(label($c['consent_method'])) ?>
                            <div class="muted"><?= e($c['first_name'] . ' ' . $c['last_name']) ?> · <?= e(date('M j, Y', strtotime($c['created_at']))) ?><?= $c['notes'] ? ' · ' . e($c['notes']) : '' ?></div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <div class="card" id="sessions">
            <div class="card-head-flex"><div class="card-title" style="font-size: 1rem;">Counseling sessions</div></div>
            <?php if (!$sessions): ?><div class="empty" style="padding: 10px;">None yet.</div><?php endif; ?>
            <?php foreach ($sessions as $s): ?>
                <div style="padding: 10px 0; border-top: 1px solid var(--border);">
                    <div style="display: flex; justify-content: space-between; gap: 8px;">
                        <div><strong style="font-size: 0.84rem;"><?= e(SESSION_TYPES[$s['session_type']]) ?></strong>
                            <div class="muted" style="font-size: 0.74rem;"><?= e(date('D M j, g:i A', strtotime($s['scheduled_start']))) ?> · <?= e($s['c_first']) ?></div></div>
                        <?= badge(label($s['status']), ['scheduled' => 'blue', 'completed' => 'green', 'cancelled' => 'gray', 'no_show' => 'amber'][$s['status']]) ?>
                    </div>
                    <?php if ($s['status'] === 'scheduled'): ?><div style="margin-top: 8px;"><?php session_status_buttons((int)$s['id'], 'case_bip_manager.php?case=' . $caseId); ?></div><?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>

        <?php wellbeing_legend_card(true); ?>
    </div>
</div>

<div id="modalEditCase" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="ecTitle" aria-hidden="true" <?= $reopen === 'modalEditCase' ? 'data-autoopen' : '' ?>>
    <form class="modal-card" method="post" action="case_bip_manager.php?case=<?= $caseId ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update_case"><input type="hidden" name="case_id" value="<?= $caseId ?>">
        <div class="modal-head">
            <div class="modal-icon ic-violet"><?= icon('users') ?></div>
            <div><h3 id="ecTitle">Edit case</h3><p>Every change is recorded in the audit log.</p></div>
            <button type="button" class="modal-close" data-close aria-label="Close">✕</button>
        </div>
        <div class="modal-body">
            <div class="form-grid">
                <div class="field span-2"><label for="ec_title">Title</label><input id="ec_title" name="title" maxlength="150" value="<?= e($form['title']) ?>" required></div>
                <div class="field"><label for="ec_status">Status</label><select id="ec_status" name="status"><?= opts(CASE_STATUS, $form['status']) ?></select></div>
                <div class="field"><label for="ec_risk">Risk level</label><select id="ec_risk" name="risk_level"><?= opts(RISK_LEVELS, $form['risk_level']) ?></select></div>
                <div class="field"><label for="ec_type">Type</label><select id="ec_type" name="case_type"><?= opts(CASE_TYPES, $form['case_type']) ?></select></div>
                <div class="field"><label for="ec_counselor">Counselor</label><select id="ec_counselor" name="counselor_id"><?= opts($counselorOpts, $form['counselor_id']) ?></select></div>
                <div class="field span-2">
                    <?php if ($case['is_mental_health']): ?>
                        <div class="notice notice-info"><?= icon('shield') ?><span>This case is marked as mental health and stays that way, so it can't become visible to parents by accident. Use “Parent visibility” to share it with the student's consent.</span></div>
                    <?php else: ?>
                        <label class="check"><input type="checkbox" name="is_mental_health" value="1" <?= !empty($form['is_mental_health']) ? 'checked' : '' ?>> Involves mental health <span class="muted">(hides it from parents unless the student consents)</span></label>
                    <?php endif; ?>
                </div>
                <div class="field span-2"><label for="ec_summary">Summary for families</label><input id="ec_summary" name="parent_summary" maxlength="255" value="<?= e($form['parent_summary']) ?>"></div>
            </div>
        </div>
        <div class="modal-foot">
            <button type="button" class="btn btn-soft" data-close>Cancel</button>
            <button type="submit" class="btn btn-primary">Save changes</button>
        </div>
    </form>
</div>

<?php $pf = $reopen === 'modalAddPlan' ? $_POST : ['plan_type' => 'counseling', 'status' => 'active', 'start_date' => date('Y-m-d'), 'review_date' => date('Y-m-d', strtotime('+14 days'))]; ?>
<div id="modalAddPlan" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="apTitle" aria-hidden="true" <?= $reopen === 'modalAddPlan' ? 'data-autoopen' : '' ?>>
    <form class="modal-card" method="post" action="case_bip_manager.php?case=<?= $caseId ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add_intervention"><input type="hidden" name="case_id" value="<?= $caseId ?>">
        <div class="modal-head">
            <div class="modal-icon ic-green"><?= icon('heart') ?></div>
            <div><h3 id="apTitle">Add an intervention plan</h3><p>Advisers see active plans so they know how to support the student.</p></div>
            <button type="button" class="modal-close" data-close aria-label="Close">✕</button>
        </div>
        <div class="modal-body">
            <div class="form-grid">
                <div class="field"><label for="ap_type">Plan type</label><select id="ap_type" name="plan_type"><?= opts(PLAN_TYPES, $pf['plan_type'] ?? '') ?></select></div>
                <div class="field"><label for="ap_status">Status</label><select id="ap_status" name="status"><?= opts(PLAN_STATUS, $pf['status'] ?? 'active') ?></select></div>
                <div class="field span-2"><label for="ap_goals">Goal</label><textarea id="ap_goals" name="goals" rows="2" required placeholder="What should change? e.g. Attend all classes for 3 weeks"><?= e($pf['goals'] ?? '') ?></textarea></div>
                <div class="field span-2"><label for="ap_strat">Strategies <span class="muted" style="font-weight: 500;">(optional)</span></label><textarea id="ap_strat" name="strategies" rows="2" placeholder="How? e.g. Weekly check-in; adviser praises on-time arrival"><?= e($pf['strategies'] ?? '') ?></textarea></div>
                <div class="field"><label for="ap_start">Start date</label><input id="ap_start" type="date" name="start_date" value="<?= e($pf['start_date'] ?? '') ?>" required></div>
                <div class="field"><label for="ap_review">Review date</label><input id="ap_review" type="date" name="review_date" value="<?= e($pf['review_date'] ?? '') ?>"></div>
            </div>
        </div>
        <div class="modal-foot">
            <button type="button" class="btn btn-soft" data-close>Cancel</button>
            <button type="submit" class="btn btn-primary">Add plan</button>
        </div>
    </form>
</div>

<?php foreach ($plans as $p):
    $pid = (int)$p['id'];
    $pv = $reopen === "plan-$pid" ? $_POST + $p : $p; ?>
<div id="plan-<?= $pid ?>" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="up-<?= $pid ?>" aria-hidden="true" <?= $reopen === "plan-$pid" ? 'data-autoopen' : '' ?>>
    <form class="modal-card" method="post" action="case_bip_manager.php?case=<?= $caseId ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update_intervention"><input type="hidden" name="case_id" value="<?= $caseId ?>"><input type="hidden" name="intervention_id" value="<?= $pid ?>">
        <div class="modal-head">
            <div class="modal-icon ic-green"><?= icon('heart') ?></div>
            <div><h3 id="up-<?= $pid ?>">Update plan: <?= e(PLAN_TYPES[$p['plan_type']]) ?></h3><p>Log a progress check-in, change the status, or edit the plan.</p></div>
            <button type="button" class="modal-close" data-close aria-label="Close">✕</button>
        </div>
        <div class="modal-body">
            <span class="section-label" style="margin-top: 0;">Progress check-in</span>
            <div class="choice-row" style="grid-template-columns: repeat(5, 1fr); margin-bottom: 10px;">
                <?php foreach (PROGRESS as $k => [$lbl]): ?>
                    <label class="choice"><input type="radio" name="progress" value="<?= $k ?>" <?= ($pv['progress'] ?? '') === $k ? 'checked' : '' ?>><span style="font-size: 0.78rem; text-align: center;"><?= e($lbl) ?></span></label>
                <?php endforeach; ?>
            </div>
            <div class="field"><textarea name="progress_note" rows="2" aria-label="Progress note" placeholder="What did you observe since the last check-in?"><?= e($pv['progress_note'] ?? '') ?></textarea>
                <span class="hint">Leave blank to only edit the plan. “Goal achieved” marks the plan completed.</span></div>

            <span class="section-label">Plan details</span>
            <div class="form-grid">
                <div class="field"><label>Plan type</label><select name="plan_type"><?= opts(PLAN_TYPES, $pv['plan_type']) ?></select></div>
                <div class="field"><label>Status</label><select name="status"><?= opts(PLAN_STATUS, $pv['status']) ?></select></div>
                <div class="field span-2"><label>Goal</label><textarea name="goals" rows="2" required><?= e($pv['goals']) ?></textarea></div>
                <div class="field span-2"><label>Strategies</label><textarea name="strategies" rows="2"><?= e($pv['strategies']) ?></textarea></div>
                <div class="field"><label>Start date</label><input type="date" name="start_date" value="<?= e($pv['start_date']) ?>" required></div>
                <div class="field"><label>Review date</label><input type="date" name="review_date" value="<?= e($pv['review_date']) ?>"></div>
            </div>
        </div>
        <div class="modal-foot">
            <button type="button" class="btn btn-soft" data-close>Cancel</button>
            <button type="submit" class="btn btn-primary">Save update</button>
        </div>
    </form>
</div>
<?php endforeach; ?>

<?php if ($case['is_mental_health']): $grant = !$case['is_parent_accessible']; ?>
<div id="modalConsent" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="csTitle" aria-hidden="true" <?= $reopen === 'modalConsent' ? 'data-autoopen' : '' ?>>
    <form class="modal-card" method="post" action="case_bip_manager.php?case=<?= $caseId ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="consent"><input type="hidden" name="case_id" value="<?= $caseId ?>"><input type="hidden" name="grant" value="<?= $grant ? '1' : '0' ?>">
        <div class="modal-head">
            <div class="modal-icon ic-violet"><?= icon('shield') ?></div>
            <div><h3 id="csTitle"><?= $grant ? 'Share this case with parents' : 'Make this case private again' ?></h3>
                <p><?= $grant ? 'Only record this after the student has agreed.' : 'Record that the student withdrew consent.' ?></p></div>
            <button type="button" class="modal-close" data-close aria-label="Close">✕</button>
        </div>
        <div class="modal-body">
            <div class="form-grid">
                <div class="field span-2"><label for="cs_method">How did the student <?= $grant ? 'give' : 'withdraw' ?> consent?</label>
                    <select id="cs_method" name="consent_method" required><?= opts(['' => 'Choose one…', 'verbal_in_session' => 'Verbally, during a session', 'written_form' => 'Signed a written form', 'student_portal' => 'Through the student portal'], '') ?></select></div>
                <div class="field span-2"><label for="cs_notes">Notes <span class="muted" style="font-weight: 500;">(optional)</span></label><input id="cs_notes" name="notes" maxlength="255" placeholder="e.g. Agreed to share plans only, not session details"></div>
            </div>
            <?php if ($grant): ?><div class="notice notice-warn" style="margin-top: 12px;"><?= icon('bell') ?><span>Parents will see the case title, family summary, plans and shareable notes. Confidential notes stay hidden.</span></div><?php endif; ?>
        </div>
        <div class="modal-foot">
            <button type="button" class="btn btn-soft" data-close>Cancel</button>
            <button type="submit" class="btn btn-primary"><?= $grant ? 'Record consent and share' : 'Make private' ?></button>
        </div>
    </form>
</div>
<?php endif; ?>

<?php
    schedule_session_modal($students, $reopen === 'modalSession' ? $_POST : ['student_id' => $sid, 'case_id' => $caseId, 'priority' => $case['is_mental_health'] ? 'priority' : 'routine'], $reopen === 'modalSession', 'case_bip_manager.php?case=' . $caseId);
    layout_end();
    exit;
}

$fStatus  = (string)query('status', 'active');
$fRisk    = (string)query('risk', '');
$fStudent = (int)query('student', 0);
$fQ       = trim((string)query('q', ''));

$where = []; $params = [];
if ($fStatus === 'active')                 { $where[] = "gc.status <> 'closed'"; }
elseif (isset(CASE_STATUS[$fStatus]))      { $where[] = 'gc.status = ?'; $params[] = $fStatus; }
if (isset(RISK_LEVELS[$fRisk]))            { $where[] = 'gc.risk_level = ?'; $params[] = $fRisk; }
if ($fStudent)                             { $where[] = 'gc.student_id = ?'; $params[] = $fStudent; }
if ($fQ !== '') {
    // Case-insensitive on every database (PostgreSQL's LIKE is case-sensitive).
    $like = '%' . mb_strtolower(addcslashes($fQ, '\\%_'), 'UTF-8') . '%';
    $where[] = "(LOWER(s.first_name) LIKE ? OR LOWER(s.last_name) LIKE ? OR LOWER(s.first_name || ' ' || s.last_name) LIKE ? OR LOWER(s.student_no) LIKE ? OR LOWER(gc.title) LIKE ?)";
    array_push($params, $like, $like, $like, $like, $like);
}

$cases = db_all(
    "SELECT gc.*, s.first_name, s.last_name, s.student_no, sec.grade_level, sec.name AS section_name,
            c.first_name AS c_first, c.last_name AS c_last,
            (SELECT COUNT(*) FROM interventions i WHERE i.case_id = gc.id AND i.status IN ('planned','active')) AS active_plans
       FROM guidance_cases gc
       JOIN students s ON s.id = gc.student_id
       LEFT JOIN sections sec ON sec.id = s.section_id
       LEFT JOIN users c ON c.id = gc.counselor_id
      " . ($where ? 'WHERE ' . implode(' AND ', $where) : '') . "
      ORDER BY CASE gc.status WHEN 'open' THEN 0 WHEN 'monitoring' THEN 1 ELSE 2 END,
               CASE gc.risk_level WHEN 'critical' THEN 0 WHEN 'high' THEN 1 WHEN 'moderate' THEN 2 ELSE 3 END,
               gc.updated_at DESC",
    $params
);
$counts = db_one("SELECT SUM(CASE WHEN status='open' THEN 1 ELSE 0 END) AS open_n, SUM(CASE WHEN status='monitoring' THEN 1 ELSE 0 END) AS mon_n,
                         SUM(CASE WHEN status<>'closed' AND risk_level IN ('high','critical') THEN 1 ELSE 0 END) AS hi_n FROM guidance_cases");
$activePlans = (int)db_value("SELECT COUNT(*) FROM interventions i JOIN guidance_cases gc ON gc.id = i.case_id WHERE i.status IN ('planned','active') AND gc.status <> 'closed'");
$wb = wellbeing_status(array_column($cases, 'student_id'));
$fs = $fStudent ? db_one('SELECT first_name, last_name FROM students WHERE id = ?', [$fStudent]) : null;
$studentName = $fs ? $fs['first_name'] . ' ' . $fs['last_name'] : null;

layout_start($user, 'bip', 'Case & intervention manager', 'Review and edit student cases, write remarks, and track intervention progress.');
?>

<div class="stat-grid-4">
    <div class="stat-card-box"><div class="stat-icon-wrap ic-violet"><?= icon('users') ?></div><div class="stat-info"><span class="stat-lbl">Open cases</span><div class="stat-val"><?= (int)$counts['open_n'] ?></div></div></div>
    <div class="stat-card-box"><div class="stat-icon-wrap ic-blue"><?= icon('search') ?></div><div class="stat-info"><span class="stat-lbl">Monitoring</span><div class="stat-val"><?= (int)$counts['mon_n'] ?></div></div></div>
    <div class="stat-card-box"><div class="stat-icon-wrap ic-red"><?= icon('bell') ?></div><div class="stat-info"><span class="stat-lbl">High / critical risk</span><div class="stat-val"><?= (int)$counts['hi_n'] ?></div></div></div>
    <div class="stat-card-box"><div class="stat-icon-wrap ic-green"><?= icon('heart') ?></div><div class="stat-info"><span class="stat-lbl">Active plans</span><div class="stat-val"><?= $activePlans ?></div></div></div>
</div>

<div class="card">
    <div class="card-head-flex">
        <div>
            <div class="card-title">Cases<?= $studentName ? ' · ' . e($studentName) : '' ?></div>
            <div class="card-sub">Sorted by status and risk. Click a case to edit it, add remarks or update plans.</div>
        </div>
        <button type="button" class="btn btn-primary btn-sm" data-open="modalOpenCase">+ Open new case</button>
    </div>

    <form class="filters" method="get" action="case_bip_manager.php" style="margin-bottom: 18px;">
        <?php if ($fStudent): ?><input type="hidden" name="student" value="<?= $fStudent ?>"><?php endif; ?>
        <div class="field" style="flex: 1; min-width: 220px;"><label for="f_q">Search</label><input id="f_q" type="search" name="q" value="<?= e($fQ) ?>" placeholder="Student name, ID or case title"></div>
        <div class="field"><label for="f_status">Status</label><select id="f_status" name="status"><?= opts(['active' => 'Open + monitoring', 'open' => 'Open', 'monitoring' => 'Monitoring', 'closed' => 'Closed', 'all' => 'All'], $fStatus) ?></select></div>
        <div class="field"><label for="f_risk">Risk</label><select id="f_risk" name="risk"><?= opts(['' => 'Any risk'] + RISK_LEVELS, $fRisk) ?></select></div>
        <button class="btn btn-soft" type="submit">Filter</button>
        <?php if ($fQ !== '' || $fRisk !== '' || $fStudent || $fStatus !== 'active'): ?><a class="card-link" href="case_bip_manager.php" style="padding-bottom: 10px;">Clear</a><?php endif; ?>
    </form>

    <?php if (!$cases): ?>
        <div class="empty">No cases match these filters.</div>
    <?php else: ?>
    <div class="case-grid">
        <?php foreach ($cases as $c): ?>
            <a class="case-card" href="case_bip_manager.php?case=<?= (int)$c['id'] ?>">
                <div class="case-card-top">
                    <div class="case-card-student">
                        <strong><?= e($c['first_name'] . ' ' . $c['last_name']) ?></strong>
                        <span><?= e($c['student_no']) ?><?= $c['grade_level'] ? ' · Grade ' . e($c['grade_level']) . ' – ' . e($c['section_name']) : '' ?></span>
                    </div>
                    <?= wellbeing_badge($wb[(int)$c['student_id']]) ?>
                </div>
                <div>
                    <div class="case-card-title"><?= e($c['title']) ?></div>
                    <div class="case-card-meta"><?= e(CASE_TYPES[$c['case_type']]) ?><?= $c['is_mental_health'] ? ($c['is_parent_accessible'] ? ' · Shared with parents' : ' · Private from parents') : '' ?> · <?= (int)$c['active_plans'] ?> active plan<?= (int)$c['active_plans'] === 1 ? '' : 's' ?></div>
                </div>
                <div class="tags">
                    <?= badge(RISK_LEVELS[$c['risk_level']] . ' risk', RISK_TONE[$c['risk_level']]) ?>
                    <?= badge(CASE_STATUS[$c['status']], ['open' => 'purple', 'monitoring' => 'blue', 'closed' => 'gray'][$c['status']]) ?>
                </div>
                <div class="case-card-foot">
                    <span><?= e($c['c_first'] ? $c['c_first'] . ' ' . $c['c_last'] : 'No counselor yet') ?> · Updated <?= e(date('M j', strtotime($c['updated_at']))) ?></span>
                    <span class="case-card-open">Open case ›</span>
                </div>
            </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<?php
wellbeing_legend_card(false, true);
open_case_modal($students, $reopen === 'modalOpenCase' ? $_POST : ['student_id' => $fStudent ?: ''], $reopen === 'modalOpenCase');
layout_end();

<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require __DIR__ . '/inc/guidance_ui.php';
$user = require_page('guidance_desk');

$students = visible_students($user);
$reopen = null;

if (is_post()) {
    verify_csrf();
    $action = (string)post('action');

    if (in_array($action, ['report_ack', 'report_close', 'report_link'], true)) {
        $err = guidance_report_action($user, (int)post('report_id'), substr($action, 7) === 'ack' ? 'acknowledge' : substr($action, 7), (int)post('case_id', 0));
        flash($err ? 'error' : 'success', $err ?: ['report_ack' => 'Report marked as seen. The teacher can see it was picked up.',
            'report_close' => 'Report closed — no follow-up needed.', 'report_link' => 'Report added to the case.'][$action]);
        redirect('guidance_desk.php');
    }

    if ($action === 'open_case') {
        [$caseId, $err] = guidance_open_case($user, $_POST);
        if ($err) { flash('error', $err); $reopen = post('report_id') ? 'report-' . (int)post('report_id') : 'modalOpenCase'; }
        else { flash('success', 'Case opened.'); redirect('case_bip_manager.php?case=' . $caseId); }
    }

    if ($action === 'schedule_session') {
        $err = guidance_schedule_session($user, $_POST);
        if ($err) { flash('error', $err); $reopen = 'modalSession'; }
        else { flash('success', 'Session scheduled.'); redirect('guidance_desk.php#sessions'); }
    }

    if ($action === 'session_status') {
        $err = guidance_set_session_status($user, (int)post('session_id'), (string)post('status'));
        flash($err ? 'error' : 'success', $err ?: 'Session updated.');
        redirect('guidance_desk.php#sessions');
    }
}

$reports = db_all(
    "SELECT r.*, s.first_name, s.last_name, s.student_no, sec.grade_level, sec.name AS section_name,
            u.first_name AS t_first, u.last_name AS t_last
       FROM incident_reports r
       JOIN students s ON s.id = r.student_id
       LEFT JOIN sections sec ON sec.id = s.section_id
       JOIN users u ON u.id = r.reported_by
      WHERE r.routed_to = 'guidance' AND r.status IN ('new','acknowledged')
      ORDER BY CASE WHEN r.status = 'new' THEN 0 ELSE 1 END,
               CASE r.severity WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 WHEN 'moderate' THEN 2 ELSE 3 END,
               r.observed_at DESC"
);
$newReports = count(array_filter($reports, fn($r) => $r['status'] === 'new'));

$openCases = [];
foreach (db_all("SELECT id, student_id, title FROM guidance_cases WHERE status <> 'closed' ORDER BY opened_at DESC") as $c) {
    $openCases[(int)$c['student_id']][(int)$c['id']] = $c['title'];
}
$caseCounts = db_one("SELECT COUNT(*) AS n, SUM(CASE WHEN risk_level IN ('high','critical') THEN 1 ELSE 0 END) AS hi FROM guidance_cases WHERE status <> 'closed'");

$weekEnd = date('Y-m-d 23:59:59', strtotime('+6 days'));
$sessions = db_all(
    "SELECT cs.*, s.first_name, s.last_name, c.first_name AS c_first, c.last_name AS c_last
       FROM counseling_sessions cs
       JOIN students s ON s.id = cs.student_id
       JOIN users c ON c.id = cs.counselor_id
      WHERE cs.status = 'scheduled' AND cs.scheduled_start BETWEEN ? AND ?
      ORDER BY cs.scheduled_start",
    [date('Y-m-d 00:00:00', strtotime('-7 days')), $weekEnd]
);
$today = date('Y-m-d');
$sessionsToday = count(array_filter($sessions, fn($s) => substr($s['scheduled_start'], 0, 10) === $today));

$status = wellbeing_status(array_column($students, 'id'));
$attention = array_filter($students, fn($s) => $status[(int)$s['id']]['level'] !== 'green');
usort($attention, fn($a, $b) => ($status[(int)$a['id']]['level'] === 'red' ? 0 : 1) <=> ($status[(int)$b['id']]['level'] === 'red' ? 0 : 1));
$redCount = count(array_filter($status, fn($s) => $s['level'] === 'red'));

layout_start($user, 'guidance', 'Guidance desk', 'Incoming reports, students needing attention, and this week\'s sessions.');
?>

<div style="display: flex; justify-content: flex-end; gap: 10px; margin-bottom: 20px;">
    <button type="button" class="btn btn-soft" data-open="modalSession"><?= icon('bell') ?> Schedule session</button>
    <button type="button" class="btn btn-primary" data-open="modalOpenCase">+ Open new case</button>
</div>

<div class="stat-grid-4">
    <a class="stat-card-box" href="#reports">
        <div class="stat-icon-wrap ic-amber"><?= icon('clipboard') ?></div>
        <div class="stat-info"><span class="stat-lbl">Reports to review</span><div class="stat-val"><?= $newReports ?></div>
            <span class="stat-note"><?= count($reports) - $newReports ?> seen, not yet resolved</span></div>
    </a>
    <a class="stat-card-box" href="case_bip_manager.php">
        <div class="stat-icon-wrap ic-violet"><?= icon('users') ?></div>
        <div class="stat-info"><span class="stat-lbl">Open cases</span><div class="stat-val"><?= (int)$caseCounts['n'] ?></div>
            <span class="stat-note <?= $caseCounts['hi'] ? 'note-amber' : '' ?>"><?= (int)$caseCounts['hi'] ?> high or critical risk</span></div>
    </a>
    <a class="stat-card-box" href="#sessions">
        <div class="stat-icon-wrap ic-blue"><?= icon('bell') ?></div>
        <div class="stat-info"><span class="stat-lbl">Sessions this week</span><div class="stat-val"><?= count($sessions) ?></div>
            <span class="stat-note"><?= $sessionsToday ?> today</span></div>
    </a>
    <a class="stat-card-box" href="#attention">
        <div class="stat-icon-wrap ic-red"><?= icon('heart') ?></div>
        <div class="stat-info"><span class="stat-lbl">Students needing attention</span><div class="stat-val"><?= count($attention) ?></div>
            <span class="stat-note <?= $redCount ? 'note-amber' : '' ?>"><?= $redCount ?> need immediate intervention</span></div>
    </a>
</div>

<div class="grid-main-side">
    <div>
        <div class="card" id="reports">
            <div class="card-head-flex">
                <div>
                    <div class="card-title">Incoming teacher reports</div>
                    <div class="card-sub">Reports teachers sent to the Guidance Office. Review each one and decide the next step.</div>
                </div>
            </div>
            <?php if (!$reports): ?>
                <div class="empty">All caught up — no reports waiting.</div>
            <?php else: ?>
            <table class="table">
                <thead><tr><th>Student</th><th>Report</th><th>Severity</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($reports as $r): $rid = (int)$r['id']; ?>
                    <tr>
                        <td style="white-space: nowrap;">
                            <strong><?= e($r['first_name'] . ' ' . $r['last_name']) ?></strong>
                            <div class="muted" style="font-size: 0.72rem;">G<?= e($r['grade_level']) ?> <?= e($r['section_name']) ?></div>
                        </td>
                        <td>
                            <?= e($r['title']) ?>
                            <div class="muted" style="font-size: 0.74rem; margin-top: 2px;"><?= e(label($r['record_type'])) ?> · from <?= e($r['t_first'] . ' ' . $r['t_last']) ?> · <?= e(date('M j, g:i A', strtotime($r['observed_at']))) ?></div>
                        </td>
                        <td><?= badge(ucfirst($r['severity']), ['urgent' => 'red', 'high' => 'red', 'moderate' => 'amber', 'low' => 'gray'][$r['severity']]) ?></td>
                        <td><?= $r['status'] === 'new' ? badge('New', 'amber') : badge('Seen', 'blue') ?></td>
                        <td style="text-align: right;"><button type="button" class="btn btn-soft btn-sm" data-open="report-<?= $rid ?>">Review</button></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>

        <div class="card" id="attention">
            <div class="card-head-flex">
                <div>
                    <div class="card-title">Students needing attention</div>
                    <div class="card-sub">Red and Yellow students, based on the criteria in the legend below.</div>
                </div>
            </div>
            <?php if (!$attention): ?>
                <div class="empty">Every student is currently Green.</div>
            <?php else: ?>
            <table class="table">
                <thead><tr><th>Student</th><th>Status</th><th>Why</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($attention as $s): $st = $status[(int)$s['id']]; ?>
                    <tr>
                        <td style="white-space: nowrap;"><strong><?= e(student_name($s)) ?></strong>
                            <div class="muted" style="font-size: 0.72rem;">G<?= e($s['grade_level']) ?> <?= e($s['section_name']) ?></div></td>
                        <td><?= wellbeing_badge($st) ?></td>
                        <td class="muted" style="font-size: 0.8rem; line-height: 1.5;"><?= e(implode(' · ', array_unique($st['reasons']))) ?></td>
                        <td style="text-align: right;"><a class="btn btn-soft btn-sm" href="case_bip_manager.php?student=<?= (int)$s['id'] ?>">Cases</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>

        <?php wellbeing_legend_card(false, true); ?>
    </div>

    <div>
        <div class="card" id="sessions">
            <div class="card-head-flex">
                <div>
                    <div class="card-title" style="font-size: 1rem;">Upcoming sessions</div>
                    <div class="card-sub">Next 7 days. Mark each one when it's done.</div>
                </div>
            </div>
            <?php if (!$sessions): ?>
                <div class="empty">No sessions scheduled.</div>
            <?php endif; ?>
            <?php foreach ($sessions as $s):
                $isPast = $s['scheduled_end'] < now(); ?>
                <div style="padding: 12px 0; border-bottom: 1px solid var(--border);">
                    <div style="display: flex; justify-content: space-between; gap: 8px;">
                        <div>
                            <strong style="font-size: 0.875rem;"><?= e($s['first_name'] . ' ' . $s['last_name']) ?></strong>
                            <div class="muted" style="font-size: 0.75rem;"><?= e(SESSION_TYPES[$s['session_type']]) ?> · <?= e($s['c_first'] . ' ' . $s['c_last']) ?><?= $s['location'] ? ' · ' . e($s['location']) : '' ?></div>
                        </div>
                        <div style="text-align: right; white-space: nowrap;">
                            <div style="font-size: 0.8rem; font-weight: 700; color: <?= $isPast ? '#b45309' : '#0f172a' ?>;"><?= e(date('D M j', strtotime($s['scheduled_start']))) ?></div>
                            <div class="muted" style="font-size: 0.75rem;"><?= e(date('g:i A', strtotime($s['scheduled_start']))) ?></div>
                        </div>
                    </div>
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 8px;">
                        <?= $s['priority'] !== 'routine' ? badge(ucfirst($s['priority']), $s['priority'] === 'urgent' ? 'red' : 'amber') : ($isPast ? badge('Needs update', 'amber') : '<span></span>') ?>
                        <?php session_status_buttons((int)$s['id'], 'guidance_desk.php'); ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<?php foreach ($reports as $r):
    $rid = (int)$r['id'];
    $sid = (int)$r['student_id'];
    $suggestType = $r['record_type'] === 'mental_emotional' ? 'mental_health' : (['peer_conflict' => 'peer', 'academic' => 'academic'][$r['category']] ?? 'behavioral');
    $suggestRisk = ['urgent' => 'high', 'high' => 'high', 'moderate' => 'moderate', 'low' => 'low'][$r['severity']];
?>
<div id="report-<?= $rid ?>" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="rt-<?= $rid ?>" aria-hidden="true" <?= $reopen === "report-$rid" ? 'data-autoopen' : '' ?>>
    <div class="modal-card wide">
        <div class="modal-head">
            <div class="modal-icon ic-amber"><?= icon('clipboard') ?></div>
            <div>
                <h3 id="rt-<?= $rid ?>"><?= e($r['title']) ?></h3>
                <p><?= e($r['first_name'] . ' ' . $r['last_name']) ?> · <?= e(label($r['record_type'])) ?> · <?= e(label($r['category'])) ?> · reported by <?= e($r['t_first'] . ' ' . $r['t_last']) ?></p>
            </div>
            <button type="button" class="modal-close" data-close aria-label="Close">✕</button>
        </div>
        <div class="modal-body">
            <div class="form-grid" style="gap: 10px; margin-bottom: 14px;">
                <div class="info-tile"><span class="k">Observed</span><span class="v"><?= e(date('l, M j · g:i A', strtotime($r['observed_at']))) ?></span></div>
                <div class="info-tile"><span class="k">Severity</span><span class="v"><?= e(ucfirst($r['severity'])) ?></span></div>
                <div class="info-tile span-2"><span class="k">What the teacher observed</span><span class="v" style="font-weight: 500; white-space: pre-line;"><?= e($r['description']) ?></span></div>
                <?php if ($r['action_taken']): ?>
                    <div class="info-tile span-2"><span class="k">Action already taken</span><span class="v" style="font-weight: 500;"><?= e($r['action_taken']) ?></span></div>
                <?php endif; ?>
            </div>

            <span class="section-label">Next step</span>
            <div class="form-grid">
                <?php if (!empty($openCases[$sid])): ?>
                <form method="post" action="guidance_desk.php" class="info-tile">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="report_link"><input type="hidden" name="report_id" value="<?= $rid ?>">
                    <span class="k" style="margin-bottom: 6px;">Add to an existing case</span>
                    <div class="field" style="margin-bottom: 8px;"><select name="case_id" aria-label="Case"><?= opts($openCases[$sid]) ?></select></div>
                    <button class="btn btn-primary btn-sm" type="submit">Add to case</button>
                </form>
                <?php endif; ?>

                <form method="post" action="guidance_desk.php" class="info-tile <?= empty($openCases[$sid]) ? 'span-2' : '' ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="open_case"><input type="hidden" name="report_id" value="<?= $rid ?>"><input type="hidden" name="student_id" value="<?= $sid ?>">
                    <span class="k" style="margin-bottom: 6px;">Open a new case from this report</span>
                    <div class="field" style="margin-bottom: 8px;"><input name="title" value="<?= e($r['title']) ?>" maxlength="150" aria-label="Case title"></div>
                    <div class="form-grid" style="gap: 8px; margin-bottom: 8px;">
                        <div class="field"><select name="case_type" aria-label="Case type"><?= opts(CASE_TYPES, $suggestType) ?></select></div>
                        <div class="field"><select name="risk_level" aria-label="Risk level"><?= opts(RISK_LEVELS, $suggestRisk) ?></select></div>
                    </div>
                    <button class="btn btn-primary btn-sm" type="submit">Open case</button>
                </form>
            </div>
        </div>
        <div class="modal-foot" style="justify-content: space-between;">
            <form method="post" action="guidance_desk.php" class="inline-form">
                <?= csrf_field() ?><input type="hidden" name="action" value="report_close"><input type="hidden" name="report_id" value="<?= $rid ?>">
                <button class="btn btn-soft" type="submit">Close — no follow-up needed</button>
            </form>
            <?php if ($r['status'] === 'new'): ?>
            <form method="post" action="guidance_desk.php" class="inline-form">
                <?= csrf_field() ?><input type="hidden" name="action" value="report_ack"><input type="hidden" name="report_id" value="<?= $rid ?>">
                <button class="btn btn-soft" type="submit">Mark as seen, decide later</button>
            </form>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endforeach; ?>

<?php
open_case_modal($students, $reopen === 'modalOpenCase' ? $_POST : [], $reopen === 'modalOpenCase', 'guidance_desk.php');
schedule_session_modal($students, is_post() ? $_POST : [], $reopen === 'modalSession', 'guidance_desk.php');
layout_end();

<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
$user = require_page('parent_records');

$children = parent_children($user);
$child = selected_child($children);
if (!$child) {
    flash('error', "Your child's records appear once the school verifies your link.");
    redirect('parent_welfare_feed.php');
}
$sid = (int)$child['id'];

$clinic   = parent_clinic_view($user, $sid);
$guidance = parent_guidance_view($user, $sid);
$comms    = db_all('SELECT c.*, u.first_name AS by_first, u.last_name AS by_last FROM commendations c JOIN users u ON u.id = c.issued_by WHERE c.student_id = ? ORDER BY c.awarded_on DESC', [$sid]);
$att      = attendance_summary($sid, 30);
$plans    = array_sum(array_map(fn($c) => count(array_filter($c['interventions'], fn($i) => in_array($i['status'], ['planned', 'active'], true))), $guidance['cases']));

layout_start($user, 'records', 'School records', $child['first_name'] . ' ' . $child['last_name'] . ' · Grade ' . $child['grade_level'] . ' – ' . $child['section_name']);
?>

<?php if (count($children) > 1): ?>
<div class="chip-row" style="margin-bottom: 18px;">
    <?php foreach ($children as $c): ?>
        <a class="chip<?= (int)$c['id'] === $sid ? ' active' : '' ?>" style="text-decoration: none;" href="?child=<?= (int)$c['id'] ?>"><?= e($c['first_name'] . ' ' . $c['last_name']) ?></a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="stat-grid-4">
    <a class="stat-card-box" href="#clinic"><div class="stat-icon-wrap ic-red"><?= icon('heart') ?></div><div class="stat-info"><span class="stat-lbl">Clinic visits</span><div class="stat-val"><?= count($clinic) ?></div><span class="stat-note">this school year</span></div></a>
    <a class="stat-card-box" href="#guidance"><div class="stat-icon-wrap ic-violet"><?= icon('shield') ?></div><div class="stat-info"><span class="stat-lbl">Guidance support</span><div class="stat-val"><?= count($guidance['cases']) ?></div><span class="stat-note"><?= $plans ?> active plan<?= $plans === 1 ? '' : 's' ?></span></div></a>
    <a class="stat-card-box" href="#commendations"><div class="stat-icon-wrap ic-amber"><?= icon('heart') ?></div><div class="stat-info"><span class="stat-lbl">Commendations</span><div class="stat-val"><?= count($comms) ?></div></div></a>
    <div class="stat-card-box"><div class="stat-icon-wrap ic-green"><?= icon('clipboard') ?></div><div class="stat-info"><span class="stat-lbl">Attendance (30 days)</span><div class="stat-val"><?= $att['rate'] === null ? '—' : $att['rate'] . '%' ?></div><span class="stat-note"><?= $att['counts']['absent'] ?> absent · <?= $att['counts']['late'] ?> late</span></div></div>
</div>

<div class="card" id="clinic">
    <div class="card-head-flex"><div><div class="card-title">Clinic visits</div><div class="card-sub">Times, outcome and any medication given. Private visits show only the date and time.</div></div></div>
    <?php if (!$clinic): ?><div class="empty">No clinic visits recorded.</div><?php else: ?>
    <table class="table">
        <thead><tr><th>Date</th><th>Time in – out</th><th>Reason</th><th>Medication given</th><th>Outcome</th></tr></thead>
        <tbody>
        <?php foreach ($clinic as $v): ?>
            <tr>
                <td style="white-space: nowrap;"><strong><?= e(date('M j, Y', strtotime($v['arrived_at']))) ?></strong></td>
                <td class="muted" style="white-space: nowrap;"><?= e(date('g:i A', strtotime($v['arrived_at']))) ?> – <?= $v['departed_at'] ? e(date('g:i A', strtotime($v['departed_at']))) : '<em>still in clinic</em>' ?></td>
                <td style="font-size: 0.85rem;">
                    <?= e($v['complaint']) ?>
                    <?php if ($v['sensitive']): ?><div class="muted" style="font-size: 0.74rem; margin-top: 2px;">Details kept private</div><?php endif; ?>
                    <?php if ($v['temperature_c']): ?><div class="muted" style="font-size: 0.74rem;">Temp <?= e($v['temperature_c']) ?>°C</div><?php endif; ?>
                </td>
                <td style="font-size: 0.82rem; white-space: pre-line;"><?= $v['sensitive'] ? '<span class="muted">—</span>' : e($v['medication_notes'] ?: 'None') ?></td>
                <td><?= $v['case_status'] ? badge(...CLINIC_STATUS[$v['case_status']]) : '<span class="muted">—</span>' ?>
                    <?php if ($v['parent_notified']): ?><div class="muted" style="font-size: 0.72rem; margin-top: 3px;">Family was contacted</div><?php endif; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<div class="card" id="guidance">
    <div class="card-head-flex"><div><div class="card-title">Guidance Office</div><div class="card-sub">Support plans and remarks the Guidance Office has shared with families.</div></div></div>
    <?php if ($guidance['locked_count']): ?>
        <div class="notice notice-info" style="margin-bottom: 16px;"><?= icon('shield') ?>
            <span><strong>Private well-being support.</strong> <?= e($child['first_name']) ?> is also receiving support that is kept private unless <?= e($child['first_name']) ?> chooses to share it. Please contact the Guidance Office if you have concerns.</span></div>
    <?php endif; ?>
    <?php if (!$guidance['cases'] && !$guidance['locked_count']): ?><div class="empty">No guidance records shared with families.</div><?php endif; ?>
    <?php foreach ($guidance['cases'] as $i => $c): ?>
        <div style="<?= $i ? 'border-top: 1px solid var(--border); padding-top: 18px; margin-top: 18px;' : '' ?>">
            <div style="display: flex; justify-content: space-between; gap: 12px; align-items: flex-start;">
                <div>
                    <strong style="font-size: 1rem;"><?= e($c['title']) ?></strong>
                    <div class="muted" style="font-size: 0.78rem; margin-top: 2px;">Since <?= e(date('M j, Y', strtotime($c['opened_at']))) ?><?= $c['closed_at'] ? ' · closed ' . e(date('M j, Y', strtotime($c['closed_at']))) : '' ?></div>
                </div>
                <?= badge(CASE_STATUS[$c['status']] ?? $c['status'], ['open' => 'purple', 'monitoring' => 'blue', 'closed' => 'gray'][$c['status']] ?? 'gray') ?>
            </div>
            <?php if ($c['parent_summary']): ?><p style="font-size: 0.9rem; line-height: 1.6; color: #334155; margin: 10px 0 0;"><?= e($c['parent_summary']) ?></p><?php endif; ?>

            <?php if ($c['interventions']): ?>
                <span class="section-label">Support plan</span>
                <?php foreach ($c['interventions'] as $iv): ?>
                    <div class="info-tile" style="margin-bottom: 8px; display: flex; justify-content: space-between; gap: 12px;">
                        <div>
                            <span class="v" style="margin: 0;"><?= e(PLAN_TYPES[$iv['plan_type']] ?? label($iv['plan_type'])) ?> — <?= e($iv['goals']) ?></span>
                            <div class="muted" style="font-size: 0.74rem; margin-top: 3px;">Started <?= e(date('M j, Y', strtotime($iv['start_date']))) ?><?= $iv['review_date'] ? ' · next review ' . e(date('M j, Y', strtotime($iv['review_date']))) : '' ?></div>
                        </div>
                        <?= badge(PLAN_STATUS[$iv['status']] ?? $iv['status'], ['planned' => 'gray', 'active' => 'blue', 'completed' => 'green', 'discontinued' => 'gray'][$iv['status']] ?? 'gray') ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <?php if ($c['notes']): ?>
                <span class="section-label">Remarks from the Guidance Office</span>
                <?php foreach ($c['notes'] as $n): ?>
                    <div style="padding: 8px 0; border-bottom: 1px dashed var(--border); font-size: 0.875rem; line-height: 1.55;"><?= e($n['body']) ?>
                        <div class="muted" style="font-size: 0.72rem; margin-top: 2px;"><?= e(date('M j, Y', strtotime($n['created_at']))) ?></div></div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>

<div class="card" id="commendations">
    <div class="card-head-flex"><div><div class="card-title">Commendations</div><div class="card-sub">Recognition from <?= e($child['first_name']) ?>'s teachers.</div></div></div>
    <?php if (!$comms): ?><div class="empty">No commendations yet.</div><?php endif; ?>
    <?php foreach ($comms as $c): ?>
        <div style="display: flex; gap: 14px; padding: 12px 0; border-top: 1px solid var(--border);">
            <div class="stat-icon-wrap ic-amber" style="width: 38px; height: 38px;"><?= icon('heart') ?></div>
            <div style="flex: 1;">
                <strong><?= e($c['title']) ?></strong> <?= badge(ucfirst($c['category']), 'amber') ?>
                <?php if ($c['description']): ?><div style="font-size: 0.875rem; color: #475569; margin-top: 3px; line-height: 1.55;"><?= e($c['description']) ?></div><?php endif; ?>
                <div class="muted" style="font-size: 0.74rem; margin-top: 3px;"><?= e(date('M j, Y', strtotime($c['awarded_on']))) ?> · from <?= e($c['by_first'] . ' ' . $c['by_last']) ?></div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<?php layout_end(); ?>

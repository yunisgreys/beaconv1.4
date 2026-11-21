<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
$user = require_page('clinic_overview');

if (is_post()) {
    verify_csrf();
    $action = (string)post('action');
    if (in_array($action, ['report_ack', 'report_close'], true)) {
        $err = clinic_report_action($user, (int)post('report_id'), $action === 'report_ack' ? 'acknowledge' : 'close');
        flash($err ? 'error' : 'success', $err ?: ($action === 'report_ack' ? 'Report marked as seen.' : 'Report closed.'));
    }
    redirect('clinic_overview.php');
}

clinic_expire_passes();
$today      = date('Y-m-d 00:00:00');
$passes     = clinic_pending_passes();
$inClinic   = clinic_visits_where('v.departed_at IS NULL');
$todayDone  = clinic_visits_where('v.arrived_at >= ? AND v.departed_at IS NOT NULL', [$today]);
$reports    = clinic_reports();
$items      = inventory_items();
$lowItems   = array_values(array_filter($items, fn($i) => $i['status'] !== 'in_stock'));
$visitsToday = count($todayDone) + count(array_filter($inClinic, fn($v) => $v['arrived_at'] >= $today));
$yesterday  = (int)db_value('SELECT COUNT(*) FROM clinic_visits WHERE arrived_at >= ? AND arrived_at < ?', [date('Y-m-d 00:00:00', strtotime('-1 day')), $today]);
$awaitingGuardian = count(array_filter($inClinic, fn($v) => $v['case_status'] === 'guardian_contacted'));
$tz = config('timezone') ?: 'Asia/Manila';

layout_start($user, 'clinic', 'Clinic overview', 'Passes on the way, students in the clinic now, and supplies to watch.');
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; gap: 12px; flex-wrap: wrap;">
    <div class="muted" style="font-size: 0.85rem; font-weight: 600;">
        <span data-clock="date" data-server-time="<?= time() ?>" data-tz="<?= e($tz) ?>"><?= e(date('l · F j, Y')) ?></span>
        · <span class="live-clock" data-clock="time" data-server-time="<?= time() ?>" data-tz="<?= e($tz) ?>"><?= e(date('g:i:s A')) ?></span>
    </div>
    <a class="btn btn-primary" href="patient_intake.php">+ Check in a student</a>
</div>

<div class="stat-grid-4">
    <a class="stat-card-box" href="patient_intake.php#history">
        <div class="stat-icon-wrap ic-red"><?= icon('heart') ?></div>
        <div class="stat-info"><span class="stat-lbl">Visits today</span><div class="stat-val"><?= $visitsToday ?></div><span class="stat-note"><?= $yesterday ?> yesterday</span></div>
    </a>
    <a class="stat-card-box" href="patient_intake.php#queue">
        <div class="stat-icon-wrap ic-amber"><?= icon('users') ?></div>
        <div class="stat-info"><span class="stat-lbl">In the clinic now</span><div class="stat-val"><?= count($inClinic) ?></div><span class="stat-note"><?= $awaitingGuardian ?> awaiting guardian</span></div>
    </a>
    <a class="stat-card-box" href="#passes">
        <div class="stat-icon-wrap ic-blue"><?= icon('shield') ?></div>
        <div class="stat-info"><span class="stat-lbl">Passes on the way</span><div class="stat-val"><?= count($passes) ?></div><span class="stat-note">Issued by teachers</span></div>
    </a>
    <a class="stat-card-box" href="medical_inventory.php?filter=attention">
        <div class="stat-icon-wrap ic-violet"><?= icon('box') ?></div>
        <div class="stat-info"><span class="stat-lbl">Low / out of stock</span><div class="stat-val"><?= count($lowItems) ?></div><span class="stat-note <?= $lowItems ? 'note-amber' : '' ?>"><?= $lowItems ? 'Reorder needed' : 'All stocked' ?></span></div>
    </a>
</div>

<div class="grid-main-side">
    <div>
        <div class="card" id="passes">
            <div class="card-head-flex">
                <div><div class="card-title">Clinic passes on the way</div><div class="card-sub">Check the code on the student's pass when they arrive. Passes expire after 1 hour.</div></div>
            </div>
            <?php if (!$passes): ?><div class="empty">No students are on their way.</div><?php else: ?>
            <table class="table">
                <thead><tr><th>Student</th><th>Code</th><th>Reason</th><th>Urgency</th><th>Issued</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($passes as $p): ?>
                    <tr>
                        <td style="white-space: nowrap;"><strong><?= e($p['first_name'] . ' ' . $p['last_name']) ?></strong><div class="muted" style="font-size: 0.72rem;">G<?= e($p['grade_level']) ?> <?= e($p['section_name']) ?> · from <?= e($p['t_first'] . ' ' . $p['t_last']) ?></div></td>
                        <td class="mono"><strong><?= e($p['pass_code']) ?></strong></td>
                        <td style="font-size: 0.84rem;"><?= e($p['reason']) ?><div class="muted" style="font-size: 0.72rem;"><?= e(label($p['reason_type'])) ?></div></td>
                        <td><?= badge(ucfirst($p['urgency']), ['emergency' => 'red', 'urgent' => 'amber', 'routine' => 'gray'][$p['urgency']]) ?></td>
                        <td class="muted" style="font-size: 0.8rem; white-space: nowrap;"><?= e(date('g:i A', strtotime($p['issued_at']))) ?></td>
                        <td style="text-align: right;"><a class="btn btn-primary btn-sm" href="patient_intake.php?pass=<?= (int)$p['id'] ?>">Check in</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>

        <div class="card">
            <div class="card-head-flex">
                <div><div class="card-title">In the clinic now</div><div class="card-sub">Update status, medication and check-out on the Patient intake page.</div></div>
                <a class="card-link" href="patient_intake.php#queue">Open queue &gt;</a>
            </div>
            <?php if (!$inClinic): ?><div class="empty">Nobody is in the clinic right now.</div><?php else: ?>
            <table class="table">
                <thead><tr><th>Student</th><th>Checked in</th><th>Complaint</th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach ($inClinic as $v): ?>
                    <tr>
                        <td><strong><?= e($v['first_name'] . ' ' . $v['last_name']) ?></strong></td>
                        <td class="muted" style="white-space: nowrap;"><?= e(date('g:i A', strtotime($v['arrived_at']))) ?><?= substr($v['arrived_at'], 0, 10) !== date('Y-m-d') ? ' · ' . e(date('M j', strtotime($v['arrived_at']))) : '' ?></td>
                        <td style="font-size: 0.84rem;"><?= e($v['complaint']) ?></td>
                        <td><?= badge(...CLINIC_STATUS[$v['case_status']]) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>

        <div class="card" id="reports">
            <div class="card-head-flex">
                <div><div class="card-title">Reports from teachers</div><div class="card-sub">Physical or emotional concerns teachers sent to the clinic.</div></div>
            </div>
            <?php if (!$reports): ?><div class="empty">No reports waiting.</div><?php else: ?>
            <table class="table">
                <thead><tr><th>Student</th><th>Report</th><th>Severity</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($reports as $r): ?>
                    <tr>
                        <td style="white-space: nowrap;"><strong><?= e($r['first_name'] . ' ' . $r['last_name']) ?></strong><div class="muted" style="font-size: 0.72rem;">G<?= e($r['grade_level']) ?> <?= e($r['section_name']) ?></div></td>
                        <td>
                            <?= e($r['title']) ?> <?= $r['status'] === 'new' ? badge('New', 'amber') : badge('Seen', 'blue') ?>
                            <div style="font-size: 0.8rem; margin-top: 3px;"><?= e($r['description']) ?></div>
                            <div class="muted" style="font-size: 0.72rem; margin-top: 2px;"><?= e(label($r['record_type'])) ?> · <?= e($r['t_first'] . ' ' . $r['t_last']) ?> · <?= e(date('M j, g:i A', strtotime($r['observed_at']))) ?></div>
                        </td>
                        <td><?= badge(ucfirst($r['severity']), ['urgent' => 'red', 'high' => 'red', 'moderate' => 'amber', 'low' => 'gray'][$r['severity']]) ?></td>
                        <td>
                            <div class="row-actions">
                                <a class="btn btn-primary btn-sm" href="patient_intake.php?report=<?= (int)$r['id'] ?>">Start visit</a>
                                <?php if ($r['status'] === 'new'): ?>
                                <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="report_ack"><input type="hidden" name="report_id" value="<?= (int)$r['id'] ?>"><button class="btn btn-soft btn-sm">Seen</button></form>
                                <?php endif; ?>
                                <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="report_close"><input type="hidden" name="report_id" value="<?= (int)$r['id'] ?>"><button class="btn btn-soft btn-sm">Close</button></form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>

    <div>
        <div class="card">
            <div class="card-head-flex">
                <div><div class="card-title" style="font-size: 1rem;">Supplies to watch</div><div class="card-sub">Low, out of stock, or flagged.</div></div>
            </div>
            <?php if (!$lowItems): ?><div class="empty">Everything is in stock.</div><?php endif; ?>
            <?php foreach (array_slice($lowItems, 0, 8) as $i): ?>
                <div style="display: flex; justify-content: space-between; align-items: center; gap: 8px; padding: 10px 0; border-top: 1px solid var(--border);">
                    <div>
                        <strong style="font-size: 0.85rem;"><?= e($i['name']) ?></strong>
                        <div class="muted" style="font-size: 0.74rem;"><?= (int)$i['quantity'] ?> <?= e($i['unit']) ?> left<?= $i['requested_qty'] ? ' · ' . (int)$i['requested_qty'] . ' requested' : '' ?></div>
                    </div>
                    <?= badge(...STOCK_STATUS[$i['status']]) ?>
                </div>
            <?php endforeach; ?>
            <a class="btn btn-soft btn-sm" href="medical_inventory.php" style="margin-top: 12px;">Open inventory</a>
        </div>

        <div class="card">
            <div class="card-title" style="font-size: 1rem; margin-bottom: 6px;">Seen today</div>
            <?php if (!$todayDone): ?><div class="empty" style="padding: 10px;">No completed visits yet today.</div><?php endif; ?>
            <?php foreach ($todayDone as $v): ?>
                <div style="padding: 9px 0; border-top: 1px solid var(--border); font-size: 0.82rem;">
                    <strong><?= e($v['first_name'] . ' ' . $v['last_name']) ?></strong> · <?= e(date('g:i', strtotime($v['arrived_at']))) ?>–<?= e(date('g:i A', strtotime($v['departed_at']))) ?>
                    <div style="margin-top: 4px;"><?= badge(...CLINIC_STATUS[$v['case_status']]) ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<?php layout_end(); ?>

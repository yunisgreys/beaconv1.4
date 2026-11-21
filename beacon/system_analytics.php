<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
$user = require_page('system_analytics');

$feedRole = isset(STAFF_ROLES[query('feed', '')]) ? query('feed') : '';
// v1.3: both lists are paginated (?apage= for activity, ?lpage= for sign-ins).
[, $feedTotal]   = activity_feed($feedRole, 1, 0);
$feedPg          = paginate($feedTotal, LOG_PER_PAGE, 'apage');
[$feed]          = activity_feed($feedRole, LOG_PER_PAGE, $feedPg['offset']);
[, $loginTotal]  = recent_logins(1, 0);
$loginPg         = paginate($loginTotal, LOG_PER_PAGE, 'lpage');
[$logins]        = recent_logins(LOG_PER_PAGE, $loginPg['offset']);
$staff    = array_values(array_filter(staff_accounts(), fn($s) => $s['status'] === 'active'));
$parents  = parent_accounts();
$pending  = (int)db_value("SELECT COUNT(*) FROM parent_student_links WHERE status = 'pending'");
$today    = date('Y-m-d 00:00:00');
$loginsToday = db_one('SELECT SUM(CASE WHEN succeeded = 1 THEN 1 ELSE 0 END) AS ok, SUM(CASE WHEN succeeded = 0 THEN 1 ELSE 0 END) AS failed FROM login_attempts WHERE attempted_at >= ?', [$today]);
$onlineStaff = count(array_filter($staff, fn($s) => is_online($s['last_seen_at'])));
$onlineParents = count(array_filter($parents, fn($p) => $p['status'] === 'active' && is_online($p['last_seen_at'])));

$moduleToday = [
    'teacher'  => (int)db_value("SELECT COUNT(*) FROM audit_logs WHERE actor_role = 'teacher' AND action = 'create' AND created_at >= ?", [$today]),
    'guidance' => (int)db_value("SELECT COUNT(*) FROM audit_logs WHERE actor_role = 'guidance' AND action IN ('create','update') AND created_at >= ?", [$today]),
    'clinic'   => (int)db_value("SELECT COUNT(*) FROM audit_logs WHERE actor_role = 'clinic' AND action IN ('create','update') AND created_at >= ?", [$today]),
];

layout_start($user, 'analytics', 'Admin dashboard', 'System activity, sign-ins and who is online. Student records stay private to their offices.');
?>

<div class="stat-grid-4">
    <a class="stat-card-box" href="#roles">
        <div class="stat-icon-wrap ic-green"><?= icon('users') ?></div>
        <div class="stat-info"><span class="stat-lbl">Staff online now</span><div class="stat-val"><?= $onlineStaff ?></div><span class="stat-note">of <?= count($staff) ?> active staff accounts</span></div>
    </a>
    <a class="stat-card-box" href="#logins">
        <div class="stat-icon-wrap ic-blue"><?= icon('key') ?></div>
        <div class="stat-info"><span class="stat-lbl">Sign-ins today</span><div class="stat-val"><?= (int)$loginsToday['ok'] ?></div>
            <span class="stat-note <?= $loginsToday['failed'] ? 'note-amber' : '' ?>"><?= (int)$loginsToday['failed'] ?> failed attempt<?= $loginsToday['failed'] == 1 ? '' : 's' ?></span></div>
    </a>
    <a class="stat-card-box" href="admin_parents.php">
        <div class="stat-icon-wrap ic-amber"><?= icon('clipboard') ?></div>
        <div class="stat-info"><span class="stat-lbl">Parent links to review</span><div class="stat-val"><?= $pending ?></div><span class="stat-note <?= $pending ? 'note-amber' : '' ?>"><?= $pending ? 'Waiting for approval' : 'All reviewed' ?></span></div>
    </a>
    <a class="stat-card-box" href="admin_parents.php#accounts">
        <div class="stat-icon-wrap ic-violet"><?= icon('heart') ?></div>
        <div class="stat-info"><span class="stat-lbl">Family accounts</span><div class="stat-val"><?= count($parents) ?></div><span class="stat-note"><?= $onlineParents ?> online now</span></div>
    </a>
</div>

<div class="grid-main-side">
    <div>
        <div class="card" id="activity">
            <div class="card-head-flex">
                <div>
                    <div class="card-title">Recent activity</div>
                    <div class="card-sub">Updates from the Teacher, Guidance and Clinic modules. Today: <?= $moduleToday['teacher'] ?> teacher · <?= $moduleToday['guidance'] ?> guidance · <?= $moduleToday['clinic'] ?> clinic actions.</div>
                </div>
            </div>
            <div class="chip-row" style="margin-bottom: 14px;">
                <?php foreach (['' => 'All modules'] + STAFF_ROLES as $k => $lbl): ?>
                    <a class="chip<?= $feedRole === $k ? ' active' : '' ?>" href="system_analytics.php<?= $k || isset($_GET['lpage']) ? '?' . http_build_query(array_filter(['feed' => $k, 'lpage' => $_GET['lpage'] ?? ''])) : '' ?>#activity" style="text-decoration: none;"><?= e($lbl) ?></a>
                <?php endforeach; ?>
            </div>
            <?php if (!$feed): ?><div class="empty">No activity yet.</div><?php endif; ?>
            <div class="timeline">
            <?php foreach ($feed as $a): ?>
                <div class="timeline-item">
                    <?= role_pill($a['actor_role'], STAFF_ROLES[$a['actor_role']] ?? $a['actor_role']) ?>
                    <strong><?= e(trim($a['first_name'] . ' ' . $a['last_name']) ?: 'Someone') ?></strong> <?= e($a['label']) ?>
                    <div class="timeline-meta"><?= e(date('M j, g:i A', strtotime($a['created_at']))) ?></div>
                </div>
            <?php endforeach; ?>
            </div>
            <?= pager($feedPg, 'apage', 'activity') ?>
            <p class="muted" style="font-size: 0.75rem; margin: 10px 0 0;">Student names and record details are not shown here, to protect student privacy.</p>
        </div>

        <div class="card" id="logins">
            <div class="card-head-flex"><div><div class="card-title">Recent sign-ins</div><div class="card-sub">Successful and failed attempts. <?= LOGIN_MAX_FAILS ?> failures in a row lock that e-mail for <?= login_window_minutes() ?> minutes.</div></div></div>
            <?php if (!$logins): ?><div class="empty">No sign-ins yet.</div><?php else: ?>
            <table class="table">
                <thead><tr><th>Account</th><th>Role</th><th>Result</th><th>IP address</th><th>When</th></tr></thead>
                <tbody>
                <?php foreach ($logins as $l): ?>
                    <tr>
                        <td><strong><?= e($l['first_name'] ? $l['first_name'] . ' ' . $l['last_name'] : 'Unknown account') ?></strong><div class="muted" style="font-size: 0.74rem;"><?= e($l['email']) ?></div></td>
                        <td style="font-size: 0.82rem;"><?= $l['role_label'] ? e($l['role_label']) : '<span class="muted">—</span>' ?></td>
                        <td><?= $l['succeeded'] ? badge('Signed in', 'green') : badge('Failed', 'red') ?></td>
                        <td class="mono muted" style="font-size: 0.8rem;"><?= e($l['ip_address']) ?></td>
                        <td class="muted" style="white-space: nowrap; font-size: 0.82rem;"><?= e(date('M j, g:i A', strtotime($l['attempted_at']))) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?= pager($loginPg, 'lpage', 'logins') ?>
            <?php endif; ?>
        </div>
    </div>

    <div>
        <div class="card" id="roles">
            <div class="card-head-flex">
                <div><div class="card-title" style="font-size: 1rem;">Role status monitor</div><div class="card-sub">Online = active in the last <?= ONLINE_WINDOW / 60 ?> minutes.</div></div>
                <a class="card-link" href="admin_staff.php" style="font-size: 0.8rem;">Manage</a>
            </div>
            <?php foreach (['super_admin' => 'Super Admin'] + STAFF_ROLES as $role => $label):
                $members = array_filter($staff, fn($s) => $s['role'] === $role);
                $on = count(array_filter($members, fn($s) => is_online($s['last_seen_at']))); ?>
                <div style="padding: 12px 0; border-top: 1px solid var(--border);">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                        <strong style="font-size: 0.84rem;"><?= e($label) ?></strong>
                        <span class="muted" style="font-size: 0.74rem;"><?= $on ?>/<?= count($members) ?> online</span>
                    </div>
                    <?php if (!$members): ?><div class="muted" style="font-size: 0.78rem;">No accounts.</div><?php endif; ?>
                    <?php foreach ($members as $m): $online = is_online($m['last_seen_at']); ?>
                        <div style="display: flex; justify-content: space-between; align-items: center; gap: 8px; padding: 4px 0;">
                            <span style="display: flex; align-items: center; gap: 8px; font-size: 0.82rem;">
                                <span class="presence <?= $online ? 'on' : '' ?>" aria-hidden="true"></span>
                                <?= e($m['first_name'] . ' ' . $m['last_name']) ?>
                            </span>
                            <span class="muted" style="font-size: 0.72rem; white-space: nowrap;">
                                <?= $online ? '<span style="color:#15803d;font-weight:700">Online</span>' : 'Offline' . ($m['last_login_at'] ? ' · ' . e(date('M j, g:i A', strtotime($m['last_login_at']))) : ' · never signed in') ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
            <div style="padding: 12px 0 0; border-top: 1px solid var(--border); display: flex; justify-content: space-between; font-size: 0.82rem;">
                <strong>Parents / guardians</strong>
                <span class="muted"><?= $onlineParents ?>/<?= count($parents) ?> online</span>
            </div>
        </div>

        <div class="notice notice-info">
            <?= icon('shield') ?>
            <span>By design, the Super Admin manages accounts and access. <a href="admin_students.php" style="color: inherit; font-weight: 700;">Student records</a> show enrolment details only (read-only). Guidance and clinic records stay private to their offices.</span>
        </div>
    </div>
</div>

<style>
    .presence { width: 9px; height: 9px; border-radius: 50%; background: #cbd5e1; flex-shrink: 0; }
    .presence.on { background: #22c55e; box-shadow: 0 0 0 3px rgba(34,197,94,.18); }
</style>

<?php layout_end(); ?>

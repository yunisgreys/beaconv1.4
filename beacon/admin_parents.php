<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
$user = require_page('admin_parents');

$reopen = null;
if (is_post()) {
    verify_csrf();
    $action = (string)post('action');
    if (in_array($action, ['approve', 'reject', 'revoke', 'reopen'], true)) {
        $lid = (int)post('link_id');
        $err = admin_review_link($user, $lid, $action, (string)post('note', ''));
        if (!$err) {
            flash('success', ['approve' => 'Approved. The parent can now see this child in the Family Portal.', 'reject' => 'Request rejected.',
                              'revoke' => 'Access revoked. The parent no longer sees this child.', 'reopen' => 'Moved back to pending.'][$action]);
            redirect('admin_parents.php?show=' . urlencode((string)query('show', 'pending')));
        }
        flash('error', $err);
        $reopen = "$action-$lid";
    }
    if ($action === 'status') {
        $err = admin_set_status($user, (int)post('user_id'), (string)post('status'));
        flash($err ? 'error' : 'success', $err ?: 'Account updated.');
        redirect('admin_parents.php#accounts');
    }
}

$show = in_array(query('show'), ['pending', 'approved', 'closed', 'all'], true) ? query('show') : 'pending';
$links = parent_link_requests($show);
$counts = db_one("SELECT SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END) AS pending, SUM(CASE WHEN status='approved' THEN 1 ELSE 0 END) AS approved,
                         SUM(CASE WHEN status IN ('rejected','revoked') THEN 1 ELSE 0 END) AS closed, COUNT(*) AS total FROM parent_student_links");
$accounts = parent_accounts();
$matchLabel = ['name' => ['Matches guardian on file', 'green'], 'phone' => ['Phone matches guardian on file', 'green'],
               'surname' => ['Same surname as guardian on file', 'amber'], 'none' => ['Not the guardian on file — check before approving', 'red']];

layout_start($user, 'parents', 'Parent registrations', 'Review family accounts and approve which student each parent can see.');
?>

<div class="stat-grid-4">
    <a class="stat-card-box" href="?show=pending"><div class="stat-icon-wrap ic-amber"><?= icon('clipboard') ?></div><div class="stat-info"><span class="stat-lbl">Waiting for review</span><div class="stat-val"><?= (int)$counts['pending'] ?></div></div></a>
    <a class="stat-card-box" href="?show=approved"><div class="stat-icon-wrap ic-green"><?= icon('shield') ?></div><div class="stat-info"><span class="stat-lbl">Approved Links</span><div class="stat-val"><?= (int)$counts['approved'] ?></div></div></a>
    <a class="stat-card-box" href="?show=closed"><div class="stat-icon-wrap ic-red"><?= icon('bell') ?></div><div class="stat-info"><span class="stat-lbl">Rejected / Revoked</span><div class="stat-val"><?= (int)$counts['closed'] ?></div></div></a>
    <a class="stat-card-box" href="#accounts"><div class="stat-icon-wrap ic-violet"><?= icon('users') ?></div><div class="stat-info"><span class="stat-lbl">Family Accounts</span><div class="stat-val"><?= count($accounts) ?></div></div></a>
</div>

<div class="card">
    <div class="card-head-flex">
        <div><div class="card-title">Student link requests</div><div class="card-sub">Every request already passed the Student ID + birthdate check at sign-up. Compare the parent with the guardian on file before approving.</div></div>
    </div>
    <div class="chip-row" style="margin-bottom: 14px;">
        <?php foreach (['pending' => 'Pending', 'approved' => 'Approved', 'closed' => 'Rejected / revoked', 'all' => 'All'] as $k => $lbl): ?>
            <a class="chip<?= $show === $k ? ' active' : '' ?>" href="?show=<?= $k ?>" style="text-decoration: none;"><?= e($lbl) ?></a>
        <?php endforeach; ?>
    </div>

    <?php if (!$links): ?><div class="empty"><?= $show === 'pending' ? 'No requests waiting.' : 'Nothing here.' ?></div><?php endif; ?>
    <?php foreach ($links as $l): $lid = (int)$l['id']; $m = guardian_match($l); ?>
        <div class="link-row">
            <div class="form-grid" style="grid-template-columns: 1fr 1fr 1fr; gap: 12px; flex: 1;">
                <div>
                    <span class="section-label" style="margin: 0 0 4px;">Parent</span>
                    <strong><?= e($l['p_first'] . ' ' . $l['p_last']) ?></strong> <span class="muted">(<?= e($l['relationship']) ?>)</span>
                    <div class="muted" style="font-size: 0.78rem;"><?= e($l['email']) ?><?= $l['phone'] ? ' · ' . e($l['phone']) : '' ?></div>
                    <div class="muted" style="font-size: 0.72rem;">Registered <?= e(date('M j, Y g:i A', strtotime($l['registered_at']))) ?><?= $l['account_status'] === 'disabled' ? ' · <strong style="color:#b91c1c">account disabled</strong>' : '' ?></div>
                </div>
                <div>
                    <span class="section-label" style="margin: 0 0 4px;">Student Requested</span>
                    <strong><?= e($l['s_first'] . ' ' . $l['s_last']) ?></strong>
                    <div class="muted" style="font-size: 0.78rem;"><span class="mono"><?= e($l['student_no']) ?></span> · G<?= e($l['grade_level']) ?> <?= e($l['section_name']) ?></div>
                    <div style="font-size: 0.72rem; color: #15803d; font-weight: 600;">✓ Student ID + Birthdate verified</div>
                </div>
                <div>
                    <span class="section-label" style="margin: 0 0 4px;">Guardian on file</span>
                    <span style="font-size: 0.84rem;"><?= e($l['guardian_name'] ?: '—') ?><?= $l['guardian_relation'] ? ' (' . e($l['guardian_relation']) . ')' : '' ?></span>
                    <div class="muted" style="font-size: 0.78rem;"><?= e($l['guardian_contact'] ?: '') ?></div>
                    <div style="margin-top: 4px;"><?= badge(...$matchLabel[$m]) ?></div>
                </div>
            </div>
            <div style="min-width: 180px; text-align: right;">
                <div style="margin-bottom: 8px;"><?= badge(ucfirst($l['status']), ['pending' => 'amber', 'approved' => 'green', 'rejected' => 'red', 'revoked' => 'gray'][$l['status']]) ?></div>
                <?php if ($l['reviewed_at']): ?>
                    <div class="muted" style="font-size: 0.72rem; margin-bottom: 8px;"><?= e(ucfirst($l['status'])) ?> by <?= e($l['rv_first'] . ' ' . $l['rv_last']) ?> · <?= e(date('M j', strtotime($l['reviewed_at']))) ?><?= $l['review_note'] ? '<br>“' . e($l['review_note']) . '”' : '' ?></div>
                <?php endif; ?>
                <div class="row-actions">
                    <?php if (in_array($l['status'], ['pending', 'rejected', 'revoked'], true)): ?>
                        <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="approve"><input type="hidden" name="link_id" value="<?= $lid ?>"><button class="btn btn-primary btn-sm" type="submit">Approve</button></form>
                    <?php endif; ?>
                    <?php if ($l['status'] === 'pending'): ?><button type="button" class="btn btn-danger-soft btn-sm" data-open="reject-<?= $lid ?>">Reject</button><?php endif; ?>
                    <?php if ($l['status'] === 'approved'): ?><button type="button" class="btn btn-danger-soft btn-sm" data-open="revoke-<?= $lid ?>">Revoke Access</button><?php endif; ?>
                </div>
            </div>
        </div>

        <?php foreach (['reject' => 'Reject this request', 'revoke' => 'Revoke this parent\'s access'] as $act => $title):
            if (($act === 'reject' && $l['status'] !== 'pending') || ($act === 'revoke' && $l['status'] !== 'approved')) continue; ?>
        <div id="<?= $act ?>-<?= $lid ?>" class="modal-overlay" role="dialog" aria-modal="true" aria-hidden="true" <?= $reopen === "$act-$lid" ? 'data-autoopen' : '' ?>>
            <form class="modal-card" method="post" action="admin_parents.php?show=<?= e($show) ?>" novalidate>
                <?= csrf_field() ?><input type="hidden" name="action" value="<?= $act ?>"><input type="hidden" name="link_id" value="<?= $lid ?>">
                <div class="modal-head">
                    <div class="modal-icon ic-red"><?= icon('shield') ?></div>
                    <div><h3><?= e($title) ?></h3><p><?= e($l['p_first'] . ' ' . $l['p_last']) ?> → <?= e($l['s_first'] . ' ' . $l['s_last']) ?></p></div>
                    <button type="button" class="modal-close" data-close aria-label="Close">✕</button>
                </div>
                <div class="modal-body">
                    <div class="field"><label>Reason</label><input name="note" maxlength="255" placeholder="<?= $act === 'reject' ? 'e.g. Not listed as a guardian; please visit the registrar' : 'e.g. Custody change confirmed by the school office' ?>" required></div>
                    <?php if ($act === 'revoke'): ?><div class="notice notice-warn" style="margin-top: 12px;"><?= icon('bell') ?><span>The parent immediately stops seeing this child's records. Their account stays active.</span></div><?php endif; ?>
                </div>
                <div class="modal-foot"><button type="button" class="btn btn-soft" data-close>Cancel</button><button type="submit" class="btn btn-primary"><?= $act === 'reject' ? 'Reject request' : 'Revoke access' ?></button></div>
            </form>
        </div>
        <?php endforeach; ?>
    <?php endforeach; ?>
</div>

<div class="card" id="accounts">
    <div class="card-head-flex"><div><div class="card-title">Family Accounts</div><div class="card-sub">Disable an account to block sign-in completely.</div></div></div>
    <?php if (!$accounts): ?><div class="empty">No family accounts yet.</div><?php else: ?>
    <table class="table">
        <thead><tr><th>Parent</th><th>Children</th><th>Status</th><th>Registered</th><th>Last Sign-in</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($accounts as $a): ?>
            <tr style="<?= $a['status'] === 'disabled' ? 'opacity: .55;' : '' ?>">
                <td><strong><?= e($a['first_name'] . ' ' . $a['last_name']) ?></strong><div class="muted" style="font-size: 0.74rem;"><?= e($a['email']) ?></div></td>
                <td style="font-size: 0.82rem;"><?= (int)$a['approved_links'] ?> approved<?= $a['pending_links'] ? ' · <span style="color:#b45309;font-weight:600">' . (int)$a['pending_links'] . ' pending</span>' : '' ?></td>
                <td><?= $a['status'] === 'active' ? badge('Active', 'green') : badge('Disabled', 'gray') ?><?php if (is_online($a['last_seen_at'])): ?><div style="font-size: 0.7rem; color: #15803d; font-weight: 700; margin-top: 3px;">● Online</div><?php endif; ?></td>
                <td class="muted" style="font-size: 0.8rem;"><?= e(date('M j, Y', strtotime($a['created_at']))) ?></td>
                <td class="muted" style="font-size: 0.8rem;"><?= $a['last_login_at'] ? e(date('M j, g:i A', strtotime($a['last_login_at']))) : 'Never' ?></td>
                <td style="text-align: right;">
                    <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="status"><input type="hidden" name="user_id" value="<?= (int)$a['id'] ?>"><input type="hidden" name="status" value="<?= $a['status'] === 'active' ? 'disabled' : 'active' ?>">
                        <button class="btn btn-sm <?= $a['status'] === 'active' ? 'btn-danger-soft' : 'btn-soft' ?>" type="submit"><?= $a['status'] === 'active' ? 'Disable' : 'Enable' ?></button></form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<style>
    .link-row { display: flex; gap: 18px; align-items: flex-start; padding: 16px 0; border-top: 1px solid var(--border); flex-wrap: wrap; }
    @media (max-width: 1000px) { .link-row .form-grid { grid-template-columns: 1fr !important; } }
</style>

<?php layout_end(); ?>

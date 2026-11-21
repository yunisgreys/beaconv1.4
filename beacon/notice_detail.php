<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
$user = require_page('notice_detail');

$id = (int)query('id', 0);
$childQ = query('child') ? '?child=' . (int)query('child') : '';
if (!$id || !parent_can_see_notice($user, $id)) {
    flash('error', 'That notice is not available.');
    redirect('parent_welfare_feed.php' . $childQ);
}

if (is_post()) {
    verify_csrf();
    $err = notice_acknowledge($user, $id);
    flash($err ? 'error' : 'success', $err ?: 'Thank you — the school can see that you have read this notice.');
    redirect('notice_detail.php?id=' . $id . ($childQ ? '&child=' . (int)query('child') : ''));
}

$n = db_one('SELECT n.*, u.first_name, u.last_name, r.label AS role_label FROM notices n JOIN users u ON u.id = n.posted_by JOIN roles r ON r.id = u.role_id WHERE n.id = ?', [$id]);
$acked = notice_acknowledged($id, (int)$user['id']);
[$lbl, $tone] = NOTICE_TAGS[$n['tag']];

layout_start($user, 'notice', 'Notice', 'From ' . $n['first_name'] . ' ' . $n['last_name'] . ' · ' . $n['role_label']);
?>

<a class="card-link" href="parent_welfare_feed.php<?= e($childQ) ?>" style="display: inline-block; margin-bottom: 16px;">‹ Back to family feed</a>

<div class="card" style="max-width: 760px;">
    <div class="tags" style="margin-bottom: 12px;"><?= badge($lbl, $tone) ?> <?= badge(notice_audience_label($n), 'gray') ?></div>
    <h2 style="font-family: 'Manrope', sans-serif; font-size: 1.45rem; font-weight: 800; color: #0f172a; margin: 0 0 6px;"><?= e($n['title']) ?></h2>
    <p class="muted" style="font-size: 0.8rem; margin: 0 0 20px;">Posted <?= e(date('l, F j, Y · g:i A', strtotime($n['published_at']))) ?></p>
    <div style="font-size: 0.95rem; line-height: 1.75; color: #334155; white-space: pre-line;"><?= e($n['body']) ?></div>

    <?php if ($n['requires_ack']): ?>
        <div style="margin-top: 24px; padding-top: 18px; border-top: 1px solid var(--border);">
            <?php if ($acked): ?>
                <div class="notice notice-info"><?= icon('shield') ?><span>You acknowledged this notice on <?= e(date('M j, Y g:i A', strtotime($acked))) ?>.</span></div>
            <?php else: ?>
                <form method="post">
                    <?= csrf_field() ?>
                    <p class="muted" style="font-size: 0.85rem; margin: 0 0 10px;">The school asked families to confirm they have read this notice.</p>
                    <button class="btn btn-primary" type="submit">I have read this</button>
                </form>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<?php layout_end(); ?>

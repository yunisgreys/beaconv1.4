<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
$user = require_page('parent_feed');

$reopen = null;
if (is_post()) {
    verify_csrf();
    if (post('action') === 'link_child') {
        $rel = (string)post('relationship', '');
        $err = !in_array($rel, PARENT_RELATIONSHIPS, true) ? 'Please choose your relationship to the student.'
             : (!valid_date((string)post('birthdate')) ? "Please enter your child's birthdate." : null);
        $err = $err ?? request_student_link((int)$user['id'], (string)post('student_no', ''), (string)post('birthdate'), $rel);
        if (!$err) { flash('success', 'Request sent. The school will verify it, usually within one school day.'); redirect('parent_welfare_feed.php'); }
        flash('error', $err); $reopen = 'modalLink';
    }
}

$children = parent_children($user);
$child    = selected_child($children);
$others   = parent_other_links($user);
$feed     = $child ? family_feed($user, $child) : ['items' => [], 'locked' => 0];
$tagCounts = array_fill_keys(array_keys(NOTICE_TAGS), 0);
foreach ($feed['items'] as $i) $tagCounts[$i['tag']]++;
$tag = isset(NOTICE_TAGS[query('tag', '')]) ? query('tag') : 'all';
$toAck = count(array_filter($feed['items'], fn($i) => $i['ack'] === false));
$childQ = $child ? '&child=' . (int)$child['id'] : '';

layout_start($user, 'feed', 'Family feed', $child ? 'Notices from school and updates about ' . $child['first_name'] . ', newest first.' : 'Notices and updates about your child.');
?>

<?php if (!$children): ?>
    <div class="card" style="max-width: 720px;">
        <div class="card-title">Waiting for the school to verify your link</div>
        <p class="muted" style="font-size: 0.9rem; line-height: 1.6;">Your child's records will appear here once the school approves your request. This usually takes one school day.</p>
        <?php foreach ($others as $o): ?>
            <div style="padding: 10px 0; border-top: 1px solid var(--border); font-size: 0.875rem;">
                <strong><?= e($o['first_name'] . ' ' . $o['last_name']) ?></strong>
                <?= badge(ucfirst($o['status']), ['pending' => 'amber', 'rejected' => 'red', 'revoked' => 'gray'][$o['status']] ?? 'gray') ?>
                <?php if ($o['review_note']): ?><div class="muted" style="font-size: 0.8rem; margin-top: 3px;">School note: <?= e($o['review_note']) ?></div><?php endif; ?>
            </div>
        <?php endforeach; ?>
        <button type="button" class="btn btn-primary" data-open="modalLink" style="margin-top: 12px;">Link a child</button>
    </div>
<?php else: ?>

<?php if (count($children) > 1): ?>
<div class="chip-row" style="margin-bottom: 18px;" aria-label="Choose child">
    <?php foreach ($children as $c): ?>
        <a class="chip<?= (int)$c['id'] === (int)$child['id'] ? ' active' : '' ?>" style="text-decoration: none;" href="?child=<?= (int)$c['id'] ?>"><?= e($c['first_name'] . ' ' . $c['last_name']) ?></a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="grid-main-side">
    <div>
        <?php if ($toAck): ?>
            <div class="notice notice-warn" style="margin-bottom: 18px;"><?= icon('bell') ?><span><strong><?= $toAck ?> notice<?= $toAck > 1 ? 's need' : ' needs' ?> your acknowledgement.</strong> Open it and tap “I have read this”.</span></div>
        <?php endif; ?>

        <div class="card">
            <div class="chip-row" role="group" aria-label="Filter by tag" style="margin-bottom: 4px;">
                <a class="chip<?= $tag === 'all' ? ' active' : '' ?>" data-tag="all" href="?tag=all<?= $childQ ?>" style="text-decoration: none;">All<span class="count"><?= count($feed['items']) ?></span></a>
                <?php foreach (NOTICE_TAGS as $k => [$lbl]): ?>
                    <a class="chip<?= $tag === $k ? ' active' : '' ?>" data-tag="<?= $k ?>" href="?tag=<?= $k . $childQ ?>" style="text-decoration: none;"><?= e($lbl) ?><span class="count"><?= $tagCounts[$k] ?></span></a>
                <?php endforeach; ?>
            </div>

            <div id="feed">
            <?php foreach ($feed['items'] as $i): [$lbl, $tone] = NOTICE_TAGS[$i['tag']]; ?>
                <article class="feed-item" data-tag="<?= e($i['tag']) ?>" <?= $tag !== 'all' && $tag !== $i['tag'] ? 'hidden' : '' ?>>
                    <div style="display: flex; justify-content: space-between; gap: 12px; align-items: flex-start;">
                        <div class="tags">
                            <?= badge($lbl, $tone) ?>
                            <?php if ($i['ack'] === false): ?><?= badge('Please acknowledge', 'red') ?><?php elseif ($i['ack'] === true): ?><?= badge('Acknowledged ✓', 'green') ?><?php endif; ?>
                        </div>
                        <time class="muted" style="font-size: 0.75rem; white-space: nowrap;" datetime="<?= e($i['at']) ?>"><?= e(date('M j, Y', strtotime($i['at']))) ?></time>
                    </div>
                    <h3><?= e($i['title']) ?></h3>
                    <p style="white-space: pre-line;"><?= e(mb_strlen($i['body']) > 260 ? mb_substr($i['body'], 0, 257) . '…' : $i['body']) ?></p>
                    <div style="display: flex; justify-content: space-between; align-items: center; gap: 10px;">
                        <span class="muted" style="font-size: 0.75rem;"><?= e($i['meta']) ?></span>
                        <?php if ($i['link']): ?><a class="card-link" style="font-size: 0.8rem;" href="<?= e($i['link']) ?>"><?= str_starts_with($i['link'], 'notice_detail') ? ($i['ack'] === false ? 'Read and acknowledge' : 'Read notice') : 'See in school records' ?> ›</a><?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
            </div>
            <div class="empty" id="feedEmpty" <?= ($tag === 'all' ? count($feed['items']) : $tagCounts[$tag]) ? 'hidden' : '' ?>>Nothing here yet.</div>
        </div>
    </div>

    <div>
        <div class="card">
            <div style="display: flex; gap: 12px; align-items: center;">
                <div class="roster-avatar" style="width: 46px; height: 46px; border-radius: 50%; background: #e6e6ff; color: #1A19A5; display: flex; align-items: center; justify-content: center; font-weight: 800;"><?= e(student_initials($child)) ?></div>
                <div>
                    <strong style="font-size: 1rem;"><?= e($child['first_name'] . ' ' . $child['last_name']) ?></strong>
                    <div class="muted" style="font-size: 0.78rem;">Grade <?= e($child['grade_level']) ?> – <?= e($child['section_name']) ?> · <span class="mono"><?= e($child['student_no']) ?></span></div>
                    <div class="muted" style="font-size: 0.78rem;">Adviser: <?= e($child['adviser_first'] ? $child['adviser_first'] . ' ' . $child['adviser_last'] : '—') ?></div>
                </div>
            </div>
            <a class="btn btn-soft btn-sm" href="parent_records.php?child=<?= (int)$child['id'] ?>" style="margin-top: 14px; width: 100%;">View school records</a>
        </div>

        <?php if ($feed['locked']): ?>
        <div class="card" style="background: #f8f7ff;">
            <div style="display: flex; gap: 10px; align-items: flex-start;">
                <div class="modal-icon ic-violet" style="width: 34px; height: 34px;"><?= icon('shield') ?></div>
                <div>
                    <strong style="font-size: 0.9rem;">Private well-being support</strong>
                    <p class="muted" style="font-size: 0.8rem; line-height: 1.55; margin: 4px 0 0;"><?= e($child['first_name']) ?> is getting support from the Guidance Office. Details stay private unless <?= e($child['first_name']) ?> chooses to share them — this helps students feel safe asking for help. You're welcome to contact the Guidance Office with any concerns.</p>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-title" style="font-size: 0.95rem;">My children</div>
            <?php foreach ($children as $c): ?><div style="font-size: 0.84rem; padding: 6px 0;"><?= e($c['first_name'] . ' ' . $c['last_name']) ?> <?= badge('Verified', 'green') ?></div><?php endforeach; ?>
            <?php foreach ($others as $o): ?>
                <div style="font-size: 0.84rem; padding: 6px 0;"><?= e($o['first_name'] . ' ' . $o['last_name']) ?> <?= badge(ucfirst($o['status']), ['pending' => 'amber', 'rejected' => 'red', 'revoked' => 'gray'][$o['status']] ?? 'gray') ?>
                    <?php if ($o['review_note']): ?><div class="muted" style="font-size: 0.74rem;"><?= e($o['review_note']) ?></div><?php endif; ?></div>
            <?php endforeach; ?>
            <button type="button" class="btn btn-soft btn-sm" data-open="modalLink" style="margin-top: 8px;">+ Link another child</button>
        </div>
    </div>
</div>
<?php endif; ?>

<div id="modalLink" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="lkTitle" aria-hidden="true" <?= $reopen === 'modalLink' ? 'data-autoopen' : '' ?>>
    <form class="modal-card" method="post" action="parent_welfare_feed.php" novalidate>
        <?= csrf_field() ?><input type="hidden" name="action" value="link_child">
        <div class="modal-head">
            <div class="modal-icon ic-blue"><?= icon('users') ?></div>
            <div><h3 id="lkTitle">Link a child</h3><p>The school verifies every request before any records are shown.</p></div>
            <button type="button" class="modal-close" data-close aria-label="Close">✕</button>
        </div>
        <div class="modal-body">
            <div class="form-grid">
                <div class="field"><label for="lk_no">Student ID</label><input id="lk_no" name="student_no" placeholder="SV-2026-0000" value="<?= e(post('student_no', '')) ?>"></div>
                <div class="field"><label for="lk_bd">Child's birthdate</label><input id="lk_bd" type="date" name="birthdate" value="<?= e(post('birthdate', '')) ?>"></div>
                <div class="field span-2"><label for="lk_rel">Your relationship</label><select id="lk_rel" name="relationship"><?= opts(['' => 'Choose one'] + array_combine(PARENT_RELATIONSHIPS, PARENT_RELATIONSHIPS), post('relationship', '')) ?></select><span class="hint">Each student can have at most <?= MOTHER_LIMIT ?> Mother accounts.</span></div>
            </div>
        </div>
        <div class="modal-foot"><button type="button" class="btn btn-soft" data-close>Cancel</button><button type="submit" class="btn btn-primary">Send request</button></div>
    </form>
</div>

<style>
    .feed-item { padding: 18px 0; border-top: 1px solid var(--border); }
    .feed-item:first-child { border-top: 0; }
    .feed-item h3 { font-family: 'Manrope', sans-serif; font-size: 1rem; font-weight: 800; color: #0f172a; margin: 10px 0 4px; }
    .feed-item p { font-size: 0.875rem; color: #475569; line-height: 1.6; margin: 0 0 8px; }
    #feed { margin-top: 10px; }
</style>
<script>
(function () {
    var chips = document.querySelectorAll('[data-tag].chip'), items = document.querySelectorAll('.feed-item'), empty = document.getElementById('feedEmpty');
    chips.forEach(function (chip) {
        chip.addEventListener('click', function (e) {
            e.preventDefault();
            var tag = chip.getAttribute('data-tag'), shown = 0;
            chips.forEach(function (c) { c.classList.toggle('active', c === chip); });
            items.forEach(function (it) { var ok = tag === 'all' || it.getAttribute('data-tag') === tag; it.hidden = !ok; if (ok) shown++; });
            if (empty) empty.hidden = shown !== 0;
            history.replaceState(null, '', chip.getAttribute('href'));
        });
    });
})();
</script>

<?php layout_end(); ?>

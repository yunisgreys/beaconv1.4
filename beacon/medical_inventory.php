<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
$user = require_page('medical_inventory');

$reopen = null;
if (is_post()) {
    verify_csrf();
    $action = (string)post('action');
    if ($action === 'add_item') {
        $err = inventory_add_item($user, $_POST);
        if (!$err) {
            flash('success', (post('mode') === 'request' ? 'Request logged for ' : 'Added ') . trim((string)post('name')) . '.');
            redirect('medical_inventory.php');
        }
        flash('error', $err); $reopen = 'modalAddItem';
    }
        if ($action === 'receive_item') {
        $id = (int)post('item_id');
        $err = inventory_receive_request($user, $id);
        if (!$err) {
            $it = db_one('SELECT * FROM inventory_items WHERE id = ?', [$id]);
            flash('success', 'Delivery recorded: ' . $it['name'] . ' now has ' . (int)$it['quantity'] . ' ' . $it['unit'] . ' (' . STOCK_STATUS[stock_status($it)][0] . ').');
            redirect('medical_inventory.php');
        }
        flash('error', $err);
    }
    if ($action === 'update_item') {
        $id = (int)post('item_id');
        $err = inventory_update_item($user, $id, $_POST);
        if (!$err) { flash('success', 'Item updated.'); redirect('medical_inventory.php'); }
        flash('error', $err); $reopen = 'item-' . $id;
    }
}

$items = inventory_items();
$counts = ['all' => count($items), 'low_stock' => 0, 'out_of_stock' => 0, 'requested' => 0];
foreach ($items as $i) {
    if (isset($counts[$i['status']])) $counts[$i['status']]++;
    if ($i['requested_qty']) $counts['requested']++;
}
$moves = db_all('SELECT m.*, i.name, i.unit, u.first_name, u.last_name FROM inventory_movements m JOIN inventory_items i ON i.id = m.item_id JOIN users u ON u.id = m.performed_by ORDER BY m.created_at DESC, m.id DESC LIMIT 8');
$soon = date('Y-m-d', strtotime('+60 days'));
$filter = in_array(query('filter'), ['attention', 'low_stock', 'out_of_stock', 'requested'], true) ? query('filter') : 'all';

layout_start($user, 'inventory', 'Medical inventory', 'Track stock levels, log new or requested supplies, and flag items running low.',
    ['search' => ['id' => 'supplySearch', 'placeholder' => 'Search medical supplies…']]);
?>

<div class="stat-grid-4">
    <div class="stat-card-box"><div class="stat-icon-wrap ic-green"><?= icon('box') ?></div><div class="stat-info"><span class="stat-lbl">Items tracked</span><div class="stat-val"><?= $counts['all'] ?></div></div></div>
    <div class="stat-card-box"><div class="stat-icon-wrap ic-amber"><?= icon('bell') ?></div><div class="stat-info"><span class="stat-lbl">Low stock</span><div class="stat-val"><?= $counts['low_stock'] ?></div></div></div>
    <div class="stat-card-box"><div class="stat-icon-wrap ic-red"><?= icon('box') ?></div><div class="stat-info"><span class="stat-lbl">Out of stock</span><div class="stat-val"><?= $counts['out_of_stock'] ?></div></div></div>
    <div class="stat-card-box"><div class="stat-icon-wrap ic-blue"><?= icon('clipboard') ?></div><div class="stat-info"><span class="stat-lbl">Requested / on order</span><div class="stat-val"><?= $counts['requested'] ?></div></div></div>
</div>

<div class="card">
    <div class="card-head-flex">
        <div><div class="card-title">Clinic supplies</div><div class="card-sub">Status updates automatically from the quantity and low-stock level, unless you flag it yourself.</div></div>
        <button type="button" class="btn btn-primary btn-sm" data-open="modalAddItem">+ Add supply item</button>
    </div>

    <div class="chip-row" style="margin-bottom: 16px;" role="group" aria-label="Filter by status">
        <?php foreach (['all' => ['All', $counts['all']], 'attention' => ['Needs attention', $counts['low_stock'] + $counts['out_of_stock']], 'low_stock' => ['Low stock', $counts['low_stock']], 'out_of_stock' => ['Out of stock', $counts['out_of_stock']], 'requested' => ['Requested', $counts['requested']]] as $k => [$lbl, $n]): ?>
            <button type="button" class="chip<?= $filter === $k ? ' active' : '' ?>" data-chip="<?= $k ?>" aria-pressed="<?= $filter === $k ? 'true' : 'false' ?>"><?= e($lbl) ?><span class="count"><?= $n ?></span></button>
        <?php endforeach; ?>
    </div>

    <table class="table" id="supplyTable">
        <thead><tr><th>Item</th><th>Category</th><th>Quantity</th><th>Status</th><th>Requested</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($items as $i):
            $expired = $i['expiry_date'] && $i['expiry_date'] < date('Y-m-d');
            $expiring = $i['expiry_date'] && !$expired && $i['expiry_date'] <= $soon; ?>
            <tr data-status="<?= e($i['status']) ?>" data-requested="<?= $i['requested_qty'] ? '1' : '0' ?>"
                data-search="<?= e(strtolower($i['name'] . ' ' . $i['category'] . ' ' . $i['unit'] . ' ' . STOCK_STATUS[$i['status']][0] . ($i['requested_qty'] ? ' requested' : ''))) ?>">
                <td>
                    <strong><?= e($i['name']) ?></strong>
                    <?php if ($i['expiry_date']): ?>
                        <div style="font-size: 0.72rem; margin-top: 2px; color: <?= $expired ? '#b91c1c' : ($expiring ? '#b45309' : '#64748b') ?>; font-weight: <?= $expired || $expiring ? '700' : '500' ?>;">
                            <?= $expired ? 'Expired' : 'Expires' ?> <?= e(date('M j, Y', strtotime($i['expiry_date']))) ?></div>
                    <?php endif; ?>
                </td>
                <td><?= badge(INVENTORY_CATEGORIES[$i['category']], 'gray') ?></td>
                <td class="mono" style="font-weight: 600;"><?= (int)$i['quantity'] ?> <?= e($i['unit']) ?><div class="muted" style="font-size: 0.7rem; font-weight: 500;">low at <?= (int)$i['reorder_level'] ?></div></td>
                <td><?= badge(...STOCK_STATUS[$i['status']]) ?><?= $i['status_override'] ? '<div class="muted" style="font-size: 0.68rem; margin-top: 3px;">set by staff</div>' : '' ?></td>
                <td style="font-size: 0.8rem;">
                    <?php if ($i['requested_qty']): ?>
                        <strong><?= (int)$i['requested_qty'] ?> <?= e($i['unit']) ?></strong>
                        <div class="muted" style="font-size: 0.72rem;"><?= e($i['request_note'] ?: 'Requested') ?><?= $i['requested_at'] ? ' · ' . e(date('M j', strtotime($i['requested_at']))) : '' ?></div>
                        <form method="post" action="medical_inventory.php" class="deliver-form" data-confirm-title="Record this delivery?" data-confirm-ok="Add to stock" data-confirm="Add the <?= (int)$i['requested_qty'] ?> <?= e($i['unit']) ?> that arrived to stock? The status will update automatically.">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="receive_item"><input type="hidden" name="item_id" value="<?= (int)$i['id'] ?>">
                            <button type="submit" class="btn btn-soft btn-sm">Mark delivered</button>
                        </form>
                    <?php else: ?><span class="muted">—</span><?php endif; ?>
                </td>
                <td style="text-align: right;"><button type="button" class="btn btn-soft btn-sm" data-open="item-<?= (int)$i['id'] ?>">Edit</button></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <div class="empty" id="supplyEmpty" hidden>No supplies match your search.</div>
</div>

<div class="card">
    <div class="card-title" style="font-size: 1rem; margin-bottom: 8px;">Recent stock changes</div>
    <?php if (!$moves): ?><div class="empty">No changes yet.</div><?php else: ?>
    <table class="table">
        <thead><tr><th>Item</th><th>Change</th><th>Reason</th><th>By</th><th>When</th></tr></thead>
        <tbody>
        <?php foreach ($moves as $m): ?>
            <tr>
                <td><?= e($m['name']) ?></td>
                <td class="mono" style="font-weight: 700; color: <?= $m['change_qty'] > 0 ? '#15803d' : '#b91c1c' ?>;"><?= $m['change_qty'] > 0 ? '+' : '' ?><?= (int)$m['change_qty'] ?> <?= e($m['unit']) ?></td>
                <td class="muted"><?= e(STOCK_REASONS[$m['reason']] ?? $m['reason']) ?></td>
                <td class="muted"><?= e($m['first_name'] . ' ' . $m['last_name']) ?></td>
                <td class="muted" style="white-space: nowrap;"><?= e(date('M j, g:i A', strtotime($m['created_at']))) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<?php $af = $reopen === 'modalAddItem' ? $_POST : ['mode' => 'stock', 'category' => 'medicine', 'quantity' => '0', 'reorder_level' => '10']; ?>
<div id="modalAddItem" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="aiTitle" aria-hidden="true" <?= $reopen === 'modalAddItem' ? 'data-autoopen' : '' ?>>
    <form class="modal-card" method="post" action="medical_inventory.php" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add_item">
        <div class="modal-head">
            <div class="modal-icon ic-green"><?= icon('box') ?></div>
            <div><h3 id="aiTitle">Add a supply item</h3><p>Log stock you have now, or a supply you've requested.</p></div>
            <button type="button" class="modal-close" data-close aria-label="Close">✕</button>
        </div>
        <div class="modal-body">
            <div class="choice-row" style="margin-bottom: 16px;">
                <label class="choice"><input type="radio" name="mode" value="stock" <?= ($af['mode'] ?? '') !== 'request' ? 'checked' : '' ?>><span>In stock now<small>Add it with the quantity on hand</small></span></label>
                <label class="choice"><input type="radio" name="mode" value="request" <?= ($af['mode'] ?? '') === 'request' ? 'checked' : '' ?>><span>Requested / on order<small>Not here yet — track the request</small></span></label>
            </div>
            <div class="form-grid">
                <div class="field span-2"><label for="ai_name">Item name</label><input id="ai_name" name="name" maxlength="120" placeholder="e.g. Cetirizine 10mg" value="<?= e($af['name'] ?? '') ?>" required></div>
                <div class="field"><label for="ai_cat">Category</label><select id="ai_cat" name="category"><?= opts(INVENTORY_CATEGORIES, $af['category'] ?? '') ?></select></div>
                <div class="field"><label for="ai_unit">Unit</label><input id="ai_unit" name="unit" maxlength="20" placeholder="tablet, box, pc, bottle" value="<?= e($af['unit'] ?? '') ?>" required></div>
                <div class="field" data-mode="stock"><label for="ai_qty">Quantity on hand</label><input id="ai_qty" name="quantity" type="number" min="0" value="<?= e($af['quantity'] ?? '0') ?>"></div>
                <div class="field" data-mode="request"><label for="ai_req">Quantity requested</label><input id="ai_req" name="requested_qty" type="number" min="1" value="<?= e($af['requested_qty'] ?? '') ?>"></div>
                <div class="field"><label for="ai_low">Low-stock level</label><input id="ai_low" name="reorder_level" type="number" min="0" value="<?= e($af['reorder_level'] ?? '10') ?>"><span class="hint">Flagged “Low stock” at or below this.</span></div>
                <div class="field span-2" data-mode="request"><label for="ai_note">Request note <span class="muted" style="font-weight: 500;">(optional)</span></label><input id="ai_note" name="request_note" maxlength="255" placeholder="e.g. Requested from the division office" value="<?= e($af['request_note'] ?? '') ?>"></div>
                <div class="field"><label for="ai_exp">Expiry date <span class="muted" style="font-weight: 500;">(optional)</span></label><input id="ai_exp" name="expiry_date" type="date" value="<?= e($af['expiry_date'] ?? '') ?>"></div>
                <div class="field" style="display: flex; align-items: flex-end;"><label class="check"><input type="checkbox" name="flag_low" value="1" <?= !empty($af['flag_low']) ? 'checked' : '' ?>> Flag as low stock now</label></div>
            </div>
        </div>
        <div class="modal-foot">
            <button type="button" class="btn btn-soft" data-close>Cancel</button>
            <button type="submit" class="btn btn-primary">Save item</button>
        </div>
    </form>
</div>

<?php foreach ($items as $i):
    $iid = (int)$i['id'];
    $ef = $reopen === "item-$iid" ? $_POST + $i : $i; ?>
<div id="item-<?= $iid ?>" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="it-<?= $iid ?>" aria-hidden="true" <?= $reopen === "item-$iid" ? 'data-autoopen' : '' ?>>
    <form class="modal-card" method="post" action="medical_inventory.php" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update_item"><input type="hidden" name="item_id" value="<?= $iid ?>">
        <div class="modal-head">
            <div class="modal-icon ic-green"><?= icon('box') ?></div>
            <div><h3 id="it-<?= $iid ?>">Edit: <?= e($i['name']) ?></h3><p>Now <?= (int)$i['quantity'] ?> <?= e($i['unit']) ?> · <?= e(STOCK_STATUS[$i['status']][0]) ?></p></div>
            <button type="button" class="modal-close" data-close aria-label="Close">✕</button>
        </div>
        <div class="modal-body">
            <div class="form-grid">
                <div class="field"><label>Quantity on hand</label><input type="number" name="quantity" min="0" value="<?= e($ef['quantity']) ?>" data-qty data-original="<?= (int)$i['quantity'] ?>"></div>
                <div class="field"><label>Reason for change</label><select name="change_reason"><?= opts(['' => 'Only if quantity changed…'] + STOCK_REASONS, $ef['change_reason'] ?? '') ?></select></div>
                <div class="field"><label>Status</label><select name="status_override"><?= opts(['' => 'Automatic (from quantity)'] + array_map(fn($s) => $s[0], STOCK_STATUS), $ef['status_override'] ?? '') ?></select></div>
                <div class="field"><label>Low-stock level</label><input type="number" name="reorder_level" min="0" value="<?= e($ef['reorder_level']) ?>"></div>

                <span class="section-label span-2" style="grid-column: 1 / -1; margin: 6px 0 0;">Request / order</span>
                <div class="field"><label>Quantity requested</label><input type="number" name="requested_qty" min="1" value="<?= e($ef['requested_qty'] ?? '') ?>" placeholder="None"></div>
                <div class="field"><label>Request note</label><input name="request_note" maxlength="255" value="<?= e($ef['request_note'] ?? '') ?>"></div>
                <?php if ($i['requested_qty']): ?>
                                        <div class="field span-2"><label class="check"><input type="checkbox" name="mark_received" value="1"> The <?= (int)$i['requested_qty'] ?> <?= e($i['unit']) ?> requested have arrived — add them to stock</label>
                        <span class="hint" style="padding-left: 24px;"></span></div>
                <?php endif; ?>

                <span class="section-label" style="grid-column: 1 / -1; margin: 6px 0 0;">Details</span>
                <div class="field span-2"><label>Item name</label><input name="name" maxlength="120" value="<?= e($ef['name']) ?>"></div>
                <div class="field"><label>Category</label><select name="category"><?= opts(INVENTORY_CATEGORIES, $ef['category']) ?></select></div>
                <div class="field"><label>Unit</label><input name="unit" maxlength="20" value="<?= e($ef['unit']) ?>"></div>
                <div class="field"><label>Expiry date</label><input type="date" name="expiry_date" value="<?= e($ef['expiry_date'] ?? '') ?>"></div>
            </div>
        </div>
        <div class="modal-foot">
            <button type="button" class="btn btn-soft" data-close>Cancel</button>
            <button type="submit" class="btn btn-primary">Save changes</button>
        </div>
    </form>
</div>
<?php endforeach; ?>

<script>
(function () {
    var search = document.getElementById('supplySearch');
    var rows = document.querySelectorAll('#supplyTable tbody tr');
    var empty = document.getElementById('supplyEmpty');
    var chip = <?= json_encode($filter) ?>;

    function matchesChip(r) {
        var s = r.getAttribute('data-status');
        if (chip === 'all') return true;
        if (chip === 'attention') return s === 'low_stock' || s === 'out_of_stock';
        if (chip === 'requested') return r.getAttribute('data-requested') === '1';
        return s === chip;
    }
    function apply() {
        var q = (search ? search.value : '').toLowerCase().trim(), shown = 0;
        rows.forEach(function (r) {
            var ok = matchesChip(r) && (r.getAttribute('data-search') || '').indexOf(q) !== -1;
            r.hidden = !ok;
            if (ok) shown++;
        });
        empty.hidden = shown !== 0;
    }
    if (search) search.addEventListener('input', apply);
    document.querySelectorAll('[data-chip]').forEach(function (b) {
        b.addEventListener('click', function () {
            chip = b.getAttribute('data-chip');
            document.querySelectorAll('[data-chip]').forEach(function (x) { x.classList.toggle('active', x === b); x.setAttribute('aria-pressed', x === b ? 'true' : 'false'); });
            apply();
        });
    });
    apply();

    var form = document.querySelector('#modalAddItem form');
    function mode() {
        var m = form.querySelector('input[name=mode]:checked').value;
        form.querySelectorAll('[data-mode]').forEach(function (el) { el.hidden = el.getAttribute('data-mode') !== m; });
    }
    form.querySelectorAll('input[name=mode]').forEach(function (r) { r.addEventListener('change', mode); });
    mode();
})();
</script>

<?php layout_end(); ?>

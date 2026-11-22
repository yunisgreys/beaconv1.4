<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
$user = require_page('patient_intake');

clinic_expire_passes();
$students = visible_students($user);
$items    = inventory_items();
$reopen   = null;

$now = date('Y-m-d\TH:i');
$form = ['student_id' => '', 'pass_code' => '', 'pass_id' => '', 'report_id' => '', 'arrived_at' => $now, 'departed_at' => '',
         'complaint' => '', 'assessment' => '', 'temperature_c' => '', 'blood_pressure' => '', 'pulse_bpm' => '',
         'medication_notes' => '', 'stock_item_id' => '', 'stock_qty' => '1', 'case_status' => 'under_observation', 'is_sensitive' => '', 'parent_notified' => ''];
$fromPass = null;
$fromReport = null;

if (!is_post() && ($pid = (int)query('pass', 0))) {
    $fromPass = db_one("SELECT p.*, s.first_name, s.last_name FROM clinic_passes p JOIN students s ON s.id = p.student_id WHERE p.id = ? AND p.status = 'issued'", [$pid]);
    if ($fromPass) {
        $form['student_id'] = $fromPass['student_id']; $form['pass_id'] = $fromPass['id']; $form['complaint'] = $fromPass['reason'];
        $form['is_sensitive'] = $fromPass['reason_type'] === 'psychological' ? '1' : '';
    } else {
        flash('error', 'That pass is no longer active (already used or expired).');
    }
}
if (!is_post() && ($rid = (int)query('report', 0))) {
    $fromReport = db_one("SELECT r.*, s.first_name, s.last_name FROM incident_reports r JOIN students s ON s.id = r.student_id WHERE r.id = ? AND r.routed_to = 'clinic'", [$rid]);
    if ($fromReport) {
        $form['student_id'] = $fromReport['student_id']; $form['report_id'] = $fromReport['id']; $form['complaint'] = $fromReport['title'];
        $form['is_sensitive'] = $fromReport['record_type'] === 'mental_emotional' ? '1' : '';
    }
}

if (is_post()) {
    verify_csrf();
    $action = (string)post('action');

    if ($action === 'check_in') {
        foreach ($form as $k => $_) $form[$k] = trim((string)post($k, ''));
        [$visitId, $err] = clinic_check_in($user, $form);
        if (!$err) {
            $info = db_one("SELECT s.first_name, s.last_name,
                                   (SELECT COUNT(*) FROM parent_student_links l WHERE l.student_id = s.id AND l.status = 'approved') AS families
                              FROM clinic_visits v JOIN students s ON s.id = v.student_id WHERE v.id = ?", [$visitId]);
            $fam = (int)$info['families'];
            flash('success', "{$info['first_name']} {$info['last_name']} checked in. "
                . ($fam ? 'A notice was sent to ' . ($fam === 1 ? 'their parent' : "their $fam linked parents") . ' in the Family Portal.'
                        : 'No parent account is linked yet, so no family notice was sent.'));
            redirect('patient_intake.php#queue');
        }
        flash('error', $err);
    }

    if ($action === 'update_visit') {
        $vid = (int)post('visit_id');
        $err = clinic_update_visit($user, $vid, $_POST);
        if (!$err) { flash('success', 'Visit updated. The family notice now shows the latest details.'); redirect('patient_intake.php#queue'); }
        flash('error', $err);
        $reopen = 'visit-' . $vid;
    }
}

$queue = clinic_visits_where('v.departed_at IS NULL');
$medsByVisit = [];
if ($queue) {
    $ids = array_column($queue, 'id');
    $in = implode(',', array_fill(0, count($ids), '?'));
    foreach (db_all("SELECT visit_id, medication_name, dose FROM medications_administered WHERE visit_id IN ($in)", $ids) as $m) {
        $medsByVisit[$m['visit_id']][] = $m;
    }
}

// Patient visit history (completed visits), 15 per page
$perPage  = 15;
$hTotal   = (int)db_value('SELECT COUNT(*) FROM clinic_visits WHERE departed_at IS NOT NULL');
$hPages   = max(1, (int)ceil($hTotal / $perPage));
$hPage    = min($hPages, max(1, (int)query('hpage', 1)));
$history  = clinic_visit_history($perPage, ($hPage - 1) * $perPage);

$stockOpts = ['' => 'Not from clinic stock'];
foreach ($items as $i) if ((int)$i['quantity'] > 0) $stockOpts[$i['id']] = $i['name'] . ' (' . (int)$i['quantity'] . ' ' . $i['unit'] . ' left)';
$statusOpts = array_map(fn($s) => $s[0], CLINIC_STATUS);

// v1.4: clinic passes teachers issued in the last 12 hours, newest per student, so
// picking a student shows their pass right away (active ones fill in the code).
$passesByStudent = [];
foreach (db_all("SELECT p.id, p.pass_code, p.student_id, p.reason_type, p.reason, p.urgency, p.status, p.issued_at, p.expires_at,
                        u.first_name, u.last_name
                   FROM clinic_passes p JOIN users u ON u.id = p.issued_by
                  WHERE p.status IN ('issued', 'expired') AND p.issued_at >= ?
                  ORDER BY (p.status = 'issued') DESC, p.issued_at DESC", [date('Y-m-d H:i:s', time() - 12 * 3600)]) as $p) {
    $sid = (int)$p['student_id'];
    if (isset($passesByStudent[$sid])) continue;
    $passesByStudent[$sid] = [
        'code'    => $p['pass_code'],
        'active'  => $p['status'] === 'issued',
        'reason'  => (PASS_REASONS[$p['reason_type']] ?? 'Clinic visit') . ($p['reason'] ? ' — ' . $p['reason'] : ''),
        'psych'   => $p['reason_type'] === 'psychological',
        'urgency' => PASS_URGENCY[$p['urgency']] ?? '',
        'by'      => $p['first_name'] . ' ' . $p['last_name'],
        'issued'  => date('g:i A', strtotime($p['issued_at'])),
        'expires' => date('g:i A', strtotime($p['expires_at'])),
        'complaint' => (string)$p['reason'],
    ];
}

$alerts = [];

foreach (db_all('SELECT student_id, allergies, conditions FROM medical_profiles') as $m) {
    $alerts[$m['student_id']] = trim(($m['allergies'] ? 'Allergy: ' . $m['allergies'] : '') . ($m['allergies'] && $m['conditions'] ? ' · ' : '') . ($m['conditions'] ? $m['conditions'] : ''));
}

function fmt_time(?string $dt, bool $withDate = false): string {
    if (!$dt) return '—';
    $t = strtotime($dt);
    return $withDate ? date('M j', $t) . ' · ' . date('g:i A', $t) : date('g:i A', $t);
}

layout_start($user, 'intake', 'Patient intake', "Check students in and out, record time in, time out and medication, and set each visit's status.");
?>

<div class="card intake-card">
    <div class="card-head-flex" style="margin-bottom: 16px;">
        <div>
            <div class="card-title">Check in a student</div>
            <div class="card-sub">
                <?php if ($fromPass): ?>From pass <strong class="mono"><?= e($fromPass['pass_code']) ?></strong> for <?= e($fromPass['first_name'] . ' ' . $fromPass['last_name']) ?>.
                <?php elseif ($fromReport): ?>From a teacher report about <?= e($fromReport['first_name'] . ' ' . $fromReport['last_name']) ?>.
                <?php else: ?>If the student has a clinic pass, enter the code on it. Linked parents get a Family Portal notice as soon as you check the student in.<?php endif; ?>
            </div>
        </div>
    </div>
    <form method="post" action="patient_intake.php" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="check_in">
        <input type="hidden" name="pass_id" value="<?= e($form['pass_id']) ?>">
        <input type="hidden" name="report_id" value="<?= e($form['report_id']) ?>">

        <div class="intake-grid">
            <div class="field col-2">
                <label for="ci_student">Student</label>
                <?= student_picker('ci_student', $students, $form['student_id'], false) ?>
            <span class="hint alert-hint" id="allergyHint"></span>
                <div class="pass-found" id="passFound" hidden aria-live="polite"></div>
            </div>
            <?php if (!$form['pass_id']): ?>
            <div class="field col-2">
                <label for="ci_code">Clinic pass code <span class="opt">(if the student has one)</span></label>
                <input id="ci_code" name="pass_code" class="mono" maxlength="8" placeholder="e.g. 3BA29B" value="<?= e($form['pass_code']) ?>" style="text-transform: uppercase;" autocomplete="off">
            </div>
            <?php endif; ?>

            <div class="field">
                <label for="ci_in">Time in</label>
                <input id="ci_in" type="datetime-local" name="arrived_at" max="<?= $now ?>" value="<?= e($form['arrived_at']) ?>" required>
            </div>
            <div class="field">
                <label for="ci_out">Time out <span class="opt">(optional)</span></label>
                <input id="ci_out" type="datetime-local" name="departed_at" max="<?= $now ?>" value="<?= e($form['departed_at']) ?>">
                <span class="hint">Leave blank while the student is still here.</span>
            </div>
            <div class="field col-2">
                <label for="ci_status">Status</label>
                <select id="ci_status" name="case_status"><?= opts($statusOpts, $form['case_status']) ?></select>
                <span class="hint">"Referred to guidance" also sends a report to the Guidance Office.</span>
            </div>

            <div class="field col-2">
                <label for="ci_complaint">Main complaint</label>
                <input id="ci_complaint" name="complaint" maxlength="255" placeholder="e.g. Headache since 2nd period" value="<?= e($form['complaint']) ?>" required>
            </div>
            <div class="field"><label for="ci_temp">Temperature (°C)</label><input id="ci_temp" name="temperature_c" inputmode="decimal" placeholder="36.8" value="<?= e($form['temperature_c']) ?>"></div>
            <div class="field vitals-2">
                <div><label for="ci_bp">Blood pressure</label><input id="ci_bp" name="blood_pressure" maxlength="10" placeholder="110/70" value="<?= e($form['blood_pressure']) ?>"></div>
                <div><label for="ci_pulse">Pulse (bpm)</label><input id="ci_pulse" name="pulse_bpm" inputmode="numeric" placeholder="80" value="<?= e($form['pulse_bpm']) ?>"></div>
            </div>

            <div class="field col-2">
                <label for="ci_meds">Medication administered</label>
                <textarea id="ci_meds" name="medication_notes" rows="2" placeholder="Name, dose, route and time, e.g. Paracetamol 500mg, 1 tablet orally, 10:15 AM. Leave blank if none."><?= e($form['medication_notes']) ?></textarea>
            </div>
            <div class="field col-2">
                <label for="ci_assess">Assessment / notes <span class="opt">(optional)</span></label>
                <textarea id="ci_assess" name="assessment" rows="2" placeholder="What you found and did"><?= e($form['assessment']) ?></textarea>
            </div>

            <div class="field">
                <label for="ci_stock">Taken from stock</label>
                <select id="ci_stock" name="stock_item_id"><?= opts($stockOpts, $form['stock_item_id']) ?></select>
            </div>
            <div class="field">
                <label for="ci_qty">Quantity used</label>
                <input id="ci_qty" name="stock_qty" type="number" min="1" value="<?= e($form['stock_qty']) ?>">
            </div>
            <div class="field col-2 checks">
                <label class="check"><input type="checkbox" name="parent_notified" value="1" <?= $form['parent_notified'] ? 'checked' : '' ?>> <span>Guardian was also contacted by phone</span></label>
                <label class="check"><input type="checkbox" name="is_sensitive" value="1" <?= $form['is_sensitive'] ? 'checked' : '' ?>> <span>Psychological / sensitive visit <span class="muted">(parents see only that a visit happened)</span></span></label>
            </div>
        </div>
        <div class="form-actions">
            <?php if ($fromPass || $fromReport): ?><a class="btn btn-soft" href="patient_intake.php">Clear</a><?php endif; ?>
            <button class="btn btn-primary" type="submit">Check in</button>
        </div>
    </form>
</div>

<div class="intake-below">
    <div class="card intake-gray" id="queue">
        <div class="card-head-flex">
            <div><div class="card-title">In the clinic now</div><div class="card-sub">Update the status, add medication, or set the time out.</div></div>
            <span class="badge badge-gray"><?= count($queue) ?> student<?= count($queue) === 1 ? '' : 's' ?></span>
        </div>
        <?php if (!$queue): ?><div class="empty">Nobody is in the clinic right now.</div><?php else: ?>
        <div style="overflow-x: auto;">
        <table class="table">
            <thead><tr><th>Student</th><th>Time in</th><th>Complaint</th><th>Medication administered</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($queue as $v): ?>
                <tr>
                    <td style="white-space: nowrap;"><strong><?= e($v['first_name'] . ' ' . $v['last_name']) ?></strong>
                        <div class="muted" style="font-size: 0.72rem;"><?= $v['pass_code'] ? 'Pass ' . e($v['pass_code']) : 'Walk-in' ?><?= $v['is_sensitive'] ? ' · sensitive' : '' ?></div></td>
                    <td class="muted" style="white-space: nowrap;"><?= e(fmt_time($v['arrived_at'])) ?></td>
                    <td style="font-size: 0.84rem;"><?= e($v['complaint']) ?></td>
                    <td style="font-size: 0.8rem; white-space: pre-line;"><?= e($v['medication_notes'] ?: '—') ?></td>
                    <td><?= badge(...CLINIC_STATUS[$v['case_status']]) ?></td>
                    <td style="text-align: right;"><button type="button" class="btn btn-soft btn-sm" data-open="visit-<?= (int)$v['id'] ?>">Update</button></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>

    <div class="card intake-gray" id="history">
        <div class="card-head-flex">
            <div><div class="card-title">Patient visit history</div><div class="card-sub">Completed visits, newest first.</div></div>
            <span class="badge badge-gray"><?= $hTotal ?> visit<?= $hTotal === 1 ? '' : 's' ?></span>
        </div>
        <?php if (!$history): ?><div class="empty">No completed visits yet.</div><?php else: ?>
        <div style="overflow-x: auto;">
        <table class="table">
            <thead><tr><th>Student name</th><th>Time in</th><th>Time out</th><th>Medication</th></tr></thead>
            <tbody>
            <?php foreach ($history as $v): ?>
                <tr>
                    <td style="white-space: nowrap;"><strong><?= e($v['first_name'] . ' ' . $v['last_name']) ?></strong><div class="muted mono" style="font-size: 0.72rem;"><?= e($v['student_no']) ?></div></td>
                    <td style="white-space: nowrap;"><?= e(fmt_time($v['arrived_at'], true)) ?></td>
                    <td style="white-space: nowrap;"><?= e(fmt_time($v['departed_at'], date('Y-m-d', strtotime($v['departed_at'])) !== date('Y-m-d', strtotime($v['arrived_at'])))) ?></td>
                    <td style="font-size: 0.82rem; white-space: pre-line;"><?= e($v['medication_notes'] ?: 'None') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php if ($hPages > 1): ?>
        <nav class="pager" aria-label="Visit history pages">
            <span class="muted">Page <?= $hPage ?> of <?= $hPages ?></span>
            <div>
                <?php if ($hPage > 1): ?><a class="btn btn-soft btn-sm" href="patient_intake.php?hpage=<?= $hPage - 1 ?>#history">‹ Newer</a><?php endif; ?>
                <?php if ($hPage < $hPages): ?><a class="btn btn-soft btn-sm" href="patient_intake.php?hpage=<?= $hPage + 1 ?>#history">Older ›</a><?php endif; ?>
            </div>
        </nav>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php foreach ($queue as $v):
    $vid = (int)$v['id'];
    $vf = $reopen === "visit-$vid" ? $_POST : ['case_status' => $v['case_status'], 'departed_at' => '', 'assessment' => $v['assessment']];
?>
<div id="visit-<?= $vid ?>" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="vt-<?= $vid ?>" aria-hidden="true" <?= $reopen === "visit-$vid" ? 'data-autoopen' : '' ?>>
    <form class="modal-card" method="post" action="patient_intake.php">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update_visit"><input type="hidden" name="visit_id" value="<?= $vid ?>">
        <div class="modal-head">
            <div class="modal-icon ic-red"><?= icon('heart') ?></div>
            <div>
                <h3 id="vt-<?= $vid ?>"><?= e($v['first_name'] . ' ' . $v['last_name']) ?></h3>
                <p>Time in <?= e(date('M j, g:i A', strtotime($v['arrived_at']))) ?> · <?= e($v['complaint']) ?></p>
            </div>
            <button type="button" class="modal-close" data-close aria-label="Close">✕</button>
        </div>
        <div class="modal-body">
            <?php if (!empty($alerts[$v['student_id']])): ?>
                <div class="notice notice-warn" style="margin-bottom: 14px;"><?= icon('bell') ?><span><strong>Medical alert:</strong> <?= e($alerts[$v['student_id']]) ?></span></div>
            <?php endif; ?>
            <div class="form-grid">
                <div class="field span-2"><label for="vs-<?= $vid ?>">Status</label><select id="vs-<?= $vid ?>" name="case_status"><?= opts($statusOpts, $vf['case_status'] ?? '') ?></select></div>
                <div class="field span-2">
                    <label for="vo-<?= $vid ?>">Time out</label>
                    <div style="display: flex; gap: 8px;">
                        <input id="vo-<?= $vid ?>" type="datetime-local" name="departed_at" value="<?= e($vf['departed_at'] ?? '') ?>" data-checkout>
                        <button type="button" class="btn btn-soft btn-sm" data-now style="white-space: nowrap;">Now</button>
                    </div>
                    <span class="hint">Leave blank if the student is still here. "Sent home", "Cleared to class" and hospital referrals set the time out automatically.</span>
                </div>
                <div class="field span-2"><label for="va-<?= $vid ?>">Assessment / notes</label><textarea id="va-<?= $vid ?>" name="assessment" rows="2" style="min-height: 64px;"><?= e($vf['assessment'] ?? '') ?></textarea></div>
                <div class="field span-2">
                    <span class="field-label">Medication administered so far</span>
                    <div class="info-tile" style="font-size: 0.82rem; white-space: pre-line;"><?= e($v['medication_notes'] ?: 'None recorded.') ?><?php foreach ($medsByVisit[$vid] ?? [] as $m): ?>

<span class="muted">From stock: <?= e($m['medication_name'] . ', ' . $m['dose']) ?></span><?php endforeach; ?></div>
                </div>
                <div class="field span-2"><label for="vm-<?= $vid ?>">Add medication administered</label><textarea id="vm-<?= $vid ?>" name="add_medication" rows="2" style="min-height: 56px;" placeholder="e.g. Paracetamol 500mg, 1 tablet orally"><?= e($vf['add_medication'] ?? '') ?></textarea></div>
                <div class="field"><label for="vk-<?= $vid ?>">Taken from stock</label><select id="vk-<?= $vid ?>" name="stock_item_id"><?= opts($stockOpts, $vf['stock_item_id'] ?? '') ?></select></div>
                <div class="field"><label for="vq-<?= $vid ?>">Quantity used</label><input id="vq-<?= $vid ?>" type="number" name="stock_qty" min="1" value="<?= e($vf['stock_qty'] ?? '1') ?>"></div>
                <div class="field span-2"><label class="check"><input type="checkbox" name="parent_notified" value="1" <?= $v['parent_notified'] ? 'checked' : '' ?>> <span>Guardian was also contacted by phone</span></label></div>
            </div>
        </div>
        <div class="modal-foot">
            <button type="button" class="btn btn-soft" data-close>Cancel</button>
            <button type="submit" class="btn btn-primary">Save</button>
        </div>
    </form>
</div>
<?php endforeach; ?>

<style>
    .intake-card { margin-bottom: 24px; }
    .intake-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 14px 16px; }
    .intake-grid .col-2 { grid-column: span 2; }
    .intake-grid textarea { min-height: 66px; }
    .intake-grid .opt { font-weight: 500; color: var(--text-muted, #64748b); }
    .intake-grid .vitals-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
    .intake-grid .checks { display: grid; gap: 8px; align-content: center; }
    .alert-hint { color: #b91c1c; font-weight: 600; }
    #ci_code::placeholder { text-transform: none; }
    .intake-below { display: grid; gap: 20px; }
    .intake-gray { background: #f1f4f8; border-color: #e2e8f0; box-shadow: none; margin-bottom: 0; }
    .intake-gray .table th { background: transparent; }
    .intake-gray .table tbody tr { background: #fff; }
    .intake-gray .table tbody td:first-child { border-top-left-radius: 10px; border-bottom-left-radius: 10px; }
    .intake-gray .table tbody td:last-child { border-top-right-radius: 10px; border-bottom-right-radius: 10px; }
    .intake-gray .table { border-collapse: separate; border-spacing: 0 6px; }
    .intake-gray .table tbody td { border-bottom: 0; }
    .intake-gray .empty { background: #fff; border-radius: 10px; }
    .pager { display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-top: 12px; font-size: 0.8rem; }
    .pager div { display: flex; gap: 8px; }
    @media (max-width: 1100px) { .intake-grid { grid-template-columns: 1fr 1fr; } }
    @media (max-width: 640px)  { .intake-grid { grid-template-columns: 1fr; } .intake-grid .col-2 { grid-column: auto; } }
</style>
<script>
(function () {
    var alerts = <?= json_encode((object)$alerts) ?>;
    var sel = document.getElementById('ci_student'), hint = document.getElementById('allergyHint');
    function show() { hint.textContent = alerts[sel.value] ? 'Medical alert: ' + alerts[sel.value] : ''; }
        if (sel) { sel.addEventListener('change', show); show(); }

    // v1.4: show the clinic pass issued to the chosen student and fill in its code
    var passes = <?= json_encode((object)$passesByStudent) ?>;
    var box = document.getElementById('passFound'), code = document.getElementById('ci_code');
    var complaint = document.getElementById('ci_complaint'), sensitive = document.querySelector('input[name="is_sensitive"]');
    var autoCode = '', autoComplaint = '';
    function el(tag, cls, text) { var n = document.createElement(tag); if (cls) n.className = cls; if (text) n.textContent = text; return n; }
    function showPass() {
        if (!sel || !box || !code) return;          // code box is hidden when the page was opened from a pass
        var p = passes[sel.value];
        box.textContent = '';
        if (code.value === autoCode) code.value = '';
        if (complaint && complaint.value === autoComplaint) complaint.value = '';
        autoCode = autoComplaint = '';
        if (!sel.value) { box.hidden = true; return; }
        box.hidden = false;
        if (!p) {
            box.className = 'pass-found pass-none';
            box.appendChild(el('span', '', 'No clinic pass was issued to this student today. Check them in as a walk-in.'));
            return;
        }
        box.className = 'pass-found' + (p.active ? '' : ' pass-none');
        box.insertAdjacentHTML('afterbegin', '<svg class="ico" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3l7 3v5c0 4.5-3 8.3-7 10-4-1.7-7-5.5-7-10V6l7-3z"/></svg>');
        var body = el('div');
        var line = el('div');
        line.appendChild(el('strong', '', p.active ? 'Clinic pass ' : 'Expired pass '));
        line.appendChild(el('span', 'mono', p.code));
        line.appendChild(document.createTextNode(' · ' + p.reason + (p.urgency ? ' · ' + p.urgency : '')));
        body.appendChild(line);
        body.appendChild(el('div', 'pass-found-meta', 'Issued by ' + p.by + ' at ' + p.issued + (p.active ? ' · valid until ' + p.expires + ' · code filled in below' : ' · it expired at ' + p.expires + ', so check them in without it')));
        box.appendChild(body);
        if (p.active) {
            if (code.value === '') { code.value = p.code; autoCode = p.code; }
            if (complaint && complaint.value === '' && p.complaint) { complaint.value = p.complaint; autoComplaint = p.complaint; }
            if (sensitive && p.psych) sensitive.checked = true;
        }
    }
    if (sel) { sel.addEventListener('change', showPass); showPass(); }

    document.querySelectorAll('[data-now]').forEach(function (b) {
        b.addEventListener('click', function () {
            var d = new Date(), pad = function (n) { return String(n).padStart(2, '0'); };
            b.parentNode.querySelector('[data-checkout]').value = d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes());
        });
    });
})();
</script>

<?php layout_end(); ?>

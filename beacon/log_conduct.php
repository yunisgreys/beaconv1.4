<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
$user = require_page('log_conduct');

const RECORD_TYPES = [
    'conduct' => [
        'label' => 'Behavioral conduct', 'hint' => 'Classroom behavior, conflicts, academic or attendance concerns',
        'categories' => ['behavioral' => 'Classroom behavior', 'peer_conflict' => 'Peer conflict', 'academic' => 'Academic concern',
                         'attendance' => 'Attendance / tardiness', 'other' => 'Other'],
        'routes' => ['guidance'], 'default_route' => 'guidance',
    ],
    'physical' => [
        'label' => 'Physical state', 'hint' => 'Illness, pain, injury, fatigue, hunger',
        'categories' => ['physical' => 'Physical health concern'],
        // v1.3: physical-state records go to the Clinic only.
        'routes' => ['clinic'], 'default_route' => 'clinic',
    ],
    'mental_emotional' => [
        'label' => 'Mental / emotional state', 'hint' => 'Distress, anxiety, withdrawal, sudden changes in mood',
        'categories' => ['emotional' => 'Emotional distress', 'mental_health' => 'Mental health concern'],
        'routes' => ['guidance', 'clinic'], 'default_route' => 'guidance',
    ],
    'commendation' => [
        'label' => 'Commendation', 'hint' => 'Positive behavior worth recognizing',
        'categories' => ['conduct' => 'Good conduct', 'academic' => 'Academic', 'leadership' => 'Leadership',
                         'service' => 'Service', 'kindness' => 'Kindness', 'other' => 'Other'],
        'routes' => [], 'default_route' => null,
    ],
];
const ROUTES   = ['guidance' => ['Guidance Office', 'Counselors review it and follow up'],
                  'clinic'   => ['Clinic', 'The nurse reviews it and checks on the student']];
const SEVERITY = ['low' => 'Low', 'moderate' => 'Moderate', 'high' => 'High', 'urgent' => 'Urgent'];
// A physical-state pass is for a body complaint, so the "emotional" reason is left out here.
const PHYSICAL_PASS_REASONS = ['medical', 'injury', 'other'];

$students   = visible_students($user);          // v1.3: the adviser's own advisory class only
$studentIds = array_map('intval', array_column($students, 'id'));
$advisory   = advisory_section((int)$user['id']);

$old = [
    'student_id'     => (string)query('student_id', ''),
    'record_type'    => isset(RECORD_TYPES[query('type', '')]) ? (string)query('type') : 'conduct',
    'category'       => '',
    'title'          => '',
    'severity'       => 'low',
    'observed_at'    => date('Y-m-d\TH:i'),
    'description'    => '',
    'action_taken'   => '',
    'routed_to'      => '',
    'issue_pass'     => '1',
    'pass_reason'    => 'medical',
    'pass_urgency'   => 'routine',
];
if ($old['student_id'] !== '' && !in_array((int)$old['student_id'], $studentIds, true)) $old['student_id'] = '';

if (is_post()) {
    verify_csrf();
    foreach ($old as $k => $_) $old[$k] = trim((string)post($k, ''));

    $type      = $old['record_type'];
    $def       = RECORD_TYPES[$type] ?? null;
    $studentId = (int)$old['student_id'];
    $student   = in_array($studentId, $studentIds, true) ? find_student($user, $studentId) : null;
    $observed  = DateTime::createFromFormat('Y-m-d\TH:i', $old['observed_at']);
    $route     = $def ? ($def['routes'] ? ($old['routed_to'] ?: $def['default_route']) : null) : null;
    $wantsPass = $type === 'physical' && $old['issue_pass'] === '1';

    $error = !$student ? 'Please type and pick a student from your advisory class.'
        : (!$def ? 'Please choose a record type.'
        : (!isset($def['categories'][$old['category']]) ? 'Please choose a category for this record type.'
        : ($old['title'] === '' ? 'Please add a short title.'
        : ($old['description'] === '' ? 'Please describe what you observed.'
        : (!$observed || $observed > new DateTime('+5 minutes') ? 'Please enter when this happened (not in the future).'
        : ($def['routes'] && !in_array($route, $def['routes'], true) ? ($type === 'physical' ? 'Physical-state records can only be sent to the Clinic.' : 'Please choose where to send this report.')
        : ($type !== 'commendation' && !isset(SEVERITY[$old['severity']]) ? 'Please choose a severity.'
        : ($wantsPass && !in_array($old['pass_reason'], PHYSICAL_PASS_REASONS, true) ? 'Please choose why the student is going to the clinic.'
        : ($wantsPass && !isset(PASS_URGENCY[$old['pass_urgency']]) ? 'Please choose how urgent the clinic visit is.' : null)))))))));

    if ($error) {
        flash('error', $error);
    } elseif ($type === 'commendation') {
        require_permission('commendation.issue');
        $reason = $old['description'] . ($old['action_taken'] !== '' ? "\n\n" . $old['action_taken'] : '');
        db_run('INSERT INTO commendations (student_id, issued_by, category, title, description, awarded_on, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$studentId, $user['id'], $old['category'], mb_substr($old['title'], 0, 120), $reason, $observed->format('Y-m-d'), now()]);
        audit('create', 'commendations', db()->lastInsertId(), $studentId, ['title', 'description', 'category'], $user);
        flash('success', 'Commendation saved for ' . student_name($student) . '. Their family can see it in the Family Portal.');
        redirect('log_conduct.php#my-reports');
    } else {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            db_run('INSERT INTO incident_reports (student_id, reported_by, record_type, category, severity, routed_to, title, description, action_taken, observed_at, status, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$studentId, $user['id'], $type, $old['category'], $old['severity'], $route, mb_substr($old['title'], 0, 150),
                 $old['description'], $old['action_taken'] ?: null, $observed->format('Y-m-d H:i:s'), 'new', now()]);
            $reportId = (int)$pdo->lastInsertId();
            audit('create', 'incident_reports', $reportId, $studentId, ['record_type', 'category', 'severity', 'routed_to', 'description'], $user);

            $pass = null;
            if ($wantsPass) {
                $pass = issue_clinic_pass($user, $studentId, $old['pass_reason'], $old['title'] . ' — ' . $old['description'], $old['pass_urgency'], $reportId);
                if (isset($pass['error'])) throw new RuntimeException($pass['error']);
            }
            $pdo->commit();
        } catch (RuntimeException $e) {
            $pdo->rollBack();
            flash('error', $e->getMessage());
            $pass = false;
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        if ($pass !== false) {
            $msg = RECORD_TYPES[$type]['label'] . ' report for ' . student_name($student) . ' sent to the ' . ROUTES[$route][0] . '.';
            if ($pass) {
                $msg .= ' Clinic pass ' . $pass['code'] . ' issued (valid for ' . PASS_VALID_MINUTES . ' minutes). Follow it in the Clinic pass tracker on your overview.';
            }
            flash('success', $msg);
            redirect('log_conduct.php#my-reports');
        }
    }
}

$myReports = db_all(
    "SELECT r.record_type, r.category, r.severity, r.routed_to, r.title, r.status, r.observed_at, s.first_name, s.last_name,
            p.pass_code
       FROM incident_reports r
       JOIN students s ON s.id = r.student_id
       LEFT JOIN clinic_passes p ON p.report_id = r.id
      WHERE r.reported_by = ? ORDER BY r.created_at DESC, r.id DESC LIMIT 15",
    [$user['id']]
);

// Data for the type-in student search: the adviser's own class only.
$studentData = array_map(fn($s) => [
    'id'   => (int)$s['id'],
    'name' => $s['last_name'] . ', ' . $s['first_name'],
    'alt'  => $s['first_name'] . ' ' . $s['last_name'],
    'no'   => $s['student_no'],
], $students);
$picked = $old['student_id'] !== '' ? current(array_filter($studentData, fn($d) => (string)$d['id'] === $old['student_id'])) : null;

layout_start($user, 'conduct', 'Log conduct', 'Record behavior, a physical or mental/emotional state, or a commendation.');
?>

<div class="card" style="max-width: 860px; margin: 0 auto 24px;">
    <div class="card-head-flex" style="margin-bottom: 22px;">
        <div>
            <div class="card-title">New record</div>
            <div class="card-sub">Reports go straight to the right office. A physical state can include a digital clinic pass.</div>
        </div>
    </div>

    <?php if (!$students): ?>
        <div class="empty">No advisory class is assigned to you yet, so there are no students to log. The Registrar assigns advisers to sections.</div>
    <?php else: ?>
    <form method="post" action="log_conduct.php" id="conductForm" novalidate>
        <?= csrf_field() ?>

        <div class="field" style="margin-bottom: 20px;">
            <span class="field-label">Record type</span>
            <div class="choice-row" style="grid-template-columns: repeat(4, 1fr);">
                <?php foreach (RECORD_TYPES as $k => $t): ?>
                    <label class="choice">
                        <input type="radio" name="record_type" value="<?= $k ?>" <?= $old['record_type'] === $k ? 'checked' : '' ?>>
                        <span><?= e($t['label']) ?><small><?= e($t['hint']) ?></small></span>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="form-grid">
            <div class="field combo" id="studentCombo">
                <label for="student_search">Student</label>
                <div class="combo-box">
                    <span class="combo-ico" aria-hidden="true"><?= icon('search') ?></span>
                    <input type="text" id="student_search" role="combobox" aria-autocomplete="list" aria-expanded="false"
                           aria-controls="studentListbox" aria-describedby="studentHint" autocomplete="off" spellcheck="false"
                           placeholder="Type a name or student ID…" value="<?= e($picked ? $picked['name'] . ' · ' . $picked['no'] : '') ?>">
                    <button type="button" class="combo-clear" id="studentClear" aria-label="Clear student" <?= $picked ? '' : 'hidden' ?>>✕</button>
                    <ul class="combo-list" id="studentListbox" role="listbox" aria-label="Students in your advisory class" hidden></ul>
                </div>
                <input type="hidden" name="student_id" id="student_id" value="<?= e($old['student_id']) ?>">
                <span class="hint" id="studentHint">Only students in <?= $advisory ? 'Grade ' . e($advisory['grade_level']) . ' – ' . e($advisory['name']) : 'your advisory class' ?> are listed.</span>
                <span class="field-error" id="studentError" hidden>Pick a student from the list.</span>
            </div>

            <div class="field">
                <label for="category">Category</label>
                <select id="category" name="category" required>
                    <option value="">Choose a category…</option>
                    <?php foreach (RECORD_TYPES as $k => $t): ?>
                        <optgroup label="<?= e($t['label']) ?>" data-type="<?= $k ?>">
                            <?php foreach ($t['categories'] as $ck => $cv): ?>
                                <option value="<?= $ck ?>" <?= $old['record_type'] === $k && $old['category'] === $ck ? 'selected' : '' ?>><?= e($cv) ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field">
                <label for="title">Title</label>
                <input id="title" name="title" maxlength="150" placeholder="A short summary, e.g. Complained of a headache" value="<?= e($old['title']) ?>" required>
            </div>

            <div class="field">
                <label for="observed_at">When did this happen?</label>
                <input id="observed_at" type="datetime-local" name="observed_at" max="<?= date('Y-m-d\TH:i') ?>" value="<?= e($old['observed_at']) ?>" required>
            </div>

            <div class="field" data-hide-for="commendation">
                <label for="severity">Severity</label>
                <select id="severity" name="severity">
                    <?php foreach (SEVERITY as $k => $v): ?>
                        <option value="<?= $k ?>" <?= $old['severity'] === $k ? 'selected' : '' ?>><?= e($v) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field span-2">
                <label for="description" data-label-commendation="What did the student do?">What did you observe?</label>
                <textarea id="description" name="description" rows="4" placeholder="Describe what you saw or heard, when, and anything the student said. Stick to facts." required><?= e($old['description']) ?></textarea>
            </div>

            <div class="field span-2">
                <label for="action_taken" data-label-commendation="Anything else to add? (optional)">Action already taken <span class="muted" style="font-weight: 500;">(optional)</span></label>
                <input id="action_taken" name="action_taken" placeholder="e.g. Let the student rest, spoke privately, called the parent" value="<?= e($old['action_taken']) ?>">
            </div>

            <div class="field span-2" id="routeField">
                <span class="field-label">Send this report to</span>
                <div class="choice-row">
                    <?php foreach (ROUTES as $k => [$name, $desc]): ?>
                        <label class="choice" data-route="<?= $k ?>">
                            <input type="radio" name="routed_to" value="<?= $k ?>" <?= $old['routed_to'] === $k ? 'checked' : '' ?>>
                            <span><?= e($name) ?><small><?= e($desc) ?></small></span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <span class="hint" id="routeHint"></span>
            </div>

            <div class="field span-2 pass-box" id="passBox" hidden>
                <label class="check pass-toggle">
                    <input type="checkbox" name="issue_pass" value="1" id="issuePass" <?= $old['issue_pass'] === '1' ? 'checked' : '' ?>>
                    <span><strong>Issue a digital clinic pass now</strong><br><span class="muted">The clinic sees it right away and checks the code when the student arrives. Valid for <?= PASS_VALID_MINUTES ?> minutes.</span></span>
                </label>
                <div class="pass-fields" id="passFields">
                    <div class="field">
                        <label for="pass_reason">Reason for the pass</label>
                        <select id="pass_reason" name="pass_reason">
                            <?php foreach (PHYSICAL_PASS_REASONS as $k): ?>
                                <option value="<?= $k ?>" <?= $old['pass_reason'] === $k ? 'selected' : '' ?>><?= e(PASS_REASONS[$k]) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label for="pass_urgency">How urgent?</label>
                        <select id="pass_urgency" name="pass_urgency">
                            <?php foreach (PASS_URGENCY as $k => $v): ?>
                                <option value="<?= $k ?>" <?= $old['pass_urgency'] === $k ? 'selected' : '' ?>><?= e($v) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
        </div>

        <div class="notice notice-warn" id="urgentNotice" style="margin-top: 18px;" hidden>
            <?= icon('bell') ?>
            <span><strong>Urgent?</strong> If the student may be in danger or needs care right now, go to the Guidance Office or Clinic in person (or send another adult). Don't wait for this report to be read.</span>
        </div>

        <div class="form-actions">
            <a class="btn btn-soft" href="user_overview.php">Cancel</a>
            <button class="btn btn-primary" type="submit" id="submitBtn">Save and send</button>
        </div>
    </form>
    <?php endif; ?>
</div>

<div class="card" id="my-reports" style="max-width: 860px; margin: 0 auto;">
    <div class="card-head-flex">
        <div>
            <div class="card-title">My recent reports</div>
            <div class="card-sub">What you've sent and whether the office has picked it up.</div>
        </div>
    </div>
    <?php if (!$myReports): ?>
        <div class="empty">You haven't sent any reports yet.</div>
    <?php else: ?>
    <div style="overflow-x: auto;">
    <table class="table">
        <thead><tr><th>Student</th><th>Report</th><th>Sent to</th><th>Status</th><th>When</th></tr></thead>
        <tbody>
        <?php foreach ($myReports as $r):
            $tone = ['new' => 'amber', 'acknowledged' => 'blue', 'linked' => 'purple', 'closed' => 'gray'][$r['status']] ?? 'gray';
            $statusLabel = ['new' => 'Awaiting review', 'acknowledged' => 'Seen by office', 'linked' => 'Being followed up', 'closed' => 'Closed'][$r['status']] ?? $r['status'];
        ?>
            <tr>
                <td style="white-space: nowrap;"><strong><?= e($r['first_name'] . ' ' . $r['last_name']) ?></strong></td>
                <td>
                    <?= e($r['title']) ?>
                    <div class="muted" style="font-size: 0.75rem; margin-top: 2px;">
                        <?= e(RECORD_TYPES[$r['record_type']]['label'] ?? label($r['record_type'])) ?> · <?= e(SEVERITY[$r['severity']] ?? $r['severity']) ?>
                        <?php if ($r['pass_code']): ?> · Pass <span class="mono"><?= e($r['pass_code']) ?></span><?php endif; ?>
                    </div>
                </td>
                <td><?= e(ROUTES[$r['routed_to']][0] ?? '') ?></td>
                <td><span class="badge badge-<?= $tone ?>"><?= e($statusLabel) ?></span></td>
                <td class="muted" style="white-space: nowrap;"><?= e(date('M j, g:i A', strtotime($r['observed_at']))) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>

<style>
    .combo { position: relative; }
    .combo-box { position: relative; }
    .combo-box input { width: 100%; padding-left: 38px; padding-right: 36px; }
    .combo-ico { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #94a3b8; display: flex; pointer-events: none; }
    .combo-ico .ico { width: 16px; height: 16px; }
    .combo-clear { position: absolute; right: 6px; top: 50%; transform: translateY(-50%); width: 28px; height: 28px; border: 0; border-radius: 7px; background: transparent; color: #64748b; cursor: pointer; }
    .combo-clear:hover { background: #f1f5f9; color: #0f172a; }
    .combo-list { position: absolute; z-index: 30; left: 0; right: 0; top: calc(100% + 4px); margin: 0; padding: 6px; list-style: none; max-height: 260px; overflow-y: auto;
                  background: #fff; border: 1px solid var(--border); border-radius: 12px; box-shadow: 0 18px 40px -16px rgba(15,23,42,.35); }
    .combo-list li { display: flex; justify-content: space-between; gap: 12px; padding: 9px 11px; border-radius: 8px; cursor: pointer; font-size: 0.875rem; }
    .combo-list li .mono { color: #64748b; font-size: 0.75rem; }
    .combo-list li[aria-selected="true"], .combo-list li:hover { background: #eef2ff; }
    .combo-list li.combo-empty { cursor: default; color: #64748b; background: none; }
    .combo-list mark { background: #fde68a; color: inherit; border-radius: 3px; padding: 0 1px; }
    .field-error { color: #b91c1c; font-size: 0.76rem; font-weight: 600; margin-top: 4px; }
    .combo.invalid input { border-color: #fca5a5; box-shadow: 0 0 0 3px rgba(239,68,68,.12); }
    .pass-box { background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 12px; padding: 14px 16px; }
    .pass-box label.check { display: flex; align-items: flex-start; gap: 10px; font-weight: 500; margin: 0; line-height: 1.45; }
    .pass-toggle strong { color: #1e3a8a; }
    .pass-fields { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-top: 12px; }
    .pass-fields[hidden] { display: none; }
    .role-super_admin .combo-list { background: #182235; }
    @media (max-width: 640px) { .pass-fields { grid-template-columns: 1fr; } }
</style>

<script>
(function () {
    var form = document.getElementById('conductForm');
    if (!form) return;
    var types = <?= json_encode(array_map(fn($t) => ['routes' => $t['routes'], 'default' => $t['default_route']], RECORD_TYPES)) ?>;
    var category = document.getElementById('category');
    var routeField = document.getElementById('routeField');
    var routeHint = document.getElementById('routeHint');
    var severity = document.getElementById('severity');
    var urgent = document.getElementById('urgentNotice');
    var submit = document.getElementById('submitBtn');
    var passBox = document.getElementById('passBox'), issuePass = document.getElementById('issuePass'), passFields = document.getElementById('passFields');

    function currentType() { return form.querySelector('input[name=record_type]:checked').value; }

    function applyType(resetCategory) {
        var t = currentType(), def = types[t];

        category.querySelectorAll('optgroup').forEach(function (g) {
            var match = g.getAttribute('data-type') === t;
            g.hidden = !match; g.disabled = !match;
        });
        var opts = category.querySelectorAll('optgroup[data-type="' + t + '"] option');
        if (resetCategory || (opts.length === 1 && !category.value) || (category.selectedOptions[0] && category.selectedOptions[0].parentNode.disabled)) {
            category.value = opts.length === 1 ? opts[0].value : '';
        }

        if (!def.routes.length) {
            routeField.hidden = true;
        } else {
            routeField.hidden = false;
            form.querySelectorAll('[data-route]').forEach(function (el) {
                el.hidden = def.routes.indexOf(el.getAttribute('data-route')) === -1;
            });
            var checked = form.querySelector('input[name=routed_to]:checked');
            if (!checked || def.routes.indexOf(checked.value) === -1 || resetCategory) {
                form.querySelector('input[name=routed_to][value="' + def.default + '"]').checked = true;
            }
            routeHint.textContent = t === 'physical' ? 'Physical-state records always go to the Clinic.'
                : (def.routes.length === 1 ? 'Behavioral reports always go to the Guidance Office.'
                : 'Choose the office best placed to help. You can pick either one.');
        }

        passBox.hidden = t !== 'physical';
        form.querySelectorAll('[data-hide-for]').forEach(function (el) { el.hidden = el.getAttribute('data-hide-for') === t; });
        form.querySelectorAll('[data-label-commendation]').forEach(function (el) {
            if (!el.dataset.labelDefault) el.dataset.labelDefault = el.innerHTML;
            el.innerHTML = t === 'commendation' ? el.getAttribute('data-label-commendation') : el.dataset.labelDefault;
        });
        applySubmitLabel();
        applySeverity();
    }
    function applySubmitLabel() {
        var t = currentType();
        submit.textContent = t === 'commendation' ? 'Save commendation'
            : (t === 'physical' && issuePass.checked ? 'Save and issue pass' : 'Save and send');
        passFields.hidden = !issuePass.checked;
    }
    function applySeverity() { urgent.hidden = currentType() === 'commendation' || ['high', 'urgent'].indexOf(severity.value) === -1; }

    form.querySelectorAll('input[name=record_type]').forEach(function (r) { r.addEventListener('change', function () { applyType(true); }); });
    severity.addEventListener('change', applySeverity);
    issuePass.addEventListener('change', applySubmitLabel);
    applyType(false);

    // ---- Type-in student search (advisory class only) ----
    var students = <?= json_encode(array_values($studentData), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var combo = document.getElementById('studentCombo'), box = document.getElementById('student_search'),
        hidden = document.getElementById('student_id'), list = document.getElementById('studentListbox'),
        clear = document.getElementById('studentClear'), err = document.getElementById('studentError');
    var matches = [], active = -1;

    function norm(s) { return (s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, ''); }
    function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
    function highlight(text, q) {
        if (!q) return esc(text);
        var i = norm(text).indexOf(q);
        if (i === -1) return esc(text);
        return esc(text.slice(0, i)) + '<mark>' + esc(text.slice(i, i + q.length)) + '</mark>' + esc(text.slice(i + q.length));
    }
    function label(s) { return s.name + ' · ' + s.no; }
    function open(show) { list.hidden = !show; box.setAttribute('aria-expanded', show ? 'true' : 'false'); }
    function render() {
        var q = norm(box.value.trim());
        matches = students.filter(function (s) { return !q || norm(s.name).indexOf(q) !== -1 || norm(s.alt).indexOf(q) !== -1 || norm(s.no).indexOf(q) !== -1; });
        active = matches.length ? 0 : -1;
        list.innerHTML = matches.length
            ? matches.map(function (s, i) {
                return '<li role="option" id="stuopt' + s.id + '" data-i="' + i + '" aria-selected="' + (i === active) + '">'
                    + '<span>' + highlight(s.name, q) + '</span><span class="mono">' + highlight(s.no, q) + '</span></li>';
              }).join('')
            : '<li class="combo-empty" role="option" aria-disabled="true">No student in your advisory class matches.</li>';
        box.setAttribute('aria-activedescendant', active >= 0 ? 'stuopt' + matches[active].id : '');
        open(true);
    }
    function move(d) {
        if (!matches.length) return;
        active = (active + d + matches.length) % matches.length;
        list.querySelectorAll('li[data-i]').forEach(function (li) { li.setAttribute('aria-selected', li.getAttribute('data-i') == active ? 'true' : 'false'); });
        var li = list.querySelector('li[data-i="' + active + '"]');
        if (li) { li.scrollIntoView({ block: 'nearest' }); box.setAttribute('aria-activedescendant', li.id); }
    }
    function pick(s) {
        hidden.value = s.id; box.value = label(s); clear.hidden = false;
        combo.classList.remove('invalid'); err.hidden = true; open(false);
    }
    box.addEventListener('input', function () { hidden.value = ''; clear.hidden = box.value === ''; render(); });
    box.addEventListener('focus', function () { if (!hidden.value) render(); });
    box.addEventListener('keydown', function (e) {
        if (e.key === 'ArrowDown') { e.preventDefault(); if (list.hidden) render(); else move(1); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); move(-1); }
        else if (e.key === 'Enter') { if (!list.hidden && active >= 0) { e.preventDefault(); pick(matches[active]); } }
        else if (e.key === 'Escape') { if (!list.hidden) { e.stopPropagation(); open(false); } }
    });
    list.addEventListener('mousedown', function (e) {
        var li = e.target.closest('li[data-i]');
        if (li) { e.preventDefault(); pick(matches[+li.getAttribute('data-i')]); }
    });
    box.addEventListener('blur', function () {
        setTimeout(function () {
            open(false);
            if (!hidden.value) {
                // Accept an exact typed match (e.g. a full student ID) without clicking.
                var q = norm(box.value.trim());
                var exact = students.filter(function (s) { return q && (norm(s.no) === q || norm(s.name) === q || norm(s.alt) === q || norm(label(s)) === q); });
                if (exact.length === 1) pick(exact[0]);
            }
        }, 120);
    });
    clear.addEventListener('click', function () { hidden.value = ''; box.value = ''; clear.hidden = true; box.focus(); render(); });
    form.addEventListener('submit', function (e) {
        if (!hidden.value) {
            e.preventDefault();
            combo.classList.add('invalid'); err.hidden = false; box.focus();
        }
    });
})();
</script>

<?php layout_end(); ?>

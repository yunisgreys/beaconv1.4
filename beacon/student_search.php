<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
$user = require_page('student_search');

$students = visible_students($user);
$isTeacher = $user['role'] === 'teacher';
$advisory  = $isTeacher ? advisory_section((int)$user['id']) : null;

$attErr = null;
$attOld = ['att_date' => date('Y-m-d'), 'status' => 'present', 'remarks' => ''];
if (is_post() && post('action') === 'save_attendance') {
    verify_csrf();
    $sid = (int)post('student_id', 0);
    $attOld = ['att_date' => (string)post('att_date', ''), 'status' => (string)post('status', ''), 'remarks' => (string)post('remarks', '')];
    $attErr = save_attendance($user, $sid, $attOld['att_date'], $attOld['status'], $attOld['remarks']);
    if ($attErr === null) {
        flash('success', 'Attendance for ' . date('M j, Y', strtotime($attOld['att_date'])) . ' saved as ' . ATTENDANCE_STATUSES[$attOld['status']][0] . '.');
        redirect('student_search.php?id=' . $sid . '&tab=attendance');
    }
    flash('error', $attErr);
    $_GET['id'] = (string)$sid;
    $_GET['tab'] = 'attendance';
}

// v1.3: Clinic staff update the Health & Welfare record.
$healthOld = null;
if (is_post() && post('action') === 'save_health') {
    verify_csrf();
    $sid = (int)post('student_id', 0);
    if (!can_access_student($user, $sid)) deny();
    $err = save_medical_profile($user, $sid, $_POST);
    if ($err === null) {
        flash('success', 'Health information saved. It now shows for everyone who can see this student.');
        redirect('student_search.php?id=' . $sid . '&tab=health');
    }
    flash('error', $err);
    $healthOld = $_POST;
    $_GET['id'] = (string)$sid;
    $_GET['tab'] = 'health';
}

$selectedId = (int)query('id', 0);
if ($selectedId && !can_access_student($user, $selectedId)) {
    deny($user, "student:$selectedId");
}
$active = $selectedId ? find_student($user, $selectedId) : ($students[0] ?? null);
$activeId = $active ? (int)$active['id'] : 0;

if ($active) {
    audit('view', 'students', $activeId, $activeId, [], $user);
    $att      = attendance_summary($activeId, 30);
    $medical  = medical_profile($activeId);
    $welfare  = welfare_indicators([$activeId])[$activeId] ?? [];
    $hasCase  = has_open_guidance_case($activeId);
    $canNotes = $hasCase && can('guidance.summary.read', $user);
    $showNotes = $canNotes && query('notes') === '1';
    $guidance = $showNotes ? teacher_guidance_summary($user, $activeId) : [];
    $canRecordAtt = can('attendance.record', $user) && is_adviser_of($user, $activeId);
    $canEditHealth = can('clinic.medical.manage', $user);
    $healthMeta = $medical ? db_one('SELECT m.updated_at, u.first_name, u.last_name FROM medical_profiles m LEFT JOIN users u ON u.id = m.updated_by WHERE m.student_id = ?', [$activeId]) : null;
}
$openTab = in_array(query('tab'), ['general', 'attendance', 'health'], true) ? query('tab') : 'general';

$gradeOpts = $sectionList = [];
foreach ($students as $s) {
    if (!$s['section_id']) continue;
    $gradeOpts[$s['grade_level']] = 'Grade ' . $s['grade_level'];
    $sectionList[$s['section_id']] = [$s['grade_level'], $s['section_name']];
}
ksort($gradeOpts, SORT_NUMERIC);
uasort($sectionList, fn($a, $b) => strcmp($a[1], $b[1]));
$letterOpts = ['' => 'Any letter'] + array_combine(range('A', 'Z'), range('A', 'Z'));

$rosterTitle = $isTeacher ? 'My advisory class' : 'All enrolled students';
layout_start($user, 'search', 'Student lookup', 'Search and view student profiles, attendance and health information.');
?>

<div class="card" style="margin-bottom: 20px; padding: 16px 20px;">
    <div style="position: relative;">
        <span class="lookup-search-ico" style="position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: #94a3b8; display: flex;"><?= icon('search') ?></span>
        <input type="search" id="studentSearchInput" placeholder="Search by name, student ID or LRN…" autocomplete="off" aria-label="Search students"
               style="width: 100%; padding: 11px 14px 11px 42px; border: 1px solid var(--border); border-radius: 10px; font: inherit; font-size: 0.9rem; background: #f8fafc; color: var(--text-main);">
    </div>
    <div class="lookup-filters">
        <?php if ($isTeacher): ?>
            <div class="field"><span class="field-label">Grade level</span><div class="static-field" aria-readonly="true"><?= $advisory ? 'Grade ' . e($advisory['grade_level']) : '—' ?></div></div>
            <div class="field"><span class="field-label">Section</span><div class="static-field" aria-readonly="true"><?= $advisory ? e($advisory['name']) : '—' ?></div></div>
        <?php else: ?>
        <div class="field"><label for="fGrade">Grade level</label><select id="fGrade"><?= opts(['' => 'All grades'] + $gradeOpts) ?></select></div>
        <div class="field"><label for="fSection">Section</label><select id="fSection"><option value="">All sections</option>
            <?php foreach ($sectionList as $id => [$g, $n]): ?><option value="<?= (int)$id ?>" data-grade="<?= (int)$g ?>"><?= e($n) ?></option><?php endforeach; ?>
        </select></div>
        <?php endif; ?>
        <div class="field"><label for="fLetter">Name starts with</label><select id="fLetter"><?= opts($letterOpts) ?></select></div>
        <div class="field"><label for="fLetterOf">Letter of</label><select id="fLetterOf"><?= opts(['last' => 'Last name', 'first' => 'First name']) ?></select></div>
        <button type="button" class="btn btn-soft" id="fReset">Clear</button>
    </div>
</div>

<?php if (!$students): ?>
    <div class="card"><div class="empty">No students to show yet<?= $isTeacher ? ': no advisory class is assigned to you. The Registrar assigns advisers to sections.' : '.' ?></div></div>
<?php else: ?>
<div class="lookup-grid">

    <div class="card" style="padding: 18px; margin-bottom: 0;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px;">
            <div class="card-title" style="font-size: 0.95rem;"><?= e($rosterTitle) ?></div>
            <span class="badge badge-gray" id="studentCountBadge"><?= count($students) ?> learners</span>
        </div>

        <div id="studentList" class="roster">
            <?php foreach ($students as $s): $isActive = (int)$s['id'] === $activeId; ?>
                <a href="student_search.php?id=<?= (int)$s['id'] ?>"
                   class="roster-item<?= $isActive ? ' active' : '' ?>"
                   <?= $isActive ? 'aria-current="true"' : '' ?>
                   data-grade="<?= (int)$s['grade_level'] ?>" data-section="<?= (int)$s['section_id'] ?>"
                   data-first="<?= e(strtoupper(mb_substr($s['first_name'], 0, 1))) ?>" data-last="<?= e(strtoupper(mb_substr($s['last_name'], 0, 1))) ?>"
                   data-search="<?= e(strtolower(student_name($s) . ' ' . $s['last_name'] . ' ' . $s['student_no'] . ' ' . $s['lrn'])) ?>">
                    <div class="roster-avatar"><?= e(student_initials($s)) ?></div>
                    <div style="overflow: hidden;">
                        <strong><?= e($s['last_name'] . ', ' . $s['first_name']) ?></strong>
                        <span class="mono"><?= e($s['student_no']) ?><?= !$isTeacher && $s['grade_level'] ? ' · G' . e($s['grade_level']) . ' ' . e($s['section_name']) : '' ?></span>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
        <div id="noResultsMsg" class="empty" hidden>No matching learners found.</div>
    </div>

    <?php if ($active): ?>
    <div class="card" style="padding: 24px; margin-bottom: 0;">
        <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; border-bottom: 1px solid var(--border); padding-bottom: 18px; margin-bottom: 20px; flex-wrap: wrap;">
            <div style="display: flex; gap: 14px; align-items: center;">
                <div class="roster-avatar" style="width: 52px; height: 52px; font-size: 1rem;"><?= e(student_initials($active)) ?></div>
                <div>
                    <h2 style="font-family: 'Manrope', sans-serif; font-size: 1.25rem; font-weight: 800; color: #0f172a; margin: 0;"><?= e(student_name($active)) ?></h2>
                    <p class="muted" style="font-size: 0.8rem; margin: 3px 0 0;">
                        <span class="mono"><?= e($active['student_no']) ?></span>
                        <?php if ($active['lrn']): ?> · LRN <span class="mono"><?= e($active['lrn']) ?></span><?php endif; ?>
                        <?php if ($active['grade_level']): ?> · Grade <?= e($active['grade_level']) ?> – <?= e($active['section_name']) ?><?php endif; ?>
                    </p>
                </div>
            </div>
            <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                <?php if ($canNotes): ?>
                    <a class="btn btn-soft" href="student_search.php?id=<?= $activeId ?>&amp;notes=1" style="font-size: 0.8rem; padding: 8px 12px;"><?= icon('shield') ?> View guidance notes</a>
                <?php endif; ?>
                <?php if (can('incident.create', $user)): ?>
                    <a class="btn btn-primary" href="log_conduct.php?student_id=<?= $activeId ?>" style="font-size: 0.8rem; padding: 8px 12px;">+ Log conduct</a>
                <?php endif; ?>
            </div>
        </div>

        <div class="tabs" role="tablist">
            <button type="button" class="tab-btn<?= $openTab === 'general' ? ' active-tab' : '' ?>" role="tab" data-tab="tab-general">General profile</button>
            <button type="button" class="tab-btn<?= $openTab === 'attendance' ? ' active-tab' : '' ?>" role="tab" data-tab="tab-attendance">Attendance</button>
            <button type="button" class="tab-btn<?= $openTab === 'health' ? ' active-tab' : '' ?>" role="tab" data-tab="tab-health">Health &amp; welfare</button>
        </div>

        <div id="tab-general" class="tab-content" role="tabpanel"<?= $openTab === 'general' ? '' : ' hidden' ?>>
            <div class="form-grid" style="gap: 12px;">
                <div class="info-tile"><span class="k">Class adviser</span><span class="v"><?= e($active['adviser_first'] ? $active['adviser_first'] . ' ' . $active['adviser_last'] : '—') ?></span></div>
                <div class="info-tile"><span class="k">Birthdate &amp; sex</span><span class="v"><?= e(date('F j, Y', strtotime($active['birthdate']))) ?> · <?= $active['sex'] === 'F' ? 'Female' : 'Male' ?></span></div>
                <div class="info-tile"><span class="k">Parent / guardian</span><span class="v"><?= e($active['guardian_name'] ?: '—') ?><?= $active['guardian_relation'] ? ' (' . e($active['guardian_relation']) . ')' : '' ?></span></div>
                <div class="info-tile"><span class="k">Guardian contact</span><span class="v"><?= e($active['guardian_contact'] ?: '—') ?></span></div>
                <div class="info-tile span-2"><span class="k">Address</span><span class="v"><?= e($active['address'] ?: '—') ?></span></div>
            </div>
        </div>

        <div id="tab-attendance" class="tab-content" role="tabpanel"<?= $openTab === 'attendance' ? '' : ' hidden' ?>>
            <div class="form-grid" style="grid-template-columns: repeat(4, 1fr); gap: 10px; margin-bottom: 16px;">
                <div class="info-tile"><span class="k">Rate (30 days)</span><span class="v" style="font-size: 1.2rem;"><?= $att['rate'] === null ? '—' : $att['rate'] . '%' ?></span></div>
                <div class="info-tile"><span class="k">Late</span><span class="v" style="font-size: 1.2rem;"><?= $att['counts']['late'] ?></span></div>
                <div class="info-tile"><span class="k">Absent</span><span class="v" style="font-size: 1.2rem;"><?= $att['counts']['absent'] ?></span></div>
                <div class="info-tile"><span class="k">Excused</span><span class="v" style="font-size: 1.2rem;"><?= $att['counts']['excused'] ?></span></div>
            </div>

            <?php if ($canRecordAtt): ?>
            <form method="post" action="student_search.php?id=<?= $activeId ?>&amp;tab=attendance" class="att-form" id="attForm" novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_attendance">
                <input type="hidden" name="student_id" value="<?= $activeId ?>">
                <div class="att-form-head">
                    <strong id="attFormTitle">Record attendance</strong>
                    <span class="muted">Saving a date that already has a record updates it.</span>
                </div>
                <div class="att-form-grid">
                    <div class="field">
                        <label for="att_date">Date</label>
                        <input type="date" id="att_date" name="att_date" max="<?= date('Y-m-d') ?>" value="<?= e($attOld['att_date']) ?>" required>
                    </div>
                    <div class="field">
                        <span class="field-label" id="attStatusLbl">Status</span>
                        <div class="att-status" role="radiogroup" aria-labelledby="attStatusLbl">
                            <?php foreach (ATTENDANCE_STATUSES as $k => [$lbl, $tone]): ?>
                                <label class="att-pill att-<?= $tone ?>"><input type="radio" name="status" value="<?= $k ?>" <?= $attOld['status'] === $k ? 'checked' : '' ?>><span><?= e($lbl) ?></span></label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="field att-remarks">
                        <label for="att_remarks">Remarks <span class="muted" style="font-weight: 500;">(optional)</span></label>
                        <input id="att_remarks" name="remarks" maxlength="255" placeholder="e.g. Arrived 7:45 AM, excuse letter from parent" value="<?= e($attOld['remarks']) ?>">
                    </div>
                    <div class="att-actions">
                        <button type="button" class="btn btn-soft" id="attReset" hidden>Cancel edit</button>
                        <button type="submit" class="btn btn-primary" id="attSubmit">Save</button>
                    </div>
                </div>
            </form>
            <?php endif; ?>

            <?php if (!$att['rows']): ?>
                <div class="empty">No attendance recorded in the last 30 days.</div>
            <?php else: ?>
                <table class="table">
                    <thead><tr><th>Date</th><th>Status</th><th>Remarks</th><th>Recorded by</th><?php if ($canRecordAtt): ?><th style="width: 70px;"></th><?php endif; ?></tr></thead>
                    <tbody>
                    <?php foreach ($att['rows'] as $r): [$stLbl, $tone] = ATTENDANCE_STATUSES[$r['status']] ?? [ucfirst($r['status']), 'gray']; ?>
                        <tr>
                            <td style="white-space: nowrap;"><?= e(date('D, M j', strtotime($r['att_date']))) ?></td>
                            <td><span class="badge badge-<?= $tone ?>"><?= e($stLbl) ?></span></td>
                            <td class="muted"><?= e($r['remarks'] ?: '—') ?></td>
                            <td class="muted" style="font-size: 0.78rem; white-space: nowrap;"><?= e($r['by_first'] . ' ' . $r['by_last']) ?></td>
                            <?php if ($canRecordAtt): ?>
                                <td style="text-align: right;">
                                    <button type="button" class="btn btn-soft btn-sm" data-att-edit
                                            data-date="<?= e($r['att_date']) ?>" data-status="<?= e($r['status']) ?>" data-remarks="<?= e($r['remarks'] ?? '') ?>"
                                            aria-label="Edit attendance for <?= e(date('M j', strtotime($r['att_date']))) ?>">Edit</button>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <div id="tab-health" class="tab-content" role="tabpanel"<?= $openTab === 'health' ? '' : ' hidden' ?>>
            <div class="info-tile" style="margin-bottom: 12px;">
                <span class="k">Welfare indicators</span>
                <div class="tags" style="margin-top: 6px;">
                    <?php foreach ($welfare as $tag): ?>
                        <span class="badge badge-<?= e($tag['tone']) ?>" title="<?= e($tag['hint']) ?>"><?= e($tag['label']) ?></span>
                    <?php endforeach; ?>
                    <?php if (!$welfare): ?><span class="badge badge-gray">No active flags</span><?php endif; ?>
                </div>
            </div>
            <?php if ($canEditHealth):
                $h = $healthOld ?? ($medical ?: []);
                $bloodOpts = ['' => 'Not recorded'] + array_combine(BLOOD_TYPES, BLOOD_TYPES);
            ?>
            <form method="post" action="student_search.php?id=<?= $activeId ?>&amp;tab=health" class="health-form" novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_health">
                <input type="hidden" name="student_id" value="<?= $activeId ?>">
                <div class="health-form-head">
                    <strong>Edit health information</strong>
                    <span class="muted"><?= $healthMeta && $healthMeta['first_name'] ? 'Last updated by ' . e($healthMeta['first_name'] . ' ' . $healthMeta['last_name']) . ' · ' . e(date('M j, Y g:i A', strtotime($healthMeta['updated_at']))) : 'No health record yet.' ?></span>
                </div>
                <div class="form-grid" style="gap: 12px;">
                    <div class="field"><label for="h_allergies">Allergies</label><textarea id="h_allergies" name="allergies" rows="2" maxlength="1000" placeholder="e.g. Peanuts, penicillin. Leave blank if none."><?= e($h['allergies'] ?? '') ?></textarea></div>
                    <div class="field"><label for="h_conditions">Medical conditions</label><textarea id="h_conditions" name="conditions" rows="2" maxlength="1000" placeholder="e.g. Asthma (mild, exercise-induced)"><?= e($h['conditions'] ?? '') ?></textarea></div>
                    <div class="field"><label for="h_meds">Regular medication</label><textarea id="h_meds" name="regular_medications" rows="2" maxlength="1000" placeholder="e.g. Salbutamol inhaler as needed"><?= e($h['regular_medications'] ?? '') ?></textarea></div>
                    <div class="field"><label for="h_blood">Blood type</label><select id="h_blood" name="blood_type"><?= opts($bloodOpts, $h['blood_type'] ?? '') ?></select></div>
                    <div class="field span-2"><label for="h_emergency">Emergency notes</label><textarea id="h_emergency" name="emergency_notes" rows="2" maxlength="1000" placeholder="What staff should do in an emergency, e.g. Give own inhaler, then call the clinic"><?= e($h['emergency_notes'] ?? '') ?></textarea></div>
                </div>
                <div class="form-actions" style="margin-top: 14px;">
                    <button type="submit" class="btn btn-primary">Save health information</button>
                </div>
            </form>
            <?php else: ?>
            <div class="form-grid" style="gap: 12px;">
                <div class="info-tile"><span class="k">Allergies</span><span class="v"><?= e($medical['allergies'] ?? '') ?: 'None recorded' ?></span></div>
                <div class="info-tile"><span class="k">Medical conditions</span><span class="v"><?= e($medical['conditions'] ?? '') ?: 'None recorded' ?></span></div>
                <div class="info-tile"><span class="k">Regular medication</span><span class="v"><?= e($medical['regular_medications'] ?? '') ?: 'None recorded' ?></span></div>
                <div class="info-tile"><span class="k">Blood type</span><span class="v"><?= e($medical['blood_type'] ?? '') ?: '—' ?></span></div>
                <?php if (!empty($medical['emergency_notes'])): ?>
                    <div class="info-tile span-2"><span class="k">Emergency notes</span><span class="v"><?= e($medical['emergency_notes']) ?></span></div>
                <?php endif; ?>
            </div>
            <p class="muted" style="font-size: 0.78rem; margin-top: 12px;">Health information is maintained by the school clinic<?= $healthMeta && $healthMeta['first_name'] ? ' (last updated ' . e(date('M j, Y', strtotime($healthMeta['updated_at']))) . ')' : '' ?>.</p>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if (!empty($showNotes)): ?>
<div id="modalGuidance" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="guidanceTitle" aria-hidden="true" data-autoopen>
    <div class="modal-card wide">
        <div class="modal-head">
            <div class="modal-icon ic-violet"><?= icon('shield') ?></div>
            <div>
                <h3 id="guidanceTitle">Guidance notes · <?= e(student_name($active)) ?></h3>
                <p>Read-only. Only notes the counselor marked as shareable are shown here.</p>
            </div>
            <a class="modal-close" href="student_search.php?id=<?= $activeId ?>" aria-label="Close">✕</a>
        </div>
        <div class="modal-body">
            <?php if (!$guidance): ?>
                <div class="empty">There is no active guidance case for this student.</div>
            <?php endif; ?>
            <?php foreach ($guidance as $i => $c): ?>
                <div style="<?= $i ? 'border-top: 1px solid var(--border); padding-top: 18px; margin-top: 18px;' : '' ?>">
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 12px;">
                        <div>
                            <strong style="font-size: 0.95rem;"><?= e($c['title']) ?></strong>
                            <div class="muted" style="font-size: 0.78rem; margin-top: 2px;">
                                Counselor: <?= e($c['counselor_first'] ? $c['counselor_first'] . ' ' . $c['counselor_last'] : 'Not yet assigned') ?>
                                · since <?= e(date('M j, Y', strtotime($c['opened_at']))) ?>
                            </div>
                        </div>
                        <span class="badge badge-<?= $c['status'] === 'open' ? 'purple' : 'blue' ?>"><?= e(ucfirst($c['status'])) ?></span>
                    </div>

                    <?php if ($c['interventions']): ?>
                        <div class="k" style="font-size: 0.68rem; color: #64748b; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; margin: 14px 0 6px;">How you can support</div>
                        <?php foreach ($c['interventions'] as $iv): ?>
                            <div class="info-tile" style="margin-bottom: 8px;">
                                <span class="v" style="margin: 0;"><?= e(label($iv['plan_type'])) ?> — <?= e($iv['goals']) ?></span>
                                <?php if ($iv['strategies']): ?><div class="muted" style="font-size: 0.8rem; margin-top: 4px;"><?= e($iv['strategies']) ?></div><?php endif; ?>
                                <?php if ($iv['review_date']): ?><div class="muted" style="font-size: 0.72rem; margin-top: 4px;">Next review: <?= e(date('M j, Y', strtotime($iv['review_date']))) ?></div><?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <div class="k" style="font-size: 0.68rem; color: #64748b; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; margin: 14px 0 6px;">Shared notes</div>
                    <?php if (!$c['notes']): ?>
                        <p class="muted" style="font-size: 0.8rem; margin: 0;">No shared notes yet.</p>
                    <?php endif; ?>
                    <?php foreach ($c['notes'] as $n): ?>
                        <div style="padding: 10px 0; border-bottom: 1px dashed var(--border); font-size: 0.85rem; line-height: 1.5;">
                            <?= e($n['body']) ?>
                            <div class="muted" style="font-size: 0.72rem; margin-top: 3px;"><?= e(label($n['note_type'])) ?> · <?= e(date('M j, Y', strtotime($n['created_at']))) ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
            <div class="notice notice-info" style="margin-top: 18px;">
                <?= icon('shield') ?>
                <span>Please keep this information private. To share a concern with the Guidance Office, use <a href="log_conduct.php?student_id=<?= $activeId ?>" style="color: inherit; font-weight: 700;">Log conduct</a>.</span>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<style>
    .lookup-filters { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)) auto; gap: 12px; align-items: end; margin-top: 14px; }
    .lookup-filters .field label, .lookup-filters .field .field-label { font-size: 0.72rem; margin-bottom: 4px; }
    .lookup-filters .btn { height: 41px; }
    @media (max-width: 800px) { .lookup-filters { grid-template-columns: 1fr 1fr; } }
    .lookup-grid { display: grid; grid-template-columns: 330px 1fr; gap: 24px; align-items: start; }
    .roster { display: flex; flex-direction: column; gap: 8px; max-height: 560px; overflow-y: auto; padding-right: 2px; }
    .roster-item { display: flex; align-items: center; gap: 10px; padding: 11px 12px; border-radius: 10px; border: 1px solid var(--border); text-decoration: none; color: inherit; background: #fff; transition: background .15s, border-color .15s; }
    .roster-item:hover { background: #f8fafc; border-color: #cbd5e1; }
    .roster-item.active { background: #f1f1ff; border-color: var(--primary); }
    .roster-item strong { font-size: 0.85rem; color: #0f172a; display: block; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .roster-item .mono { font-size: 0.7rem; color: #64748b; display: block; }
    .roster-avatar { width: 34px; height: 34px; flex-shrink: 0; border-radius: 50%; background: #e6e6ff; color: #1A19A5; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.75rem; }
    .tabs { display: flex; gap: 18px; border-bottom: 1px solid var(--border); margin-bottom: 20px; }
    .tab-btn { background: none; border: none; padding: 6px 2px 10px; font: inherit; font-size: 0.85rem; font-weight: 600; color: #64748b; cursor: pointer; border-bottom: 2px solid transparent; margin-bottom: -1px; }
    .tab-btn:hover { color: #0f172a; }
    .tab-btn.active-tab { color: var(--primary); border-bottom-color: var(--primary); }
    a.modal-close { text-decoration: none; }
    .lookup-search-ico .ico { width: 17px; height: 17px; }
    .health-form { background: #f8fafc; border: 1px solid var(--border); border-radius: 12px; padding: 16px 18px; }
    .health-form-head { display: flex; justify-content: space-between; align-items: baseline; gap: 12px; flex-wrap: wrap; margin-bottom: 12px; }
    .health-form-head strong { font-size: 0.9rem; color: #0f172a; }
    .health-form-head .muted { font-size: 0.75rem; }
    .health-form textarea { min-height: 62px; }
    .static-field { display: flex; align-items: center; height: 41px; padding: 0 13px; border: 1px solid var(--border); border-radius: 10px; background: #f1f5f9; color: #0f172a; font-weight: 600; font-size: 0.875rem; cursor: default; }
    .att-form { background: #f8fafc; border: 1px solid var(--border); border-radius: 12px; padding: 16px 18px; margin-bottom: 18px; }
    .att-form.editing { border-color: var(--primary); background: #f5f6ff; }
    .att-form-head { display: flex; justify-content: space-between; align-items: baseline; gap: 12px; flex-wrap: wrap; margin-bottom: 12px; }
    .att-form-head strong { font-size: 0.9rem; color: #0f172a; }
    .att-form-head .muted { font-size: 0.75rem; }
    .att-form-grid { display: grid; grid-template-columns: 170px 1fr; gap: 12px 14px; align-items: end; }
    .att-remarks { grid-column: 1 / -1; }
    .att-actions { grid-column: 1 / -1; display: flex; justify-content: flex-end; gap: 8px; }
    .att-status { display: flex; flex-wrap: wrap; gap: 8px; }
    .att-pill { position: relative; cursor: pointer; }
    .att-pill input { position: absolute; opacity: 0; pointer-events: none; }
    .att-pill span { display: inline-flex; align-items: center; height: 41px; padding: 0 16px; border-radius: 10px; border: 1px solid var(--border); background: #fff; font-size: 0.85rem; font-weight: 600; color: #475569; transition: background .15s, border-color .15s, color .15s; }
    .att-pill input:focus-visible + span { outline: 2px solid var(--primary); outline-offset: 2px; }
    .att-green input:checked + span { background: #dcfce7; border-color: #86efac; color: #166534; }
    .att-amber input:checked + span { background: #fef3c7; border-color: #fcd34d; color: #92400e; }
    .att-red   input:checked + span { background: #fee2e2; border-color: #fca5a5; color: #991b1b; }
    .att-blue  input:checked + span { background: #dbeafe; border-color: #93c5fd; color: #1e40af; }
    @media (max-width: 640px) { .att-form-grid { grid-template-columns: 1fr; } }
    @media (max-width: 1000px) { .lookup-grid { grid-template-columns: 1fr; } }
</style>

<script>
(function () {
    var input = document.getElementById('studentSearchInput');
    var fGrade = document.getElementById('fGrade'), fSection = document.getElementById('fSection'),
        fLetter = document.getElementById('fLetter'), fLetterOf = document.getElementById('fLetterOf');
    var sectionOpts = fSection ? [].slice.call(fSection.options, 1) : [];
    function apply() {
        var q = input.value.toLowerCase().trim(), shown = 0;
        document.querySelectorAll('.roster-item').forEach(function (el) {
            var hit = (el.getAttribute('data-search') || '').indexOf(q) !== -1
                && (!fGrade || !fGrade.value || el.getAttribute('data-grade') === fGrade.value)
                && (!fSection || !fSection.value || el.getAttribute('data-section') === fSection.value)
                && (!fLetter.value || el.getAttribute('data-' + fLetterOf.value) === fLetter.value);
            el.style.display = hit ? '' : 'none';
            if (hit) shown++;
        });
        var badge = document.getElementById('studentCountBadge'), none = document.getElementById('noResultsMsg');
        if (badge) badge.textContent = shown + ' learners';
        if (none) none.hidden = shown !== 0;
    }
    if (fGrade && fSection) {
        fGrade.addEventListener('change', function () {
            var keep = fSection.value;
            fSection.length = 1;
            sectionOpts.forEach(function (o) { if (!fGrade.value || o.getAttribute('data-grade') === fGrade.value) fSection.appendChild(o); });
            fSection.value = keep;
            if (fSection.value !== keep) fSection.value = '';
            apply();
        });
        fSection.addEventListener('change', apply);
    }
    [fLetter, fLetterOf].forEach(function (el) { el.addEventListener('change', apply); });
    input.addEventListener('input', apply);
    document.getElementById('fReset').addEventListener('click', function () {
        input.value = ''; fLetter.value = ''; fLetterOf.value = 'last';
        if (fGrade) { fGrade.value = ''; fGrade.dispatchEvent(new Event('change')); } else { apply(); }
    });

    // Attendance: "Edit" fills the form with that day's record.
    var attForm = document.getElementById('attForm');
    if (attForm) {
        var attDate = document.getElementById('att_date'), attRemarks = document.getElementById('att_remarks'),
            attTitle = document.getElementById('attFormTitle'), attReset = document.getElementById('attReset'),
            attSubmit = document.getElementById('attSubmit'), today = attDate.getAttribute('max');
        function setStatus(v) { var r = attForm.querySelector('input[name=status][value="' + v + '"]'); if (r) r.checked = true; }
        document.querySelectorAll('[data-att-edit]').forEach(function (b) {
            b.addEventListener('click', function () {
                attDate.value = b.getAttribute('data-date');
                setStatus(b.getAttribute('data-status'));
                attRemarks.value = b.getAttribute('data-remarks');
                attTitle.textContent = 'Edit attendance for ' + new Date(b.getAttribute('data-date') + 'T00:00:00').toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric' });
                attSubmit.textContent = 'Update';
                attReset.hidden = false;
                attForm.classList.add('editing');
                attForm.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            });
        });
        attReset.addEventListener('click', function () {
            attDate.value = today; setStatus('present'); attRemarks.value = '';
            attTitle.textContent = 'Record attendance'; attSubmit.textContent = 'Save';
            attReset.hidden = true; attForm.classList.remove('editing');
        });
    }

    document.querySelectorAll('.tab-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.querySelectorAll('.tab-content').forEach(function (el) { el.hidden = true; });
            document.querySelectorAll('.tab-btn').forEach(function (el) { el.classList.remove('active-tab'); });
            document.getElementById(btn.getAttribute('data-tab')).hidden = false;
            btn.classList.add('active-tab');
        });
    });
})();
</script>

<?php layout_end(); ?>

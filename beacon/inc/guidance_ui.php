<?php

/**
 * "Status criteria & legend" card.
 * $horizontal = true: gray card with the three levels side by side (Guidance desk, case list).
 * $collapsible = true: criteria hidden behind "Show criteria" (narrow side column on a case).
 */
function wellbeing_legend_card(bool $collapsible = false, bool $horizontal = false): void {
    $risks = [
        ['Critical', 'red',    'Red',    'Possible danger to self or others; act immediately'],
        ['High',     'red',    'Red',    'Serious, worsening, or affecting safety or attendance'],
        ['Moderate', 'yellow', 'Yellow', 'Ongoing concern that needs regular check-ins'],
        ['Low',      'green',  'Green',  'Mild or improving; monitor only'],
    ];
?>
    <div class="card legend-card<?= $horizontal ? ' legend-horizontal' : '' ?>" id="status-legend">
        <div class="card-head-flex" style="margin-bottom: <?= $horizontal ? '14px' : '6px' ?>;">
            <div>
                <div class="card-title" style="font-size: 1rem;">Status criteria &amp; legend</div>
                <div class="card-sub">How BEACON sets each student's status. The highest matching level wins.</div>
            </div>
        </div>
        <?php if ($collapsible): ?><details><summary class="card-link" style="cursor: pointer; margin: 8px 0;">Show criteria</summary><?php endif; ?>
        <div class="<?= $horizontal ? 'legend-grid' : '' ?>">
        <?php foreach (WELLBEING_LEVELS as $key => $lvl): ?>
            <div class="legend-level">
                <div class="legend-level-head">
                    <?= wellbeing_badge(['level' => $key, 'reasons' => []]) ?>
                    <span class="muted"><?= e($lvl['short']) ?> if any of these apply:</span>
                </div>
                <ul>
                    <?php foreach ($lvl['criteria'] as $c): ?><li><?= e($c) ?></li><?php endforeach; ?>
                </ul>
                <p><strong>What to do:</strong> <?= e($lvl['response']) ?></p>
            </div>
        <?php endforeach; ?>
        </div>
        <div class="legend-risks">
            <span class="section-label" style="margin-top: 0;">Case risk levels</span>
            <div class="legend-risk-list">
                <?php foreach ($risks as [$name, $tone, $lbl, $desc]): ?>
                    <div class="legend-risk">
                        <div><strong><?= e($name) ?></strong> <span class="legend-arrow" aria-hidden="true">→</span>
                            <span class="wb-pill wb-<?= $tone ?>" style="padding: 1px 8px;"><span class="wb-dot"></span><?= e($lbl) ?></span>
                            <span class="sr-only">shows as <?= e($lbl) ?></span></div>
                        <span class="muted"><?= e($desc) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php if ($collapsible): ?></details><?php endif; ?>
    </div>
<?php }

function open_case_modal(array $students, array $prefill = [], bool $autoOpen = false, string $action = 'case_bip_manager.php'): void {
    $counselorOpts = ['' => 'Me'] + array_column(array_map(fn($c) => ['id' => $c['id'], 'n' => $c['first_name'] . ' ' . $c['last_name']], counselors()), 'n', 'id');
?>
<div id="modalOpenCase" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="openCaseTitle" aria-hidden="true" <?= $autoOpen ? 'data-autoopen' : '' ?>>
    <form class="modal-card" method="post" action="<?= e($action) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="open_case">
        <input type="hidden" name="report_id" value="<?= (int)($prefill['report_id'] ?? 0) ?>">
        <div class="modal-head">
            <div class="modal-icon ic-violet"><?= icon('users') ?></div>
            <div><h3 id="openCaseTitle">Open a new case</h3><p>Start tracking support for a student.</p></div>
            <button type="button" class="modal-close" data-close aria-label="Close">✕</button>
        </div>
        <div class="modal-body">
            <div class="form-grid">
                <div class="field span-2">
                    <label for="oc_student">Student</label>
                    <?= student_picker('oc_student', $students, $prefill['student_id'] ?? '') ?>
                </div>
                <div class="field span-2">
                    <label for="oc_title">Case title</label>
                    <input id="oc_title" name="title" maxlength="150" required value="<?= e($prefill['title'] ?? '') ?>" placeholder="e.g. Exam anxiety and sleep problems">
                </div>
                <div class="field">
                    <label for="oc_type">Type</label>
                    <select id="oc_type" name="case_type"><?= opts(CASE_TYPES, $prefill['case_type'] ?? 'behavioral') ?></select>
                </div>
                <div class="field">
                    <label for="oc_risk">Risk level</label>
                    <select id="oc_risk" name="risk_level"><?= opts(RISK_LEVELS, $prefill['risk_level'] ?? 'low') ?></select>
                    <span class="hint"><a href="#status-legend" class="card-link" style="font-size: 0.75rem;" data-close>What do these mean?</a></span>
                </div>
                <div class="field">
                    <label for="oc_counselor">Assigned counselor</label>
                    <select id="oc_counselor" name="counselor_id"><?= opts($counselorOpts, '') ?></select>
                </div>
                <div class="field" style="display: flex; align-items: flex-end;">
                    <label class="check"><input type="checkbox" name="is_mental_health" value="1"> Involves mental health <span class="muted">(kept private from parents unless the student consents)</span></label>
                </div>
                <div class="field span-2">
                    <label for="oc_summary">Summary for families <span class="muted" style="font-weight: 500;">(optional)</span></label>
                    <input id="oc_summary" name="parent_summary" maxlength="255" placeholder="Plain, kind wording parents may see, e.g. Weekly study-planning sessions">
                    <span class="hint">Parents only see this when the case is visible to them.</span>
                </div>
            </div>
        </div>
        <div class="modal-foot">
            <button type="button" class="btn btn-soft" data-close>Cancel</button>
            <button type="submit" class="btn btn-primary">Open case</button>
        </div>
    </form>
</div>
<?php }

function schedule_session_modal(array $students, array $prefill = [], bool $autoOpen = false, string $action = 'guidance_desk.php'): void {
    $counselorOpts = ['' => 'Me'] + array_column(array_map(fn($c) => ['id' => $c['id'], 'n' => $c['first_name'] . ' ' . $c['last_name']], counselors()), 'n', 'id');
    $defaultStart = date('Y-m-d\TH:i', (int)(ceil((time() + 3600) / 1800) * 1800));
?>
<div id="modalSession" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="sessionTitle" aria-hidden="true" <?= $autoOpen ? 'data-autoopen' : '' ?>>
    <form class="modal-card" method="post" action="<?= e($action) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="schedule_session">
        <input type="hidden" name="case_id" value="<?= (int)($prefill['case_id'] ?? 0) ?>">
        <div class="modal-head">
            <div class="modal-icon ic-blue"><?= icon('bell') ?></div>
            <div><h3 id="sessionTitle">Schedule a counseling session</h3><p>Mental-health check-ins should be booked as Priority or Urgent.</p></div>
            <button type="button" class="modal-close" data-close aria-label="Close">✕</button>
        </div>
        <div class="modal-body">
            <div class="form-grid">
                <div class="field span-2">
                    <label for="ss_student">Student</label>
                    <?= student_picker('ss_student', $students, $prefill['student_id'] ?? '') ?>
                </div>
                <div class="field">
                    <label for="ss_type">Session type</label>
                    <select id="ss_type" name="session_type"><?= opts(SESSION_TYPES, $prefill['session_type'] ?? 'check_in') ?></select>
                </div>
                <div class="field">
                    <label for="ss_priority">Priority</label>
                    <select id="ss_priority" name="priority"><?= opts(SESSION_PRIORITY, $prefill['priority'] ?? 'routine') ?></select>
                </div>
                <div class="field">
                    <label for="ss_start">Date &amp; time</label>
                    <input id="ss_start" type="datetime-local" name="start" min="<?= date('Y-m-d\TH:i') ?>" value="<?= e($prefill['start'] ?? $defaultStart) ?>" required>
                </div>
                <div class="field">
                    <label for="ss_duration">Length</label>
                    <select id="ss_duration" name="duration"><?= opts(['15' => '15 minutes', '30' => '30 minutes', '45' => '45 minutes', '60' => '1 hour', '90' => '1½ hours'], $prefill['duration'] ?? '30') ?></select>
                </div>
                <div class="field">
                    <label for="ss_counselor">Counselor</label>
                    <select id="ss_counselor" name="counselor_id"><?= opts($counselorOpts, '') ?></select>
                </div>
                <div class="field">
                    <label for="ss_location">Location <span class="muted" style="font-weight: 500;">(optional)</span></label>
                    <input id="ss_location" name="location" maxlength="80" placeholder="e.g. Guidance Room 2" value="<?= e($prefill['location'] ?? '') ?>">
                </div>
            </div>
        </div>
        <div class="modal-foot">
            <button type="button" class="btn btn-soft" data-close>Cancel</button>
            <button type="submit" class="btn btn-primary">Schedule session</button>
        </div>
    </form>
</div>
<?php }

function session_status_buttons(int $sessionId, string $action): void { ?>
    <div class="row-actions">
        <?php foreach (['completed' => ['Done', 'btn-soft'], 'no_show' => ['No-show', 'btn-soft'], 'cancelled' => ['Cancel', 'btn-danger-soft']] as $st => [$lbl, $cls]): ?>
            <form method="post" action="<?= e($action) ?>" class="inline-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="session_status">
                <input type="hidden" name="session_id" value="<?= $sessionId ?>">
                <input type="hidden" name="status" value="<?= $st ?>">
                <button class="btn btn-sm <?= $cls ?>" type="submit"><?= e($lbl) ?></button>
            </form>
        <?php endforeach; ?>
    </div>
<?php }

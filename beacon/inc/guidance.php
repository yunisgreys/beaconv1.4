<?php

const CASE_TYPES   = ['academic' => 'Academic', 'behavioral' => 'Behavioral', 'mental_health' => 'Mental health',
                      'family' => 'Family', 'peer' => 'Peer relationships', 'career' => 'Career', 'other' => 'Other'];
const CASE_STATUS  = ['open' => 'Open', 'monitoring' => 'Monitoring', 'closed' => 'Closed'];
const RISK_LEVELS  = ['low' => 'Low', 'moderate' => 'Moderate', 'high' => 'High', 'critical' => 'Critical'];
const RISK_TONE    = ['low' => 'green', 'moderate' => 'amber', 'high' => 'red', 'critical' => 'red'];
const NOTE_TYPES   = ['progress' => 'Progress note', 'session' => 'Session note', 'remark' => 'Remark', 'referral' => 'Referral'];
const PLAN_TYPES   = ['counseling' => 'Counseling', 'bip' => 'Behavior plan (BIP)', 'referral' => 'Referral',
                      'parent_conference' => 'Parent conference', 'peer_support' => 'Peer support', 'monitoring' => 'Monitoring', 'other' => 'Other'];
const PLAN_STATUS  = ['planned' => 'Planned', 'active' => 'Active', 'completed' => 'Completed', 'discontinued' => 'Discontinued'];
const PROGRESS     = ['declining' => ['Declining', 'red'], 'no_change' => ['No change', 'gray'], 'improving' => ['Improving', 'blue'],
                      'on_track' => ['On track', 'green'], 'achieved' => ['Goal achieved', 'green']];
const SESSION_TYPES = ['check_in' => 'Check-in', 'individual' => 'Individual counseling', 'group' => 'Group session',
                       'crisis' => 'Crisis support', 'follow_up' => 'Follow-up', 'parent_conference' => 'Parent conference'];
const SESSION_PRIORITY = ['routine' => 'Routine', 'priority' => 'Priority', 'urgent' => 'Urgent'];

const WB_SIGNALS_30D  = 3;
const WB_ABSENCES_30D = 3;
const WB_RECENT_DAYS  = 7;

const WELLBEING_LEVELS = [
    'red' => [
        'label'  => 'Immediate intervention', 'short' => 'Red', 'tone' => 'red',
        'criteria' => [
            'An open case is rated High or Critical risk',
            'A High or Urgent mental/emotional report in the last 7 days that is not yet linked to a case',
        ],
        'response' => 'See the student today. Inform the adviser, follow the school\'s child-protection protocol, and involve parents where it is safe to do so.',
    ],
    'yellow' => [
        'label'  => 'Needs check-in', 'short' => 'Yellow', 'tone' => 'amber',
        'criteria' => [
            'An open case is rated Moderate risk',
            'A teacher report is waiting for Guidance review',
            '3 or more reports or clinic visits in the last 30 days',
            'A psychological/sensitive clinic visit in the last 30 days',
            '3 or more absences in the last 30 days',
        ],
        'response' => 'Schedule a check-in within the week and review again at the next case meeting.',
    ],
    'green' => [
        'label'  => 'Stable', 'short' => 'Green', 'tone' => 'green',
        'criteria' => [
            'None of the Red or Yellow signals apply',
            'Low-risk cases that are only being monitored stay Green',
        ],
        'response' => 'Routine monitoring. No action needed beyond planned sessions.',
    ],
];

function wellbeing_status(array $studentIds): array {
    $studentIds = array_values(array_unique(array_map('intval', $studentIds)));
    $out = [];
    foreach ($studentIds as $id) $out[$id] = ['level' => 'green', 'reasons' => []];
    if (!$studentIds) return $out;

    $in = implode(',', array_fill(0, count($studentIds), '?'));
    $raise = function (int $id, string $level, string $reason) use (&$out) {
        $rank = ['green' => 0, 'yellow' => 1, 'red' => 2];
        if ($rank[$level] > $rank[$out[$id]['level']]) $out[$id]['level'] = $level;
        $out[$id]['reasons'][] = $reason;
    };

    foreach (db_all("SELECT student_id, risk_level, title FROM guidance_cases WHERE status <> 'closed' AND student_id IN ($in)", $studentIds) as $c) {
        if (in_array($c['risk_level'], ['high', 'critical'], true)) $raise((int)$c['student_id'], 'red', RISK_LEVELS[$c['risk_level']] . '-risk open case');
        elseif ($c['risk_level'] === 'moderate')                  $raise((int)$c['student_id'], 'yellow', 'Moderate-risk open case');
    }

    $since = date('Y-m-d H:i:s', strtotime('-' . WB_RECENT_DAYS . ' days'));
    foreach (db_all("SELECT student_id FROM incident_reports
                      WHERE record_type = 'mental_emotional' AND severity IN ('high','urgent') AND status IN ('new','acknowledged')
                        AND observed_at >= ? AND student_id IN ($in)", array_merge([$since], $studentIds)) as $r) {
        $raise((int)$r['student_id'], 'red', 'Recent high/urgent mental-emotional report');
    }

    foreach (db_all("SELECT student_id, COUNT(*) AS n FROM incident_reports WHERE routed_to = 'guidance' AND status = 'new' AND student_id IN ($in) GROUP BY student_id", $studentIds) as $r) {
        $raise((int)$r['student_id'], 'yellow', $r['n'] . ((int)$r['n'] === 1 ? ' report awaiting review' : ' reports awaiting review'));
    }

    foreach (db_all("SELECT * FROM v_wellbeing_signals WHERE student_id IN ($in)", $studentIds) as $w) {
        $id = (int)$w['student_id'];
        $signals = (int)$w['incidents_30d'] + (int)$w['clinic_visits_30d'];
        if ($signals >= WB_SIGNALS_30D)            $raise($id, 'yellow', "$signals reports/clinic visits in 30 days");
        if ((int)$w['sensitive_visits_30d'] > 0)  $raise($id, 'yellow', 'Sensitive clinic visit in 30 days');
        if ((int)$w['absences_30d'] >= WB_ABSENCES_30D) $raise($id, 'yellow', $w['absences_30d'] . ' absences in 30 days');
    }
    return $out;
}

function counselors(): array {
    return db_all("SELECT u.id, u.first_name, u.last_name FROM users u JOIN roles r ON r.id = u.role_id
                    WHERE r.code = 'guidance' AND u.status = 'active' ORDER BY u.last_name");
}

function guidance_case(int $caseId): ?array {
    return db_one(
        "SELECT gc.*, s.first_name, s.last_name, s.student_no, s.id AS sid, sec.grade_level, sec.name AS section_name,
                c.first_name AS counselor_first, c.last_name AS counselor_last
           FROM guidance_cases gc
           JOIN students s       ON s.id = gc.student_id
           LEFT JOIN sections sec ON sec.id = s.section_id
           LEFT JOIN users c      ON c.id = gc.counselor_id
          WHERE gc.id = ?",
        [$caseId]
    );
}

function valid_date(?string $d): bool {
    $x = DateTime::createFromFormat('Y-m-d', (string)$d);
    return $x && $x->format('Y-m-d') === $d;
}

function require_guidance(array $user): void {
    if (!can('guidance.cases.manage', $user)) throw new RuntimeException('Only the Guidance Office can do this.');
}

function case_input_error(array $in): ?string {
    if (trim($in['title'] ?? '') === '')                        return 'Please give the case a title.';
    if (!isset(CASE_TYPES[$in['case_type'] ?? '']))            return 'Please choose a case type.';
    if (!isset(RISK_LEVELS[$in['risk_level'] ?? '']))          return 'Please choose a risk level.';
    if (!empty($in['counselor_id']) && !in_array((int)$in['counselor_id'], array_map('intval', array_column(counselors(), 'id')), true))
                                                              return 'Please choose a counselor from the Guidance Office.';
    return null;
}

function guidance_open_case(array $user, array $in): array {
    require_guidance($user);
    $studentId = (int)($in['student_id'] ?? 0);
    if (!db_value("SELECT 1 FROM students WHERE id = ? AND status = 'enrolled'", [$studentId])) return [null, 'Please choose a student.'];
    if ($err = case_input_error($in)) return [null, $err];

    $reportId = (int)($in['report_id'] ?? 0);
    if ($reportId && !db_value("SELECT 1 FROM incident_reports WHERE id = ? AND student_id = ? AND routed_to = 'guidance'", [$reportId, $studentId])) {
        return [null, 'That report belongs to a different student.'];
    }

    $isMh = ($in['case_type'] === 'mental_health' || !empty($in['is_mental_health'])) ? 1 : 0;
    $pdo = db();
    $pdo->beginTransaction();
    try {
        db_run('INSERT INTO guidance_cases (student_id, opened_by, counselor_id, case_type, is_mental_health, is_parent_accessible, risk_level, status, title, parent_summary, opened_at, updated_at)
                VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?)',
            [$studentId, $user['id'], ((int)($in['counselor_id'] ?? 0)) ?: $user['id'], $in['case_type'], $isMh, $in['risk_level'], 'open',
             mb_substr(trim($in['title']), 0, 150), trim($in['parent_summary'] ?? '') ?: null, now(), now()]);
        $caseId = (int)$pdo->lastInsertId();
        audit('create', 'guidance_cases', $caseId, $studentId, ['title', 'case_type', 'risk_level'], $user);

        if ($reportId) {
            db_run("UPDATE incident_reports SET status = 'linked', case_id = ?, acknowledged_by = COALESCE(acknowledged_by, ?), acknowledged_at = COALESCE(acknowledged_at, ?) WHERE id = ?",
                [$caseId, $user['id'], now(), $reportId]);
            audit('update', 'incident_reports', $reportId, $studentId, ['status', 'case_id'], $user);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return [$caseId, null];
}

function guidance_update_case(array $user, int $caseId, array $in): ?string {
    require_guidance($user);
    $case = db_one('SELECT * FROM guidance_cases WHERE id = ?', [$caseId]);
    if (!$case) return 'Case not found.';
    if ($err = case_input_error($in)) return $err;
    if (!isset(CASE_STATUS[$in['status'] ?? ''])) return 'Please choose a status.';

    $isMh = ($case['is_mental_health'] || $in['case_type'] === 'mental_health' || !empty($in['is_mental_health'])) ? 1 : 0;
    $new = [
        'title'          => mb_substr(trim($in['title']), 0, 150),
        'case_type'      => $in['case_type'],
        'is_mental_health' => $isMh,
        'risk_level'     => $in['risk_level'],
        'status'         => $in['status'],
        'counselor_id'   => ((int)($in['counselor_id'] ?? 0)) ?: null,
        'parent_summary' => trim($in['parent_summary'] ?? '') ?: null,
    ];
    $closedAt = $new['status'] === 'closed' ? ($case['closed_at'] ?: now()) : null;
    $changed = changed_fields($case, $new);
    if (!$changed && $closedAt === $case['closed_at']) return null;

    db_run('UPDATE guidance_cases SET title = ?, case_type = ?, is_mental_health = ?, risk_level = ?, status = ?, counselor_id = ?, parent_summary = ?, closed_at = ?, updated_at = ? WHERE id = ?',
        [$new['title'], $new['case_type'], $new['is_mental_health'], $new['risk_level'], $new['status'], $new['counselor_id'], $new['parent_summary'], $closedAt, now(), $caseId]);
    audit('update', 'guidance_cases', $caseId, (int)$case['student_id'], $changed, $user);
    return null;
}

function guidance_touch_case(int $caseId): void {
    db_run('UPDATE guidance_cases SET updated_at = ? WHERE id = ?', [now(), $caseId]);
}

function intervention_input_error(array $in): ?string {
    if (!isset(PLAN_TYPES[$in['plan_type'] ?? '']))     return 'Please choose a plan type.';
    if (trim($in['goals'] ?? '') === '')                return 'Please write the goal of this plan.';
    if (!valid_date($in['start_date'] ?? null))         return 'Please choose a start date.';
    if (($in['review_date'] ?? '') !== '' && (!valid_date($in['review_date']) || $in['review_date'] < $in['start_date']))
                                                        return 'The review date must be on or after the start date.';
    if (!isset(PLAN_STATUS[$in['status'] ?? '']))       return 'Please choose a status.';
    return null;
}

function guidance_add_intervention(array $user, int $caseId, array $in): ?string {
    require_guidance($user);
    $studentId = (int)db_value('SELECT student_id FROM guidance_cases WHERE id = ?', [$caseId]);
    if (!$studentId) return 'Case not found.';
    if ($err = intervention_input_error($in)) return $err;

    db_run('INSERT INTO interventions (case_id, plan_type, goals, strategies, start_date, review_date, status, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [$caseId, $in['plan_type'], trim($in['goals']), trim($in['strategies'] ?? '') ?: null, $in['start_date'], ($in['review_date'] ?? '') ?: null, $in['status'], $user['id'], now(), now()]);
    audit('create', 'interventions', db()->lastInsertId(), $studentId, ['plan_type', 'goals', 'status'], $user);
    guidance_touch_case($caseId);
    return null;
}

function guidance_update_intervention(array $user, int $interventionId, array $in): ?string {
    require_guidance($user);
    $iv = db_one('SELECT i.*, gc.student_id FROM interventions i JOIN guidance_cases gc ON gc.id = i.case_id WHERE i.id = ?', [$interventionId]);
    if (!$iv) return 'Plan not found.';
    if ($err = intervention_input_error($in)) return $err;

    $progress = $in['progress'] ?? '';
    $note     = trim($in['progress_note'] ?? '');
    if ($progress !== '' && !isset(PROGRESS[$progress])) return 'Please choose a progress rating.';
    if ($progress !== '' && $note === '')                return 'Please add a short note about the progress.';
    if ($progress === '' && $note !== '')                return 'Please choose a progress rating for your note.';

    $new = ['plan_type' => $in['plan_type'], 'goals' => trim($in['goals']), 'strategies' => trim($in['strategies'] ?? '') ?: null,
            'start_date' => $in['start_date'], 'review_date' => ($in['review_date'] ?? '') ?: null, 'status' => $in['status']];
    if ($progress === 'achieved' && $new['status'] !== 'completed') $new['status'] = 'completed';
    $changed = changed_fields($iv, $new);

    $pdo = db();
    $pdo->beginTransaction();
    try {
        if ($changed) {
            db_run('UPDATE interventions SET plan_type = ?, goals = ?, strategies = ?, start_date = ?, review_date = ?, status = ?, updated_at = ? WHERE id = ?',
                [$new['plan_type'], $new['goals'], $new['strategies'], $new['start_date'], $new['review_date'], $new['status'], now(), $interventionId]);
            audit('update', 'interventions', $interventionId, (int)$iv['student_id'], $changed, $user);
        }
        if ($progress !== '') {
            db_run('INSERT INTO intervention_updates (intervention_id, progress, note, created_by, created_at) VALUES (?, ?, ?, ?, ?)',
                [$interventionId, $progress, $note, $user['id'], now()]);
            audit('create', 'intervention_updates', $pdo->lastInsertId(), (int)$iv['student_id'], ['progress', 'note'], $user);
        }
        guidance_touch_case((int)$iv['case_id']);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return null;
}

function guidance_report_action(array $user, int $reportId, string $action, int $caseId = 0): ?string {
    if (!can('guidance.incidents.review', $user)) return 'Only the Guidance Office can review reports.';
    $r = db_one("SELECT id, student_id, status FROM incident_reports WHERE id = ? AND routed_to = 'guidance'", [$reportId]);
    if (!$r) return 'Report not found.';

    if ($action === 'acknowledge') {
        db_run("UPDATE incident_reports SET status = 'acknowledged', acknowledged_by = ?, acknowledged_at = ? WHERE id = ?", [$user['id'], now(), $reportId]);
    } elseif ($action === 'close') {
        db_run("UPDATE incident_reports SET status = 'closed', acknowledged_by = COALESCE(acknowledged_by, ?), acknowledged_at = COALESCE(acknowledged_at, ?) WHERE id = ?", [$user['id'], now(), $reportId]);
    } elseif ($action === 'link') {
        if (!db_value("SELECT 1 FROM guidance_cases WHERE id = ? AND student_id = ? AND status <> 'closed'", [$caseId, $r['student_id']])) {
            return 'Choose an open case for the same student.';
        }
        db_run("UPDATE incident_reports SET status = 'linked', case_id = ?, acknowledged_by = COALESCE(acknowledged_by, ?), acknowledged_at = COALESCE(acknowledged_at, ?) WHERE id = ?",
            [$caseId, $user['id'], now(), $reportId]);
        guidance_touch_case($caseId);
    } else {
        return 'Unknown action.';
    }
    audit('update', 'incident_reports', $reportId, (int)$r['student_id'], ['status'], $user);
    return null;
}

function guidance_schedule_session(array $user, array $in): ?string {
    if (!can('guidance.sessions.manage', $user)) return 'Only the Guidance Office can schedule sessions.';
    $studentId = (int)($in['student_id'] ?? 0);
    if (!db_value("SELECT 1 FROM students WHERE id = ? AND status = 'enrolled'", [$studentId])) return 'Please choose a student.';
    if (!isset(SESSION_TYPES[$in['session_type'] ?? '']))   return 'Please choose a session type.';
    if (!isset(SESSION_PRIORITY[$in['priority'] ?? '']))   return 'Please choose a priority.';

    $start = DateTime::createFromFormat('Y-m-d\TH:i', (string)($in['start'] ?? ''));
    $mins  = (int)($in['duration'] ?? 0);
    if (!$start)                         return 'Please choose a date and time.';
    if ($mins < 10 || $mins > 180)       return 'Sessions should be between 10 and 180 minutes.';
    if ($start < new DateTime('-5 minutes')) return 'Please choose a time in the future.';
    $end = (clone $start)->modify("+$mins minutes");

    $counselorId = ((int)($in['counselor_id'] ?? 0)) ?: (int)$user['id'];
    if (!in_array($counselorId, array_map('intval', array_column(counselors(), 'id')), true)) return 'Please choose a counselor.';

    $caseId = (int)($in['case_id'] ?? 0) ?: null;
    if ($caseId && !db_value('SELECT 1 FROM guidance_cases WHERE id = ? AND student_id = ?', [$caseId, $studentId])) return 'That case belongs to a different student.';

    $clash = db_one("SELECT s.first_name, s.last_name, cs.scheduled_start FROM counseling_sessions cs JOIN students s ON s.id = cs.student_id
                      WHERE cs.counselor_id = ? AND cs.status = 'scheduled' AND cs.scheduled_start < ? AND cs.scheduled_end > ?",
        [$counselorId, $end->format('Y-m-d H:i:s'), $start->format('Y-m-d H:i:s')]);
    if ($clash) {
        return 'That counselor already has a session with ' . $clash['first_name'] . ' ' . $clash['last_name'] . ' at ' . date('g:i A', strtotime($clash['scheduled_start'])) . '. Pick another time.';
    }

    db_run('INSERT INTO counseling_sessions (student_id, case_id, counselor_id, session_type, priority, scheduled_start, scheduled_end, location, status, created_by, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [$studentId, $caseId, $counselorId, $in['session_type'], $in['priority'], $start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s'),
         trim($in['location'] ?? '') ?: null, 'scheduled', $user['id'], now()]);
    audit('create', 'counseling_sessions', db()->lastInsertId(), $studentId, ['scheduled_start', 'session_type'], $user);
    if ($caseId) guidance_touch_case($caseId);
    return null;
}

function guidance_set_session_status(array $user, int $sessionId, string $status): ?string {
    if (!can('guidance.sessions.manage', $user)) return 'Only the Guidance Office can update sessions.';
    if (!in_array($status, ['completed', 'cancelled', 'no_show'], true)) return 'Unknown status.';
    $s = db_one('SELECT student_id, case_id FROM counseling_sessions WHERE id = ?', [$sessionId]);
    if (!$s) return 'Session not found.';
    db_run('UPDATE counseling_sessions SET status = ? WHERE id = ?', [$status, $sessionId]);
    audit('update', 'counseling_sessions', $sessionId, (int)$s['student_id'], ['status'], $user);
    if ($s['case_id']) guidance_touch_case((int)$s['case_id']);
    return null;
}

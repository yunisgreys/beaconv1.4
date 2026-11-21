<?php

const PARENT_CASE_VISIBLE_SQL = '(gc.is_mental_health = 0 OR gc.is_parent_accessible = 1)';
const CONSENT_METHODS = ['verbal_in_session', 'written_form', 'student_portal'];

function parent_guidance_view(array $parent, int $studentId): array {
    if ($parent['role'] !== 'parent' || !can_access_student($parent, $studentId)) {
        return ['cases' => [], 'locked_count' => 0];
    }

    $cases = db_all(
        'SELECT gc.id, gc.case_type, gc.status, gc.title, gc.parent_summary, gc.opened_at, gc.closed_at
           FROM guidance_cases gc
          WHERE gc.student_id = ? AND ' . PARENT_CASE_VISIBLE_SQL . '
          ORDER BY gc.opened_at DESC',
        [$studentId]
    );
    foreach ($cases as &$c) {
        $c['notes'] = db_all(
            'SELECT note_type, body, created_at FROM case_notes
              WHERE case_id = ? AND is_confidential = 0 ORDER BY created_at DESC',
            [$c['id']]
        );
        $c['interventions'] = db_all(
            'SELECT plan_type, goals, start_date, review_date, status FROM interventions
              WHERE case_id = ? ORDER BY start_date DESC',
            [$c['id']]
        );
    }
    unset($c);

    $locked = (int)db_value(
        "SELECT COUNT(*) FROM guidance_cases gc
          WHERE gc.student_id = ? AND gc.status <> 'closed' AND NOT " . PARENT_CASE_VISIBLE_SQL,
        [$studentId]
    );

    audit('view', 'guidance_cases', null, $studentId, [], $parent);
    return ['cases' => $cases, 'locked_count' => $locked];
}

function parent_clinic_view(array $parent, int $studentId): array {
    if ($parent['role'] !== 'parent' || !can_access_student($parent, $studentId)) return [];

    $visits = db_all(
        'SELECT id, arrived_at, departed_at, complaint, assessment, disposition, case_status, medication_notes, temperature_c, parent_notified, is_sensitive
           FROM clinic_visits WHERE student_id = ? ORDER BY arrived_at DESC',
        [$studentId]
    );
    foreach ($visits as &$v) {
        if ($v['is_sensitive']) {
            $v['complaint'] = 'Clinic visit';
            $v['assessment'] = $v['disposition'] = $v['case_status'] = $v['medication_notes'] = $v['temperature_c'] = null;
            $v['medications'] = [];
        } else {
            $v['medications'] = db_all(
                'SELECT medication_name, dose, administered_at FROM medications_administered WHERE visit_id = ?',
                [$v['id']]
            );
        }
        $v['sensitive'] = (bool)$v['is_sensitive'];
        unset($v['is_sensitive']);
    }
    unset($v);
    return $visits;
}

function set_parent_access(array $counselor, int $caseId, bool $grant, string $method, ?string $notes = null): ?string {
    if (!can('guidance.consent.record', $counselor)) return 'Only the Guidance Office can record student consent.';
    if (!in_array($method, CONSENT_METHODS, true))   return 'Please choose how the student gave consent.';

    $case = db_one('SELECT id, student_id, is_mental_health, is_parent_accessible FROM guidance_cases WHERE id = ?', [$caseId]);
    if (!$case)                         return 'Case not found.';
    if (!$case['is_mental_health'])     return 'This case is not marked as mental-health, so parents can already see it.';
    if ((bool)$case['is_parent_accessible'] === $grant) return null;

    $pdo = db();
    $pdo->beginTransaction();
    try {
        db_run('UPDATE guidance_cases SET is_parent_accessible = ?, updated_at = ? WHERE id = ?', [$grant ? 1 : 0, now(), $caseId]);
        db_run('INSERT INTO consent_events (case_id, student_id, granted, consent_method, recorded_by, notes, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$caseId, $case['student_id'], $grant ? 1 : 0, $method, $counselor['id'], $notes, now()]);
        audit($grant ? 'consent_grant' : 'consent_withdraw', 'guidance_cases', $caseId, (int)$case['student_id'],
            ['is_parent_accessible'], $counselor);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return null;
}

function add_case_note(array $author, int $caseId, string $body, string $type = 'progress', bool $confidential = true): int {
    if (!can('guidance.cases.manage', $author)) throw new RuntimeException('Not allowed to write guidance notes.');
    $studentId = (int)db_value('SELECT student_id FROM guidance_cases WHERE id = ?', [$caseId]);
    if (!$studentId) throw new RuntimeException('Case not found.');

    db_run('INSERT INTO case_notes (case_id, author_id, note_type, is_confidential, body, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
        [$caseId, $author['id'], $type, $confidential ? 1 : 0, $confidential ? encrypt_text($body) : $body, now(), now()]);
    $id = (int)db()->lastInsertId();
    audit('create', 'case_notes', $id, $studentId, ['body', 'is_confidential'], $author);
    return $id;
}

function guidance_case_notes(array $user, int $caseId): array {
    if (!can('guidance.notes.confidential', $user)) throw new RuntimeException('Not allowed to read guidance notes.');
    $studentId = (int)db_value('SELECT student_id FROM guidance_cases WHERE id = ?', [$caseId]);
    $notes = db_all('SELECT id, author_id, note_type, is_confidential, body, created_at FROM case_notes WHERE case_id = ? ORDER BY created_at DESC', [$caseId]);
    foreach ($notes as &$n) {
        if ($n['is_confidential']) $n['body'] = decrypt_text($n['body']);
    }
    unset($n);
    audit('view', 'case_notes', $caseId, $studentId ?: null, [], $user);
    return $notes;
}

function teacher_guidance_summary(array $teacher, int $studentId): array {
    if (!can('guidance.summary.read', $teacher) || !can_access_student($teacher, $studentId)) return [];

    $cases = db_all(
        "SELECT gc.id, gc.case_type, gc.is_mental_health, gc.status, gc.title, gc.opened_at,
                u.first_name AS counselor_first, u.last_name AS counselor_last
           FROM guidance_cases gc LEFT JOIN users u ON u.id = gc.counselor_id
          WHERE gc.student_id = ? AND gc.status <> 'closed' ORDER BY gc.opened_at DESC",
        [$studentId]
    );
    foreach ($cases as &$c) {
        if ($c['is_mental_health']) {
            $c['title'] = 'Well-being support';
            $c['case_type'] = 'well-being';
        }
        $c['interventions'] = db_all(
            "SELECT plan_type, goals, strategies, start_date, review_date, status FROM interventions
              WHERE case_id = ? AND status IN ('planned','active') ORDER BY start_date DESC",
            [$c['id']]
        );
        $c['notes'] = db_all(
            'SELECT note_type, body, created_at FROM case_notes WHERE case_id = ? AND is_confidential = 0 ORDER BY created_at DESC',
            [$c['id']]
        );
    }
    unset($c);
    if ($cases) audit('view', 'guidance_summary', null, $studentId, [], $teacher);
    return $cases;
}

<?php

const STUDENT_SELECT = "
    SELECT s.id, s.student_no, s.lrn, s.first_name, s.middle_name, s.last_name, s.birthdate, s.sex,
           s.address, s.guardian_name, s.guardian_relation, s.guardian_contact, s.section_id,
           sec.grade_level, sec.name AS section_name,
           adv.first_name AS adviser_first, adv.last_name AS adviser_last
      FROM students s
      LEFT JOIN sections sec ON sec.id = s.section_id
      LEFT JOIN users adv    ON adv.id = sec.adviser_id";

function student_name(array $s): string {
    return $s['first_name'] . ' ' . $s['last_name'];
}

function student_initials(array $s): string {
    return strtoupper(mb_substr($s['first_name'], 0, 1) . mb_substr($s['last_name'], 0, 1));
}

/**
 * v1.3: a teacher works with their ADVISORY class only (the section where
 * sections.adviser_id is them). Guidance and Clinic see every enrolled student.
 */
function visible_students(array $user): array {
    if ($user['role'] === 'teacher') {
        $section = advisory_section((int)$user['id']);
        if (!$section) return [];
        return db_all(STUDENT_SELECT . " WHERE s.status = 'enrolled' AND s.section_id = ? ORDER BY s.last_name, s.first_name", [(int)$section['id']]);
    }
    if (in_array($user['role'], ['guidance', 'clinic'], true)) {
        return db_all(STUDENT_SELECT . " WHERE s.status = 'enrolled' ORDER BY s.last_name, s.first_name");
    }
    return [];
}

function find_student(array $user, int $id): ?array {
    if (!can_access_student($user, $id)) return null;
    return db_one(STUDENT_SELECT . ' WHERE s.id = ?', [$id]);
}

function advisory_section(int $teacherId): ?array {
    static $cache = [];
    if (!array_key_exists($teacherId, $cache)) {
        $cache[$teacherId] = db_one('SELECT id, grade_level, name, school_year FROM sections WHERE adviser_id = ? ORDER BY school_year DESC, id LIMIT 1', [$teacherId]);
    }
    return $cache[$teacherId];
}

/** True when this user is the class adviser of the student's section. */
function is_adviser_of(array $user, int $studentId): bool {
    if ($user['role'] !== 'teacher') return false;
    $section = advisory_section((int)$user['id']);
    if (!$section) return false;
    return (int)db_value("SELECT COUNT(*) FROM students WHERE id = ? AND section_id = ? AND status = 'enrolled'", [$studentId, (int)$section['id']]) === 1;
}

const ATTENDANCE_STATUSES = ['present' => ['Present', 'green'], 'late' => ['Late', 'amber'], 'absent' => ['Absent', 'red'], 'excused' => ['Excused', 'blue']];

/**
 * v1.3: record or correct one day of attendance for a student in the adviser's class.
 * One row per student per day: saving an existing date updates it.
 * Returns null on success, or a message to show.
 */
function save_attendance(array $user, int $studentId, string $date, string $status, string $remarks): ?string {
    if (!can('attendance.record', $user))      return 'Only class advisers can record attendance.';
    if (!is_adviser_of($user, $studentId))      return 'You can only record attendance for students in your advisory class.';
    $d = DateTime::createFromFormat('Y-m-d', $date);
    if (!$d || $d->format('Y-m-d') !== $date)  return 'Please choose a valid date.';
    if ($date > date('Y-m-d'))                  return 'Attendance cannot be recorded for a future date.';
    if ($date < date('Y-m-d', strtotime('-1 year'))) return 'That date is too far in the past.';
    if (!isset(ATTENDANCE_STATUSES[$status]))   return 'Please choose Present, Late, Absent or Excused.';
    $remarks = trim($remarks);
    if (mb_strlen($remarks) > 255)              return 'Keep the remarks under 255 characters.';

    $sectionId = (int)advisory_section((int)$user['id'])['id'];
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $existing = db_one('SELECT id, status, remarks FROM attendance WHERE student_id = ? AND att_date = ?', [$studentId, $date]);
        if ($existing) {
            db_run('UPDATE attendance SET status = ?, remarks = ?, section_id = ?, updated_by = ?, updated_at = ? WHERE id = ?',
                [$status, $remarks !== '' ? $remarks : null, $sectionId, $user['id'], now(), $existing['id']]);
            $changed = [];
            if ($existing['status'] !== $status) $changed[] = 'status';
            if ((string)$existing['remarks'] !== $remarks) $changed[] = 'remarks';
            audit('update', 'attendance', $existing['id'], $studentId, $changed, $user);
        } else {
            db_run('INSERT INTO attendance (student_id, section_id, att_date, status, remarks, recorded_by, recorded_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$studentId, $sectionId, $date, $status, $remarks !== '' ? $remarks : null, $user['id'], now(), now()]);
            audit('create', 'attendance', db()->lastInsertId(), $studentId, ['status', 'remarks'], $user);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return null;
}

function attendance_today(array $studentIds): array {
    if (!$studentIds) return [];
    $in = implode(',', array_fill(0, count($studentIds), '?'));
    $rows = db_all("SELECT student_id, status FROM attendance WHERE att_date = ? AND student_id IN ($in)",
        array_merge([date('Y-m-d')], $studentIds));
    return array_column($rows, 'status', 'student_id');
}

function welfare_indicators(array $studentIds): array {
    if (!$studentIds) return [];
    $in = implode(',', array_fill(0, count($studentIds), '?'));
    $out = array_fill_keys($studentIds, []);

    foreach (db_all("SELECT student_id, conditions, allergies FROM medical_profiles WHERE student_id IN ($in)", $studentIds) as $m) {
        if (trim((string)$m['conditions']) !== '') {
            $label = trim(preg_split('/[(,;]/', $m['conditions'])[0]);
            $out[$m['student_id']][] = ['label' => $label, 'tone' => 'blue', 'hint' => $m['conditions']];
        }
        if (trim((string)$m['allergies']) !== '') {
            $out[$m['student_id']][] = ['label' => 'Allergy', 'tone' => 'blue', 'hint' => 'Allergy: ' . $m['allergies']];
        }
    }

    foreach (db_all("SELECT DISTINCT student_id FROM guidance_cases WHERE status <> 'closed' AND student_id IN ($in)", $studentIds) as $g) {
        $out[$g['student_id']][] = ['label' => 'Guidance support', 'tone' => 'purple', 'hint' => 'Has an active guidance case'];
    }

    foreach (db_all("SELECT * FROM v_wellbeing_signals WHERE student_id IN ($in)", $studentIds) as $w) {
        $signals = (int)$w['incidents_30d'] + (int)$w['clinic_visits_30d'];
        if ($signals >= 3 || (int)$w['sensitive_visits_30d'] >= 1) {
            $out[$w['student_id']][] = ['label' => 'Check in', 'tone' => 'amber',
                'hint' => "{$w['incidents_30d']} reports and {$w['clinic_visits_30d']} clinic visits in the last 30 days"];
        }
        if ((int)$w['absences_30d'] >= 3) {
            $out[$w['student_id']][] = ['label' => 'Frequent absences', 'tone' => 'amber', 'hint' => "{$w['absences_30d']} absences in the last 30 days"];
        }
    }
    return $out;
}

function attendance_summary(int $studentId, int $days = 30): array {
    $since = date('Y-m-d', strtotime("-$days days"));
    $rows = db_all('SELECT a.att_date, a.status, a.remarks, a.recorded_at, a.updated_at,
                           COALESCE(ub.first_name, rb.first_name) AS by_first, COALESCE(ub.last_name, rb.last_name) AS by_last
                      FROM attendance a
                      JOIN users rb ON rb.id = a.recorded_by
                      LEFT JOIN users ub ON ub.id = a.updated_by
                     WHERE a.student_id = ? AND a.att_date >= ? ORDER BY a.att_date DESC', [$studentId, $since]);
    $counts = ['present' => 0, 'late' => 0, 'absent' => 0, 'excused' => 0];
    foreach ($rows as $r) $counts[$r['status']]++;
    $total = array_sum($counts);
    return [
        'rows'   => $rows,
        'counts' => $counts,
        'rate'   => $total ? round(($counts['present'] + $counts['late']) / $total * 100) : null,
    ];
}

function medical_profile(int $studentId): ?array {
    return db_one('SELECT blood_type, allergies, conditions, regular_medications, emergency_notes FROM medical_profiles WHERE student_id = ?', [$studentId]);
}

function has_open_guidance_case(int $studentId): bool {
    return (bool)db_value("SELECT 1 FROM guidance_cases WHERE student_id = ? AND status <> 'closed'", [$studentId]);
}

function label(string $value): string {
    static $map = [
        'mental_health' => 'Mental health', 'mental_emotional' => 'Mental / emotional state', 'peer_conflict' => 'Peer conflict',
        'conduct' => 'Behavioral conduct', 'physical' => 'Physical state', 'bip' => 'Behavior plan (BIP)',
        'parent_conference' => 'Parent conference', 'peer_support' => 'Peer support', 'check_in' => 'Check-in',
        'returned_to_class' => 'Returned to class', 'referred_guidance' => 'Referred to guidance',
        'referred_hospital' => 'Referred to hospital', 'sent_home' => 'Sent home', 'new' => 'Awaiting review',
    ];
    return $map[$value] ?? ucfirst(str_replace('_', ' ', $value));
}

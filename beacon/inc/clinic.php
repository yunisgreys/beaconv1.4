<?php

const CLINIC_STATUS = [
    'under_observation'  => ['Under observation', 'amber'],
    'guardian_contacted' => ['Guardian contacted', 'blue'],
    'sent_home'          => ['Sent home', 'gray'],
    'cleared_to_class'   => ['Cleared to return to class', 'green'],
    'referred_guidance'  => ['Referred to guidance', 'purple'],
    'referred_hospital'  => ['Referred to hospital', 'red'],
];
const CLINIC_LEAVING = ['sent_home', 'cleared_to_class', 'referred_hospital'];
const CLINIC_DISPOSITION = ['under_observation' => 'observation', 'guardian_contacted' => 'observation', 'sent_home' => 'sent_home',
                            'cleared_to_class' => 'returned_to_class', 'referred_guidance' => 'referred_guidance', 'referred_hospital' => 'referred_hospital'];

const INVENTORY_CATEGORIES = ['medicine' => 'Medicine', 'supply' => 'Supply', 'equipment' => 'Equipment'];
const STOCK_STATUS = ['in_stock' => ['In stock', 'green'], 'low_stock' => ['Low stock', 'amber'], 'out_of_stock' => ['Out of stock', 'red']];
const STOCK_REASONS = ['restock' => 'Restock / delivery', 'administered' => 'Used for a student', 'expired' => 'Expired', 'damaged' => 'Damaged / lost', 'adjustment' => 'Count correction'];

const PASS_REASONS = ['medical' => 'Feeling unwell', 'injury' => 'Injury', 'psychological' => 'Emotional / needs a calm space', 'other' => 'Other'];
const PASS_URGENCY = ['routine' => 'Routine', 'urgent' => 'Urgent', 'emergency' => 'Emergency'];
const PASS_VALID_MINUTES = 60;

/**
 * Issues a digital clinic pass (v1.3: done from Log conduct when a teacher
 * records a physical state). Returns ['code' => ..., 'id' => ...] or ['error' => ...].
 */
function issue_clinic_pass(array $user, int $studentId, string $reasonType, string $reason, string $urgency, ?int $reportId = null): array {
    if (!can('clinic_pass.issue', $user))      return ['error' => 'Only teachers can issue clinic passes.'];
    if (!is_adviser_of($user, $studentId))      return ['error' => 'Please choose a student from your advisory class.'];
    if (!isset(PASS_REASONS[$reasonType]))      return ['error' => 'Please choose why the student is going to the clinic.'];
    if (!isset(PASS_URGENCY[$urgency]))         return ['error' => 'Please choose how urgent this is.'];
    $reason = trim($reason);
    if ($reason === '')                         return ['error' => 'Please describe what the student needs.'];

    do {
        $code = strtoupper(substr(str_replace(['0', 'O', '1', 'I'], '', bin2hex(random_bytes(6))), 0, 6));
    } while (strlen($code) < 6 || db_value('SELECT 1 FROM clinic_passes WHERE pass_code = ?', [$code]));

    db_run('INSERT INTO clinic_passes (pass_code, student_id, issued_by, reason_type, reason, urgency, status, report_id, issued_at, expires_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [$code, $studentId, $user['id'], $reasonType, mb_substr($reason, 0, 255), $urgency, 'issued', $reportId,
         now(), date('Y-m-d H:i:s', time() + PASS_VALID_MINUTES * 60)]);
    $id = (int)db()->lastInsertId();
    audit('create', 'clinic_passes', $id, $studentId, ['reason_type', 'reason', 'urgency'], $user);
    return ['code' => $code, 'id' => $id];
}

/**
 * Clinic Pass Tracker for a teacher: passes they issued in the last $days days,
 * plus any still open, with what happened at the clinic (time in/out, outcome).
 * Clinical details are not included; sensitive visits only show that the clinic saw the student.
 */
function teacher_pass_tracker(array $teacher, int $days = 7): array {
    clinic_expire_passes();
    $rows = db_all(
        "SELECT p.id, p.pass_code, p.reason_type, p.urgency, p.status, p.issued_at, p.expires_at,
                s.id AS student_id, s.first_name, s.last_name,
                v.arrived_at, v.departed_at, v.case_status, v.is_sensitive
           FROM clinic_passes p
           JOIN students s ON s.id = p.student_id
           LEFT JOIN clinic_visits v ON v.pass_id = p.id
          WHERE p.issued_by = ? AND (p.issued_at >= ? OR p.status IN ('issued','arrived'))
          ORDER BY p.issued_at DESC LIMIT 30",
        [$teacher['id'], date('Y-m-d 00:00:00', strtotime('-' . max(0, $days - 1) . ' days'))]
    );
    foreach ($rows as &$r) {
        if ($r['status'] === 'issued')          { $r['stage'] = ['On the way', 'blue']; }
        elseif ($r['status'] === 'expired')     { $r['stage'] = ['Expired, not used', 'gray']; }
        elseif ($r['status'] === 'cancelled')   { $r['stage'] = ['Cancelled', 'gray']; }
        elseif ($r['departed_at'])              { $r['stage'] = ['Left the clinic', 'green']; }
        else                                    { $r['stage'] = ['At the clinic', 'amber']; }
        $r['outcome'] = null;
        if ($r['case_status']) {
            $r['outcome'] = (int)$r['is_sensitive'] === 1 ? ['Seen by the clinic', 'gray'] : (CLINIC_STATUS[$r['case_status']] ?? null);
        }
    }
    unset($r);
    return $rows;
}

/**
 * v1.3: the family-feed notice for one clinic visit. Created at check-in and
 * rewritten whenever the visit changes (time out, status, medication), so
 * parents always see the latest. Sensitive visits only say that a visit happened.
 * Returns [title, body].
 */
function clinic_visit_notice_text(array $v, string $firstName): array {
    $in  = date('g:i A', strtotime($v['arrived_at']));
    $out = $v['departed_at'] ? date('g:i A', strtotime($v['departed_at'])) : null;
    if ((int)$v['is_sensitive'] === 1) {
        return [
            "$firstName visited the school clinic",
            "$firstName visited the school clinic today (time in $in" . ($out ? ", time out $out" : '') . '). '
            . 'Details are kept private. You can contact the clinic or the Guidance Office if you have questions.',
        ];
    }
    $lines = [
        'Time in: ' . $in,
        'Time out: ' . ($out ?? 'Still in the clinic'),
        'Reason: ' . $v['complaint'],
        'Status: ' . (CLINIC_STATUS[$v['case_status']][0] ?? ucfirst(str_replace('_', ' ', (string)$v['case_status']))),
        'Medication administered: ' . ($v['medication_notes'] ? str_replace("\n", '; ', trim($v['medication_notes'])) : 'None'),
    ];
    return [$out ? "$firstName visited the school clinic" : "$firstName checked in at the school clinic", implode("\n", $lines)];
}

/** Creates or refreshes the automatic family notice for a clinic visit. */
function clinic_sync_parent_notice(array $user, int $visitId): void {
    $v = db_one('SELECT v.*, s.first_name FROM clinic_visits v JOIN students s ON s.id = v.student_id WHERE v.id = ?', [$visitId]);
    if (!$v) return;
    [$title, $body] = clinic_visit_notice_text($v, $v['first_name']);
    $id = db_value('SELECT id FROM notices WHERE clinic_visit_id = ?', [$visitId]);
    if ($id) {
        db_run('UPDATE notices SET title = ?, body = ? WHERE id = ?', [$title, $body, $id]);
    } else {
        db_run("INSERT INTO notices (title, body, tag, audience, student_id, clinic_visit_id, requires_ack, posted_by, published_at)
                VALUES (?, ?, 'clinic', 'student', ?, ?, 0, ?, ?)",
            [$title, $body, $v['student_id'], $visitId, $user['id'], now()]);
        audit('create', 'notices', db()->lastInsertId(), (int)$v['student_id'], ['title', 'body', 'audience'], $user);
    }
}

const BLOOD_TYPES = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];

/**
 * v1.3: Clinic staff update a student's Health & Welfare record.
 * Returns null on success, or a message.
 */
function save_medical_profile(array $user, int $studentId, array $in): ?string {
    if (!can('clinic.medical.manage', $user)) return 'Only clinic staff can update health information.';
    if (!db_value("SELECT 1 FROM students WHERE id = ?", [$studentId])) return 'Student not found.';
    $new = [
        'blood_type'          => strtoupper(trim((string)($in['blood_type'] ?? ''))),
        'allergies'           => trim((string)($in['allergies'] ?? '')),
        'conditions'          => trim((string)($in['conditions'] ?? '')),
        'regular_medications' => trim((string)($in['regular_medications'] ?? '')),
        'emergency_notes'     => trim((string)($in['emergency_notes'] ?? '')),
    ];
    if ($new['blood_type'] !== '' && !in_array($new['blood_type'], BLOOD_TYPES, true)) return 'Choose a blood type from the list, or leave it blank.';
    foreach (['allergies' => 'Allergies', 'conditions' => 'Medical conditions', 'regular_medications' => 'Regular medication', 'emergency_notes' => 'Emergency notes'] as $k => $label) {
        if (mb_strlen($new[$k]) > 1000) return "$label is too long (1,000 characters at most).";
    }
    $new = array_map(fn($v) => $v === '' ? null : $v, $new);

    $old = db_one('SELECT blood_type, allergies, conditions, regular_medications, emergency_notes FROM medical_profiles WHERE student_id = ?', [$studentId]);
    if ($old) {
        $changed = changed_fields($old, $new);
        if (!$changed) return null;
        db_run('UPDATE medical_profiles SET blood_type = ?, allergies = ?, conditions = ?, regular_medications = ?, emergency_notes = ?, updated_by = ?, updated_at = ? WHERE student_id = ?',
            [$new['blood_type'], $new['allergies'], $new['conditions'], $new['regular_medications'], $new['emergency_notes'], $user['id'], now(), $studentId]);
        audit('update', 'medical_profiles', $studentId, $studentId, $changed, $user);
    } else {
        db_run('INSERT INTO medical_profiles (student_id, blood_type, allergies, conditions, regular_medications, emergency_notes, updated_by, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$studentId, $new['blood_type'], $new['allergies'], $new['conditions'], $new['regular_medications'], $new['emergency_notes'], $user['id'], now()]);
        audit('create', 'medical_profiles', $studentId, $studentId, array_keys(array_filter($new, fn($v) => $v !== null)), $user);
    }
    return null;
}

/** v1.3 Patient visit history: completed visits, newest first. */
function clinic_visit_history(int $limit = 25, int $offset = 0): array {
    return db_all(
        "SELECT v.id, v.arrived_at, v.departed_at, v.medication_notes, v.is_sensitive, s.first_name, s.last_name, s.student_no
           FROM clinic_visits v JOIN students s ON s.id = v.student_id
          WHERE v.departed_at IS NOT NULL
          ORDER BY v.arrived_at DESC, v.id DESC LIMIT " . max(1, $limit) . " OFFSET " . max(0, $offset)
    );
}

function require_clinic(array $user, string $perm = 'clinic.visits.manage'): void {
    if (!can($perm, $user)) throw new RuntimeException('Only clinic staff can do this.');
}

function parse_local_dt(?string $v): ?DateTime {
    $d = DateTime::createFromFormat('Y-m-d\TH:i', (string)$v);
    return $d ?: null;
}

function clinic_expire_passes(): void {
    db_run("UPDATE clinic_passes SET status = 'expired' WHERE status = 'issued' AND expires_at < ?", [now()]);
}

function clinic_pending_passes(): array {
    return db_all(
        "SELECT p.*, s.first_name, s.last_name, s.student_no, sec.grade_level, sec.name AS section_name, u.first_name AS t_first, u.last_name AS t_last
           FROM clinic_passes p
           JOIN students s ON s.id = p.student_id
           LEFT JOIN sections sec ON sec.id = s.section_id
           JOIN users u ON u.id = p.issued_by
          WHERE p.status = 'issued' ORDER BY CASE p.urgency WHEN 'emergency' THEN 0 WHEN 'urgent' THEN 1 ELSE 2 END, p.issued_at"
    );
}

function clinic_use_stock(array $user, int $visitId, int $itemId, int $qty, DateTime $when): ?string {
    if ($qty < 1) return 'Quantity used must be at least 1.';
    $item = db_one('SELECT id, name, unit, quantity FROM inventory_items WHERE id = ?', [$itemId]);
    if (!$item) return 'Choose an item from the inventory.';
    if ($qty > (int)$item['quantity']) return "Only {$item['quantity']} {$item['unit']} of {$item['name']} left in stock.";

    db_run('INSERT INTO medications_administered (visit_id, item_id, medication_name, dose, route, quantity_used, administered_at, administered_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        [$visitId, $itemId, $item['name'], $qty . ' ' . $item['unit'], 'oral', $qty, $when->format('Y-m-d H:i:s'), $user['id']]);
    $medId = (int)db()->lastInsertId();
    db_run('UPDATE inventory_items SET quantity = quantity - ?, updated_at = ? WHERE id = ?', [$qty, now(), $itemId]);
    db_run('INSERT INTO inventory_movements (item_id, change_qty, reason, medication_id, performed_by, created_at) VALUES (?, ?, ?, ?, ?, ?)',
        [$itemId, -$qty, 'administered', $medId, $user['id'], now()]);
    return null;
}

function clinic_refer_to_guidance(array $user, array $visit): void {
    if (!empty($visit['referral_report_id'])) return;
    $psych = (bool)db_value("SELECT 1 FROM clinic_passes WHERE id = ? AND reason_type = 'psychological'", [(int)($visit['pass_id'] ?? 0)]);
    db_run("INSERT INTO incident_reports (student_id, reported_by, record_type, category, severity, routed_to, title, description, action_taken, observed_at, status, created_at)
            VALUES (?, ?, ?, ?, ?, 'guidance', ?, ?, ?, ?, 'new', ?)",
        [$visit['student_id'], $user['id'], $psych ? 'mental_emotional' : 'physical', $psych ? 'emotional' : 'physical', 'moderate',
         mb_substr('Clinic referral: ' . $visit['complaint'], 0, 150),
         trim(($visit['assessment'] ?? '') ?: $visit['complaint']),
         'Seen at the clinic on ' . date('M j, g:i A', strtotime($visit['arrived_at'])) . '.', $visit['arrived_at'], now()]);
    $rid = (int)db()->lastInsertId();
    db_run('UPDATE clinic_visits SET referral_report_id = ?, is_sensitive = 1 WHERE id = ?', [$rid, $visit['id']]);
    audit('create', 'incident_reports', $rid, (int)$visit['student_id'], ['routed_to'], $user);
}

function clinic_check_in(array $user, array $in): array {
    require_clinic($user);
    clinic_expire_passes();

    $studentId = (int)($in['student_id'] ?? 0);
    $pass = null;
    $code = strtoupper(trim($in['pass_code'] ?? ''));
    if ($code !== '' || !empty($in['pass_id'])) {
        $pass = $code !== ''
            ? db_one('SELECT * FROM clinic_passes WHERE pass_code = ?', [$code])
            : db_one('SELECT * FROM clinic_passes WHERE id = ?', [(int)$in['pass_id']]);
        if (!$pass)                          return [null, 'No clinic pass has that code.'];
        if ($pass['status'] === 'expired')   return [null, 'That pass has expired. Check the student in without it (and let the teacher know).'];
        if ($pass['status'] !== 'issued')    return [null, 'That pass was already used.'];
        if ($studentId && $studentId !== (int)$pass['student_id']) {
            $owner = db_one('SELECT first_name, last_name FROM students WHERE id = ?', [$pass['student_id']]);
            return [null, "That pass was issued to {$owner['first_name']} {$owner['last_name']}, not the student you chose."];
        }
        $studentId = (int)$pass['student_id'];
    }
    if (!db_value("SELECT 1 FROM students WHERE id = ? AND status = 'enrolled'", [$studentId])) return [null, 'Please choose the student.'];

    $complaint = trim($in['complaint'] ?? '');
    $status    = $in['case_status'] ?? 'under_observation';
    $arrived   = parse_local_dt($in['arrived_at'] ?? '');
    $departed  = ($in['departed_at'] ?? '') !== '' ? parse_local_dt($in['departed_at']) : null;
    $temp      = ($in['temperature_c'] ?? '') !== '' ? (float)$in['temperature_c'] : null;
    $pulse     = ($in['pulse_bpm'] ?? '') !== '' ? (int)$in['pulse_bpm'] : null;
    $stockItem = (int)($in['stock_item_id'] ?? 0);
    $stockQty  = (int)($in['stock_qty'] ?? 0);

    if ($complaint === '')                               return [null, 'Please write the main complaint.'];
    if (!isset(CLINIC_STATUS[$status]))                  return [null, 'Please choose a case status.'];
    if (!$arrived)                                       return [null, 'Please enter the check-in time.'];
    if ($arrived > new DateTime('+5 minutes'))           return [null, 'Check-in time cannot be in the future.'];
    if (($in['departed_at'] ?? '') !== '' && !$departed) return [null, 'Please enter a valid check-out time.'];
    if ($departed && $departed < $arrived)               return [null, 'Check-out time must be after check-in time.'];
    if ($departed && $departed > new DateTime('+5 minutes')) return [null, 'Check-out time cannot be in the future.'];
    if ($temp !== null && ($temp < 30 || $temp > 45))   return [null, 'Temperature should be in °C (30–45).'];
    if ($pulse !== null && ($pulse < 30 || $pulse > 250)) return [null, 'Pulse looks wrong — enter beats per minute.'];
    if ($stockItem && trim($in['medication_notes'] ?? '') === '') return [null, 'Write what was given in “Medication administered” when you take it from stock.'];
    if (!$departed && in_array($status, CLINIC_LEAVING, true)) $departed = new DateTime();

    $sensitive = !empty($in['is_sensitive']) || ($pass && $pass['reason_type'] === 'psychological') || $status === 'referred_guidance';
    $reportId = (int)($in['report_id'] ?? 0);
    if ($reportId && !db_value("SELECT 1 FROM incident_reports WHERE id = ? AND student_id = ? AND routed_to = 'clinic'", [$reportId, $studentId])) {
        return [null, 'That teacher report is for a different student.'];
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        db_run('INSERT INTO clinic_visits (student_id, pass_id, attended_by, arrived_at, departed_at, complaint, assessment, medication_notes, temperature_c, blood_pressure, pulse_bpm,
                                           disposition, case_status, is_sensitive, parent_notified, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$studentId, $pass['id'] ?? null, $user['id'], $arrived->format('Y-m-d H:i:s'), $departed ? $departed->format('Y-m-d H:i:s') : null,
             mb_substr($complaint, 0, 255), trim($in['assessment'] ?? '') ?: null, trim($in['medication_notes'] ?? '') ?: null,
             $temp, trim($in['blood_pressure'] ?? '') ?: null, $pulse, CLINIC_DISPOSITION[$status], $status, $sensitive ? 1 : 0,
             ($status === 'guardian_contacted' || !empty($in['parent_notified'])) ? 1 : 0, now()]);
        $visitId = (int)$pdo->lastInsertId();

        if ($stockItem && ($err = clinic_use_stock($user, $visitId, $stockItem, $stockQty, $arrived))) {
            $pdo->rollBack();
            return [null, $err];
        }
        if ($pass) {
            db_run('UPDATE clinic_passes SET status = ? WHERE id = ?', [$departed ? 'completed' : 'arrived', $pass['id']]);
        }
        if ($reportId) {
            db_run("UPDATE incident_reports SET status = 'acknowledged', acknowledged_by = ?, acknowledged_at = ? WHERE id = ? AND status = 'new'", [$user['id'], now(), $reportId]);
        }
        if ($status === 'referred_guidance') {
            clinic_refer_to_guidance($user, db_one('SELECT * FROM clinic_visits WHERE id = ?', [$visitId]));
        }
        audit('create', 'clinic_visits', $visitId, $studentId, ['arrived_at', 'complaint', 'case_status', 'medication_notes'], $user);
        clinic_sync_parent_notice($user, $visitId);   // v1.3: shows up in the family feed right away
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return [$visitId, null];
}

function clinic_update_visit(array $user, int $visitId, array $in): ?string {
    require_clinic($user);
    $v = db_one('SELECT * FROM clinic_visits WHERE id = ?', [$visitId]);
    if (!$v) return 'Visit not found.';

    $status   = $in['case_status'] ?? $v['case_status'];
    $departed = ($in['departed_at'] ?? '') !== '' ? parse_local_dt($in['departed_at']) : null;
    $addMed   = trim($in['add_medication'] ?? '');
    $stockItem = (int)($in['stock_item_id'] ?? 0);
    $stockQty  = (int)($in['stock_qty'] ?? 0);
    $arrived  = new DateTime($v['arrived_at']);

    if (!isset(CLINIC_STATUS[$status]))                    return 'Please choose a case status.';
    if (($in['departed_at'] ?? '') !== '' && !$departed)   return 'Please enter a valid check-out time.';
    if ($departed && $departed < $arrived)                 return 'Check-out time must be after check-in (' . $arrived->format('g:i A') . ').';
    if ($departed && $departed > new DateTime('+5 minutes')) return 'Check-out time cannot be in the future.';
    if ($stockItem && $addMed === '')                      return 'Write what was given when you take it from stock.';
    if (!$departed && in_array($status, CLINIC_LEAVING, true)) $departed = new DateTime();

    $meds = $v['medication_notes'];
    if ($addMed !== '') $meds = trim(($meds ? $meds . "\n" : '') . date('g:i A') . ' — ' . $addMed);

    $new = [
        'case_status'      => $status,
        'disposition'      => CLINIC_DISPOSITION[$status],
        'departed_at'      => $departed ? $departed->format('Y-m-d H:i:s') : null,
        'assessment'       => trim($in['assessment'] ?? (string)$v['assessment']) ?: null,
        'medication_notes' => $meds,
        'parent_notified'  => ($status === 'guardian_contacted' || !empty($in['parent_notified']) || $v['parent_notified']) ? 1 : 0,
        'is_sensitive'     => ($v['is_sensitive'] || !empty($in['is_sensitive']) || $status === 'referred_guidance') ? 1 : 0,
    ];
    $changed = changed_fields($v, $new);

    $pdo = db();
    $pdo->beginTransaction();
    try {
        db_run('UPDATE clinic_visits SET case_status = ?, disposition = ?, departed_at = ?, assessment = ?, medication_notes = ?, parent_notified = ?, is_sensitive = ? WHERE id = ?',
            [$new['case_status'], $new['disposition'], $new['departed_at'], $new['assessment'], $new['medication_notes'], $new['parent_notified'], $new['is_sensitive'], $visitId]);
        if ($stockItem && ($err = clinic_use_stock($user, $visitId, $stockItem, $stockQty, new DateTime()))) {
            $pdo->rollBack();
            return $err;
        }
        if ($v['pass_id']) {
            db_run('UPDATE clinic_passes SET status = ? WHERE id = ?', [$new['departed_at'] ? 'completed' : 'arrived', $v['pass_id']]);
        }
        if ($status === 'referred_guidance') clinic_refer_to_guidance($user, array_merge($v, $new));
        if ($changed || $stockItem) audit('update', 'clinic_visits', $visitId, (int)$v['student_id'], $changed ?: ['medication'], $user);
        clinic_sync_parent_notice($user, $visitId);   // keep time out, status and medication current for parents
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return null;
}

function clinic_visits_where(string $where, array $params = []): array {
    return db_all(
        "SELECT v.*, s.first_name, s.last_name, s.student_no, sec.grade_level, sec.name AS section_name,
                n.first_name AS n_first, n.last_name AS n_last, p.pass_code, p.reason_type
           FROM clinic_visits v
           JOIN students s ON s.id = v.student_id
           LEFT JOIN sections sec ON sec.id = s.section_id
           JOIN users n ON n.id = v.attended_by
           LEFT JOIN clinic_passes p ON p.id = v.pass_id
          WHERE $where ORDER BY v.arrived_at DESC",
        $params
    );
}

function clinic_reports(): array {
    return db_all(
        "SELECT r.*, s.first_name, s.last_name, sec.grade_level, sec.name AS section_name, u.first_name AS t_first, u.last_name AS t_last
           FROM incident_reports r
           JOIN students s ON s.id = r.student_id
           LEFT JOIN sections sec ON sec.id = s.section_id
           JOIN users u ON u.id = r.reported_by
          WHERE r.routed_to = 'clinic' AND r.status IN ('new','acknowledged')
          ORDER BY CASE WHEN r.status = 'new' THEN 0 ELSE 1 END,
                   CASE r.severity WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 WHEN 'moderate' THEN 2 ELSE 3 END, r.observed_at DESC"
    );
}

function clinic_report_action(array $user, int $reportId, string $action): ?string {
    require_clinic($user);
    $r = db_one("SELECT id, student_id FROM incident_reports WHERE id = ? AND routed_to = 'clinic'", [$reportId]);
    if (!$r) return 'Report not found.';
    if (!in_array($action, ['acknowledge', 'close'], true)) return 'Unknown action.';
    db_run("UPDATE incident_reports SET status = ?, acknowledged_by = COALESCE(acknowledged_by, ?), acknowledged_at = COALESCE(acknowledged_at, ?) WHERE id = ?",
        [$action === 'close' ? 'closed' : 'acknowledged', $user['id'], now(), $reportId]);
    audit('update', 'incident_reports', $reportId, (int)$r['student_id'], ['status'], $user);
    return null;
}

function stock_status(array $item): string {
    if (!empty($item['status_override'])) return $item['status_override'];
    if ((int)$item['quantity'] <= 0) return 'out_of_stock';
    return (int)$item['quantity'] <= (int)$item['reorder_level'] ? 'low_stock' : 'in_stock';
}

function inventory_items(): array {
    $items = db_all('SELECT * FROM inventory_items ORDER BY name');
    foreach ($items as &$i) $i['status'] = stock_status($i);
    unset($i);
    return $items;
}

function inventory_item_error(array $in): ?string {
    if (trim($in['name'] ?? '') === '')                      return 'Please enter the item name.';
    if (!isset(INVENTORY_CATEGORIES[$in['category'] ?? ''])) return 'Please choose a category.';
    if (trim($in['unit'] ?? '') === '')                      return 'Please enter the unit (e.g. tablet, box, pc).';
    if (!ctype_digit((string)($in['reorder_level'] ?? '')))  return 'Low-stock level must be a whole number (0 or more).';
    if (($in['expiry_date'] ?? '') !== '' && !valid_date($in['expiry_date'])) return 'Please enter a valid expiry date.';
    return null;
}

function inventory_add_item(array $user, array $in): ?string {
    require_clinic($user, 'clinic.inventory.manage');
    if ($err = inventory_item_error($in)) return $err;
    $mode = ($in['mode'] ?? 'stock') === 'request' ? 'request' : 'stock';
    $qty  = (string)($in['quantity'] ?? '0');
    $req  = (string)($in['requested_qty'] ?? '');
    if ($mode === 'stock' && !ctype_digit($qty))                  return 'Quantity must be a whole number (0 or more).';
    if ($mode === 'request' && (!ctype_digit($req) || (int)$req < 1)) return 'Enter how many you are requesting.';
    $name = trim($in['name']);
    if (db_value('SELECT 1 FROM inventory_items WHERE LOWER(name) = LOWER(?)', [$name])) return 'An item with that name already exists — edit it instead.';

    $pdo = db();
    $pdo->beginTransaction();
    try {
        db_run('INSERT INTO inventory_items (name, category, unit, quantity, reorder_level, status_override, requested_qty, request_note, requested_at, expiry_date, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [mb_substr($name, 0, 120), $in['category'], mb_substr(trim($in['unit']), 0, 20), $mode === 'stock' ? (int)$qty : 0, (int)$in['reorder_level'],
             !empty($in['flag_low']) ? 'low_stock' : null,
             $mode === 'request' ? (int)$req : null, $mode === 'request' ? (trim($in['request_note'] ?? '') ?: null) : null, $mode === 'request' ? now() : null,
             ($in['expiry_date'] ?? '') ?: null, now()]);
        $id = (int)$pdo->lastInsertId();
        if ($mode === 'stock' && (int)$qty > 0) {
            db_run('INSERT INTO inventory_movements (item_id, change_qty, reason, performed_by, created_at) VALUES (?, ?, ?, ?, ?)', [$id, (int)$qty, 'restock', $user['id'], now()]);
        }
        audit('create', 'inventory_items', $id, null, ['name', 'quantity'], $user);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return null;
}

function inventory_update_item(array $user, int $itemId, array $in): ?string {
    require_clinic($user, 'clinic.inventory.manage');
    $item = db_one('SELECT * FROM inventory_items WHERE id = ?', [$itemId]);
    if (!$item) return 'Item not found.';
    if ($err = inventory_item_error($in + ['name' => $item['name']])) return $err;
    if (!ctype_digit((string)($in['quantity'] ?? '')))  return 'Quantity must be a whole number (0 or more).';
    $override = $in['status_override'] ?? '';
    if ($override !== '' && !isset(STOCK_STATUS[$override])) return 'Please choose a status.';
    $req = trim((string)($in['requested_qty'] ?? ''));
    if ($req !== '' && (!ctype_digit($req) || (int)$req < 1)) return 'Requested quantity must be 1 or more (leave blank for none).';
    $name = trim($in['name'] ?? $item['name']);
    if (db_value('SELECT 1 FROM inventory_items WHERE LOWER(name) = LOWER(?) AND id <> ?', [$name, $itemId])) return 'Another item already has that name.';

    $qty = (int)$in['quantity'];
    $received = !empty($in['mark_received']) && $item['requested_qty'];
        // v1.4: a delivery puts the item back on automatic status, so it shows In stock again
    if ($received) { $qty += (int)$item['requested_qty']; $req = ''; $override = ''; }
    $delta = $qty - (int)$item['quantity'];
    $reason = $received ? 'restock' : ($in['change_reason'] ?? '');
    if ($delta !== 0 && !isset(STOCK_REASONS[$reason])) return 'Please choose why the quantity changed.';

    $new = [
        'name' => mb_substr($name, 0, 120), 'category' => $in['category'], 'unit' => mb_substr(trim($in['unit']), 0, 20),
        'quantity' => $qty, 'reorder_level' => (int)$in['reorder_level'], 'status_override' => $override ?: null,
        'requested_qty' => $req !== '' ? (int)$req : null,
        'request_note' => $req !== '' ? (trim($in['request_note'] ?? '') ?: null) : null,
        'expiry_date' => ($in['expiry_date'] ?? '') ?: null,
    ];
    $requestedAt = $req === '' ? null : ($item['requested_at'] ?: now());
    $changed = changed_fields($item, $new);
    if (!$changed && !$received) return null;

    $pdo = db();
    $pdo->beginTransaction();
    try {
        db_run('UPDATE inventory_items SET name = ?, category = ?, unit = ?, quantity = ?, reorder_level = ?, status_override = ?, requested_qty = ?, request_note = ?, requested_at = ?, expiry_date = ?, updated_at = ? WHERE id = ?',
            [$new['name'], $new['category'], $new['unit'], $new['quantity'], $new['reorder_level'], $new['status_override'], $new['requested_qty'], $new['request_note'], $requestedAt, $new['expiry_date'], now(), $itemId]);
        if ($delta !== 0) {
            db_run('INSERT INTO inventory_movements (item_id, change_qty, reason, performed_by, created_at) VALUES (?, ?, ?, ?, ?)', [$itemId, $delta, $reason, $user['id'], now()]);
        }
        audit('update', 'inventory_items', $itemId, null, $changed ?: ['quantity'], $user);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return null;
}

function inventory_receive_request(array $user, int $itemId): ?string {
    require_clinic($user, 'clinic.inventory.manage');
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $item = db_one('SELECT * FROM inventory_items WHERE id = ? FOR UPDATE', [$itemId]);
        if (!$item)                   { $pdo->rollBack(); return 'Item not found.'; }
        if (!$item['requested_qty'])  { $pdo->rollBack(); return 'There is no pending request for that item.'; }
        $add = (int)$item['requested_qty'];
        db_run('UPDATE inventory_items SET quantity = quantity + ?, requested_qty = NULL, request_note = NULL, requested_at = NULL, status_override = NULL, updated_at = ? WHERE id = ?',
            [$add, now(), $itemId]);
        db_run('INSERT INTO inventory_movements (item_id, change_qty, reason, performed_by, created_at) VALUES (?, ?, ?, ?, ?)', [$itemId, $add, 'restock', $user['id'], now()]);
        audit('update', 'inventory_items', $itemId, null, ['quantity', 'requested_qty', 'status_override'], $user);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return null;
}

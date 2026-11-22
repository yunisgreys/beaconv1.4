<?php

const STAFF_ROLES   = ['teacher' => 'Teacher', 'guidance' => 'Guidance Office', 'clinic' => 'Clinic', 'registrar' => 'Registrar'];
/** Solid role colours, readable on both the light and the dark (Super Admin) theme. */
const ROLE_PILL     = ['super_admin' => 'pill-admin', 'teacher' => 'pill-teacher', 'guidance' => 'pill-guidance', 'clinic' => 'pill-clinic', 'registrar' => 'pill-registrar', 'parent' => 'pill-parent'];
const STAFF_PER_PAGE = 10;
const ONLINE_WINDOW = 5 * 60;

function require_admin(array $user, string $perm = 'admin.staff.manage'): void {
    if (!can($perm, $user)) throw new RuntimeException('Only the Super Admin can do this.');
}

function is_online(?string $lastSeen): bool {
    return $lastSeen !== null && strtotime($lastSeen) >= time() - ONLINE_WINDOW;
}

function temp_password(): string {
    $words = ['amber', 'beacon', 'cedar', 'delta', 'ember', 'harbor', 'island', 'jasmine', 'kite', 'lumen', 'maple', 'north', 'orbit', 'pearl', 'river', 'solar', 'tide', 'violet', 'willow'];
    return ucfirst($words[random_int(0, count($words) - 1)]) . '-' . random_int(1000, 9999) . '-' . $words[random_int(0, count($words) - 1)];
}

function staff_accounts(): array {
    return db_all(
        "SELECT u.id, u.email, u.first_name, u.last_name, u.title, u.phone, u.status, u.must_change_password, u.last_login_at, u.last_seen_at, u.created_at,
                u.failed_login_attempts, u.locked_until, u.password_change_count, u.password_change_approval_required,
                r.code AS role, r.label AS role_label
           FROM users u JOIN roles r ON r.id = u.role_id
          WHERE r.code IN ('super_admin','teacher','guidance','clinic','registrar')
          ORDER BY CASE r.code WHEN 'super_admin' THEN 0 WHEN 'teacher' THEN 1 WHEN 'guidance' THEN 2 WHEN 'clinic' THEN 3 ELSE 4 END, u.last_name, u.first_name"
    );
}

/** One page of staff accounts, optionally for one role and/or matching a search. Returns [rows, total]. */
function staff_accounts_page(string $role, string $q, int $page, int $perPage = STAFF_PER_PAGE): array {
    $where = ["r.code IN ('super_admin','teacher','guidance','clinic','registrar')"];
    $params = [];
    if (isset(STAFF_ROLES[$role])) { $where[] = 'r.code = ?'; $params[] = $role; }
    if ($q !== '') {
        $like = '%' . mb_strtolower(addcslashes($q, '\\%_'), 'UTF-8') . '%';
        $where[] = "(LOWER(u.first_name) LIKE ? OR LOWER(u.last_name) LIKE ? OR LOWER(u.email) LIKE ? OR LOWER(u.first_name || ' ' || u.last_name) LIKE ?)";
        array_push($params, $like, $like, $like, $like);
    }
    $sqlWhere = implode(' AND ', $where);
    $total = (int)db_value("SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id WHERE $sqlWhere", $params);
    $rows = db_all(
        "SELECT u.id, u.email, u.first_name, u.last_name, u.title, u.phone, u.status, u.must_change_password, u.last_login_at, u.last_seen_at, u.created_at,
                u.failed_login_attempts, u.locked_until, u.password_change_count, u.password_change_approval_required,
                r.code AS role, r.label AS role_label
           FROM users u JOIN roles r ON r.id = u.role_id
          WHERE $sqlWhere
          ORDER BY CASE r.code WHEN 'super_admin' THEN 0 WHEN 'teacher' THEN 1 WHEN 'guidance' THEN 2 WHEN 'clinic' THEN 3 ELSE 4 END, u.last_name, u.first_name
          LIMIT " . max(1, $perPage) . " OFFSET " . max(0, ($page - 1) * $perPage),
        $params
    );
    return [$rows, $total];
}

function role_pill(string $role, string $label): string {
    return '<span class="role-pill ' . e(ROLE_PILL[$role] ?? 'pill-parent') . '">' . e($label) . '</span>';
}

/** True while an account is locked by the 3-failed-sign-ins rule. */
function is_locked(array $u): bool {
    return !empty($u['locked_until']) && strtotime($u['locked_until']) > time();
}

function advisory_map(): array {
    $map = [];
    foreach (db_all('SELECT id, grade_level, name, adviser_id FROM sections ORDER BY grade_level, name') as $s) {
        if ($s['adviser_id']) $map[(int)$s['adviser_id']][] = 'Grade ' . $s['grade_level'] . ' – ' . $s['name'];
    }
    return $map;
}

function section_options(): array {
    $opts = ['' => 'No advisory class'];
    foreach (db_all('SELECT s.id, s.grade_level, s.name, u.first_name, u.last_name FROM sections s LEFT JOIN users u ON u.id = s.adviser_id ORDER BY s.grade_level, s.name') as $s) {
        $opts[$s['id']] = 'Grade ' . $s['grade_level'] . ' – ' . $s['name'] . ($s['first_name'] ? ' (now: ' . $s['first_name'] . ' ' . $s['last_name'] . ')' : '');
    }
    return $opts;
}

/** v1.3: a new staff account needs every field. Sections are assigned by the Registrar. */
function staff_create_error(array $in): ?string {
    $email = normalize_email($in['email'] ?? '');
    $phone = trim($in['phone'] ?? '');
    if (!isset(STAFF_ROLES[$in['role'] ?? '']))     return 'Please choose a role: Teacher, Guidance Office, Clinic or Registrar.';
    if (trim($in['first_name'] ?? '') === '')       return 'Please enter the first name.';
    if (trim($in['last_name'] ?? '') === '')        return 'Please enter the last name.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return 'Please enter a valid e-mail address.';
    if (db_value('SELECT 1 FROM users WHERE email = ?', [$email])) return 'Another account already uses that e-mail.';
    if (trim($in['title'] ?? '') === '')            return 'Please enter the job title.';
    if ($phone === '')                              return 'Please enter a phone number.';
    if (!preg_match('/^\+?[0-9][0-9 ()-]{6,24}$/', $phone)) return 'Please enter a valid phone number (digits, spaces and dashes only).';
    return null;
}

function admin_create_staff(array $admin, array $in): array {
    require_admin($admin);
    if ($err = staff_create_error($in)) return [null, null, $err];
    $temp = temp_password();
    db_run('INSERT INTO users (role_id, email, password_hash, first_name, last_name, title, phone, status, must_change_password, created_by, created_at, updated_at)
            VALUES ((SELECT id FROM roles WHERE code = ?), ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?)',
        [$in['role'], normalize_email($in['email']), password_hash($temp, PASSWORD_DEFAULT), trim($in['first_name']), trim($in['last_name']),
         trim($in['title']), trim($in['phone']), 'active', $admin['id'], now(), now()]);
        $id = (int)db()->lastInsertId();
    audit('staff_create', 'users', $id, null, ['role', 'email', 'first_name', 'last_name', 'title', 'phone'], $admin);
    staff_registry_mark_created(normalize_email($in['email']), $id, $admin);   // v1.4: keep the Registrar's list in sync
    return [$id, $temp, null];
}

/** v1.4: marks the Registrar's pending record for this e-mail as "Account created". */
function staff_registry_mark_created(string $email, int $userId, array $admin): void {
    foreach (db_all("SELECT id FROM staff_registry WHERE email = ? AND status = 'pending'", [$email]) as $r) {
        db_run("UPDATE staff_registry SET status = 'created', user_id = ?, account_created_by = ?, account_created_at = ?, updated_at = ? WHERE id = ?",
            [$userId, $admin['id'], now(), now(), $r['id']]);
        audit('update', 'staff_registry', (int)$r['id'], null, ['status', 'user_id'], $admin);
    }
}

/**
 * v1.4: creates the sign-in account for someone the Registrar registered, using the
 * details the Registrar recorded. Returns [user id, temporary password, registry row, error].
 */
function admin_create_from_registry(array $admin, int $registryId): array {
    require_admin($admin);
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $r = db_one('SELECT * FROM staff_registry WHERE id = ? FOR UPDATE', [$registryId]);
        if (!$r)                         { $pdo->rollBack(); return [null, null, null, 'That registration was not found.']; }
        if ($r['status'] !== 'pending')  { $pdo->rollBack(); return [null, null, null, $r['status'] === 'created' ? 'An account was already created for this person.' : 'The Registrar cancelled this registration.']; }
        if (!isset(STAFF_ROLES[$r['role_code']])) { $pdo->rollBack(); return [null, null, null, 'That role cannot be given a staff account.']; }
        $email = normalize_email($r['email']);
        if (db_value('SELECT 1 FROM users WHERE email = ?', [$email])) { $pdo->rollBack(); return [null, null, null, 'Another account already uses ' . $email . '. Ask the Registrar to correct the e-mail.']; }
        $title = $r['title'] ?: (STAFF_REGISTRY_ROLES[$r['role_code']] ?? STAFF_ROLES[$r['role_code']]);
        $temp = temp_password();
        db_run('INSERT INTO users (role_id, email, password_hash, first_name, last_name, title, phone, status, must_change_password, created_by, created_at, updated_at)
                VALUES ((SELECT id FROM roles WHERE code = ?), ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?)',
            [$r['role_code'], $email, password_hash($temp, PASSWORD_DEFAULT), $r['first_name'], $r['last_name'], $title, $r['phone'], 'active', $admin['id'], now(), now()]);
        $id = (int)$pdo->lastInsertId();
        audit('staff_create', 'users', $id, null, array_values(array_filter(['role', 'email', 'first_name', 'last_name', 'title', $r['phone'] ? 'phone' : null])), $admin);
        staff_registry_mark_created($email, $id, $admin);
        $pdo->commit();
        return [$id, $temp, $r, null];
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * v1.3: for an existing staff account the Super Admin changes LOGIN CREDENTIALS only
 * (the sign-in e-mail here; the password through "Reset password"). Names, job titles
 * and section assignments are maintained by the Registrar.
 */
function admin_update_credentials(array $admin, int $id, array $in): ?string {
    require_admin($admin);
    $u = db_one("SELECT u.id, u.email, r.code AS role FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ?", [$id]);
    if (!$u || !isset(STAFF_ROLES[$u['role']])) return 'Only Teacher, Guidance, Clinic and Registrar accounts can be edited here.';
    $email = normalize_email($in['email'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return 'Please enter a valid e-mail address.';
    if ($email === $u['email']) return null;
    if (db_value('SELECT 1 FROM users WHERE email = ? AND id <> ?', [$email, $id])) return 'Another account already uses that e-mail.';
    db_run('UPDATE users SET email = ?, updated_at = ? WHERE id = ?', [$email, now(), $id]);
    audit('staff_update', 'users', $id, null, ['email'], $admin);
    return null;
}

function admin_reset_password(array $admin, int $id): array {
    require_admin($admin);
    $role = db_value('SELECT r.code FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ?', [$id]);
    if (!isset(STAFF_ROLES[$role])) return [null, 'Passwords can only be reset here for Teacher, Guidance, Clinic and Registrar accounts.'];
    $temp = temp_password();
    // A reset also unlocks the account and gives the user a fresh set of 3 password changes.
    db_run('UPDATE users SET password_hash = ?, must_change_password = 1, password_change_count = 0, password_change_approval_required = 0,
                             failed_login_attempts = 0, locked_until = NULL, updated_at = ? WHERE id = ?', [password_hash($temp, PASSWORD_DEFAULT), now(), $id]);
    db_run('DELETE FROM login_attempts WHERE email = (SELECT email FROM users WHERE id = ?) AND succeeded = 0', [$id]);
    db_run("UPDATE password_change_requests SET status = 'used', reviewed_by = ?, reviewed_at = ?, review_note = 'Password reset by the Super Admin' WHERE user_id = ? AND status IN ('pending','approved')", [$admin['id'], now(), $id]);
    audit('password_reset', 'users', $id, null, ['password_hash'], $admin);
    return [$temp, null];
}

function admin_set_status(array $admin, int $id, string $status): ?string {
    require_admin($admin);
    if (!in_array($status, ['active', 'disabled'], true)) return 'Unknown status.';
    if ($id === (int)$admin['id']) return "You can't disable your own account.";
    $role = db_value('SELECT r.code FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ?', [$id]);
    if (!$role)                 return 'Account not found.';
    if ($role === 'super_admin') return 'Super Admin accounts cannot be changed here.';
    if ($role === 'parent' && !can('admin.parents.manage', $admin)) return 'Not allowed.';
    db_run('UPDATE users SET status = ?, last_seen_at = CASE WHEN ? = \'disabled\' THEN NULL ELSE last_seen_at END, updated_at = ? WHERE id = ?', [$status, $status, now(), $id]);
    audit($status === 'disabled' ? 'account_disable' : 'account_enable', 'users', $id, null, ['status'], $admin);
    return null;
}

function parent_link_requests(string $filter = 'pending'): array {
    $where = ['pending' => "l.status = 'pending'", 'approved' => "l.status = 'approved'", 'closed' => "l.status IN ('rejected','revoked')", 'all' => '1=1'][$filter] ?? "l.status = 'pending'";
    return db_all(
        "SELECT l.*, p.first_name AS p_first, p.last_name AS p_last, p.email, p.phone, p.status AS account_status, p.created_at AS registered_at,
                s.first_name AS s_first, s.last_name AS s_last, s.student_no, s.guardian_name, s.guardian_relation, s.guardian_contact,
                sec.grade_level, sec.name AS section_name, rv.first_name AS rv_first, rv.last_name AS rv_last
           FROM parent_student_links l
           JOIN users p    ON p.id = l.parent_id
           JOIN students s ON s.id = l.student_id
           LEFT JOIN sections sec ON sec.id = s.section_id
           LEFT JOIN users rv ON rv.id = l.reviewed_by
          WHERE $where
          ORDER BY CASE l.status WHEN 'pending' THEN 0 ELSE 1 END, l.created_at DESC"
    );
}

function guardian_match(array $link): string {
    $name = strtolower(trim($link['p_first'] . ' ' . $link['p_last']));
    $onFile = strtolower(trim((string)$link['guardian_name']));
    $phone = preg_replace('/\D/', '', (string)$link['phone']);
    $filePhone = preg_replace('/\D/', '', (string)$link['guardian_contact']);
    if ($onFile !== '' && $onFile === $name) return 'name';
    if ($phone !== '' && $phone === $filePhone) return 'phone';
    if ($onFile !== '' && strtolower(trim($link['p_last'])) !== '' && str_contains($onFile, strtolower(trim($link['p_last'])))) return 'surname';
    return 'none';
}

function admin_review_link(array $admin, int $linkId, string $action, ?string $note = null): ?string {
    require_admin($admin, 'admin.parents.manage');
    $l = db_one('SELECT * FROM parent_student_links WHERE id = ?', [$linkId]);
    if (!$l) return 'Request not found.';
    $allowed = ['approve' => ['pending', 'rejected', 'revoked'], 'reject' => ['pending'], 'revoke' => ['approved'], 'reopen' => ['rejected', 'revoked']];
    if (!isset($allowed[$action]))                       return 'Unknown action.';
    if (!in_array($l['status'], $allowed[$action], true)) return 'This request is already ' . $l['status'] . '.';
    if (in_array($action, ['reject', 'revoke'], true) && trim((string)$note) === '') return 'Please add a short reason (the parent may ask why).';

    $status = ['approve' => 'approved', 'reject' => 'rejected', 'revoke' => 'revoked', 'reopen' => 'pending'][$action];

    // v1.3: bringing back a rejected/revoked Mother link must not make a 3rd active Mother.
    $limitMsg = 'This student already has ' . MOTHER_LIMIT . ' Mother accounts linked (pending or approved). Reject or revoke one of them first.';
    if (in_array($status, ['approved', 'pending'], true) && !in_array($l['status'], ['pending', 'approved'], true)
        && strtolower(trim($l['relationship'])) === 'mother') {
        $others = (int)db_value("SELECT COUNT(*) FROM parent_student_links WHERE student_id = ? AND id <> ?
                                    AND LOWER(TRIM(relationship)) = 'mother' AND status IN ('pending','approved')", [$l['student_id'], $linkId]);
        if ($others >= MOTHER_LIMIT) return $limitMsg;
    }
    try {
        db_run('UPDATE parent_student_links SET status = ?, reviewed_by = ?, reviewed_at = ?, review_note = ? WHERE id = ?',
            [$status, $admin['id'], now(), trim((string)$note) ?: null, $linkId]);
    } catch (PDOException $e) {
        if (str_contains($e->getMessage(), 'MOTHER_LIMIT')) return $limitMsg;   // database check (same rule)
        throw $e;
    }
    audit('link_' . $action, 'parent_student_links', $linkId, (int)$l['student_id'], ['status'], $admin);
    return null;
}

function parent_accounts(): array {
    return db_all(
        "SELECT u.id, u.email, u.first_name, u.last_name, u.phone, u.status, u.created_at, u.last_login_at, u.last_seen_at,
                SUM(CASE WHEN l.status = 'approved' THEN 1 ELSE 0 END) AS approved_links,
                SUM(CASE WHEN l.status = 'pending' THEN 1 ELSE 0 END) AS pending_links
           FROM users u JOIN roles r ON r.id = u.role_id
           LEFT JOIN parent_student_links l ON l.parent_id = u.id
          WHERE r.code = 'parent'
          GROUP BY u.id, u.email, u.first_name, u.last_name, u.phone, u.status, u.created_at, u.last_login_at, u.last_seen_at
          ORDER BY u.created_at DESC"
    );
}

const ACTIVITY_LABELS = [
    'incident_reports'     => ['create' => 'sent a report', 'update' => 'reviewed a report'],
    'commendations'        => ['create' => 'issued a commendation'],
    'clinic_passes'        => ['create' => 'issued a clinic pass'],
    'guidance_cases'       => ['create' => 'opened a case', 'update' => 'updated a case', 'consent_grant' => 'recorded student consent to share', 'consent_withdraw' => 'recorded withdrawal of consent'],
    'case_notes'           => ['create' => 'added a case note'],
    'interventions'        => ['create' => 'added an intervention plan', 'update' => 'updated an intervention plan'],
    'intervention_updates' => ['create' => 'logged intervention progress'],
    'counseling_sessions'  => ['create' => 'scheduled a counseling session', 'update' => 'updated a session'],
    'clinic_visits'        => ['create' => 'checked in a student', 'update' => 'updated a clinic visit'],
    'inventory_items'      => ['create' => 'added a supply item', 'update' => 'updated inventory'],
    'medical_profiles'     => ['create' => 'added health information', 'update' => 'updated health information'],
    'attendance'           => ['create' => 'recorded attendance', 'update' => 'corrected attendance'],
    'students'             => ['create' => 'added a student record', 'update' => 'updated a student record'],
    'sections'             => ['create' => 'created a section', 'update' => 'updated a section'],
    'users'                => ['update' => 'updated teacher information'],
    'staff_registry'       => ['create' => 'registered a staff member', 'update' => 'updated a staff registration'],
];

const LOG_PER_PAGE = 10;

/** Staff activity (no student names). Returns [rows, total]. */
function activity_feed(string $role = '', int $limit = 25, int $offset = 0): array {
    $entities = array_keys(ACTIVITY_LABELS);
    $in = implode(',', array_fill(0, count($entities), '?'));
    $params = $entities;
    $roleSql = "a.actor_role IN ('teacher','guidance','clinic','registrar')";
    if (isset(STAFF_ROLES[$role])) { $roleSql = 'a.actor_role = ?'; $params[] = $role; }
    $where = "a.entity IN ($in) AND $roleSql AND a.action IN ('create','update','consent_grant','consent_withdraw')";
    $total = (int)db_value("SELECT COUNT(*) FROM audit_logs a WHERE $where", $params);
    $rows = db_all(
        "SELECT a.action, a.entity, a.actor_role, a.created_at, u.first_name, u.last_name
           FROM audit_logs a LEFT JOIN users u ON u.id = a.actor_id
          WHERE $where
          ORDER BY a.created_at DESC, a.id DESC LIMIT " . max(1, $limit) . " OFFSET " . max(0, $offset),
        $params
    );
    foreach ($rows as &$r) $r['label'] = ACTIVITY_LABELS[$r['entity']][$r['action']] ?? ($r['action'] . ' ' . $r['entity']);
    unset($r);
    return [$rows, $total];
}

/** Sign-in attempts, newest first. Returns [rows, total]. */
function recent_logins(int $limit = 15, int $offset = 0): array {
    $total = (int)db_value("SELECT COUNT(*) FROM login_attempts WHERE ip_address <> 'admin-unlock'");
    $rows = db_all(
        "SELECT la.email, la.ip_address, la.succeeded, la.attempted_at, u.first_name, u.last_name, r.label AS role_label
           FROM login_attempts la
           LEFT JOIN users u ON u.email = la.email
           LEFT JOIN roles r ON r.id = u.role_id
          WHERE la.ip_address <> 'admin-unlock'
          ORDER BY la.attempted_at DESC, la.id DESC LIMIT " . max(1, $limit) . " OFFSET " . max(0, $offset)
    );
    return [$rows, $total];
}

/** Password change requests waiting for the Super Admin (and recently decided ones). */
function password_change_requests_list(): array {
    return db_all(
        "SELECT pr.id, pr.reason, pr.status, pr.requested_at, pr.reviewed_at, u.id AS user_id, u.first_name, u.last_name, u.email, r.label AS role_label, r.code AS role
           FROM password_change_requests pr
           JOIN users u ON u.id = pr.user_id JOIN roles r ON r.id = u.role_id
          WHERE pr.status = 'pending' OR pr.reviewed_at >= ?
          ORDER BY CASE pr.status WHEN 'pending' THEN 0 ELSE 1 END, pr.requested_at DESC LIMIT 20",
        [date('Y-m-d H:i:s', strtotime('-7 days'))]
    );
}

const STUDENTS_PER_PAGE = 15;

/**
 * v1.3 Student Records for the Super Admin: basic enrolment details only,
 * read-only. No guidance, clinic or health information. Returns [rows, total].
 */
function student_records_page(string $q, string $grade, string $status, int $page, int $perPage = STUDENTS_PER_PAGE): array {
    $where = []; $params = [];
    if ($q !== '') {
        $like = '%' . mb_strtolower(addcslashes($q, '\\%_'), 'UTF-8') . '%';
        $where[] = "(LOWER(s.first_name) LIKE ? OR LOWER(s.last_name) LIKE ? OR LOWER(s.first_name || ' ' || s.last_name) LIKE ? OR LOWER(s.student_no) LIKE ? OR s.lrn LIKE ?)";
        array_push($params, $like, $like, $like, $like, $like);
    }
    if ($grade !== '' && ctype_digit($grade)) { $where[] = 'sec.grade_level = ?'; $params[] = (int)$grade; }
    if (in_array($status, ['enrolled', 'transferred', 'graduated', 'inactive'], true)) { $where[] = 's.status = ?'; $params[] = $status; }
    $sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $total = (int)db_value("SELECT COUNT(*) FROM students s LEFT JOIN sections sec ON sec.id = s.section_id $sqlWhere", $params);
    $rows = db_all(
        "SELECT s.id, s.student_no, s.lrn, s.first_name, s.middle_name, s.last_name, s.birthdate, s.sex, s.status,
                s.guardian_name, s.guardian_relation, s.guardian_contact, s.updated_at,
                sec.grade_level, sec.name AS section_name, adv.first_name AS adv_first, adv.last_name AS adv_last,
                (SELECT COUNT(*) FROM parent_student_links l WHERE l.student_id = s.id AND l.status = 'approved') AS families
           FROM students s
           LEFT JOIN sections sec ON sec.id = s.section_id
           LEFT JOIN users adv ON adv.id = sec.adviser_id
           $sqlWhere
          ORDER BY sec.grade_level NULLS LAST, sec.name, s.last_name, s.first_name
          LIMIT " . max(1, $perPage) . " OFFSET " . max(0, ($page - 1) * $perPage),
        $params
    );
    return [$rows, $total];
}

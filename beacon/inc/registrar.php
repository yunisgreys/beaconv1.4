<?php
/*
 * v1.3 Registrar portal: student and teacher information, sections and
 * section assignments. Every change is written to the audit log
 * (field names only, never values).
 */

const STUDENT_STATUSES = ['enrolled' => 'Enrolled', 'transferred' => 'Transferred', 'graduated' => 'Graduated', 'inactive' => 'Inactive'];
const REG_PER_PAGE = 15;

/** v1.4: Senior High School tracks and strands (Grades 11–12). code => label, grouped by track. */
const SHS_STRANDS = [
    'Academic Track' => [
        'STEM'  => 'STEM – Science, Technology, Engineering and Mathematics',
        'ABM'   => 'ABM – Accountancy, Business and Management',
        'HUMSS' => 'HUMSS – Humanities and Social Sciences',
        'GAS'   => 'GAS – General Academic Strand',
    ],
    'Technical-Vocational-Livelihood (TVL) Track' => [
        'TVL-HE'  => 'TVL – Home Economics',
        'TVL-ICT' => 'TVL – Information and Communications Technology',
        'TVL-IA'  => 'TVL – Industrial Arts',
        'TVL-AFA' => 'TVL – Agri-Fishery Arts',
    ],
    'Sports Track'          => ['SPORTS' => 'Sports'],
    'Arts and Design Track' => ['ARTS'   => 'Arts and Design'],
];
const SHS_GRADES = [11, 12];

/** Flat code => label list of every strand. */
function shs_strand_labels(): array {
    return array_merge(...array_values(SHS_STRANDS));
}

/** <option>s grouped by track, for the strand dropdown. */
function shs_strand_options(?string $selected): string {
    $html = '<option value="">Choose a track and strand…</option>';
    foreach (SHS_STRANDS as $track => $strands) {
        $html .= '<optgroup label="' . e($track) . '">';
        foreach ($strands as $code => $label) {
            $html .= '<option value="' . e($code) . '"' . ((string)$selected === $code ? ' selected' : '') . '>' . e($label) . '</option>';
        }
        $html .= '</optgroup>';
    }
    return $html;
}

function require_registrar(array $user, string $perm): void {
    if (!can($perm, $user)) throw new RuntimeException('Only the Registrar can do this.');
}

function current_school_year(): string {
    return (string)(config('school_year') ?? '2026-2027');
}

/** All sections with adviser and head-count, for lists and dropdowns. */
function registrar_sections(): array {
    return db_all(
            "SELECT sec.id, sec.grade_level, sec.name, sec.school_year, sec.adviser_id, sec.strand,
                u.first_name AS adv_first, u.last_name AS adv_last,
                (SELECT COUNT(*) FROM students s WHERE s.section_id = sec.id AND s.status = 'enrolled') AS enrolled
           FROM sections sec LEFT JOIN users u ON u.id = sec.adviser_id
          ORDER BY sec.school_year DESC, sec.grade_level, sec.name"
    );
}

function section_label(array $s): string {
    return 'Grade ' . $s['grade_level'] . ' – ' . $s['name'] . (!empty($s['strand']) ? ' (' . $s['strand'] . ')' : '');
}

/** id => "Grade 8 – Mabini" (current school year first). */
function section_choice_map(string $empty = 'No section yet'): array {
    $map = ['' => $empty];
    foreach (registrar_sections() as $s) {
        $map[$s['id']] = section_label($s) . ($s['school_year'] !== current_school_year() ? ' (' . $s['school_year'] . ')' : '');
    }
    return $map;
}

/* --------------------------- Students --------------------------- */

/** One page of students for the Registrar. Returns [rows, total]. */
function registrar_students_page(string $q, string $sectionId, string $status, int $page, int $perPage = REG_PER_PAGE): array {
    $where = []; $params = [];
    if ($q !== '') {
        $like = '%' . mb_strtolower(addcslashes($q, '\\%_'), 'UTF-8') . '%';
        $where[] = "(LOWER(s.first_name) LIKE ? OR LOWER(s.last_name) LIKE ? OR LOWER(s.first_name || ' ' || s.last_name) LIKE ? OR LOWER(s.student_no) LIKE ? OR s.lrn LIKE ?)";
        array_push($params, $like, $like, $like, $like, $like);
    }
    if ($sectionId === 'none')          { $where[] = 's.section_id IS NULL'; }
    elseif (ctype_digit($sectionId))    { $where[] = 's.section_id = ?'; $params[] = (int)$sectionId; }
    if (isset(STUDENT_STATUSES[$status])) { $where[] = 's.status = ?'; $params[] = $status; }
    $sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $total = (int)db_value("SELECT COUNT(*) FROM students s $sqlWhere", $params);
    $rows = db_all(
        "SELECT s.*, sec.grade_level, sec.name AS section_name
           FROM students s LEFT JOIN sections sec ON sec.id = s.section_id
           $sqlWhere
          ORDER BY s.last_name, s.first_name
          LIMIT " . max(1, $perPage) . " OFFSET " . max(0, ($page - 1) * $perPage),
        $params
    );
    return [$rows, $total];
}

/** Cleans and checks student fields. Returns [clean array, error or null]. */
function student_input(array $in, int $exceptId = 0): array {
    $v = [
        'student_no'        => strtoupper(trim((string)($in['student_no'] ?? ''))),
        'lrn'               => preg_replace('/\D/', '', (string)($in['lrn'] ?? '')),
        'first_name'        => trim((string)($in['first_name'] ?? '')),
        'middle_name'       => trim((string)($in['middle_name'] ?? '')),
        'last_name'         => trim((string)($in['last_name'] ?? '')),
        'birthdate'         => trim((string)($in['birthdate'] ?? '')),
        'sex'               => strtoupper(trim((string)($in['sex'] ?? ''))),
        'section_id'        => trim((string)($in['section_id'] ?? '')),
        'address'           => trim((string)($in['address'] ?? '')),
        'guardian_name'     => trim((string)($in['guardian_name'] ?? '')),
        'guardian_relation' => trim((string)($in['guardian_relation'] ?? '')),
        'guardian_contact'  => trim((string)($in['guardian_contact'] ?? '')),
        'status'            => trim((string)($in['status'] ?? 'enrolled')),
    ];
    $d = DateTime::createFromFormat('Y-m-d', $v['birthdate']);
    $err = null;
    if (!preg_match('/^[A-Z0-9][A-Z0-9-]{3,19}$/', $v['student_no']))      $err = 'Student ID must be 4–20 letters, numbers or dashes (e.g. SV-2026-0841).';
    elseif (db_value('SELECT 1 FROM students WHERE student_no = ? AND id <> ?', [$v['student_no'], $exceptId])) $err = 'Another student already has that Student ID.';
    elseif ($v['lrn'] !== '' && strlen($v['lrn']) !== 12)                     $err = 'The LRN must be exactly 12 digits (or leave it blank).';
    elseif ($v['lrn'] !== '' && db_value('SELECT 1 FROM students WHERE lrn = ? AND id <> ?', [$v['lrn'], $exceptId])) $err = 'Another student already has that LRN.';
    elseif ($v['first_name'] === '' || $v['last_name'] === '')               $err = "Please enter the student's first and last name.";
    elseif (!$d || $d->format('Y-m-d') !== $v['birthdate'])                  $err = 'Please enter a valid birthdate.';
    elseif ($v['birthdate'] >= date('Y-m-d') || $v['birthdate'] < date('Y-m-d', strtotime('-30 years'))) $err = 'That birthdate does not look right.';
    elseif (!in_array($v['sex'], ['F', 'M'], true))                          $err = 'Please choose Female or Male.';
    elseif ($v['section_id'] !== '' && !db_value('SELECT 1 FROM sections WHERE id = ?', [(int)$v['section_id']])) $err = 'Please choose a valid section.';
    elseif (!isset(STUDENT_STATUSES[$v['status']]))                          $err = 'Please choose a status.';
    elseif ($v['guardian_contact'] !== '' && !preg_match('/^\+?[0-9][0-9 ()-]{6,24}$/', $v['guardian_contact'])) $err = 'Guardian contact should be a phone number.';
    foreach (['first_name' => 80, 'middle_name' => 80, 'last_name' => 80, 'address' => 255, 'guardian_name' => 120, 'guardian_relation' => 40] as $k => $max) {
        if (!$err && mb_strlen($v[$k]) > $max) $err = 'One of the fields is too long.';
    }
    foreach (['lrn', 'middle_name', 'address', 'guardian_name', 'guardian_relation', 'guardian_contact', 'section_id'] as $k) {
        if ($v[$k] === '') $v[$k] = null;
    }
    if ($v['section_id'] !== null) $v['section_id'] = (int)$v['section_id'];
    return [$v, $err];
}

/** Adds a student. Returns [id, error]. */
function registrar_create_student(array $user, array $in): array {
    require_registrar($user, 'registrar.students.manage');
    [$v, $err] = student_input($in);
    if ($err) return [null, $err];
    db_run('INSERT INTO students (student_no, lrn, first_name, middle_name, last_name, birthdate, sex, section_id, address, guardian_name, guardian_relation, guardian_contact, status, updated_by, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [$v['student_no'], $v['lrn'], $v['first_name'], $v['middle_name'], $v['last_name'], $v['birthdate'], $v['sex'], $v['section_id'],
         $v['address'], $v['guardian_name'], $v['guardian_relation'], $v['guardian_contact'], $v['status'], $user['id'], now(), now()]);
    $id = (int)db()->lastInsertId();
    audit('create', 'students', $id, $id, array_keys(array_filter($v, fn($x) => $x !== null)), $user);
    return [$id, null];
}

/** Updates a student's information (and section). Returns null or an error. */
function registrar_update_student(array $user, int $id, array $in): ?string {
    require_registrar($user, 'registrar.students.manage');
    $old = db_one('SELECT student_no, lrn, first_name, middle_name, last_name, birthdate, sex, section_id, address, guardian_name, guardian_relation, guardian_contact, status FROM students WHERE id = ?', [$id]);
    if (!$old) return 'Student not found.';
    [$v, $err] = student_input($in, $id);
    if ($err) return $err;
    // v1.4: the section can be set here only when the student has none yet; it is not changed from this page
    if ($old['section_id'] !== null) $v['section_id'] = (int)$old['section_id'];
    $changed = changed_fields($old, $v);
    if (!$changed) return null;
    db_run('UPDATE students SET student_no = ?, lrn = ?, first_name = ?, middle_name = ?, last_name = ?, birthdate = ?, sex = ?, section_id = ?, address = ?,
                                guardian_name = ?, guardian_relation = ?, guardian_contact = ?, status = ?, updated_by = ?, updated_at = ? WHERE id = ?',
        [$v['student_no'], $v['lrn'], $v['first_name'], $v['middle_name'], $v['last_name'], $v['birthdate'], $v['sex'], $v['section_id'], $v['address'],
         $v['guardian_name'], $v['guardian_relation'], $v['guardian_contact'], $v['status'], $user['id'], now(), $id]);
    audit('update', 'students', $id, $id, $changed, $user);
    return null;
}

/** Moves several students into one section (or out of any section with $sectionId = null). Returns [moved, error]. */
function registrar_assign_students(array $user, array $studentIds, ?int $sectionId): array {
    require_registrar($user, 'registrar.sections.manage');
    $ids = array_values(array_unique(array_filter(array_map('intval', $studentIds))));
    if (!$ids) return [0, 'Tick at least one student.'];
    if ($sectionId !== null && !db_value('SELECT 1 FROM sections WHERE id = ?', [$sectionId])) return [0, 'Please choose a valid section.'];
    $moved = 0;
    $pdo = db();
    $pdo->beginTransaction();
    try {
        foreach ($ids as $sid) {
            $cur = db_one('SELECT section_id FROM students WHERE id = ?', [$sid]);
            if (!$cur || (string)$cur['section_id'] === (string)$sectionId) continue;
            db_run('UPDATE students SET section_id = ?, updated_by = ?, updated_at = ? WHERE id = ?', [$sectionId, $user['id'], now(), $sid]);
            audit('update', 'students', $sid, $sid, ['section_id'], $user);
            $moved++;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return [$moved, null];
}

/* --------------------------- Teachers --------------------------- */

/** Teachers with their advisory class. Returns [rows, total]. */
function registrar_teachers_page(string $q, int $page, int $perPage = REG_PER_PAGE): array {
    $where = ["r.code = 'teacher'"]; $params = [];
    if ($q !== '') {
        $like = '%' . mb_strtolower(addcslashes($q, '\\%_'), 'UTF-8') . '%';
        $where[] = "(LOWER(u.first_name) LIKE ? OR LOWER(u.last_name) LIKE ? OR LOWER(u.first_name || ' ' || u.last_name) LIKE ? OR LOWER(u.email) LIKE ?)";
        array_push($params, $like, $like, $like, $like);
    }
    $sqlWhere = implode(' AND ', $where);
    $total = (int)db_value("SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id WHERE $sqlWhere", $params);
    $rows = db_all(
        "SELECT u.id, u.email, u.first_name, u.last_name, u.title, u.phone, u.status,
                (SELECT sec.id FROM sections sec WHERE sec.adviser_id = u.id AND sec.school_year = ? ORDER BY sec.id LIMIT 1) AS advisory_id
           FROM users u JOIN roles r ON r.id = u.role_id
          WHERE $sqlWhere
          ORDER BY u.last_name, u.first_name
          LIMIT " . max(1, $perPage) . " OFFSET " . max(0, ($page - 1) * $perPage),
        array_merge([current_school_year()], $params)
    );
    return [$rows, $total];
}

function teacher_options(string $empty = 'No adviser yet'): array {
    $map = ['' => $empty];
    foreach (db_all("SELECT u.id, u.first_name, u.last_name FROM users u JOIN roles r ON r.id = u.role_id WHERE r.code = 'teacher' AND u.status = 'active' ORDER BY u.last_name, u.first_name") as $t) {
        $map[$t['id']] = $t['last_name'] . ', ' . $t['first_name'];
    }
    return $map;
}

/**
 * Makes $teacherId the adviser of $sectionId (or removes the adviser with null).
 * A teacher advises one section per school year, so any other advisory class
 * of theirs in that year is released. Returns null or an error.
 */
function registrar_set_adviser(array $user, int $sectionId, ?int $teacherId): ?string {
    require_registrar($user, 'registrar.sections.manage');
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $err = set_adviser_in_tx($user, $sectionId, $teacherId);
        $err ? $pdo->rollBack() : $pdo->commit();
        return $err;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** The adviser change itself; the caller owns the transaction. */
function set_adviser_in_tx(array $user, int $sectionId, ?int $teacherId): ?string {
    $sec = db_one('SELECT id, adviser_id, school_year FROM sections WHERE id = ?', [$sectionId]);
    if (!$sec) return 'Section not found.';
    if ($teacherId !== null && db_value("SELECT r.code FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ? AND u.status = 'active'", [$teacherId]) !== 'teacher') {
        return 'Please choose an active teacher.';
    }
    if ((string)$sec['adviser_id'] === (string)$teacherId) return null;
    if ($teacherId !== null) {
        foreach (db_all('SELECT id FROM sections WHERE adviser_id = ? AND school_year = ? AND id <> ?', [$teacherId, $sec['school_year'], $sectionId]) as $other) {
            db_run('UPDATE sections SET adviser_id = NULL WHERE id = ?', [$other['id']]);
            audit('update', 'sections', $other['id'], null, ['adviser_id'], $user);
        }
    }
    db_run('UPDATE sections SET adviser_id = ? WHERE id = ?', [$teacherId, $sectionId]);
    audit('update', 'sections', $sectionId, null, ['adviser_id'], $user);
    return null;
}

/** Updates a teacher's information and advisory class. Returns null or an error. */
function registrar_update_teacher(array $user, int $id, array $in): ?string {
    require_registrar($user, 'registrar.teachers.manage');
    $old = db_one("SELECT u.first_name, u.last_name, u.title, u.phone FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ? AND r.code = 'teacher'", [$id]);
    if (!$old) return 'Teacher not found.';
    $v = ['first_name' => trim((string)($in['first_name'] ?? '')), 'last_name' => trim((string)($in['last_name'] ?? '')),
          'title' => trim((string)($in['title'] ?? '')), 'phone' => trim((string)($in['phone'] ?? ''))];
    if ($v['first_name'] === '' || $v['last_name'] === '') return 'Please enter the first and last name.';
    if (mb_strlen($v['first_name']) > 80 || mb_strlen($v['last_name']) > 80 || mb_strlen($v['title']) > 100) return 'One of the fields is too long.';
    if ($v['phone'] !== '' && !preg_match('/^\+?[0-9][0-9 ()-]{6,24}$/', $v['phone'])) return 'Please enter a valid phone number.';
    $v['title'] = $v['title'] !== '' ? $v['title'] : null;
    $v['phone'] = $v['phone'] !== '' ? $v['phone'] : null;

    $sectionIn = trim((string)($in['advisory_id'] ?? ''));
    if ($sectionIn !== '' && !db_value('SELECT 1 FROM sections WHERE id = ?', [(int)$sectionIn])) return 'Please choose a valid section.';

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $changed = changed_fields($old, $v);
        if ($changed) {
            db_run('UPDATE users SET first_name = ?, last_name = ?, title = ?, phone = ?, updated_at = ? WHERE id = ?',
                [$v['first_name'], $v['last_name'], $v['title'], $v['phone'], now(), $id]);
            audit('update', 'users', $id, null, $changed, $user);
        }
        $current = db_value('SELECT id FROM sections WHERE adviser_id = ? AND school_year = ?', [$id, current_school_year()]);
        if ((string)$current !== $sectionIn) {
            if ($sectionIn === '') {
                db_run('UPDATE sections SET adviser_id = NULL WHERE id = ?', [$current]);
                audit('update', 'sections', $current, null, ['adviser_id'], $user);
            } else {
                $err = set_adviser_in_tx($user, (int)$sectionIn, $id);
                if ($err) { $pdo->rollBack(); return $err; }
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return null;
}

/* --------------------------- Sections --------------------------- */

/** Creates or renames a section. Returns [id, error]. */
function registrar_save_section(array $user, array $in, int $id = 0): array {
    require_registrar($user, 'registrar.sections.manage');
    $grade = (int)($in['grade_level'] ?? 0);
    $name  = trim((string)($in['name'] ?? ''));
    $year  = trim((string)($in['school_year'] ?? current_school_year()));
    if ($grade < 1 || $grade > 12)                         return [null, 'Grade level must be from 1 to 12.'];
    if ($name === '' || mb_strlen($name) > 60)             return [null, 'Please enter a section name (up to 60 characters).'];
    if (!preg_match('/^(\d{4})-(\d{4})$/', $year, $m) || (int)$m[2] !== (int)$m[1] + 1) return [null, 'School year must look like 2026-2027.'];
        // v1.4: Grades 11–12 need a Senior High School track and strand; other grades have none
    $strand = trim((string)($in['strand'] ?? ''));
    if (in_array($grade, SHS_GRADES, true)) {
        if (!isset(shs_strand_labels()[$strand])) return [null, 'Please choose the Senior High School track and strand for Grade ' . $grade . '.'];
    } else {
        $strand = '';
    }
    $strand = $strand !== '' ? $strand : null;
    if (db_value('SELECT 1 FROM sections WHERE grade_level = ? AND LOWER(name) = LOWER(?) AND school_year = ? AND id <> ?', [$grade, $name, $year, $id])) {
        return [null, "Grade $grade – $name already exists for $year."];
    }
    if ($id) {
        $old = db_one('SELECT grade_level, name, school_year, strand FROM sections WHERE id = ?', [$id]);
        if (!$old) return [null, 'Section not found.'];
        $changed = changed_fields($old, ['grade_level' => $grade, 'name' => $name, 'school_year' => $year, 'strand' => $strand]);
        if ($changed) {
            db_run('UPDATE sections SET grade_level = ?, name = ?, school_year = ?, strand = ? WHERE id = ?', [$grade, $name, $year, $strand, $id]);
            audit('update', 'sections', $id, null, $changed, $user);
        }
        return [$id, null];
    }
    db_run('INSERT INTO sections (grade_level, name, school_year, strand) VALUES (?, ?, ?, ?)', [$grade, $name, $year, $strand]);
    $newId = (int)db()->lastInsertId();
    audit('create', 'sections', $newId, null, array_values(array_filter(['grade_level', 'name', 'school_year', $strand !== null ? 'strand' : null])), $user);
    return [$newId, null];
}


/* ---------------------- School staff (v1.4) ---------------------- */

/** Staff the Registrar can record. The Super Admin then creates their sign-in account. */
const STAFF_REGISTRY_ROLES  = ['teacher' => 'Teacher / Adviser', 'clinic' => 'Clinic Staff', 'guidance' => 'Guidance Staff', 'registrar' => 'Registrar Staff'];
const STAFF_REGISTRY_STATUS = ['pending' => ['Waiting for account', 'amber'], 'created' => ['Account created', 'green'], 'cancelled' => ['Cancelled', 'gray']];

/** Cleans and checks a staff record. Returns [clean array, error or null]. */
function staff_registry_input(array $in, int $exceptId = 0): array {
    $v = [
        'role_code'   => trim((string)($in['role_code'] ?? '')),
        'first_name'  => trim((string)($in['first_name'] ?? '')),
        'last_name'   => trim((string)($in['last_name'] ?? '')),
        'email'       => mb_strtolower(trim((string)($in['email'] ?? '')), 'UTF-8'),
        'phone'       => trim((string)($in['phone'] ?? '')),
        'title'       => trim((string)($in['title'] ?? '')),
        'employee_no' => strtoupper(trim((string)($in['employee_no'] ?? ''))),
    ];
    $err = null;
    if (!isset(STAFF_REGISTRY_ROLES[$v['role_code']]))                     $err = 'Please choose the staff role.';
    elseif ($v['first_name'] === '' || $v['last_name'] === '')            $err = 'Please enter the first and last name.';
    elseif (mb_strlen($v['first_name']) > 80 || mb_strlen($v['last_name']) > 80 || mb_strlen($v['title']) > 100) $err = 'One of the fields is too long.';
    elseif (!filter_var($v['email'], FILTER_VALIDATE_EMAIL) || mb_strlen($v['email']) > 190) $err = 'Please enter a valid e-mail address. It becomes their sign-in e-mail.';
    elseif (db_value('SELECT 1 FROM users WHERE LOWER(email) = ?', [$v['email']]))           $err = 'That e-mail already has a BEACON account.';
    elseif (db_value("SELECT 1 FROM staff_registry WHERE email = ? AND status <> 'cancelled' AND id <> ?", [$v['email'], $exceptId])) $err = 'That staff member is already registered.';
    elseif ($v['phone'] !== '' && !preg_match('/^\+?[0-9][0-9 ()-]{6,24}$/', $v['phone']))   $err = 'Please enter a valid phone number.';
    elseif ($v['employee_no'] !== '' && !preg_match('/^[A-Z0-9][A-Z0-9-]{1,29}$/', $v['employee_no'])) $err = 'Employee number may use letters, numbers and dashes only.';
    foreach (['phone', 'title', 'employee_no'] as $k) if ($v[$k] === '') $v[$k] = null;
    return [$v, $err];
}

/** Records a new staff member; they wait for the Super Admin to create their account. Returns [id, error]. */
function registrar_register_staff(array $user, array $in): array {
    require_registrar($user, 'registrar.teachers.manage');
    [$v, $err] = staff_registry_input($in);
    if ($err) return [null, $err];
    db_run("INSERT INTO staff_registry (role_code, first_name, last_name, email, phone, title, employee_no, status, registered_by, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?)",
        [$v['role_code'], $v['first_name'], $v['last_name'], $v['email'], $v['phone'], $v['title'], $v['employee_no'], $user['id'], now(), now()]);
    $id = (int)db()->lastInsertId();
    audit('create', 'staff_registry', $id, null, array_keys(array_filter($v, fn($x) => $x !== null)), $user);
    return [$id, null];
}

/** Edits a staff record that is still waiting for its account. Returns null or an error. */
function registrar_update_staff_entry(array $user, int $id, array $in): ?string {
    require_registrar($user, 'registrar.teachers.manage');
    $old = db_one('SELECT role_code, first_name, last_name, email, phone, title, employee_no, status FROM staff_registry WHERE id = ?', [$id]);
    if (!$old) return 'Staff record not found.';
    if ($old['status'] !== 'pending') return 'Only records still waiting for an account can be edited here.';
    [$v, $err] = staff_registry_input($in, $id);
    if ($err) return $err;
    unset($old['status']);
    $changed = changed_fields($old, $v);
    if (!$changed) return null;
    db_run('UPDATE staff_registry SET role_code = ?, first_name = ?, last_name = ?, email = ?, phone = ?, title = ?, employee_no = ?, updated_at = ? WHERE id = ?',
        [$v['role_code'], $v['first_name'], $v['last_name'], $v['email'], $v['phone'], $v['title'], $v['employee_no'], now(), $id]);
    audit('update', 'staff_registry', $id, null, $changed, $user);
    return null;
}

/** Withdraws a staff record that has no account yet. Returns null or an error. */
function registrar_cancel_staff_entry(array $user, int $id): ?string {
    require_registrar($user, 'registrar.teachers.manage');
    $row = db_one('SELECT status FROM staff_registry WHERE id = ?', [$id]);
    if (!$row) return 'Staff record not found.';
    if ($row['status'] !== 'pending') return 'Only records still waiting for an account can be cancelled.';
    db_run("UPDATE staff_registry SET status = 'cancelled', updated_at = ? WHERE id = ?", [now(), $id]);
    audit('update', 'staff_registry', $id, null, ['status'], $user);
    return null;
}

/** One page of registered staff. Returns [rows, total]. */
function staff_registry_page(string $q, string $role, string $status, int $page, int $perPage = REG_PER_PAGE): array {
    $where = []; $params = [];
    if ($q !== '') {
        $like = '%' . mb_strtolower(addcslashes($q, '\\%_'), 'UTF-8') . '%';
        $where[] = "(LOWER(sr.first_name) LIKE ? OR LOWER(sr.last_name) LIKE ? OR LOWER(sr.first_name || ' ' || sr.last_name) LIKE ? OR sr.email LIKE ? OR LOWER(COALESCE(sr.employee_no, '')) LIKE ?)";
        array_push($params, $like, $like, $like, $like, $like);
    }
    if (isset(STAFF_REGISTRY_ROLES[$role]))    { $where[] = 'sr.role_code = ?'; $params[] = $role; }
    if (isset(STAFF_REGISTRY_STATUS[$status])) { $where[] = 'sr.status = ?';    $params[] = $status; }
    $sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $total = (int)db_value("SELECT COUNT(*) FROM staff_registry sr $sqlWhere", $params);
    $rows = db_all(
        "SELECT sr.*, rb.first_name AS by_first, rb.last_name AS by_last
           FROM staff_registry sr LEFT JOIN users rb ON rb.id = sr.registered_by
           $sqlWhere
          ORDER BY (sr.status = 'pending') DESC, sr.created_at DESC
          LIMIT " . max(1, $perPage) . " OFFSET " . max(0, ($page - 1) * $perPage),
        $params
    );
    return [$rows, $total];
}

/** Staff waiting for the Super Admin to create their account, oldest first. */
function pending_staff_registrations(): array {
    return db_all("SELECT sr.*, rb.first_name AS by_first, rb.last_name AS by_last
                     FROM staff_registry sr LEFT JOIN users rb ON rb.id = sr.registered_by
                    WHERE sr.status = 'pending' ORDER BY sr.created_at");
}

<?php

const PERMISSIONS = [
    'admin.dashboard'          => ['super_admin'],
    'admin.staff.manage'       => ['super_admin'],
    'admin.parents.manage'     => ['super_admin'],
    'admin.audit.view'         => ['super_admin'],
    'admin.students.view'      => ['super_admin'],   // v1.3: read-only Student Records

    'student.search'           => ['teacher', 'guidance', 'clinic'],
    'student.profile.view'     => ['teacher', 'guidance', 'clinic'],
    'commendation.issue'       => ['teacher', 'guidance'],

    'teacher.dashboard'        => ['teacher'],
    'attendance.record'        => ['teacher'],
    'grades.record'            => ['teacher'],
    'incident.create'          => ['teacher'],
    'clinic_pass.issue'        => ['teacher'],
    'guidance.summary.read'    => ['teacher'],

    'guidance.dashboard'       => ['guidance'],
    'guidance.incidents.review'=> ['guidance'],
    'guidance.cases.manage'    => ['guidance'],
    'guidance.notes.confidential' => ['guidance'],
    'guidance.sessions.manage' => ['guidance'],
    'guidance.consent.record'  => ['guidance'],
    'guidance.audit.view'      => ['guidance'],

    'clinic.dashboard'         => ['clinic'],
    'clinic.pass.verify'       => ['clinic'],
    'clinic.visits.manage'     => ['clinic'],
    'clinic.medical.manage'    => ['clinic'],
    'clinic.inventory.manage'  => ['clinic'],

    'parent.portal'            => ['parent'],
    'parent.link.request'      => ['parent'],
    'notices.manage'           => ['super_admin', 'teacher', 'guidance', 'clinic'],

    // v1.3 Registrar portal
    'registrar.students.manage' => ['registrar'],
    'registrar.teachers.manage' => ['registrar'],
    'registrar.sections.manage' => ['registrar'],
];

const PAGES = [
    'system_analytics'  => 'admin.dashboard',
    'admin_staff'       => 'admin.staff.manage',
    'admin_parents'     => 'admin.parents.manage',
    'admin_students'    => 'admin.students.view',
    'user_overview'     => 'teacher.dashboard',
    'log_conduct'       => 'incident.create',
    'student_search'    => 'student.search',
    'guidance_desk'     => 'guidance.dashboard',
    'case_bip_manager'  => 'guidance.cases.manage',
    'clinic_overview'   => 'clinic.dashboard',
    'patient_intake'    => 'clinic.visits.manage',
    'medical_inventory' => 'clinic.inventory.manage',
    'parent_feed'       => 'parent.portal',
    'notice_detail'     => 'parent.portal',
    'parent_records'    => 'parent.portal',
    'notices_manage'    => 'notices.manage',
    'registrar_students' => 'registrar.students.manage',
    'registrar_teachers' => 'registrar.teachers.manage',
    'registrar_sections' => 'registrar.sections.manage',
];

const ROLE_HOME = [
    'super_admin' => 'system_analytics.php',
    'teacher'     => 'user_overview.php',
    'guidance'    => 'guidance_desk.php',
    'clinic'      => 'clinic_overview.php',
    'parent'      => 'parent_welfare_feed.php',
    'registrar'   => 'registrar_students.php',
];

function can(string $permission, ?array $user = null): bool {
    $user = $user ?? current_user();
    if (!$user) return false;
    if (!isset(PERMISSIONS[$permission])) {
        throw new LogicException("Unknown permission: $permission");
    }
    return in_array($user['role'], PERMISSIONS[$permission], true);
}

function home_for(array $user): string {
    return ROLE_HOME[$user['role']] ?? 'login.php';
}

function require_login(): array {
    $user = current_user();
    if (!$user) {
        flash('error', 'Please log in to continue.');
        redirect('login.php');
    }
    if ($user['must_change_password'] && basename($_SERVER['SCRIPT_NAME'] ?? '') !== 'change_password.php') {
        redirect('change_password.php');
    }
    return $user;
}

function require_permission(string $permission): array {
    $user = require_login();
    if (!can($permission, $user)) deny($user, $permission);
    return $user;
}

function require_page(string $page): array {
    if (!isset(PAGES[$page])) {
        throw new LogicException("Page '$page' has no permission mapping in PAGES.");
    }
    return require_permission(PAGES[$page]);
}

function deny(array $user, string $what = ''): void {
    audit('access_denied', null, null, null, $what ? [$what] : [], $user);
    http_response_code(403);
    require __DIR__ . '/forbidden.php';
    exit;
}

function teacher_section_ids(int $teacherId): array {
    $rows = db_all(
        'SELECT id FROM sections WHERE adviser_id = ?
         UNION SELECT section_id FROM section_teachers WHERE teacher_id = ?',
        [$teacherId, $teacherId]
    );
    return array_map('intval', array_column($rows, 'id'));
}

function linked_student_ids(int $parentId): array {
    $rows = db_all("SELECT student_id FROM parent_student_links WHERE parent_id = ? AND status = 'approved'", [$parentId]);
    return array_map('intval', array_column($rows, 'student_id'));
}

function can_access_student(array $user, int $studentId): bool {
    switch ($user['role']) {
        case 'guidance':
        case 'clinic':
            return (bool)db_value('SELECT 1 FROM students WHERE id = ?', [$studentId]);
        case 'teacher':
            $section = db_value('SELECT section_id FROM students WHERE id = ?', [$studentId]);
            return $section !== null && in_array((int)$section, teacher_section_ids((int)$user['id']), true);
        case 'parent':
            return in_array($studentId, linked_student_ids((int)$user['id']), true);
        default:
            return false;
    }
}

function require_student_access(array $user, int $studentId): void {
    if (!can_access_student($user, $studentId)) deny($user, "student:$studentId");
}

<?php
const NAV = [
    ['Administration',        'Admin dashboard',    'system_analytics.php',    'system_analytics',  'chart'],
    ['Administration',        'Staff accounts',     'admin_staff.php',         'admin_staff',       'key'],
    ['Administration',        'Parent registrations','admin_parents.php',      'admin_parents',     'users'],
    ['Administration',        'Student records',    'admin_students.php',      'admin_students',    'clipboard'],
    ['Registrar',             'Students',           'registrar_students.php',  'registrar_students', 'users'],
    ['Registrar',             'Teachers',           'registrar_teachers.php',  'registrar_teachers', 'clipboard'],
    ['Registrar',             'Sections',           'registrar_sections.php',  'registrar_sections', 'grid'],
    ['My Workspace',          'Teacher overview',   'user_overview.php',       'user_overview',     'grid'],
    ['My Workspace',          'Student lookup',     'student_search.php',      'student_search',    'search'],
    ['My Workspace',          'Log conduct',        'log_conduct.php',         'log_conduct',       'clipboard'],
    ['Guidance & Discipline', 'Guidance desk',      'guidance_desk.php',       'guidance_desk',     'shield'],
    ['Guidance & Discipline', 'Case manager',       'case_bip_manager.php',    'case_bip_manager',  'users'],
    ['Health Services',       'Clinic overview',    'clinic_overview.php',     'clinic_overview',   'heart'],
    ['Health Services',       'Patient intake',     'patient_intake.php',      'patient_intake',    'clipboard'],
    ['Health Services',       'Medical inventory',  'medical_inventory.php',   'medical_inventory', 'box'],
    ['Family Portal',         'Family feed',        'parent_welfare_feed.php', 'parent_feed',       'bell'],
    ['Family Portal',         'School records',     'parent_records.php',      'parent_records',    'clipboard'],
    ['Family Portal',         'Family notices',     'notices_manage.php',      'notices_manage',    'bell'],
];

const NAV_ACTIVE_ALIASES = ['overview' => 'user_overview', 'search' => 'student_search', 'conduct' => 'log_conduct',
    'guidance' => 'guidance_desk', 'bip' => 'case_bip_manager', 'clinic' => 'clinic_overview', 'intake' => 'patient_intake',
    'inventory' => 'medical_inventory', 'feed' => 'parent_feed', 'notice' => 'parent_feed', 'records' => 'parent_records', 'notices' => 'notices_manage', 'analytics' => 'system_analytics',
    'staff' => 'admin_staff', 'parents' => 'admin_parents', 'students' => 'admin_students',
    'reg_students' => 'registrar_students', 'reg_teachers' => 'registrar_teachers', 'reg_sections' => 'registrar_sections'];

const ROLE_ICONS = ['super_admin' => 'key', 'teacher' => 'grid', 'guidance' => 'shield', 'clinic' => 'heart', 'parent' => 'users', 'registrar' => 'clipboard'];

function icon(string $name): string {
    $paths = [
        'grid'      => '<rect x="4" y="4" width="6" height="6" rx="1.5"/><rect x="14" y="4" width="6" height="6" rx="1.5"/><rect x="4" y="14" width="6" height="6" rx="1.5"/><rect x="14" y="14" width="6" height="6" rx="1.5"/>',
        'search'    => '<circle cx="11" cy="11" r="6.5"/><path d="M20 20l-4.2-4.2"/>',
        'clipboard' => '<rect x="6" y="4.5" width="12" height="16" rx="2"/><path d="M9.5 4.5h5v2.5h-5zM9 11h6M9 15h4"/>',
        'shield'    => '<path d="M12 3l7 3v5c0 4.5-3 8.3-7 10-4-1.7-7-5.5-7-10V6l7-3z"/>',
        'users'     => '<circle cx="9" cy="8" r="3.5"/><path d="M3 20c0-3.3 2.7-6 6-6s6 2.7 6 6M16 4.6a3.5 3.5 0 0 1 0 6.8M21 20c0-2.8-1.9-5.1-4.5-5.8"/>',
        'heart'     => '<path d="M12 20s-7-4.4-7-10a4 4 0 0 1 7-2.6A4 4 0 0 1 19 10c0 5.6-7 10-7 10z"/>',
        'box'       => '<path d="M12 3l8 4.5v9L12 21l-8-4.5v-9L12 3zM4 7.5l8 4.5 8-4.5M12 12v9"/>',
        'bell'      => '<path d="M6 16V11a6 6 0 0 1 12 0v5l1.5 2h-15L6 16zM10 20a2 2 0 0 0 4 0"/>',
        'chart'     => '<path d="M4 20h16M7 16v-5M12 16V6M17 16v-8"/>',
        'key'       => '<circle cx="8" cy="15" r="4"/><path d="M11 12l8-8M16 7l2 2"/>',
        'logout'    => '<path d="M15 4h3a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-3M10 16l4-4-4-4M14 12H4"/>',
        'panel'     => '<rect x="4" y="5" width="16" height="14" rx="2"/><path d="M10 5v14"/>',
    ];
    return '<svg class="ico" viewBox="0 0 24 24" aria-hidden="true">' . ($paths[$name] ?? '') . '</svg>';
}

function opts(array $map, $selected = null): string {
    $html = '';
    foreach ($map as $v => $l) {
        $html .= '<option value="' . e($v) . '"' . ((string)$selected === (string)$v ? ' selected' : '') . '>' . e($l) . '</option>';
    }
    return $html;
}

function student_picker(string $id, array $students, $selected = '', bool $required = true): string {
    $grades = $sections = [];
    $pick = ['grade' => '', 'section' => ''];
    $stu = '';
    foreach ($students as $s) {
        $g = (string)($s['grade_level'] ?? 0); $sec = (string)($s['section_id'] ?? 0);
        $grades[$g] = $g === '0' ? 'No grade' : 'Grade ' . $g;
        $sections[$sec] = [$g, $s['section_name'] ?? 'No section'];
        $isSel = (string)$selected !== '' && (string)$selected === (string)$s['id'];
        if ($isSel) $pick = ['grade' => $g, 'section' => $sec];
        $stu .= '<option value="' . (int)$s['id'] . '" data-section="' . e($sec) . '"' . ($isSel ? ' selected' : '') . '>'
            . e($s['last_name'] . ', ' . $s['first_name'] . ' · ' . $s['student_no']) . '</option>';
    }
    ksort($grades, SORT_NUMERIC);
    uasort($sections, fn($a, $b) => strcmp($a[1], $b[1]));
    $secHtml = '';
    foreach ($sections as $k => [$g, $n]) {
        $secHtml .= '<option value="' . e($k) . '" data-grade="' . e($g) . '"' . ((string)$k === $pick['section'] ? ' selected' : '') . '>' . e($n) . '</option>';
    }
    return '<div class="student-picker" data-student-picker>'
        . '<select data-pick="grade" aria-label="Grade level"><option value="">Grade level…</option>' . opts($grades, $pick['grade']) . '</select>'
        . '<select data-pick="section" aria-label="Section"><option value="">Section…</option>' . $secHtml . '</select>'
        . '<select id="' . e($id) . '" name="student_id" data-pick="student"' . ($required ? ' required' : '') . '><option value="">Choose a student…</option>' . $stu . '</select>'
        . '</div>';
}

/**
 * Page maths for a list: which page we're on, how many pages, and the row offset.
 * Reads the page number from the query string ($param).
 */
function paginate(int $total, int $perPage, string $param = 'page'): array {
    $pages = max(1, (int)ceil($total / max(1, $perPage)));
    $page  = min($pages, max(1, (int)($_GET[$param] ?? 1)));
    return ['page' => $page, 'pages' => $pages, 'offset' => ($page - 1) * $perPage, 'total' => $total, 'per' => $perPage];
}

/** Previous / page numbers / Next links. Keeps the other query-string values. */
function pager(array $p, string $param = 'page', string $anchor = ''): string {
    if ($p['pages'] <= 1) return '';
    $base = basename($_SERVER['SCRIPT_NAME'] ?? '');
    $url = function (int $n) use ($param, $anchor, $base): string {
        $q = $_GET; $q[$param] = $n;
        if ($n === 1) unset($q[$param]);
        return e($base . ($q ? '?' . http_build_query($q) : '') . ($anchor !== '' ? '#' . $anchor : ''));
    };
    $from = $p['offset'] + 1; $to = min($p['total'], $p['offset'] + $p['per']);
    $html = '<nav class="pager" aria-label="Pages"><span class="pager-info">Showing ' . $from . '–' . $to . ' of ' . $p['total'] . '</span><div class="pager-links">';
    $html .= $p['page'] > 1 ? '<a class="pager-btn" href="' . $url($p['page'] - 1) . '" rel="prev">‹ Prev</a>' : '<span class="pager-btn disabled" aria-disabled="true">‹ Prev</span>';
    $shown = array_unique(array_filter([1, $p['page'] - 1, $p['page'], $p['page'] + 1, $p['pages']], fn($n) => $n >= 1 && $n <= $p['pages']));
    sort($shown);
    $last = 0;
    foreach ($shown as $n) {
        if ($last && $n > $last + 1) $html .= '<span class="pager-gap" aria-hidden="true">…</span>';
        $html .= $n === $p['page']
            ? '<span class="pager-btn current" aria-current="page">' . $n . '</span>'
            : '<a class="pager-btn" href="' . $url($n) . '" aria-label="Page ' . $n . '">' . $n . '</a>';
        $last = $n;
    }
    $html .= $p['page'] < $p['pages'] ? '<a class="pager-btn" href="' . $url($p['page'] + 1) . '" rel="next">Next ›</a>' : '<span class="pager-btn disabled" aria-disabled="true">Next ›</span>';
    return $html . '</div></nav>';
}

function badge(string $text, string $tone = 'gray', string $title = ''): string {
    return '<span class="badge badge-' . e($tone) . '"' . ($title !== '' ? ' title="' . e($title) . '"' : '') . '>' . e($text) . '</span>';
}

function wellbeing_badge(array $status): string {
    $lvl = WELLBEING_LEVELS[$status['level']];
    return '<span class="wb-pill wb-' . e($status['level']) . '" title="' . e(implode(' · ', $status['reasons']) ?: $lvl['label']) . '">'
        . '<span class="wb-dot" aria-hidden="true"></span>' . e($lvl['label']) . '</span>';
}

function layout_start($user, $activeKey, $title, $subtitle = '', array $opts = []) {
    $flashes = flash_pull();
    $active = NAV_ACTIVE_ALIASES[$activeKey] ?? $activeKey;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($title) ?> · BEACON</title>
    <link rel="icon" href="assets/img/tab-logo.svg">
    <link rel="stylesheet" href="./index.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=DM+Sans:wght@400;500;600;700&display=swap">
    <script>try { if (localStorage.getItem('beacon.sidebar') === 'closed') document.documentElement.classList.add('sidebar-closed'); } catch (e) {}</script>
</head>
<body class="role-<?= e($user['role']) ?>">
    <div class="system-analytics">
        <nav class="shell2" aria-label="Main">
            <div class="sidebar-top">
                <img class="sidebar-wordmark" src="assets/img/logo-wordmark-white.svg" alt="BEACON">
            </div>

            <div class="navigation">
<?php
    $lastSection = null;
    foreach (NAV as [$section, $label, $href, $page, $ico]) {
        if (!can(PAGES[$page], $user)) continue;
        if ($section !== $lastSection) {
            echo '<span class="nav-section-title">' . e($section) . "</span>\n";
            $lastSection = $section;
        }
        $isActive = $page === $active;
        echo '<a class="nav-link' . ($isActive ? ' active' : '') . '" href="' . e($href) . '"' . ($isActive ? ' aria-current="page"' : '') . '>'
            . icon($ico) . '<span>' . e($label) . "</span></a>\n";
    }
?>
            </div>

            <div class="sidebar-user">
                <div class="sidebar-user-avatar"><?= e($user['initials']) ?></div>
                <div class="sidebar-user-info">
                    <div class="sidebar-user-name"><?= e($user['name']) ?></div>
                    <div class="sidebar-user-role"><?= e($user['title'] ?: $user['role_label']) ?></div>
                </div>
                <a class="sidebar-user-btn" href="change_password.php" title="Change password"><?= icon('key') ?></a>
                <button type="button" class="sidebar-user-btn" data-open="modalLogout" title="Log out" aria-label="Log out" aria-haspopup="dialog"><?= icon('logout') ?></button>
            </div>
        </nav>

        <div class="shell">
            <header class="header">
                <div class="header-left">
                    <button type="button" class="header-icon-btn sidebar-toggle" data-sidebar-toggle title="Show or hide the sidebar" aria-label="Toggle sidebar"><?= icon('panel') ?></button>
                    <div>
                        <h1 class="header-greeting"><?= e($title) ?></h1>
                        <?php if ($subtitle): ?><p class="header-sub"><?= e($subtitle) ?></p><?php endif; ?>
                    </div>
                </div>
                <div class="header-actions">
                    <?php if (!empty($opts['search'])): ?>
                        <label class="header-search">
                            <?= icon('search') ?>
                            <input type="search" id="<?= e($opts['search']['id'] ?? 'pageSearch') ?>" placeholder="<?= e($opts['search']['placeholder'] ?? 'Search this page…') ?>" autocomplete="off" aria-label="<?= e($opts['search']['placeholder'] ?? 'Search this page') ?>">
                        </label>
                    <?php elseif (can('student.search', $user)): ?>
                        <a class="header-icon-btn" href="student_search.php" title="Search students"><?= icon('search') ?></a>
                    <?php endif; ?>
                    <span class="role-badge" title="You are signed in with <?= e($user['role_label']) ?> access"><?= icon(ROLE_ICONS[$user['role']] ?? 'users') ?><?= e($user['role_label']) ?> access</span>
                    <div class="user-avatar-pill" title="<?= e($user['name']) ?>"><?= e($user['initials']) ?></div>
                </div>
            </header>

            <?php foreach ($flashes as $f): ?>
                <div class="flash flash-<?= $f['type'] === 'success' ? 'ok' : 'err' ?>" role="status"><?= e($f['msg']) ?></div>
            <?php endforeach; ?>

            <main class="main-content">
<?php
}

function layout_end() {
?>
            </main>
        </div>
    </div>
    <?= logout_modal() ?>
    <script src="assets/js/beacon.js"></script>
</body>
</html>
<?php
}

<?php
declare(strict_types=1);

require __DIR__ . '/db.php';
date_default_timezone_set(config('timezone') ?: 'Asia/Manila');

require __DIR__ . '/audit.php';
require __DIR__ . '/crypto.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/rbac.php';
require __DIR__ . '/privacy.php';
require __DIR__ . '/auth_shell.php';
require __DIR__ . '/students.php';
require __DIR__ . '/guidance.php';
require __DIR__ . '/clinic.php';
require __DIR__ . '/admin.php';
require __DIR__ . '/family.php';
require __DIR__ . '/registrar.php';

start_secure_session();

if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
}

if (!isset($_SESSION['beacon_state'])) {
    $_SESSION['beacon_state'] = [
        'students' => [
            'SV-2026-0841' => [
                'id' => 'SV-2026-0841',
                'name' => 'Andrea Cruz',
                'lrn' => '136102930188',
                'grade' => '8',
                'section' => 'Mabini',
                'adviser' => 'Ms. Mariel Reyes',
                'today' => 'Present',
                'flag' => 'Guidance plan',
                'flag_tone' => 'purple',
                'dob' => 'April 12, 2012',
                'gender' => 'Female',
                'guardian' => 'Elena Cruz (Mother)',
                'contact' => '0917 555 0123',
                'address' => 'Brgy. Poblacion, Makati City',
                'attendance_rate' => '96%'
            ],
            'SV-2026-0914' => [
                'id' => 'SV-2026-0914',
                'name' => 'Joaquin Mendoza',
                'lrn' => '136102930192',
                'grade' => '8',
                'section' => 'Mabini',
                'adviser' => 'Ms. Mariel Reyes',
                'today' => 'Late',
                'flag' => 'Asthma alert',
                'flag_tone' => 'blue',
                'dob' => 'May 14, 2012',
                'gender' => 'Male',
                'guardian' => 'Ramon Mendoza (Father)',
                'contact' => '0918 442 1093',
                'address' => 'Purok 4, Makati City',
                'attendance_rate' => '98%'
            ],
            'SV-2026-1032' => [
                'id' => 'SV-2026-1032',
                'name' => 'Leila Santos',
                'lrn' => '136102930205',
                'grade' => '8',
                'section' => 'Mabini',
                'adviser' => 'Ms. Mariel Reyes',
                'today' => 'Present',
                'flag' => 'No active flags',
                'flag_tone' => 'gray',
                'dob' => 'September 8, 2012',
                'gender' => 'Female',
                'guardian' => 'Clara Santos (Mother)',
                'contact' => '0919 888 3412',
                'address' => 'Salcedo Village, Makati City',
                'attendance_rate' => '100%'
            ],
            'SV-2026-0755' => [
                'id' => 'SV-2026-0755',
                'name' => 'Nathan Ramos',
                'lrn' => '136102930174',
                'grade' => '8',
                'section' => 'Mabini',
                'adviser' => 'Ms. Mariel Reyes',
                'today' => 'Absent',
                'flag' => 'Food allergy',
                'flag_tone' => 'blue',
                'dob' => 'January 20, 2012',
                'gender' => 'Male',
                'guardian' => 'Jose Ramos (Father)',
                'contact' => '0922 111 4567',
                'address' => 'Legaspi Village, Makati City',
                'attendance_rate' => '92%'
            ]
        ]
    ];
}

if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }

function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($_SESSION['csrf_token']) . '">';
}
function verify_csrf(): void {
    if (!hash_equals($_SESSION['csrf_token'] ?? '', (string)($_POST['csrf_token'] ?? ''))) {
        flash('error', 'Your form expired. Please try again.');
        redirect(basename($_SERVER['SCRIPT_NAME'] ?? 'login.php'));
    }
}
function S() { return $_SESSION['beacon_state']; }
function is_post(): bool { return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'; }
function post($k, $d = null) { return $_POST[$k] ?? $d; }
function query($k, $d = null) { return $_GET[$k] ?? $d; }
function redirect(string $url): void { header("Location: $url"); exit; }
function e($str): string { return htmlspecialchars((string)($str ?? ''), ENT_QUOTES, 'UTF-8'); }
function flash(string $type, string $msg): void { $_SESSION['flash'][] = ['type' => $type, 'msg' => $msg]; }
function flash_pull(): array { $f = $_SESSION['flash'] ?? []; $_SESSION['flash'] = []; return $f; }
function student($id) { return S()['students'][$id] ?? null; }

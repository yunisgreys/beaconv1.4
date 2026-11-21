<?php
return [
    'app_name' => 'BEACON',
    'timezone' => 'Asia/Manila',

    'school_name'    => 'Christ Life Academy',
    'school_year'    => '2026-2027',
    'system_tagline' => 'Student Welfare & Care System',

    'https'    => false,

    'db' => [
        'dsn'  => 'mysql:host=127.0.0.1;port=3306;dbname=beacon;charset=utf8mb4',
        'user' => 'root',
        'pass' => '',
    ],

    'encryption_key' => '',

    'session' => [
        'idle_timeout'     => 30 * 60,
        'absolute_timeout' => 8 * 60 * 60,
    ],

    'login_throttle' => [
        'window_minutes'  => 15,
        'max_per_email'   => 3,   // v1.3: fixed at 3 in inc/auth.php (LOGIN_MAX_FAILS)
        'max_per_ip'      => 20,
    ],
];

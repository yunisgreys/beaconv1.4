<?php
/*
 * Database access for BEACON.
 *
 * Connects to Supabase PostgreSQL using config/supabase.php (or the
 * DATABASE_URL environment variable). Pages keep using the same helpers:
 * db_run(), db_one(), db_all(), db_value() and db()->lastInsertId().
 *
 * If config/supabase.php is missing, the old config/config.php ['db'] DSN
 * is used instead (kept only for local testing).
 */

function config(?string $key = null) {
    static $cfg;
    if ($cfg === null) {
        $file = __DIR__ . '/../config/config.php';
        if (!is_file($file)) {
            http_response_code(500);
            exit('BEACON is not configured yet: copy config/config.example.php to config/config.php.');
        }
        $cfg = require $file;
    }
    return $key === null ? $cfg : ($cfg[$key] ?? null);
}

/** Settings from config/supabase.php, or null when that file doesn't exist. */
function supabase_config(?string $key = null) {
    static $cfg = false;
    if ($cfg === false) {
        $file = __DIR__ . '/../config/supabase.php';
        $cfg = is_file($file) ? require $file : null;
    }
    if ($cfg === null) return null;
    return $key === null ? $cfg : ($cfg[$key] ?? null);
}

/**
 * Turns a postgresql:// URL into PDO connection settings.
 * Returns ['dsn' => ..., 'user' => ..., 'pass' => ...].
 */
function db_settings_from_url(string $url, array $opts = []): array {
    if (str_contains($url, 'YOUR-PROJECT-REF') || str_contains($url, 'YOUR-DATABASE-PASSWORD') || str_contains($url, '[YOUR-PASSWORD]')) {
        setup_error('Add your Supabase connection string.',
            'Open <code>config/supabase.php</code> and replace the sample <code>database_url</code> with the '
            . '<b>Session pooler</b> URI from Supabase (Connect → Session pooler), with your database password filled in.');
    }
    $p = parse_url($url);
    if ($p === false || !in_array($p['scheme'] ?? '', ['postgres', 'postgresql'], true) || empty($p['host'])) {
        setup_error('The Supabase connection string is not valid.',
            'It should start with <code>postgresql://</code>, for example '
            . '<code>postgresql://postgres.abcd1234:PASSWORD@aws-0-ap-southeast-1.pooler.supabase.com:5432/postgres</code>. '
            . 'If your password contains <code>@ : / ? #</code> or <code>%</code>, URL-encode it (for example <code>@</code> becomes <code>%40</code>).');
    }
    parse_str($p['query'] ?? '', $query);
    $sslmode = $query['sslmode'] ?? ($opts['sslmode'] ?? 'require');
    $timeout = (int)($query['connect_timeout'] ?? ($opts['connect_timeout'] ?? 10));
    $dbname  = ltrim($p['path'] ?? '', '/') ?: 'postgres';

    $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s;sslmode=%s;connect_timeout=%d;application_name=beacon',
        $p['host'], (int)($p['port'] ?? 5432), $dbname, $sslmode, max(1, $timeout));

    return [
        'dsn'  => $dsn,
        'user' => rawurldecode($p['user'] ?? 'postgres'),
        'pass' => rawurldecode($p['pass'] ?? ''),
    ];
}

/** Connection settings: Supabase first, then the legacy config.php DSN. */
function db_settings(): array {
    $url = supabase_config('database_url');
    if (is_string($url) && $url !== '') {
        return db_settings_from_url($url, supabase_config() ?? []);
    }
    $c = config('db');
    if (!$c || empty($c['dsn'])) {
        setup_error('No database is configured.',
            'Create <code>config/supabase.php</code> with your Supabase Session pooler connection string.');
    }
    return ['dsn' => $c['dsn'], 'user' => $c['user'] ?? null, 'pass' => $c['pass'] ?? null];
}

function db(): PDO {
    static $pdo;
    if ($pdo !== null) return $pdo;

    $c = db_settings();
    $driver = strtok($c['dsn'], ':');
    if (!in_array($driver, PDO::getAvailableDrivers(), true)) {
        $ini = php_ini_loaded_file() ?: 'none loaded';
        setup_error("This PHP doesn't have the <b>pdo_$driver</b> extension turned on.",
            'In XAMPP: Control Panel → Apache → Config → <code>PHP (php.ini)</code>. Find these lines and remove the <code>;</code> at the start:'
            . '<br><code>extension=pdo_' . $driver . '</code>' . ($driver === 'pgsql' ? '<br><code>extension=pgsql</code>' : '')
            . '<br>Save the file, then Stop and Start Apache.'
            . '<br><br>php.ini in use: <code>' . htmlspecialchars($ini, ENT_QUOTES, 'UTF-8') . '</code>'
            . '<br>PHP binary: <code>' . htmlspecialchars(PHP_BINARY, ENT_QUOTES, 'UTF-8') . '</code>');
    }

    try {
        $pdo = new PDO($c['dsn'], $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        $pdo = null;
        db_connect_error($e, $driver);
    }

    if ($driver === 'pgsql') {
        $tz = supabase_config('timezone') ?: (config('timezone') ?: 'Asia/Manila');
        $pdo->exec("SET client_encoding TO 'UTF8'");
        $pdo->prepare('SELECT set_config(\'timezone\', ?, false)')->execute([$tz]);
    } elseif ($driver === 'sqlite') {
        $pdo->exec('PRAGMA foreign_keys = ON');
    }
    return $pdo;
}

/** Which database engine is in use: 'pgsql', 'mysql' or 'sqlite'. */
function db_driver(): string {
    return db()->getAttribute(PDO::ATTR_DRIVER_NAME);
}

/** Explains a failed connection in plain words (never shows the password). */
function db_connect_error(PDOException $e, string $driver): void {
    $msg  = $e->getMessage();
    $safe = htmlspecialchars($msg, ENT_QUOTES, 'UTF-8');
    $hint = 'Check the connection string in <code>config/supabase.php</code>.';

    if ($driver === 'pgsql') {
        if (stripos($msg, 'translate host name') !== false || stripos($msg, 'Name or service not known') !== false
            || stripos($msg, 'No such host') !== false || stripos($msg, 'Network is unreachable') !== false) {
            $hint = 'PHP could not reach that host. Use the <b>Session pooler</b> URI '
                  . '(<code>aws-0-…pooler.supabase.com:5432</code>), not the Direct connection '
                  . '(<code>db.…supabase.co</code>, which needs IPv6). Also check your internet connection.';
        } elseif (stripos($msg, 'password authentication failed') !== false || stripos($msg, 'Tenant or user not found') !== false) {
            $hint = 'Supabase rejected the user name or password. On the Session pooler the user is '
                  . '<code>postgres.YOUR-PROJECT-REF</code> (with the dot), and the password is your <b>database</b> password, '
                  . 'not your Supabase login. You can reset it in Project Settings → Database.';
        } elseif (stripos($msg, 'timeout') !== false || stripos($msg, 'timed out') !== false) {
            $hint = 'The connection timed out. Check your internet connection, that the Supabase project is not paused '
                  . '(free projects pause after a week without use; restore it from the dashboard), and that port 5432 is not blocked.';
        } elseif (stripos($msg, 'SSL') !== false) {
            $hint = 'The secure (SSL) connection failed. Make sure <code>sslmode</code> is <code>require</code> in <code>config/supabase.php</code>.';
        } elseif (stripos($msg, 'does not exist') !== false) {
            $hint = 'The database name is wrong. On Supabase it is <code>postgres</code> (the part after the last <code>/</code> in the URI).';
        }
    } elseif ($driver === 'mysql') {
        $hint = 'Check that MySQL is running in the XAMPP Control Panel and that the settings in <code>config/config.php</code> are right.';
    }

    setup_error("BEACON can't connect to the database.", $hint . '<br><br>Details: <code>' . $safe . '</code>');
}

function db_run(string $sql, array $params = []): PDOStatement {
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

function db_one(string $sql, array $params = []): ?array {
    $row = db_run($sql, $params)->fetch();
    return $row === false ? null : $row;
}

function db_all(string $sql, array $params = []): array {
    return db_run($sql, $params)->fetchAll();
}

function db_value(string $sql, array $params = []) {
    $v = db_run($sql, $params)->fetchColumn();
    return $v === false ? null : $v;
}

function setup_error(string $title, string $help): void {
    http_response_code(500);
    if (PHP_SAPI === 'cli') { fwrite(STDERR, strip_tags("$title\n$help\n")); exit(1); }
    echo '<!DOCTYPE html><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Setup needed · BEACON</title>'
       . '<div style="font-family:system-ui,sans-serif;max-width:640px;margin:80px auto;padding:28px 32px;border:1px solid #e2e6ef;border-radius:16px;line-height:1.6;color:#0f172a">'
       . '<p style="margin:0 0 6px;font-size:.75rem;font-weight:800;letter-spacing:.12em;color:#1948A5">SETUP NEEDED</p>'
       . "<h1 style=\"font-size:1.3rem;margin:0 0 12px\">$title</h1><p style=\"margin:0;color:#475569;font-size:.92rem\">$help</p></div>";
    exit;
}

function now(): string { return date('Y-m-d H:i:s'); }

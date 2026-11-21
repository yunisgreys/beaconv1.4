<?php

const DUMMY_PASSWORD_HASH = '$2y$10$uKpNCe3YnWc69M4BU92kYeowXzAmSCEJLfed5xSmOFpvtOEdxW/Eu';

function start_secure_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    if (PHP_SAPI === 'cli') { $_SESSION = $_SESSION ?? []; return; }

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name('BEACONSESSID');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => (bool)config('https'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function regenerate_session(): void {
    if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
}

function current_user(bool $refresh = false): ?array {
    static $cache = false;
    if ($refresh) $cache = false;
    if ($cache !== false) return $cache;

    $uid = $_SESSION['uid'] ?? null;
    if (!$uid) return $cache = null;

    $t = config('session');
    $now = time();
    if ($now - ($_SESSION['last_seen'] ?? 0) > $t['idle_timeout'] || $now - ($_SESSION['login_at'] ?? 0) > $t['absolute_timeout']) {
        end_session();
        flash('error', 'Your session expired. Please log in again.');
        return $cache = null;
    }
    $_SESSION['last_seen'] = $now;

    $row = db_one(
        'SELECT u.id, u.email, u.first_name, u.last_name, u.title, u.status, u.must_change_password, u.last_seen_at, r.code AS role, r.label AS role_label
           FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ?',
        [$uid]
    );
    if (!$row || $row['status'] !== 'active' || $row['role'] !== ($_SESSION['role'] ?? null)) {
        end_session();
        return $cache = null;
    }
    if (!$row['last_seen_at'] || strtotime($row['last_seen_at']) < $now - 60) {
        db_run('UPDATE users SET last_seen_at = ? WHERE id = ?', [now(), $row['id']]);
    }
    $row['name'] = $row['first_name'] . ' ' . $row['last_name'];
    $row['initials'] = strtoupper(mb_substr($row['first_name'], 0, 1) . mb_substr($row['last_name'], 0, 1));
    return $cache = $row;
}

function normalize_email(string $email): string {
    return strtolower(trim($email));
}

/** v1.3: sign-in locks after this many wrong passwords in a row. */
const LOGIN_MAX_FAILS = 3;
/** v1.3: self-service password changes allowed before Super Admin approval is needed. */
const PASSWORD_CHANGE_LIMIT = 3;

function login_window_minutes(): int {
    return (int)(config('login_throttle')['window_minutes'] ?? 15);
}

/**
 * Failed sign-ins for this e-mail since its last successful sign-in, within the
 * lock window. Works the same for e-mails that don't exist, so the messages
 * never reveal whether an account exists.
 * Returns ['count' => int, 'oldest' => ?string].
 */
function login_failures(string $email): array {
    $since = date('Y-m-d H:i:s', time() - login_window_minutes() * 60);
    $lastOk = db_value('SELECT MAX(attempted_at) FROM login_attempts WHERE email = ? AND succeeded = 1', [$email]);
    if ($lastOk && $lastOk > $since) $since = $lastOk;
    $row = db_one('SELECT COUNT(*) AS n, MIN(attempted_at) AS oldest FROM login_attempts
                    WHERE email = ? AND succeeded = 0 AND attempted_at > ?', [$email, $since]);
    return ['count' => (int)($row['n'] ?? 0), 'oldest' => $row['oldest'] ?? null];
}

/** Minutes until a locked e-mail can try again (at least 1). */
function login_lock_minutes_left(?string $oldestFailure): int {
    if (!$oldestFailure) return login_window_minutes();
    $unlockAt = strtotime($oldestFailure) + login_window_minutes() * 60;
    return max(1, (int)ceil(($unlockAt - time()) / 60));
}

function login_ip_throttled(): bool {
    $t = config('login_throttle');
    $since = date('Y-m-d H:i:s', time() - login_window_minutes() * 60);
    $byIp = (int)db_value('SELECT COUNT(*) FROM login_attempts WHERE ip_address = ? AND succeeded = 0 AND attempted_at >= ?', [client_ip(), $since]);
    return $byIp >= (int)($t['max_per_ip'] ?? 20);
}

/** Kept for older callers: true when this e-mail or this computer is locked out. */
function login_is_throttled(string $email): bool {
    return login_failures(normalize_email($email))['count'] >= LOGIN_MAX_FAILS || login_ip_throttled();
}

function attempt_login(string $email, string $password): array {
    $email = normalize_email($email);
    $generic = 'Incorrect e-mail or password.';

    if ($email === '' || $password === '') {
        return ['ok' => false, 'error' => 'Enter your e-mail and password.'];
    }

    $fails = login_failures($email);
    if ($fails['count'] >= LOGIN_MAX_FAILS) {
        $mins = login_lock_minutes_left($fails['oldest']);
        return ['ok' => false, 'locked' => true,
                'error' => "Sign-in is locked after " . LOGIN_MAX_FAILS . " failed attempts. Try again in $mins minute" . ($mins === 1 ? '' : 's') . ", or ask the Super Admin to unlock your account."];
    }
    if (login_ip_throttled()) {
        $mins = login_window_minutes();
        return ['ok' => false, 'locked' => true, 'error' => "Too many failed sign-ins from this computer. Please wait $mins minutes and try again."];
    }

    $user = db_one(
        'SELECT u.id, u.password_hash, u.status, r.code AS role FROM users u JOIN roles r ON r.id = u.role_id WHERE u.email = ?',
        [$email]
    );
    $valid = password_verify($password, $user['password_hash'] ?? DUMMY_PASSWORD_HASH) && $user !== null;

    db_run('INSERT INTO login_attempts (email, ip_address, succeeded, attempted_at) VALUES (?, ?, ?, ?)',
        [$email, client_ip(), $valid ? 1 : 0, now()]);

    if (!$valid) {
        $count = $fails['count'] + 1;
        if ($user) {
            $lockedUntil = $count >= LOGIN_MAX_FAILS ? date('Y-m-d H:i:s', time() + login_window_minutes() * 60) : null;
            db_run('UPDATE users SET failed_login_attempts = ?, last_failed_login_at = ?, locked_until = ? WHERE id = ?',
                [$count, now(), $lockedUntil, $user['id']]);
        }
        audit('login_failed', 'users', $user['id'] ?? null, null, [], ['id' => null, 'role' => null]);

        $left = LOGIN_MAX_FAILS - $count;
        if ($left <= 0) {
            return ['ok' => false, 'locked' => true,
                    'error' => "Incorrect e-mail or password. Sign-in is now locked for " . login_window_minutes() . " minutes."];
        }
        return ['ok' => false, 'error' => "$generic You have $left attempt" . ($left === 1 ? '' : 's') . ' left.'];
    }
    if ($user['status'] !== 'active') {
        audit('login_blocked_disabled', 'users', $user['id'], null, [], ['id' => $user['id'], 'role' => $user['role']]);
        return ['ok' => false, 'error' => 'This account has been disabled. Please contact the school administrator.'];
    }

    if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
        db_run('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $user['id']]);
    }
    db_run('UPDATE users SET last_login_at = ?, last_seen_at = ?, failed_login_attempts = 0, locked_until = NULL WHERE id = ?',
        [now(), now(), $user['id']]);

    regenerate_session();
    $_SESSION['uid']       = (int)$user['id'];
    $_SESSION['role']      = $user['role'];
    $_SESSION['login_at']  = time();
    $_SESSION['last_seen'] = time();
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

    $me = current_user(true);
    audit('login', 'users', $me['id'], null, [], $me);
    return ['ok' => true, 'user' => $me];
}

/** Super Admin: clear a sign-in lock (writes a "success" marker so old failures stop counting). */
function unlock_login(int $userId, array $admin): void {
    $email = db_value('SELECT email FROM users WHERE id = ?', [$userId]);
    if (!$email) return;
    db_run('INSERT INTO login_attempts (email, ip_address, succeeded, attempted_at) VALUES (?, ?, 1, ?)', [$email, 'admin-unlock', now()]);
    db_run('UPDATE users SET failed_login_attempts = 0, locked_until = NULL WHERE id = ?', [$userId]);
    audit('login_unlock', 'users', $userId, null, ['failed_login_attempts', 'locked_until'], $admin);
}

function end_session(): void {
    $_SESSION = [];
    if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_ACTIVE) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        session_destroy();
        session_start();
        session_regenerate_id(true);
    }
}

function logout(): void {
    if ($me = current_user()) {
        audit('logout', 'users', $me['id'], null, [], $me);
        db_run('UPDATE users SET last_seen_at = NULL WHERE id = ?', [$me['id']]);
    }
    end_session();
}

function password_problem(string $password, string $email = ''): ?string {
    if (strlen($password) < 10)                      return 'Use at least 10 characters.';
    if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password))
                                                     return 'Use a mix of letters and numbers.';
    if ($email !== '' && stripos($password, strstr($email, '@', true) ?: $email) !== false)
                                                     return 'Your password should not contain your e-mail name.';
    return null;
}

/**
 * Where a user stands with the 3-change limit.
 * Returns ['used' => int, 'left' => int, 'needs_approval' => bool,
 *          'approved' => bool, 'pending' => bool, 'forced' => bool].
 * Forced changes (temporary password from the Super Admin) never count.
 */
function password_change_status(array $user): array {
    $row = db_one('SELECT must_change_password, password_change_count, password_change_approval_required FROM users WHERE id = ?', [$user['id']]);
    $used   = (int)($row['password_change_count'] ?? 0);
    $forced = (int)($row['must_change_password'] ?? 0) === 1;
    $needs  = !$forced && ((int)($row['password_change_approval_required'] ?? 0) === 1 || $used >= PASSWORD_CHANGE_LIMIT);
    $req    = db_value("SELECT status FROM password_change_requests WHERE user_id = ? AND status IN ('pending','approved')
                        ORDER BY CASE status WHEN 'approved' THEN 0 ELSE 1 END, requested_at DESC LIMIT 1", [$user['id']]);
    return [
        'used'           => $used,
        'left'           => max(0, PASSWORD_CHANGE_LIMIT - $used),
        'needs_approval' => $needs,
        'approved'       => $req === 'approved',
        'pending'        => $req === 'pending',
        'forced'         => $forced,
    ];
}

function change_password(array $user, string $current, string $new, string $confirm): ?string {
    $st = password_change_status($user);
    if ($st['needs_approval'] && !$st['approved']) {
        return $st['pending']
            ? 'Your request to change your password is waiting for Super Admin approval.'
            : 'You have used all ' . PASSWORD_CHANGE_LIMIT . ' password changes. Ask the Super Admin for approval below.';
    }

    $hash = db_value('SELECT password_hash FROM users WHERE id = ?', [$user['id']]);
    if (!password_verify($current, (string)$hash)) return 'Your current password is incorrect.';
    if ($new !== $confirm)                          return 'The new passwords do not match.';
    if (password_verify($new, (string)$hash))       return 'Choose a password different from your current one.';
    if ($err = password_problem($new, $user['email'])) return $err;

    $pdo = db();
    $pdo->beginTransaction();
    try {
        if ($st['forced']) {
            // Temporary password replaced: does not use up one of the 3 changes.
            db_run('UPDATE users SET password_hash = ?, must_change_password = 0, updated_at = ? WHERE id = ?',
                [password_hash($new, PASSWORD_DEFAULT), now(), $user['id']]);
        } else {
            $used = $st['used'] + 1;
            db_run('UPDATE users SET password_hash = ?, must_change_password = 0, password_change_count = ?,
                           password_change_approval_required = ?, updated_at = ? WHERE id = ?',
                [password_hash($new, PASSWORD_DEFAULT), $used, $used >= PASSWORD_CHANGE_LIMIT ? 1 : 0, now(), $user['id']]);
            if ($st['approved']) {
                // One approval = one change.
                db_run("UPDATE password_change_requests SET status = 'used' WHERE user_id = ? AND status = 'approved'", [$user['id']]);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    regenerate_session();
    audit('password_change', 'users', $user['id'], null, ['password_hash'], $user);
    current_user(true);
    return null;
}

/** User asks the Super Admin for one more password change. */
function request_password_change(array $user, string $reason): ?string {
    $reason = trim($reason);
    if (mb_strlen($reason) > 255) return 'Keep the reason under 255 characters.';
    $st = password_change_status($user);
    if (!$st['needs_approval'])  return 'You can still change your password without approval.';
    if ($st['approved'])         return 'Your request was already approved. You can change your password now.';
    if ($st['pending'])          return 'You already have a request waiting for approval.';

    db_run('INSERT INTO password_change_requests (user_id, reason, status, requested_at) VALUES (?, ?, ?, ?)',
        [$user['id'], $reason !== '' ? $reason : null, 'pending', now()]);
    audit('password_change_request', 'password_change_requests', db()->lastInsertId(), null, [], $user);
    return null;
}

/** Super Admin decision on a password change request. */
function review_password_change_request(int $requestId, bool $approve, array $admin, string $note = ''): ?string {
    $req = db_one("SELECT id, user_id FROM password_change_requests WHERE id = ? AND status = 'pending'", [$requestId]);
    if (!$req) return 'That request was already handled.';
    db_run('UPDATE password_change_requests SET status = ?, reviewed_by = ?, reviewed_at = ?, review_note = ? WHERE id = ?',
        [$approve ? 'approved' : 'rejected', $admin['id'], now(), trim($note) !== '' ? trim($note) : null, $requestId]);
    audit($approve ? 'password_change_approve' : 'password_change_reject', 'password_change_requests', $requestId, null, ['status'], $admin);
    return null;
}

/** Relationships a parent account can have to a student (registration and "Link another child"). */
const PARENT_RELATIONSHIPS = ['Mother', 'Father', 'Guardian', 'Grandparent', 'Other'];

/** v1.3: a student can have at most 2 Mother accounts (pending or approved). */
const MOTHER_LIMIT = 2;
const MOTHER_LIMIT_MESSAGE = 'This student already has 2 Mother accounts linked. Please choose a different relationship, or contact the school office.';

function request_student_link(int $parentId, string $studentNo, string $birthdate, string $relationship): ?string {
    $student = db_one('SELECT id FROM students WHERE student_no = ? AND birthdate = ? AND status = ?',
        [strtoupper(trim($studentNo)), $birthdate, 'enrolled']);
    if (!$student) {
        return "We couldn't match that Student ID and birthdate. Please check them, or ask your child's adviser.";
    }
    $existing = db_one('SELECT status FROM parent_student_links WHERE parent_id = ? AND student_id = ?', [$parentId, $student['id']]);
    if ($existing) {
        return $existing['status'] === 'rejected' || $existing['status'] === 'revoked'
            ? 'This link was not approved. Please contact the school office.'
            : 'You have already requested access to this student.';
    }
    if (strtolower(trim($relationship)) === 'mother') {
        $mothers = (int)db_value("SELECT COUNT(*) FROM parent_student_links
                                   WHERE student_id = ? AND LOWER(TRIM(relationship)) = 'mother' AND status IN ('pending','approved')",
                                 [$student['id']]);
        if ($mothers >= MOTHER_LIMIT) return MOTHER_LIMIT_MESSAGE;
    }
    try {
        db_run('INSERT INTO parent_student_links (parent_id, student_id, relationship, status, created_at) VALUES (?, ?, ?, ?, ?)',
            [$parentId, $student['id'], $relationship, 'pending', now()]);
    } catch (PDOException $e) {
        // The database enforces the same limit (two requests at the same moment).
        if (str_contains($e->getMessage(), 'MOTHER_LIMIT')) return MOTHER_LIMIT_MESSAGE;
        throw $e;
    }
    audit('link_request', 'parent_student_links', db()->lastInsertId(), (int)$student['id'], [],
        ['id' => $parentId, 'role' => 'parent']);
    return null;
}

function register_parent(array $in): ?string {
    $email = normalize_email($in['email'] ?? '');
    $first = trim($in['first_name'] ?? '');
    $last  = trim($in['last_name'] ?? '');
    $rel   = trim($in['relationship'] ?? '');
    $pw    = (string)($in['password'] ?? '');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return 'Please enter a valid e-mail address.';
    if ($first === '' || $last === '')              return 'Please enter your first and last name.';
    if (!in_array($rel, PARENT_RELATIONSHIPS, true)) return 'Please choose your relationship to the student.';
    if ($pw !== ($in['password_confirm'] ?? ''))    return 'The passwords do not match.';
    if ($err = password_problem($pw, $email))       return $err;
    $d = DateTime::createFromFormat('Y-m-d', $in['birthdate'] ?? '');
    if (!$d || $d->format('Y-m-d') !== $in['birthdate']) return "Please enter your child's birthdate.";
    if (db_value('SELECT 1 FROM users WHERE email = ?', [$email])) {
        return 'An account with this e-mail already exists. Log in to link another child.';
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        db_run('INSERT INTO users (role_id, email, password_hash, first_name, last_name, phone, created_at, updated_at)
                VALUES ((SELECT id FROM roles WHERE code = ?), ?, ?, ?, ?, ?, ?, ?)',
            ['parent', $email, password_hash($pw, PASSWORD_DEFAULT), $first, $last, trim($in['phone'] ?? '') ?: null, now(), now()]);
        $parentId = (int)$pdo->lastInsertId();
        audit('parent_register', 'users', $parentId, null, [], ['id' => $parentId, 'role' => 'parent']);

        if ($err = request_student_link($parentId, (string)($in['student_no'] ?? ''), $in['birthdate'], $rel)) {
            $pdo->rollBack();
            return $err;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return null;
}

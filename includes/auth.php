<?php
// Login session for IPTB-LSMS. Every PHP page loads this file first.
//   require_login($root)  -> any logged-in user (admin or staff)
//   require_admin($root)  -> admin only
// $root is the path from the page back to the LSMS folder, e.g. '../' for admin/staff.php.
//
// The user is read again from the database on every request, so an account
// that the admin removes (is_active = 0) is logged out on its next click.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'lifetime' => 0,                                   // ends when the browser closes
        'path'     => '/',
        'httponly' => true,                                // JavaScript cannot read the cookie
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'samesite' => 'Lax',
    ]);
    session_name('LSMSSESSID');
    session_start();
}

const SESSION_IDLE_SECONDS = 7200;                         // log out after 2 hours without activity

/** Show a plain error page when MySQL is not running or the database is missing. */
function db_or_die(): PDO
{
    try {
        return db();
    } catch (PDOException $ex) {
        error_log('IPTB-LSMS database error: ' . $ex->getMessage());
        http_response_code(500);
        echo '<!DOCTYPE html><meta charset="utf-8"><title>IPTB-LSMS</title><body style="font-family:sans-serif;padding:32px">'
           . '<h1>Cannot connect to the database</h1>'
           . '<p>Start MySQL in XAMPP and import <code>database/iptb_lsms.sql</code>, '
           . 'then check <code>includes/db.php</code>.</p></body>';
        exit;
    }
}

/**
 * The logged-in user, checked against the database, or null.
 * Returns user_id, full_name, email, role, location_id, location_code, location_name
 * (location_* is NULL for an admin, who can open every location).
 */
function current_user(): ?array
{
    static $cache = [];                                  // one database check per request and user
    $uid = (int)($_SESSION['uid'] ?? 0);
    if ($uid === 0) {
        return null;
    }
    if (array_key_exists($uid, $cache)) {
        return $cache[$uid];
    }
    $user = &$cache[$uid];
    $user = null;
    if (time() - (int)($_SESSION['seen'] ?? 0) > SESSION_IDLE_SECONDS) {
        logout_user();
        return null;
    }
    $st = db_or_die()->prepare(
        'SELECT user_id, full_name, email, role,
                home_location_id AS location_id, home_location_code AS location_code, home_location_name AS location_name
           FROM v_user_home WHERE user_id = ?'          // the view only returns active users
    );
    $st->execute([(int)$_SESSION['uid']]);
    $row = $st->fetch();
    if (!$row || ($row['role'] === 'staff' && $row['location_id'] === null)) {
        logout_user();                                   // removed, or staff without a location
        return null;
    }
    $_SESSION['seen'] = time();
    $user = $row;
    return $user;
}

function is_admin(): bool
{
    $u = current_user();
    return $u !== null && $u['role'] === 'admin';
}

/** Send a visitor who is not logged in to the login page, then come back. */
function require_login(string $root = '../'): array
{
    $u = current_user();
    if ($u === null) {
        $back = $_SERVER['REQUEST_URI'] ?? '';
        header('Location: ' . $root . 'login.php' . ($back !== '' ? '?next=' . rawurlencode($back) : ''));
        exit;
    }
    return $u;
}

function require_admin(string $root = '../'): array
{
    $u = require_login($root);
    if ($u['role'] !== 'admin') {
        http_response_code(403);
        echo '<!DOCTYPE html><meta charset="utf-8"><title>IPTB-LSMS</title><body style="font-family:sans-serif;padding:32px">'
           . '<h1>Admin only</h1><p>Your account cannot open this page.</p>'
           . '<p><a href="' . e($root) . 'module%201/products.php">Back to the system</a></p></body>';
        exit;
    }
    return $u;
}

/**
 * Check a login. Staff use their email; the demo admin uses "admin".
 * Returns the user row, or null when the email or password is wrong.
 */
function attempt_login(PDO $pdo, string $login, string $password): ?array
{
    $st = $pdo->prepare('SELECT user_id, password_hash, role FROM users WHERE email = ? AND is_active = 1');
    $st->execute([strtolower(trim($login))]);
    $row = $st->fetch();
    // Run password_verify even when the user does not exist, so the reply time does not reveal valid emails.
    $hash = $row['password_hash'] ?? '$2y$10$.Wav6r.ib0.S0uYUp8g9gOks35g8MfAj2as16uz5htV1wtdqzitWa';
    if (!password_verify($password, $hash) || !$row) {
        return null;
    }
    if (password_needs_rehash($row['password_hash'], PASSWORD_DEFAULT)) {
        $pdo->prepare('UPDATE users SET password_hash = ? WHERE user_id = ?')
            ->execute([password_hash($password, PASSWORD_DEFAULT), $row['user_id']]);
    }
    return $row;
}

function login_user(PDO $pdo, int $userId): void
{
    session_regenerate_id(true);                         // new session id after login (session fixation)
    $_SESSION = ['uid' => $userId, 'seen' => time()];
    $pdo->prepare('UPDATE users SET last_login_at = NOW() WHERE user_id = ?')->execute([$userId]);
}

function logout_user(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

/**
 * The HTML prototypes (Modules 2-4) and sidebar.js still read the user from sessionStorage.
 * This script copies the PHP session into sessionStorage for display only.
 * Access is decided by PHP (require_login / require_admin), never by these values.
 */
function session_bridge_script(array $u): string
{
    $data = [
        'lsms_user' => $u['full_name'],
        'lsms_role' => $u['role'],
        'lsms_loc'  => (string)($u['location_code'] ?? ''),
    ];
    return '<script>try{var d=' . json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)
         . ';for(var k in d)sessionStorage.setItem(k,d[k]);}catch(e){}</script>';
}
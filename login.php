<?php
// Login page. Checks the email and password against the users table (password_verify),
// then keeps the user in a PHP session. Replaces the browser-only login.html.
require __DIR__ . '/includes/auth.php';

const HOME_PAGE      = 'module_4/index.html';   // page shown after login
const MAX_FAILS      = 5;                       // wrong passwords in a row ...
const LOCK_SECONDS   = 60;                      // ... then wait this long

/** Only allow a return address inside this site (no "//evil.com" or "https://..."). */
function safe_next(string $next): string
{
    if ($next !== '' && $next[0] === '/' && !preg_match('#^//|\\\\#', $next) && strpos($next, '/login.php') === false) {
        return $next;
    }
    return HOME_PAGE;
}

/** After login: copy the user into sessionStorage for the HTML prototypes, then go on. */
function go_after_login(array $u, string $next): void
{
    $dest = safe_next($next);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>IPTB-LSMS</title>'
        . session_bridge_script($u)
        . '<script>location.replace(' . json_encode($dest, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES) . ');</script>'
        . '</head><body><p>Logging in... <a href="' . e($dest) . '">Continue</a></p></body></html>';
    exit;
}

$pdo  = db_or_die();
$next = (string)($_POST['next'] ?? $_GET['next'] ?? '');

if (($u = current_user()) !== null) {               // already logged in
    go_after_login($u, $next);
}

$error = '';
$login = '';
$lockedFor = max(0, (int)($_SESSION['lock_until'] ?? 0) - time());

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login    = trim((string)($_POST['login'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if (!csrf_ok()) {
        $error = 'The page expired. Try again.';
    } elseif ($lockedFor > 0) {
        $error = 'Too many wrong attempts. Wait ' . $lockedFor . ' seconds and try again.';
    } elseif ($login === '' || $password === '') {
        $error = 'Enter your username or email, and your password.';
    } else {
        $row = attempt_login($pdo, $login, $password);
        if ($row === null) {
            $_SESSION['fails'] = (int)($_SESSION['fails'] ?? 0) + 1;
            if ($_SESSION['fails'] >= MAX_FAILS) {
                $_SESSION['fails'] = 0;
                $_SESSION['lock_until'] = time() + LOCK_SECONDS;
                $error = 'Too many wrong attempts. Wait ' . LOCK_SECONDS . ' seconds and try again.';
            } else {
                $error = 'Wrong username, email or password.';
            }
        } else {
            login_user($pdo, (int)$row['user_id']);
            $u = current_user();
            if ($u === null) {                        // staff account without a location
                $error = 'Your account has no location yet. Ask the admin to assign one.';
            } else {
                go_after_login($u, $next);
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>IPTB-LSMS · Login</title>
    <style>
        :root {
            --red: #d4161c;
            --red-d: #a80f14;
            --yel: #f5c400;
            --ink: #17202a;
            --mut: #5c6670;
            --line: #dfe3e8;
            --bg: #f6f7f9;
            --no: #b3261e;
            --nob: #fde4e2
        }

        * {
            box-sizing: border-box
        }

        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            font-family: "Segoe UI", system-ui, -apple-system, Roboto, Arial, sans-serif;
            color: var(--ink);
            background: var(--bg);
            line-height: 1.45
        }

        [hidden] {
            display: none !important
        }

        .top {
            height: 10px;
            background: var(--red);
            box-shadow: 0 5px 0 var(--yel)
        }

        main {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 32px 16px
        }

        .card {
            width: min(400px, 100%);
            background: #fff;
            border: 1px solid var(--line);
            border-radius: 8px;
            padding: 28px 26px 24px;
            box-shadow: 0 8px 30px rgba(0, 0, 0, .08)
        }

        .plate {
            display: flex;
            justify-content: center;
            margin-bottom: 6px
        }

        .plate img {
            height: 72px;
            width: auto;
            display: block
        }

        .brand {
            text-align: center;
            font-weight: 900;
            font-size: 24px;
            color: var(--red);
            letter-spacing: .5px
        }

        h1 {
            margin: 6px 0 2px;
            font-size: 22px;
            text-align: center
        }

        .sub {
            margin: 0 0 20px;
            text-align: center;
            color: var(--mut);
            font-size: 14px
        }

        label {
            display: block;
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 14px
        }

        input {
            display: block;
            width: 100%;
            margin-top: 4px;
            font: inherit;
            padding: 10px 11px;
            border: 1px solid #c7cdd4;
            border-radius: 4px;
            background: #fff;
            color: var(--ink)
        }

        .pw {
            position: relative
        }

        .pw input {
            padding-right: 64px
        }

        .show {
            position: absolute;
            right: 6px;
            bottom: 5px;
            border: 0;
            background: none;
            font: inherit;
            font-size: 13px;
            font-weight: 600;
            color: var(--mut);
            cursor: pointer;
            padding: 6px 8px;
            border-radius: 4px
        }

        .show:hover {
            background: var(--bg)
        }

        .btn {
            width: 100%;
            background: var(--red);
            color: #fff;
            border: 0;
            border-radius: 4px;
            padding: 11px 18px;
            font: inherit;
            font-weight: 600;
            cursor: pointer
        }

        .btn:hover {
            background: var(--red-d)
        }

        .err {
            background: var(--nob);
            color: var(--no);
            border-radius: 4px;
            padding: 9px 12px;
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 14px
        }

        .hint {
            margin: 16px 0 0;
            text-align: center;
            color: var(--mut);
            font-size: 13px
        }

        :focus-visible {
            outline: 3px solid #1a5fd0;
            outline-offset: 2px
        }
    </style>
</head>

<body>
    <div class="top"></div>
    <main>
        <form class="card" method="post" action="login.php" novalidate>
            <div class="plate"><img src="images/buraqoil-logo.png" alt="Buraqoil" onerror="this.hidden=true;document.getElementById('bt').hidden=false"></div>
            <div class="brand" id="bt" hidden>BURAQOIL</div>
            <h1>IPTB-LSMS</h1>
            <p class="sub">Lubricant Stock Management System</p>
            <?php if ($error !== ''): ?><div class="err" role="alert"><?= e($error) ?></div><?php endif; ?>
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="next" value="<?= e($next) ?>">
            <label>Username or email<input name="login" value="<?= e($login) ?>" autocomplete="username" maxlength="100" <?= $login === '' ? 'autofocus' : '' ?>></label>
            <label>Password<span class="pw"><input name="password" id="p" type="password" autocomplete="current-password" <?= $login !== '' ? 'autofocus' : '' ?>><button class="show" id="sh" type="button" aria-label="Show password">Show</button></span></label>
            <button class="btn" type="submit">Log in</button>
            <p class="hint">Demo account: admin / admin123</p>
        </form>
    </main>
    <script>
        // Clear any old prototype login kept in this browser.
        try {
            ['lsms_user', 'lsms_role', 'lsms_loc'].forEach(function(k) {
                sessionStorage.removeItem(k);
            });
        } catch (e) {}
        document.getElementById('sh').onclick = function() {
            var p = document.getElementById('p'),
                show = p.type === 'password';
            p.type = show ? 'text' : 'password';
            this.textContent = show ? 'Hide' : 'Show';
            this.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
        };
    </script>
</body>

</html>
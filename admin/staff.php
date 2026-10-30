<?php
// Admin: Staff and Locations. Add, edit and remove staff accounts and assign each one to a location.
// Admin only (checked by the server). Replaces the browser-only admin/staff.html.
require __DIR__ . '/../includes/auth.php';

$me  = require_admin('../');
$pdo = db_or_die();

function flash_and_go(string $type, string $msg): void
{
    $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
    header('Location: staff.php');
    exit;
}

/** An active staff account, or null. Admin accounts can never be edited or removed here. */
function find_staff(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare("SELECT user_id, full_name, email FROM users WHERE user_id = ? AND role = 'staff' AND is_active = 1");
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$formErrors = [];
$old        = [];
$formAction = 'create';
$formId     = 0;
$openForm   = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok()) {
        http_response_code(400);
        echo 'Invalid request. Go back, reload the page and try again.';
        exit;
    }
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'remove') {
        // Deactivate instead of DELETE, so the staff member's deliveries and sales keep their name.
        $id  = (int)($_POST['user_id'] ?? 0);
        $row = find_staff($pdo, $id);
        if ($row === null) {
            flash_and_go('error', 'That staff account no longer exists.');
        }
        $pdo->prepare("UPDATE users SET is_active = 0 WHERE user_id = ? AND role = 'staff'")->execute([$id]);
        flash_and_go('ok', $row['full_name'] . ' removed. They can no longer log in.');
    }

    if ($action === 'create' || $action === 'update') {
        $editing = null;
        if ($action === 'update') {
            $editing = (int)($_POST['user_id'] ?? 0);
            if (find_staff($pdo, $editing) === null) {
                flash_and_go('error', 'That staff account no longer exists.');
            }
        }
        [$errors, $v] = validate_staff($pdo, $_POST, $editing);

        if ($errors) {
            $formErrors = $errors;
            $old        = $_POST;
            unset($old['password']);                     // never send a password back to the browser
            $openForm   = true;
            $formAction = $action;
            $formId     = (int)$editing;
        } else {
            try {
                $pdo->beginTransaction();
                if ($action === 'create') {
                    $pdo->prepare("INSERT INTO users (full_name, email, password_hash, role) VALUES (?, ?, ?, 'staff')")
                        ->execute([$v['full_name'], $v['email'], password_hash($v['password'], PASSWORD_DEFAULT)]);
                    $uid = (int)$pdo->lastInsertId();
                    $pdo->prepare('INSERT INTO staff_locations (user_id, location_id, is_default, assigned_by) VALUES (?, ?, 1, ?)')
                        ->execute([$uid, $v['location_id'], $me['user_id']]);
                    $verb = 'added';
                } else {
                    $pdo->prepare('UPDATE users SET full_name = ?, email = ? WHERE user_id = ?')
                        ->execute([$v['full_name'], $v['email'], $editing]);
                    if ($v['password'] !== null) {
                        $pdo->prepare('UPDATE users SET password_hash = ? WHERE user_id = ?')
                            ->execute([password_hash($v['password'], PASSWORD_DEFAULT), $editing]);
                    }
                    // Move the staff member only when the location changed (keeps assigned_at otherwise).
                    $st = $pdo->prepare('SELECT location_id FROM staff_locations WHERE user_id = ? AND is_default = 1');
                    $st->execute([$editing]);
                    if ((int)$st->fetchColumn() !== $v['location_id']) {
                        $pdo->prepare('DELETE FROM staff_locations WHERE user_id = ?')->execute([$editing]);
                        $pdo->prepare('INSERT INTO staff_locations (user_id, location_id, is_default, assigned_by) VALUES (?, ?, 1, ?)')
                            ->execute([$editing, $v['location_id'], $me['user_id']]);
                    }
                    $verb = 'updated';
                }
                $pdo->commit();
                $st = $pdo->prepare('SELECT location_name FROM locations WHERE location_id = ?');
                $st->execute([$v['location_id']]);
                flash_and_go('ok', $v['full_name'] . ' ' . $verb . ' at ' . $st->fetchColumn() . '.');
            } catch (PDOException $ex) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                if ($ex->getCode() === '23000') {           // e.g. the same email saved twice at the same moment
                    $formErrors = ['email' => 'This email already has an account.'];
                    $old        = $_POST;
                    unset($old['password']);
                    $openForm   = true;
                    $formAction = $action;
                    $formId     = (int)$editing;
                } else {
                    error_log('IPTB-LSMS staff save error: ' . $ex->getMessage());
                    flash_and_go('error', 'The staff account could not be saved. Try again.');
                }
            }
        }
    }
}

// ---------- Read ----------
$locations = $pdo->query(
    "SELECT l.location_id, l.location_code, l.location_name, l.address,
            COUNT(u.user_id) AS staff_count
       FROM locations l
       LEFT JOIN staff_locations sl ON sl.location_id = l.location_id AND sl.is_default = 1
       LEFT JOIN users u ON u.user_id = sl.user_id AND u.is_active = 1 AND u.role = 'staff'
      WHERE l.is_active = 1
      GROUP BY l.location_id, l.location_code, l.location_name, l.address
      ORDER BY l.location_id"
)->fetchAll();

$staff = $pdo->query(
    "SELECT u.user_id, u.full_name, u.email, u.last_login_at,
            l.location_id, l.location_name, l.address
       FROM users u
       LEFT JOIN staff_locations sl ON sl.user_id = u.user_id AND sl.is_default = 1
       LEFT JOIN locations l ON l.location_id = sl.location_id
      WHERE u.role = 'staff' AND u.is_active = 1
      ORDER BY u.full_name"
)->fetchAll();

function field(array $old, string $k): string
{
    return e($old[$k] ?? '');
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>IPTB-LSMS · Staff and Locations</title>
    <?= session_bridge_script($me) ?>
    <script src="../lsms.js"></script>
    <style>
        :root {
            --red: #d4161c;
            --red-d: #a80f14;
            --yel: #f5c400;
            --ink: #17202a;
            --mut: #5c6670;
            --line: #dfe3e8;
            --bg: #f6f7f9;
            --ok: #1d7a3e;
            --okb: #e1f4e7;
            --no: #b3261e;
            --nob: #fde4e2
        }

        * {
            box-sizing: border-box
        }

        body {
            margin: 0;
            font-family: "Segoe UI", system-ui, -apple-system, Roboto, Arial, sans-serif;
            color: var(--ink);
            background: #fff;
            line-height: 1.45
        }

        header {
            padding: 10px 0;
            border-bottom: 5px solid var(--red);
            box-shadow: 0 5px 0 var(--yel);
            margin-bottom: 5px
        }

        .hin {
            padding: 0 28px;
            display: flex;
            gap: 14px;
            align-items: center
        }

        [hidden] {
            display: none !important
        }

        .logo-img {
            height: 56px;
            width: auto;
            display: block
        }

        .logo {
            font-weight: 900;
            font-size: 24px;
            letter-spacing: .5px;
            color: var(--red);
            line-height: 1
        }

        header h2 {
            margin: 0;
            font-size: 17px;
            line-height: 1.3
        }

        header p {
            margin: 2px 0 0;
            color: var(--mut);
            font-size: 13.5px
        }

        main {
            max-width: 1360px;
            margin: 0 auto;
            padding: 30px clamp(16px, 5vw, 64px) 60px
        }

        h1 {
            margin: 0 0 6px;
            font-size: 30px
        }

        h3 {
            margin: 40px 0 6px;
            font-size: 21px
        }

        .lead {
            max-width: 640px;
            color: var(--mut);
            margin: 0 0 20px
        }

        .locs {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 14px
        }

        .loc {
            border: 1px solid var(--line);
            border-left: 4px solid var(--yel);
            border-radius: 4px;
            padding: 14px 16px
        }

        .loc b {
            display: block;
            font-size: 16px
        }

        .loc p {
            margin: 4px 0 8px;
            color: var(--mut);
            font-size: 14px
        }

        .loc span {
            font-size: 13px;
            font-weight: 600
        }

        .bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 12px
        }

        button {
            font: inherit;
            cursor: pointer
        }

        .btn {
            background: var(--red);
            color: #fff;
            border: 0;
            border-radius: 4px;
            padding: 10px 18px;
            font-weight: 600
        }

        .btn:hover {
            background: var(--red-d)
        }

        .btn.sec {
            background: #fff;
            color: var(--ink);
            border: 1px solid #c7cdd4
        }

        .btn.sec:hover {
            background: var(--bg)
        }

        .ico {
            width: 36px;
            height: 36px;
            min-height: 0;
            padding: 0;
            border-radius: 50%;
            border: 1px solid #d9e0e5;
            background: #fff;
            font-size: 1rem;
            line-height: 1;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-right: 6px
        }

        .ico:hover {
            background: var(--bg)
        }

        .ico.del {
            border-color: #a3231b;
            background: #fbe4e2
        }

        td.act {
            white-space: nowrap;
            vertical-align: middle
        }

        .tw {
            border: 1px solid var(--line);
            border-radius: 4px;
            overflow-x: auto
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 720px
        }

        th {
            background: var(--bg);
            text-align: left;
            font-size: 13px;
            color: var(--mut);
            padding: 11px 14px;
            border-bottom: 1px solid var(--line)
        }

        td {
            padding: 13px 14px;
            border-bottom: 1px solid var(--line);
            font-size: 15px;
            vertical-align: top
        }

        tr:last-child td {
            border-bottom: 0
        }

        .sub {
            display: block;
            color: var(--mut);
            font-size: 13px
        }

        .empty {
            padding: 34px;
            text-align: center;
            color: var(--mut)
        }

        dialog {
            border: 0;
            border-radius: 6px;
            padding: 0;
            width: min(520px, 94vw);
            box-shadow: 0 10px 40px rgba(0, 0, 0, .25)
        }

        dialog::backdrop {
            background: rgba(23, 32, 42, .5)
        }

        .dh {
            padding: 18px 22px;
            border-bottom: 1px solid var(--line)
        }

        .dh h4 {
            margin: 0;
            font-size: 19px
        }

        .db {
            padding: 18px 22px
        }

        .df {
            padding: 14px 22px;
            border-top: 1px solid var(--line);
            display: flex;
            justify-content: flex-end;
            gap: 10px
        }

        label {
            display: flex;
            flex-direction: column;
            gap: 4px;
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 12px
        }

        label small {
            font-weight: 400;
            color: var(--mut)
        }

        input,
        select {
            font: inherit;
            padding: 9px 10px;
            border: 1px solid #c7cdd4;
            border-radius: 4px;
            background: #fff;
            color: var(--ink)
        }

        .bad {
            border-color: var(--no);
            background: var(--nob)
        }

        .err {
            color: var(--no);
            font-size: 13.5px;
            min-height: 1.1em;
            font-weight: 600
        }

        #toast {
            position: fixed;
            bottom: 22px;
            left: 50%;
            transform: translateX(-50%);
            background: var(--ink);
            color: #fff;
            padding: 11px 20px;
            border-radius: 6px;
            font-size: 14px;
            opacity: 0;
            pointer-events: none;
            transition: opacity .2s;
            max-width: 92vw;
            text-align: center
        }

        #toast.on {
            opacity: 1
        }

        #toast.error {
            background: var(--no)
        }

        :focus-visible {
            outline: 3px solid #1a5fd0;
            outline-offset: 2px
        }

        @media(max-width:860px) {
            .hin {
                padding: 0 16px;
                gap: 12px
            }

            .logo-img {
                height: 42px
            }
        }

        @media(max-width:620px) {
            main {
                padding: 22px 14px
            }
        }

        @media (prefers-reduced-motion:reduce) {
            #toast {
                transition: none
            }
        }
    </style>
</head>

<body>
    <header>
        <div class="hin">
            <img class="logo-img" src="../images/buraqoil-logo.png" alt="Buraqoil" onerror="this.hidden=true;document.getElementById('lt').hidden=false">
            <div class="logo" id="lt" hidden>BURAQOIL</div>
            <div>
                <h2>Lubricant Stock Management System</h2>
                <p>IPTB Sdn Bhd, Admin: Staff and Locations</p>
            </div>
        </div>
    </header>

    <main>
        <h1>Staff and locations</h1>
        <p class="lead">Add staff accounts and assign each one to a station. Staff log in with their email and the password you set here.</p>

        <div class="locs">
            <?php foreach ($locations as $l): ?>
                <div class="loc"><b><?= e($l['location_name']) ?></b>
                    <p><?= e($l['address']) ?></p>
                    <span><?= (int)$l['staff_count'] ?> staff assigned</span>
                </div>
            <?php endforeach; ?>
        </div>

        <h3>Staff accounts</h3>
        <div class="bar">
            <span class="sub"><?= count($staff) ?> staff account<?= count($staff) === 1 ? '' : 's' ?></span>
            <button class="btn" id="add" type="button">+ Add staff</button>
        </div>
        <div class="tw">
            <table>
                <thead>
                    <tr>
                        <th>Staff</th>
                        <th>Email</th>
                        <th>Location</th>
                        <th>Last login</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$staff): ?>
                        <tr>
                            <td colspan="5" class="empty">No staff yet. Select "Add staff" to create the first account.</td>
                        </tr>
                    <?php endif; ?>
                    <?php foreach ($staff as $s):
                        $json = json_encode([
                            'user_id' => (int)$s['user_id'],
                            'full_name' => $s['full_name'],
                            'email' => $s['email'],
                            'location_id' => (int)$s['location_id'],
                        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>
                        <tr>
                            <td><b><?= e($s['full_name']) ?></b></td>
                            <td><?= e($s['email']) ?></td>
                            <td><?= $s['location_name'] !== null ? e($s['location_name']) : '<b style="color:var(--no)">Not assigned</b>' ?>
                                <span class="sub"><?= e($s['address'] ?? '') ?></span>
                            </td>
                            <td><?= $s['last_login_at'] ? e(date('j M Y, g:i a', strtotime($s['last_login_at']))) : '<span class="sub">Never</span>' ?></td>
                            <td class="act">
                                <button type="button" class="ico" data-a="edit" data-s="<?= e($json) ?>" title="Edit" aria-label="Edit <?= e($s['full_name']) ?>">&#9999;&#65039;</button>
                                <button type="button" class="ico del" data-a="del" data-s="<?= e($json) ?>" title="Remove" aria-label="Remove <?= e($s['full_name']) ?>">&#128465;&#65039;</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </main>

    <dialog id="dlg">
        <form method="post" action="staff.php" id="fm" novalidate>
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" id="fa" value="<?= e($formAction) ?>">
            <input type="hidden" name="user_id" id="fid" value="<?= (int)$formId ?>">
            <div class="dh">
                <h4 id="dt"><?= $formAction === 'update' ? 'Edit staff' : 'Add staff' ?></h4>
            </div>
            <div class="db">
                <label>Full name<input name="full_name" id="fn" maxlength="100" autocomplete="off" value="<?= field($old, 'full_name') ?>" <?= isset($formErrors['full_name']) ? ' class="bad"' : '' ?>>
                    <span class="err"><?= e($formErrors['full_name'] ?? '') ?></span></label>
                <label>Email<input name="email" id="fe" type="email" maxlength="100" autocomplete="off" value="<?= field($old, 'email') ?>" <?= isset($formErrors['email']) ? ' class="bad"' : '' ?>>
                    <span class="err"><?= e($formErrors['email'] ?? '') ?></span></label>
                <label>Password <small id="ph"><?= $formAction === 'update' ? 'Leave blank to keep the current password.' : 'At least 6 characters. Give this to the staff member.' ?></small>
                    <input name="password" id="fp" type="text" maxlength="72" autocomplete="new-password" <?= isset($formErrors['password']) ? ' class="bad"' : '' ?>>
                    <span class="err"><?= e($formErrors['password'] ?? '') ?></span></label>
                <label>Location<select name="location_id" id="fl" <?= isset($formErrors['location_id']) ? ' class="bad"' : '' ?>>
                        <option value="">Select a location</option>
                        <?php foreach ($locations as $l): ?>
                            <option value="<?= (int)$l['location_id'] ?>" <?= (string)($old['location_id'] ?? '') === (string)$l['location_id'] ? ' selected' : '' ?>><?= e($l['location_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <span class="err"><?= e($formErrors['location_id'] ?? '') ?></span></label>
            </div>
            <div class="df">
                <button class="btn sec" type="button" id="cx">Cancel</button>
                <button class="btn" type="submit">Save staff</button>
            </div>
        </form>
    </dialog>

    <dialog id="del">
        <form method="post" action="staff.php">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="remove">
            <input type="hidden" name="user_id" id="did" value="">
            <div class="dh">
                <h4>Remove staff?</h4>
            </div>
            <div class="db">
                <p id="dmsg" style="margin:0"></p>
            </div>
            <div class="df">
                <button class="btn sec" type="button" id="dcx">Cancel</button>
                <button class="btn" type="submit">Remove</button>
            </div>
        </form>
    </dialog>
    <div id="toast" role="status" <?= $flash && $flash['type'] === 'error' ? ' class="error"' : '' ?>></div>

    <script>
        const $ = id => document.getElementById(id);

        function clearErrors() {
            document.querySelectorAll('#fm .bad').forEach(x => x.classList.remove('bad'));
            document.querySelectorAll('#fm .err').forEach(x => x.textContent = '');
        }

        function openForm(s) {
            clearErrors();
            $('fa').value = s ? 'update' : 'create';
            $('fid').value = s ? s.user_id : 0;
            $('dt').textContent = s ? 'Edit staff' : 'Add staff';
            $('ph').textContent = s ? 'Leave blank to keep the current password.' : 'At least 6 characters. Give this to the staff member.';
            $('fn').value = s ? s.full_name : '';
            $('fe').value = s ? s.email : '';
            $('fp').value = '';
            $('fl').value = s ? String(s.location_id) : '';
            $('dlg').showModal();
            $('fn').focus();
        }
        $('add').onclick = () => openForm(null);
        $('cx').onclick = () => $('dlg').close();
        $('dcx').onclick = () => $('del').close();
        document.querySelector('tbody').onclick = e => {
            const b = e.target.closest('button[data-a]');
            if (!b) return;
            const s = JSON.parse(b.dataset.s);
            if (b.dataset.a === 'edit') return openForm(s);
            $('did').value = s.user_id;
            $('dmsg').textContent = `Remove ${s.full_name} (${s.email})? They will no longer be able to log in. Their past deliveries and sales are kept.`;
            $('del').showModal();
        };
        <?php if ($openForm): ?>
            $('dlg').showModal();
            (document.querySelector('#fm .bad') || $('fn')).focus();
        <?php endif; ?>
        <?php if ($flash): ?>
                (function() {
                    const t = $('toast');
                    t.textContent = <?= json_encode($flash['msg'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
                    t.classList.add('on');
                    setTimeout(() => t.classList.remove('on'), 3200);
                })();
        <?php endif; ?>
    </script>
    <script src="../sidebar.js"></script>
</body>

</html>
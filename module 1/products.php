<?php
// Module 1: Lubricant Product Management (Create, Read, Update, Delete, Search and Filter).
require __DIR__ . '/../includes/auth.php';

$me  = require_login('../');       // admin and staff can open Module 1
$pdo = db_or_die();

const SHORT_NAMES = ['PHG' => 'Kuantan', 'KTN' => 'Kota Bharu'];
$IMG_DIR = __DIR__ . '/images';

/** Keep only the list filters we know about. */
function pick_filters(array $src): array
{
  $out = [];
  foreach (['q', 'loc', 'brand', 'type', 'status'] as $k) {
    if (isset($src[$k]) && is_string($src[$k]) && $src[$k] !== '') {
      $out[$k] = $src[$k];
    }
  }
  return $out;
}
function page_url(array $filters): string
{
  return 'products.php' . ($filters ? '?' . http_build_query($filters) : '');
}
function flash_and_go(string $type, string $msg, array $filters): void
{
  $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
  header('Location: ' . page_url($filters));
  exit;
}

$filters = pick_filters($_GET);
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$formErrors = [];
$old        = [];
$formAction = 'create';
$formId     = 0;
$openForm   = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // A file larger than post_max_size empties $_POST completely.
  if (empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    flash_and_go('error', 'The upload is too large. Images must be 2 MB or less.', $filters);
  }
  if (!csrf_ok()) {
    http_response_code(400);
    echo 'Invalid request. Go back, reload the page and try again.';
    exit;
  }
  $action = (string)($_POST['action'] ?? '');

  if ($action === 'delete') {
    // BR-08: the confirmation is shown by the Delete dialog before this request is sent.
    $id = (int)($_POST['product_id'] ?? 0);
    try {
      $pdo->beginTransaction();
      $st = $pdo->prepare('SELECT brand, product_name, viscosity_grade FROM products WHERE product_id = ?');
      $st->execute([$id]);
      $row = $st->fetch();
      if (!$row) {
        $pdo->rollBack();
        flash_and_go('error', 'That product no longer exists.', $filters);
      }
      $pdo->prepare('DELETE FROM location_stock WHERE product_id = ?')->execute([$id]);
      $pdo->prepare('DELETE FROM products WHERE product_id = ?')->execute([$id]);
      $pdo->commit();
      flash_and_go('ok', 'Product deleted: ' . $row['brand'] . ' ' . $row['product_name'] . ' ' . $row['viscosity_grade'], $filters);
    } catch (PDOException $ex) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }
      if ($ex->getCode() === '23000') {
        flash_and_go('error', 'This product already appears in deliveries, sales or stock adjustments, so it cannot be deleted.', $filters);
      }
      error_log('IPTB-LSMS delete error: ' . $ex->getMessage());
      flash_and_go('error', 'The product could not be deleted. Try again.', $filters);
    }
  }

  if ($action === 'create' || $action === 'update') {
    $editing = $action === 'update' ? (int)($_POST['product_id'] ?? 0) : null;
    if ($editing !== null) {
      $st = $pdo->prepare('SELECT COUNT(*) FROM products WHERE product_id = ?');
      $st->execute([$editing]);
      if ((int)$st->fetchColumn() === 0) {
        flash_and_go('error', 'That product no longer exists.', $filters);
      }
    }
    // One validation function for both actions. A posted stock quantity is never read (BR-05).
    [$errors, $v] = validate_product($pdo, $_POST, $editing);
    [$imgErr, $ext] = validate_image($_FILES['image'] ?? null);
    if ($imgErr !== null) {
      $errors['image'] = $imgErr;
    }

    if ($errors) {
      $formErrors = $errors;
      $old        = $_POST;
      $openForm   = true;
      $formAction = $action;
      $formId     = (int)$editing;
    } else {
      try {
        if ($action === 'create') {
          $pdo->beginTransaction();
          $pdo->prepare(
            'INSERT INTO products (sku, brand, product_name, viscosity_grade, product_type, pack_size, cost_price, selling_price, min_stock)
                         VALUES (?,?,?,?,?,?,?,?,?)'
          )->execute([
            'T' . bin2hex(random_bytes(4)),
            $v['brand'],
            $v['product_name'],
            $v['viscosity_grade'],
            $v['product_type'],
            $v['pack_size'],
            $v['cost_price'],
            $v['selling_price'],
            $v['min_stock'],
          ]);
          $id  = (int)$pdo->lastInsertId();
          $sku = 'LUB-' . str_pad((string)$id, 3, '0', STR_PAD_LEFT);
          $pdo->prepare('UPDATE products SET sku = ? WHERE product_id = ?')->execute([$sku, $id]);
          // BR-05: a new product starts with stock quantity 0 at every location
          $pdo->prepare(
            'INSERT INTO location_stock (location_id, product_id, opening_stock, opening_date, current_stock)
                         SELECT location_id, ?, 0, CURDATE(), 0 FROM locations'
          )->execute([$id]);
          $pdo->commit();
          $msg = 'Product added: ' . $sku . ' ' . $v['brand'] . ' ' . $v['product_name'];
        } else {
          $id = $editing;
          $pdo->prepare(
            'UPDATE products SET brand = ?, product_name = ?, viscosity_grade = ?, product_type = ?,
                                pack_size = ?, cost_price = ?, selling_price = ?, min_stock = ?
                         WHERE product_id = ?'
          )->execute([
            $v['brand'],
            $v['product_name'],
            $v['viscosity_grade'],
            $v['product_type'],
            $v['pack_size'],
            $v['cost_price'],
            $v['selling_price'],
            $v['min_stock'],
            $id,
          ]);
          $st = $pdo->prepare('SELECT sku FROM products WHERE product_id = ?');
          $st->execute([$id]);
          $sku = (string)$st->fetchColumn();
          $msg = 'Product updated: ' . $sku . ' ' . $v['brand'] . ' ' . $v['product_name'];
        }
        if ($ext !== null) {
          $path = store_image($_FILES['image']['tmp_name'], $IMG_DIR, $sku, $ext);
          if ($path !== null) {
            $pdo->prepare('UPDATE products SET image_path = ? WHERE product_id = ?')->execute([$path, $id]);
          } else {
            $msg .= ' (the image could not be saved)';
          }
        }
        flash_and_go('ok', $msg, $filters);
      } catch (PDOException $ex) {
        if ($pdo->inTransaction()) {
          $pdo->rollBack();
        }
        error_log('IPTB-LSMS save error: ' . $ex->getMessage());
        flash_and_go('error', 'The product could not be saved. Try again.', $filters);
      }
    }
  }
}

// ---------- Read: products, stock per location, search and filters ----------
$locations = $pdo->query('SELECT location_id, location_code, location_name FROM locations WHERE is_active = 1 ORDER BY location_id')->fetchAll();
$codes = array_column($locations, 'location_code');
$loc   = isset($filters['loc']) && in_array($filters['loc'], $codes, true) ? $filters['loc'] : '';
$scope = $loc !== '' ? [$loc] : $codes;                 // locations shown
$many  = count($scope) > 1;

$stock = [];   // [product_id][location_code] => quantity
foreach (
  $pdo->query('SELECT ls.product_id, l.location_code, ls.current_stock
                      FROM location_stock ls JOIN locations l ON l.location_id = ls.location_id') as $r
) {
  $stock[(int)$r['product_id']][$r['location_code']] = (int)$r['current_stock'];
}

$all = $pdo->query('SELECT * FROM products WHERE is_active = 1 ORDER BY product_id')->fetchAll();
foreach ($all as &$p) {
  $pid = (int)$p['product_id'];
  $p['qty']   = [];
  $p['total'] = 0;
  foreach ($codes as $c) {
    $n = $stock[$pid][$c] ?? 0;
    $p['qty'][$c] = $n;
    $p['total']  += $n;
  }
  $p['status_in'] = [];
  foreach ($scope as $c) {
    $p['status_in'][$c] = stock_status($p['qty'][$c], (int)$p['min_stock']);
  }
}
unset($p);

$q       = isset($filters['q']) ? trim($filters['q']) : '';
$fBrand  = $filters['brand']  ?? '';
$fType   = $filters['type']   ?? '';
$fStatus = in_array($filters['status'] ?? '', ['ok', 'low', 'out'], true) ? $filters['status'] : '';

$brands = array_values(array_unique(array_column($all, 'brand')));
sort($brands, SORT_NATURAL | SORT_FLAG_CASE);

$list = [];
foreach ($all as $p) {
  if ($q !== '') {
    $hay = $p['brand'] . ' ' . $p['product_name'] . ' ' . $p['viscosity_grade'];
    if (stripos($hay, $q) === false) {
      continue;
    }
  }
  if ($fBrand !== '' && $p['brand'] !== $fBrand) {
    continue;
  }
  if ($fType !== '' && $p['product_type'] !== $fType) {
    continue;
  }
  if ($fStatus !== '' && !in_array($fStatus, $p['status_in'], true)) {
    continue;
  }
  $list[] = $p;
}

$sTotal = count($all);
$sLow = 0;
$sOut = 0;
foreach ($all as $p) {
  if (in_array('low', $p['status_in'], true)) {
    $sLow++;
  }
  if (in_array('out', $p['status_in'], true)) {
    $sOut++;
  }
}

$LABEL = ['ok' => 'In stock', 'low' => 'Low stock', 'out' => 'Out of stock'];
function pack_label($n): string
{
  return rtrim(rtrim(number_format((float)$n, 2, '.', ''), '0'), '.') . ' L';
}
function field($old, $k, $default = '')
{
  return e($old[$k] ?? $default);
}
function img_src(array $p): string
{
  if (empty($p['image_path'])) {
    return '';
  }
  $file = __DIR__ . '/' . $p['image_path'];
  return $p['image_path'] . (is_file($file) ? '?v=' . filemtime($file) : '');
}
$formQuery = page_url($filters);
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="utf-8">
  <meta name="color-scheme" content="light">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title>IPTB-LSMS · Lubricant Product Management</title>
  <?= session_bridge_script($me) ?>
  <script src="../lsms.js"></script>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600&display=swap">
  <style>
    :root {
      --bg: #ffffff;
      --head: #f3f5f6;
      --surface: #fff;
      --ink: #16212b;
      --muted: #5b6875;
      --line: #d9e0e5;
      --accent: #d3171e;
      --accent-ink: #fff;
      --yellow: #ffe500;
      --ok: #1d6b3f;
      --okbg: #e3f2e9;
      --low: #8a5a00;
      --lowbg: #fdf0d3;
      --out: #a3231b;
      --outbg: #fbe4e2;
      --focus: #d3171e
    }

    :root {
      box-sizing: border-box;
      padding-top: env(safe-area-inset-top, 0px);
      padding-bottom: env(safe-area-inset-bottom, 0px)
    }

    html {
      scroll-padding-top: env(safe-area-inset-top, 0px)
    }

    *,
    *::before,
    *::after {
      box-sizing: inherit
    }

    body {
      margin: 0;
      background: var(--bg);
      color: var(--ink);
      font: 15px/1.5 "IBM Plex Sans", system-ui, -apple-system, "Segoe UI", sans-serif
    }

    .top {
      background: var(--surface);
      position: relative;
      border-bottom: 4px solid var(--accent)
    }

    .top::after {
      content: "";
      position: absolute;
      left: 0;
      right: 0;
      bottom: -8px;
      height: 4px;
      background: var(--yellow)
    }

    .top .in {
      padding: 10px 28px;
      display: flex;
      align-items: center;
      gap: 14px
    }

    .plate {
      display: flex
    }

    .plate img {
      height: 56px;
      width: auto;
      display: block
    }

    .top strong {
      display: block;
      font-size: 17px;
      line-height: 1.3
    }

    .top span {
      color: var(--muted);
      font-size: 13.5px
    }

    .wrap {
      max-width: 1360px;
      margin: 0 auto;
      padding: 32px 16px 48px
    }

    h1 {
      font-size: 1.5rem;
      line-height: 1.2;
      margin: 0 0 4px;
      font-weight: 600
    }

    .sub {
      margin: 0 0 20px;
      color: var(--muted)
    }

    .stats {
      display: flex;
      gap: 12px;
      flex-wrap: wrap;
      margin-bottom: 16px
    }

    .stat {
      background: var(--surface);
      border: 1px solid var(--line);
      border-left: 4px solid var(--yellow);
      border-radius: 6px;
      padding: 10px 14px;
      min-width: 130px
    }

    .stat b {
      display: block;
      font-size: 1.4rem;
      font-weight: 600
    }

    .stat span {
      color: var(--muted);
      font-size: .85rem
    }

    .toolbar {
      background: var(--surface);
      border: 1px solid var(--line);
      border-radius: 8px;
      padding: 14px;
      margin-bottom: 14px;
      display: flex;
      flex-direction: column;
      gap: 12px
    }

    .tb-top {
      display: flex;
      gap: 10px;
      align-items: center
    }

    .search {
      flex: 1;
      display: flex;
      align-items: center;
      border: 1px solid var(--line);
      border-radius: 8px;
      background: var(--surface);
      padding: 4px 4px 4px 12px;
      transition: border-color .15s, box-shadow .15s
    }

    .search:focus-within {
      border-color: var(--accent);
      box-shadow: 0 0 0 3px rgba(211, 23, 30, .12)
    }

    .search .s-ico {
      color: var(--muted);
      flex: 0 0 auto
    }

    .toolbar .search input {
      flex: 1;
      min-width: 0;
      border: 0;
      background: none;
      padding: 8px 10px;
      min-height: 38px;
      outline: none
    }

    .search .s-go {
      border: 0;
      background: var(--head);
      border-radius: 6px;
      padding: 8px 16px;
      min-height: 38px;
      font-weight: 500
    }

    .search .s-go:hover {
      background: var(--line)
    }

    .toolbar .add {
      min-height: 48px;
      padding: 0 20px;
      border-radius: 8px;
      white-space: nowrap;
      font-weight: 600
    }

    .toolbar .add span {
      font-size: 1.2em;
      margin-right: 2px
    }

    .tb-filters {
      display: flex;
      gap: 8px;
      flex-wrap: wrap;
      align-items: center
    }

    .f-label {
      font-size: .8rem;
      font-weight: 600;
      letter-spacing: .04em;
      text-transform: uppercase;
      color: var(--muted);
      margin-right: 4px
    }

    .tb-filters select {
      appearance: none;
      -webkit-appearance: none;
      min-height: 36px;
      padding: 6px 34px 6px 14px;
      border-radius: 999px;
      font-size: .9rem;
      cursor: pointer;
      background: var(--surface) url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%235b6875' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E") no-repeat right 12px center;
      max-width: 100%
    }

    .tb-filters select:hover {
      border-color: var(--muted)
    }

    .tb-filters select.on {
      border-color: var(--accent);
      background-color: #fdecec;
      color: var(--accent);
      font-weight: 600
    }

    .tb-filters a.clear {
      color: var(--accent);
      font-size: .9rem;
      font-weight: 500;
      padding: 6px 8px;
      margin-left: auto
    }

    @media (max-width:640px) {
      .tb-top {
        flex-direction: column;
        align-items: stretch
      }

      .toolbar .add {
        min-height: 44px
      }

      .tb-filters select {
        flex: 1 1 140px
      }

      .f-label {
        width: 100%
      }
    }

    input,
    select,
    button {
      font: inherit;
      color: inherit
    }

    input,
    select {
      background: var(--surface);
      border: 1px solid var(--line);
      border-radius: 6px;
      padding: 8px 10px;
      min-height: 40px
    }

    button {
      cursor: pointer;
      border-radius: 6px;
      border: 1px solid var(--line);
      background: var(--surface);
      padding: 8px 14px;
      min-height: 40px
    }

    button.primary {
      background: var(--accent);
      color: var(--accent-ink);
      border-color: var(--accent);
      font-weight: 500
    }

    button.danger {
      background: var(--out);
      color: var(--surface);
      border-color: var(--out)
    }

    button.link {
      border: 0;
      background: none;
      color: var(--ink);
      text-decoration: underline;
      padding: 6px 8px;
      min-height: 32px
    }

    button.link.del {
      color: var(--out)
    }

    :focus-visible {
      outline: 2px solid var(--focus);
      outline-offset: 2px
    }

    .tablewrap {
      overflow-x: auto;
      background: var(--surface);
      border: 1px solid var(--line);
      border-radius: 6px
    }

    table {
      border-collapse: collapse;
      width: 100%;
      min-width: 980px
    }

    th,
    td {
      text-align: left;
      padding: 10px 10px;
      border-bottom: 1px solid var(--line);
      white-space: nowrap
    }

    th {
      font-weight: 600;
      font-size: .85rem;
      color: var(--muted);
      background: var(--head)
    }

    tr:last-child td {
      border-bottom: 0
    }

    td.num,
    th.num {
      text-align: right;
      font-variant-numeric: tabular-nums
    }

    td.name {
      white-space: normal;
      min-width: 170px
    }

    td.name small {
      display: block;
      color: var(--muted)
    }

    .chip {
      display: inline-block;
      padding: 1px 8px;
      border-radius: 99px;
      font-size: .8rem;
      font-weight: 500
    }

    .chip.ok {
      background: var(--okbg);
      color: var(--ok)
    }

    .chip.low {
      background: var(--lowbg);
      color: var(--low)
    }

    .chip.out {
      background: var(--outbg);
      color: var(--out)
    }

    /* Stock cell: number centred above the status chip */
    td.stk,
    th.stk {
      text-align: center
    }

    td.stk .cell {
      display: inline-flex;
      flex-direction: column;
      align-items: center;
      gap: 3px
    }

    td.stk b {
      font-size: 1.05rem;
      line-height: 1.2;
      font-variant-numeric: tabular-nums
    }

    .thumb {
      width: 44px;
      height: 56px;
      object-fit: contain;
      border-radius: 6px;
      border: 1px solid var(--line);
      background: #fff;
      display: block
    }

    .zoom {
      padding: 0;
      border: 0;
      background: none;
      min-height: 0;
      cursor: zoom-in;
      border-radius: 6px;
      display: block
    }

    #imgDlg .dlg {
      text-align: center
    }

    #bigImg {
      display: block;
      margin: 0 auto;
      width: 100%;
      max-width: 380px;
      height: min(60vh, 440px);
      object-fit: contain;
      background: #fff;
      border: 1px solid var(--line);
      border-radius: 6px
    }

    #imgDlg .actions {
      justify-content: center
    }

    .ico {
      width: 36px;
      height: 36px;
      min-height: 0;
      padding: 0;
      border-radius: 50%;
      border: 1px solid var(--line);
      background: var(--surface);
      font-size: 1rem;
      line-height: 1;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      margin-right: 6px
    }

    .ico.del {
      border-color: var(--out);
      background: var(--outbg)
    }

    .empty {
      padding: 32px 16px;
      text-align: center;
      color: var(--muted)
    }

    .count {
      color: var(--muted);
      font-size: .85rem;
      margin: 8px 2px 0
    }

    dialog {
      border: 1px solid var(--line);
      border-radius: 8px;
      background: var(--surface);
      color: var(--ink);
      padding: 0;
      width: min(520px, calc(100% - 24px))
    }

    dialog::backdrop {
      background: rgba(0, 0, 0, .45)
    }

    .dlg {
      padding: 20px
    }

    .dlg h2 {
      margin: 0 0 14px;
      font-size: 1.15rem
    }

    .grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 12px
    }

    .grid .full {
      grid-column: 1/-1
    }

    label {
      display: block;
      font-size: .85rem;
      font-weight: 500
    }

    label input,
    label select {
      width: 100%;
      margin-top: 4px;
      font-weight: 400
    }

    .err {
      color: var(--out);
      font-size: .8rem;
      min-height: 1.1em;
      margin-top: 2px;
      font-weight: 400
    }

    .actions {
      display: flex;
      justify-content: flex-end;
      gap: 8px;
      margin-top: 16px
    }

    #toast {
      position: fixed;
      left: 50%;
      bottom: calc(20px + env(safe-area-inset-bottom, 0px));
      transform: translateX(-50%);
      background: var(--ink);
      color: var(--bg);
      padding: 10px 16px;
      border-radius: 6px;
      opacity: 0;
      pointer-events: none;
      transition: opacity .2s;
      max-width: 90%
    }

    #toast.show {
      opacity: 1
    }

    @media (max-width:860px) {
      .top .in {
        padding: 10px 16px;
        gap: 12px
      }

      .plate img {
        height: 42px
      }
    }

    @media (max-width:520px) {
      .grid {
        grid-template-columns: 1fr
      }
    }

    @media (prefers-reduced-motion:reduce) {
      #toast {
        transition: none
      }
    }

    .err {
      min-height: 1.1em
    }

    #toast.error {
      background: var(--out);
      color: #fff
    }
  </style>
</head>

<body>
  <header class="top">
    <div class="in">
      <div class="plate"><img alt="BURAQOIL logo" src="../images/buraqoil-logo.png"></div>
      <div><strong>Lubricant Stock Management System</strong><span>IPTB Sdn Bhd</span></div>
    </div>
  </header>
  <div class="wrap">
    <h1>Lubricant products</h1>
    <p class="sub">Master list of lubricant SKUs for IPTB Sdn Bhd. Stock quantities change only through deliveries, sales and stock adjustments.</p>
    <p class="sub" id="locNote" hidden></p>

    <div class="stats" aria-label="Summary">
      <div class="stat"><b id="sTotal"><?= $sTotal ?></b><span>Products</span></div>
      <div class="stat"><b id="sLow"><?= $sLow ?></b><span>Low stock</span></div>
      <div class="stat"><b id="sOut"><?= $sOut ?></b><span>Out of stock</span></div>
    </div>

    <form class="toolbar" method="get" action="products.php" id="filterForm" role="search">
      <div class="tb-top">
        <div class="search">
          <svg class="s-ico" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
            <circle cx="11" cy="11" r="7" />
            <path d="m20 20-3.5-3.5" />
          </svg>
          <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search brand, product or grade" aria-label="Search products" maxlength="100">
          <button type="submit" class="s-go">Search</button>
        </div>
        <button type="button" class="primary add" id="addBtn"><span aria-hidden="true">+</span> Add product</button>
      </div>
      <div class="tb-filters">
        <span class="f-label">Filter</span>
        <select name="loc" id="fLoc" class="<?= $loc !== '' ? 'on' : '' ?>" aria-label="Filter by location" onchange="this.form.submit()">
          <option value="">All locations</option>
          <?php foreach ($locations as $l): ?>
            <option value="<?= e($l['location_code']) ?>" <?= $loc === $l['location_code'] ? ' selected' : '' ?>><?= e($l['location_name']) ?></option>
          <?php endforeach; ?>
        </select>
        <select name="brand" class="<?= $fBrand !== '' ? 'on' : '' ?>" aria-label="Filter by brand" onchange="this.form.submit()">
          <option value="">All brands</option>
          <?php foreach ($brands as $b): ?>
            <option<?= $fBrand === $b ? ' selected' : '' ?>><?= e($b) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="type" class="<?= $fType !== '' ? 'on' : '' ?>" aria-label="Filter by type" onchange="this.form.submit()">
          <option value="">All types</option>
          <?php foreach (PRODUCT_TYPES as $t): ?>
            <option<?= $fType === $t ? ' selected' : '' ?>><?= e($t) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="status" class="<?= $fStatus !== '' ? 'on' : '' ?>" aria-label="Filter by stock status" onchange="this.form.submit()">
          <option value="">All stock status</option>
          <?php foreach ($LABEL as $k => $lab): ?>
            <option value="<?= $k ?>" <?= $fStatus === $k ? ' selected' : '' ?>><?= e($lab) ?></option>
          <?php endforeach; ?>
        </select>
        <?php if ($filters): ?><a class="clear" href="products.php">Clear filters</a><?php endif; ?>
      </div>
    </form>

    <div class="tablewrap">
      <table>
        <thead>
          <tr>
            <th>Image</th>
            <th>SKU</th>
            <th>Product</th>
            <th>Type</th>
            <th class="num">Pack</th>
            <th class="num">Cost (RM)</th>
            <th class="num">Price (RM)</th>
            <th class="num">Min stock</th>
            <?php if ($many): foreach ($scope as $c): ?><th class="stk">Stock: <?= e(SHORT_NAMES[$c] ?? $c) ?></th><?php endforeach;
                                                                                                                else: ?><th class="num">Current stock</th>
              <th>Status</th><?php endif; ?>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($list as $p):
            $name = $p['brand'] . ' ' . $p['product_name'] . ' ' . $p['viscosity_grade'];
            $edit = [
              'id' => (int)$p['product_id'],
              'brand' => $p['brand'],
              'product_name' => $p['product_name'],
              'viscosity_grade' => $p['viscosity_grade'],
              'product_type' => $p['product_type'],
              'pack_size' => (float)$p['pack_size'],
              'min_stock' => (int)$p['min_stock'],
              'cost_price' => number_format((float)$p['cost_price'], 2, '.', ''),
              'selling_price' => number_format((float)$p['selling_price'], 2, '.', ''),
            ];
            $src = img_src($p);
          ?>
            <tr>
              <td><button type="button" class="zoom" data-zoom="<?= e($src) ?>" data-title="<?= e($name . ' (' . pack_label($p['pack_size']) . ')') ?>" aria-label="View larger image of <?= e($name) ?>"><img class="thumb" src="<?= e($src) ?>" alt="" onerror="this.onerror=null;this.src=NOIMG"></button></td>
              <td><?= e($p['sku']) ?></td>
              <td class="name"><strong><?= e($p['brand'] . ' ' . $p['product_name']) ?></strong><small><?= e($p['viscosity_grade']) ?></small></td>
              <td><?= e($p['product_type']) ?></td>
              <td class="num"><?= e(pack_label($p['pack_size'])) ?></td>
              <td class="num"><?= e(number_format((float)$p['cost_price'], 2)) ?></td>
              <td class="num"><?= e(number_format((float)$p['selling_price'], 2)) ?></td>
              <td class="num"><?= (int)$p['min_stock'] ?></td>
              <?php if ($many): foreach ($scope as $c): $st = $p['status_in'][$c]; ?>
                  <td class="stk">
                    <div class="cell"><b><?= (int)$p['qty'][$c] ?></b><span class="chip <?= $st ?>"><?= e($LABEL[$st]) ?></span></div>
                  </td>
                <?php endforeach;
              else: $c = $scope[0] ?? '';
                $st = $p['status_in'][$c] ?? 'out'; ?>
                <td class="num"><?= (int)($p['qty'][$c] ?? 0) ?></td>
                <td><span class="chip <?= $st ?>"><?= e($LABEL[$st]) ?></span></td>
              <?php endif; ?>
              <td>
                <button type="button" class="ico" data-edit="<?= e(json_encode($edit, JSON_UNESCAPED_UNICODE)) ?>" title="Edit" aria-label="Edit <?= e($name) ?>">&#9999;&#65039;</button>
                <button type="button" class="ico del" data-del="<?= (int)$p['product_id'] ?>" data-name="<?= e($name . ' (' . pack_label($p['pack_size']) . ')') ?>" data-stock="<?= (int)$p['total'] ?>" title="Delete" aria-label="Delete <?= e($name) ?>">&#128465;&#65039;</button>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php if (!$list): ?><div class="empty">No products match your search. Clear the filters or add a new product.</div><?php endif; ?>
    </div>
    <p class="count" aria-live="polite">Showing <?= count($list) ?> of <?= $sTotal ?> products</p>
  </div>

  <dialog id="formDlg">
    <form class="dlg" id="form" method="post" action="<?= e($formQuery) ?>" enctype="multipart/form-data" novalidate>
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" id="fAction" value="<?= e($formAction) ?>">
      <input type="hidden" name="product_id" id="fId" value="<?= (int)$formId ?>">
      <input type="hidden" name="MAX_FILE_SIZE" value="<?= MAX_IMAGE_BYTES ?>">
      <h2 id="formTitle"><?= $formAction === 'update' ? 'Edit product' : 'Add product' ?></h2>
      <div class="grid">
        <label>Brand<input name="brand" value="<?= field($old, 'brand') ?>" autocomplete="off" maxlength="50">
          <div class="err"><?= e($formErrors['brand'] ?? '') ?></div>
        </label>
        <label>Product name<input name="product_name" value="<?= field($old, 'product_name') ?>" autocomplete="off" maxlength="100">
          <div class="err"><?= e($formErrors['product_name'] ?? '') ?></div>
        </label>
        <label>Viscosity grade<input name="viscosity_grade" value="<?= field($old, 'viscosity_grade') ?>" placeholder="e.g. 10W-40" autocomplete="off" maxlength="20">
          <div class="err"><?= e($formErrors['viscosity_grade'] ?? '') ?></div>
        </label>
        <label>Type<select name="product_type">
            <?php foreach (PRODUCT_TYPES as $t): ?><option<?= ($old['product_type'] ?? PRODUCT_TYPES[0]) === $t ? ' selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?>
          </select>
          <div class="err"><?= e($formErrors['product_type'] ?? '') ?></div>
        </label>
        <label>Pack size<select name="pack_size">
            <?php foreach (PACK_SIZES as $n): ?><option value="<?= $n ?>" <?= (float)($old['pack_size'] ?? 1) === $n ? ' selected' : '' ?>><?= e(pack_label($n)) ?></option><?php endforeach; ?>
          </select>
          <div class="err"><?= e($formErrors['pack_size'] ?? '') ?></div>
        </label>
        <label>Minimum stock (packs)<input name="min_stock" type="number" min="0" step="1" inputmode="numeric" value="<?= field($old, 'min_stock') ?>">
          <div class="err"><?= e($formErrors['min_stock'] ?? '') ?></div>
        </label>
        <label>Cost price (RM)<input name="cost_price" type="number" min="0" step="0.01" inputmode="decimal" value="<?= field($old, 'cost_price') ?>">
          <div class="err"><?= e($formErrors['cost_price'] ?? '') ?></div>
        </label>
        <label>Selling price (RM)<input name="selling_price" type="number" min="0" step="0.01" inputmode="decimal" value="<?= field($old, 'selling_price') ?>">
          <div class="err"><?= e($formErrors['selling_price'] ?? '') ?></div>
        </label>
        <label class="full">Product image (optional, JPG or PNG, up to 2 MB)<input name="image" type="file" accept="image/jpeg,image/png">
          <div class="err"><?= e($formErrors['image'] ?? '') ?></div>
        </label>
      </div>
      <div class="actions"><button type="button" id="cancelForm">Cancel</button><button class="primary" type="submit">Save product</button></div>
    </form>
  </dialog>

  <dialog id="delDlg">
    <form class="dlg" method="post" action="<?= e($formQuery) ?>">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="delete">
      <input type="hidden" name="product_id" id="delId" value="0">
      <h2>Delete this product?</h2>
      <p id="delText"></p>
      <div class="actions"><button type="button" id="cancelDel">Keep product</button><button class="danger" type="submit">Delete product</button></div>
    </form>
  </dialog>

  <dialog id="imgDlg" aria-labelledby="imgTitle">
    <div class="dlg">
      <h2 id="imgTitle"></h2>
      <img id="bigImg" alt="">
      <div class="actions"><button type="button" id="closeImg">Close</button></div>
    </div>
  </dialog>

  <div id="toast" role="status" class="<?= $flash ? 'show ' . e($flash['type']) : '' ?>"><?= $flash ? e($flash['msg']) : '' ?></div>

  <script>
    var NOIMG = 'data:image/svg+xml;utf8,' + encodeURIComponent('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 60 80"><rect width="60" height="80" fill="#f3f5f6"/><path d="M20 14h20l8 12v46a6 6 0 0 1-6 6H18a6 6 0 0 1-6-6V26z" fill="#c9d1d8"/></svg>');
    (function() {
      var $ = function(id) {
        return document.getElementById(id)
      };
      var F = $('form');

      // Staff see only their own location (the login in this demo is kept in the browser session).
      if (window.LSMS && LSMS.myLoc) {
        var u = new URL(location.href);
        if (u.searchParams.get('loc') !== LSMS.myLoc) {
          u.searchParams.set('loc', LSMS.myLoc);
          location.replace(u.href);
          return
        }
        if ($('fLoc')) $('fLoc').remove();
        $('locNote').textContent = 'Stock shown for your location: ' + LSMS.locName(LSMS.myLoc) + '.';
        $('locNote').hidden = false;
      }

      function clearErrors() {
        document.querySelectorAll('#form .err').forEach(function(x) {
          x.textContent = ''
        })
      }

      function openForm(p) {
        clearErrors();
        $('formTitle').textContent = p ? 'Edit product' : 'Add product';
        $('fAction').value = p ? 'update' : 'create';
        $('fId').value = p ? p.id : 0;
        F.brand.value = p ? p.brand : '';
        F.product_name.value = p ? p.product_name : '';
        F.viscosity_grade.value = p ? p.viscosity_grade : '';
        F.product_type.value = p ? p.product_type : F.product_type.options[0].value;
        F.pack_size.value = p ? String(p.pack_size) : '1';
        F.min_stock.value = p ? p.min_stock : '';
        F.cost_price.value = p ? p.cost_price : '';
        F.selling_price.value = p ? p.selling_price : '';
        F.image.value = '';
        $('formDlg').showModal();
        F.brand.focus();
      }
      $('addBtn').onclick = function() {
        openForm(null)
      };
      $('cancelForm').onclick = function() {
        $('formDlg').close()
      };
      document.querySelector('tbody').addEventListener('click', function(e) {
        var ed = e.target.closest('[data-edit]'),
          de = e.target.closest('[data-del]'),
          zm = e.target.closest('[data-zoom]');
        if (ed) openForm(JSON.parse(ed.dataset.edit));
        if (de) {
          var n = +de.dataset.stock;
          $('delId').value = de.dataset.del;
          $('delText').textContent = '"' + de.dataset.name + '" will be removed from the product list.' + (n > 0 ? ' It still has ' + n + ' in stock.' : '');
          $('delDlg').showModal();
        }
        if (zm) {
          $('imgTitle').textContent = zm.dataset.title;
          $('bigImg').src = zm.dataset.zoom || NOIMG;
          $('bigImg').alt = zm.dataset.title;
          $('imgDlg').showModal()
        }
      });
      $('cancelDel').onclick = function() {
        $('delDlg').close()
      };
      $('closeImg').onclick = function() {
        $('imgDlg').close()
      };
      $('imgDlg').addEventListener('click', function(e) {
        if (e.target === this) this.close()
      });
      <?php if ($openForm): ?>$('formDlg').showModal();
    <?php endif; ?>
    var t = $('toast');
    if (t.classList.contains('show')) setTimeout(function() {
      t.classList.remove('show')
    }, 4000);
    })();
  </script>
  <script src="../sidebar.js"></script>
</body>

</html>
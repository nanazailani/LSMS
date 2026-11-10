<?php
// Module 2: Stock Receiving. Order stock from suppliers (purchase orders), receive the delivery,
// add / edit / cancel deliveries and view stock on hand.
// Stock is never written here: the triggers on delivery_items change location_stock (see database/iptb_lsms.sql).
require __DIR__ . '/../includes/auth.php';

$me  = require_login('../');       // admin and staff can open Module 2
$pdo = db_or_die();

date_default_timezone_set('Asia/Kuala_Lumpur');
$today = date('Y-m-d');

const SHORT_NAMES = ['PHG' => 'Kuantan', 'KTN' => 'Kota Bharu'];
const IMG_BASE    = '../module%201/';    // product images are uploaded by Module 1
const NOIMG       = 'data:image/svg+xml;utf8,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 60 80%22%3E%3Crect width=%2260%22 height=%2280%22 fill=%22%23f3f5f6%22/%3E%3Cpath d=%22M20 14h20l8 12v46a6 6 0 0 1-6 6H18a6 6 0 0 1-6-6V26z%22 fill=%22%23c9d1d8%22/%3E%3C/svg%3E';

$locations = $pdo->query('SELECT location_id, location_code, location_name, address FROM locations WHERE is_active = 1 ORDER BY location_id')->fetchAll();
$locById   = array_column($locations, null, 'location_id');
$isAdmin   = $me['role'] === 'admin';
// Locations this user may see and change. Checked on every request, not only hidden in the page.
$allowed   = $isAdmin ? array_map('intval', array_column($locations, 'location_id')) : [(int)$me['location_id']];

/** Keep only the list filters we know about. */
function pick_filters(array $src): array
{
  $out = [];
  foreach (['loc', 'q', 'sup', 'ostatus'] as $k) {
    if (isset($src[$k]) && is_string($src[$k]) && $src[$k] !== '') {
      $out[$k] = $src[$k];
    }
  }
  return $out;
}
function page_url(array $filters): string
{
  return 'stock-receiving.php' . ($filters ? '?' . http_build_query($filters) : '');
}
/** $hl: rows to highlight after the redirect (codes such as PO-002, and "location:product" stock rows). */
function flash_and_go(string $type, string $msg, array $filters, array $hl = []): void
{
  $_SESSION['flash'] = ['type' => $type, 'msg' => $msg, 'hl' => $hl];
  header('Location: ' . page_url($filters));
  exit;
}
/** "?,?,?" for an IN (...) list */
function marks(array $a): string
{
  return implode(',', array_fill(0, count($a), '?'));
}
function find_order(PDO $pdo, int $id, array $allowed): ?array
{
  $st = $pdo->prepare('SELECT * FROM purchase_orders WHERE po_id = ? AND location_id IN (' . marks($allowed) . ')');
  $st->execute(array_merge([$id], $allowed));
  return $st->fetch() ?: null;
}
function find_delivery(PDO $pdo, int $id, array $allowed): ?array
{
  $st = $pdo->prepare('SELECT * FROM deliveries WHERE delivery_id = ? AND location_id IN (' . marks($allowed) . ')');
  $st->execute(array_merge([$id], $allowed));
  return $st->fetch() ?: null;
}
/** product_id => SKU */
function sku_map(PDO $pdo): array
{
  return array_column($pdo->query('SELECT product_id, sku FROM products')->fetchAll(), 'sku', 'product_id');
}
/** "LUB-001 +24, LUB-003 -5" */
function stock_text(array $delta, array $skus): string
{
  $out = [];
  foreach ($delta as $pid => $d) {
    if ($d !== 0) {
      $out[] = ($skus[$pid] ?? '?') . ' ' . ($d > 0 ? '+' : '') . $d;
    }
  }
  return implode(', ', $out);
}
/** The posted form values that are sent back to the dialog after a validation error. */
function old_input(array $post): array
{
  $out = [];
  foreach (['action', 'po_id', 'delivery_id', 'supplier_id', 'order_date', 'delivery_date', 'invoice_no', 'location_id'] as $k) {
    if (isset($post[$k]) && is_string($post[$k])) {
      $out[$k] = $post[$k];
    }
  }
  $out['items'] = [];
  foreach ((array)($post['items'] ?? []) as $r) {
    if (is_array($r)) {
      $out['items'][] = [
        'product_id' => (string)($r['product_id'] ?? ''),
        'qty'        => (string)($r['qty'] ?? ''),
        'cost'       => (string)($r['cost'] ?? ''),
      ];
    }
  }
  return $out;
}
function loc_short(array $locById, int $id): string
{
  $c = $locById[$id]['location_code'] ?? '';
  return SHORT_NAMES[$c] ?? $c;
}

$filters = pick_filters($_GET);
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$hl = $flash['hl'] ?? [];

$reopen = null;     // a dialog to open again with the posted values and the error messages

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_ok()) {
    http_response_code(400);
    echo 'Invalid request. Go back, reload the page and try again.';
    exit;
  }
  $action = (string)($_POST['action'] ?? '');

  // Only the admin places and cancels purchase orders; staff receive them at their location.
  if (($action === 'order_create' || $action === 'order_cancel') && !$isAdmin) {
    flash_and_go('error', 'Only the admin can place or cancel purchase orders.', $filters);
  }

  // ---------- Purchase order: create (stock does not change yet) ----------
  if ($action === 'order_create') {
    [$errors, $v] = validate_order($pdo, $_POST, $allowed, $today);
    if ($errors) {
      $reopen = ['dlg' => 'dOrd', 'values' => old_input($_POST), 'errors' => $errors];
    } else {
      try {
        $pdo->beginTransaction();
        $pdo->prepare('INSERT INTO purchase_orders (location_id, supplier_id, order_date, created_by) VALUES (?,?,?,?)')
          ->execute([$v['location_id'], $v['supplier_id'], $v['order_date'], $me['user_id']]);
        $id = (int)$pdo->lastInsertId();
        $ins = $pdo->prepare('INSERT INTO purchase_order_items (po_id, product_id, quantity, unit_cost) VALUES (?,?,?,?)');
        foreach ($v['items'] as $i) {
          $ins->execute([$id, $i['product_id'], $i['qty'], $i['cost']]);
        }
        $pdo->commit();
        $st = $pdo->prepare('SELECT supplier_name FROM suppliers WHERE supplier_id = ?');
        $st->execute([$v['supplier_id']]);
        $code = doc_code('PO', $id);
        flash_and_go('ok', 'Order ' . $code . ' placed with ' . $st->fetchColumn() . ' for ' . loc_short($locById, $v['location_id'])
          . '. Select Receive when the stock arrives.', $filters, [$code]);
      } catch (PDOException $ex) {
        if ($pdo->inTransaction()) {
          $pdo->rollBack();
        }
        error_log('IPTB-LSMS order save error: ' . $ex->getMessage());
        flash_and_go('error', 'The order could not be saved. Try again.', $filters);
      }
    }
  }

  // ---------- Purchase order: receive (creates the delivery, which adds the stock) ----------
  if ($action === 'order_receive') {
    $po = find_order($pdo, (int)($_POST['po_id'] ?? 0), $allowed);
    if ($po === null || $po['status'] !== 'ordered') {
      flash_and_go('error', 'That order is no longer waiting for delivery.', $filters);
    }
    $code = doc_code('PO', (int)$po['po_id']);
    $inv  = trim((string)($_POST['invoice_no'] ?? ''));
    $date = trim((string)($_POST['delivery_date'] ?? ''));
    $errors = [];
    if (($m = check_invoice($pdo, $inv, null)) !== null) {
      $errors['invoice_no'] = $m;
    }
    if (($m = check_date($date, 'delivery date', $today, $po['order_date'], 'the order date (' . date('d/m/Y', strtotime($po['order_date'])) . ')')) !== null) {
      $errors['delivery_date'] = $m;
    }
    if (!$errors) {
      try {
        $pdo->beginTransaction();
        $st = $pdo->prepare('SELECT status FROM purchase_orders WHERE po_id = ? FOR UPDATE');
        $st->execute([$po['po_id']]);
        if ($st->fetchColumn() !== 'ordered') {
          $pdo->rollBack();
          flash_and_go('error', $code . ' was already received or cancelled.', $filters);
        }
        $pdo->prepare('INSERT INTO deliveries (location_id, supplier_id, invoice_no, delivery_date, created_by) VALUES (?,?,?,?,?)')
          ->execute([$po['location_id'], $po['supplier_id'], $inv, $date, $me['user_id']]);
        $did = (int)$pdo->lastInsertId();
        // the delivery gets the ordered lines; trg_di_ai adds each line to stock
        $pdo->prepare('INSERT INTO delivery_items (delivery_id, product_id, quantity, unit_cost)
                       SELECT ?, product_id, quantity, unit_cost FROM purchase_order_items WHERE po_id = ? ORDER BY po_item_id')
          ->execute([$did, $po['po_id']]);
        $pdo->prepare("UPDATE purchase_orders SET status = 'received', delivery_id = ? WHERE po_id = ?")
          ->execute([$did, $po['po_id']]);
        $pdo->commit();

        $st = $pdo->prepare('SELECT product_id, quantity FROM delivery_items WHERE delivery_id = ?');
        $st->execute([$did]);
        $delta = array_map('intval', array_column($st->fetchAll(), 'quantity', 'product_id'));
        $dcode = doc_code('DEL', $did);
        $hlRows = [$code, $dcode];
        foreach ($delta as $pid => $d) {
          $hlRows[] = $po['location_id'] . ':' . $pid;
        }
        flash_and_go('ok', $code . ' received as ' . $dcode . '. Stock: ' . stock_text($delta, sku_map($pdo)), $filters, $hlRows);
      } catch (PDOException $ex) {
        if ($pdo->inTransaction()) {
          $pdo->rollBack();
        }
        if ($ex->getCode() === '23000') {          // the same invoice saved at the same moment
          $errors['invoice_no'] = 'Invoice ' . $inv . ' is already recorded. Check for a duplicate delivery.';
        } else {
          error_log('IPTB-LSMS receive error: ' . $ex->getMessage());
          flash_and_go('error', $code . ' could not be received. Try again.', $filters);
        }
      }
    }
    $reopen = ['dlg' => 'dRecv', 'values' => old_input($_POST), 'errors' => $errors];
  }

  // ---------- Purchase order: cancel (only while it is still waiting) ----------
  if ($action === 'order_cancel') {
    $po = find_order($pdo, (int)($_POST['po_id'] ?? 0), $allowed);
    if ($po === null) {
      flash_and_go('error', 'That order no longer exists.', $filters);
    }
    $code = doc_code('PO', (int)$po['po_id']);
    $st = $pdo->prepare("UPDATE purchase_orders SET status = 'cancelled' WHERE po_id = ? AND status = 'ordered'");
    $st->execute([$po['po_id']]);
    if ($st->rowCount() === 0) {
      flash_and_go('error', $code . ' was already received or cancelled, so it cannot be cancelled.', $filters);
    }
    flash_and_go('ok', $code . ' cancelled. Stock does not change.', $filters, [$code]);
  }

  // ---------- Delivery: add without an order, or edit ----------
  if ($action === 'delivery_create' || $action === 'delivery_update') {
    $editing  = null;
    $fixedLoc = null;
    if ($action === 'delivery_update') {
      $del = find_delivery($pdo, (int)($_POST['delivery_id'] ?? 0), $allowed);
      if ($del === null) {
        flash_and_go('error', 'That delivery no longer exists.', $filters);
      }
      $editing  = (int)$del['delivery_id'];
      $fixedLoc = (int)$del['location_id'];        // the location of a delivery cannot change
    }
    [$errors, $v] = validate_delivery($pdo, $_POST, $editing, $allowed, $fixedLoc, $today);

    if (!$errors) {
      try {
        $pdo->beginTransaction();
        $new = array_column($v['items'], null, 'product_id');
        $old = [];
        if ($editing !== null) {
          $st = $pdo->prepare('SELECT product_id, quantity FROM delivery_items WHERE delivery_id = ?');
          $st->execute([$editing]);
          $old = array_map('intval', array_column($st->fetchAll(), 'quantity', 'product_id'));
        }
        // net stock change per product (new minus old when editing)
        $delta = [];
        foreach ($new as $pid => $i) {
          $delta[$pid] = $i['qty'] - ($old[$pid] ?? 0);
        }
        foreach ($old as $pid => $q) {
          if (!isset($new[$pid])) {
            $delta[$pid] = -$q;
          }
        }
        $m = check_stock_drop($pdo, $v['location_id'], $delta, 'save the delivery');
        if ($m !== null) {
          $pdo->rollBack();
          $errors['items'] = $m;
        } else {
          if ($editing === null) {
            $pdo->prepare('INSERT INTO deliveries (location_id, supplier_id, invoice_no, delivery_date, created_by) VALUES (?,?,?,?,?)')
              ->execute([$v['location_id'], $v['supplier_id'], $v['invoice_no'], $v['delivery_date'], $me['user_id']]);
            $id = (int)$pdo->lastInsertId();
          } else {
            $id = $editing;
            $pdo->prepare('UPDATE deliveries SET supplier_id = ?, invoice_no = ?, delivery_date = ? WHERE delivery_id = ?')
              ->execute([$v['supplier_id'], $v['invoice_no'], $v['delivery_date'], $id]);
            // the triggers on delivery_items change the stock for each line added, changed or removed
            $delSt = $pdo->prepare('DELETE FROM delivery_items WHERE delivery_id = ? AND product_id = ?');
            foreach ($old as $pid => $q) {
              if (!isset($new[$pid])) {
                $delSt->execute([$id, $pid]);
              }
            }
          }
          $ins = $pdo->prepare('INSERT INTO delivery_items (delivery_id, product_id, quantity, unit_cost) VALUES (?,?,?,?)');
          $upd = $pdo->prepare('UPDATE delivery_items SET quantity = ?, unit_cost = ? WHERE delivery_id = ? AND product_id = ?');
          foreach ($new as $pid => $i) {
            if (isset($old[$pid])) {
              $upd->execute([$i['qty'], $i['cost'], $id, $pid]);
            } else {
              $ins->execute([$id, $pid, $i['qty'], $i['cost']]);
            }
          }
          $pdo->commit();

          $code = doc_code('DEL', $id);
          $hlRows = [$code];
          foreach ($delta as $pid => $d) {
            if ($d !== 0) {
              $hlRows[] = $v['location_id'] . ':' . $pid;
            }
          }
          $txt = stock_text($delta, sku_map($pdo));
          flash_and_go(
            'ok',
            ($editing === null ? 'Delivery ' . $code . ' saved' : 'Delivery ' . $code . ' updated') . ' at ' . loc_short($locById, $v['location_id'])
              . ($txt !== '' ? '. Stock: ' . $txt : '. Stock unchanged'),
            $filters,
            $hlRows
          );
        }
      } catch (PDOException $ex) {
        if ($pdo->inTransaction()) {
          $pdo->rollBack();
        }
        if ($ex->getCode() === '23000') {
          $errors['invoice_no'] = 'Invoice ' . $v['invoice_no'] . ' is already recorded. Check for a duplicate delivery.';
        } elseif ($ex->getCode() === '45000') {    // stock rule in the database trigger
          $errors['items'] = (string)($ex->errorInfo[2] ?? 'Stock would drop below 0.');
        } else {
          error_log('IPTB-LSMS delivery save error: ' . $ex->getMessage());
          flash_and_go('error', 'The delivery could not be saved. Try again.', $filters);
        }
      }
    }
    $reopen = ['dlg' => 'dForm', 'values' => old_input($_POST), 'errors' => $errors];
  }

  // ---------- Delivery: cancel (takes the stock out again) ----------
  if ($action === 'delivery_delete') {
    $del = find_delivery($pdo, (int)($_POST['delivery_id'] ?? 0), $allowed);
    if ($del === null) {
      flash_and_go('error', 'That delivery no longer exists.', $filters);
    }
    $id   = (int)$del['delivery_id'];
    $loc  = (int)$del['location_id'];
    $code = doc_code('DEL', $id);
    try {
      $pdo->beginTransaction();
      $st = $pdo->prepare('SELECT product_id, quantity FROM delivery_items WHERE delivery_id = ?');
      $st->execute([$id]);
      $delta = [];
      foreach ($st->fetchAll() as $r) {
        $delta[(int)$r['product_id']] = -(int)$r['quantity'];
      }
      $m = check_stock_drop($pdo, $loc, $delta, 'cancel ' . $code);
      if ($m !== null) {
        $pdo->rollBack();
        flash_and_go('error', $m, $filters);
      }
      $st = $pdo->prepare('SELECT po_id FROM purchase_orders WHERE delivery_id = ?');
      $st->execute([$id]);
      $poId = $st->fetchColumn();
      // an order received as this delivery goes back to "Ordered"
      $pdo->prepare("UPDATE purchase_orders SET status = 'ordered', delivery_id = NULL WHERE delivery_id = ?")->execute([$id]);
      $pdo->prepare('DELETE FROM deliveries WHERE delivery_id = ?')->execute([$id]);   // trg_deliveries_bd takes the stock out
      $pdo->commit();

      $hlRows = [];
      foreach ($delta as $pid => $d) {
        $hlRows[] = $loc . ':' . $pid;
      }
      $msg = $code . ' cancelled. Stock: ' . stock_text($delta, sku_map($pdo));
      if ($poId !== false) {
        $hlRows[] = doc_code('PO', (int)$poId);
        $msg .= '. ' . doc_code('PO', (int)$poId) . ' is back to Ordered.';
      }
      flash_and_go('ok', $msg, $filters, $hlRows);
    } catch (PDOException $ex) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }
      if ($ex->getCode() === '45000') {
        flash_and_go('error', (string)($ex->errorInfo[2] ?? 'Cannot cancel the delivery.'), $filters);
      }
      error_log('IPTB-LSMS delivery delete error: ' . $ex->getMessage());
      flash_and_go('error', $code . ' could not be cancelled. Try again.', $filters);
    }
  }
}

// ---------- Read: orders, deliveries and stock for the locations in view ----------
$codes = array_column($locations, 'location_code');
$loc   = $isAdmin && isset($filters['loc']) && in_array($filters['loc'], $codes, true) ? $filters['loc'] : '';
$scope = $loc !== '' ? [(int)array_column($locations, 'location_id', 'location_code')[$loc]] : $allowed;
$many  = count($scope) > 1;          // show the Location column
$in    = marks($scope);

$suppliers = $pdo->query('SELECT supplier_id, supplier_name FROM suppliers WHERE is_active = 1 ORDER BY supplier_name')->fetchAll();
$products  = $pdo->query('SELECT * FROM products WHERE is_active = 1 ORDER BY product_id')->fetchAll();

$q       = isset($filters['q']) ? trim($filters['q']) : '';
$fSup    = (int)($filters['sup'] ?? 0);
$fStatus = isset(ORDER_STATUS[$filters['ostatus'] ?? '']) ? $filters['ostatus'] : '';

/** Lines of the orders / deliveries in view, grouped by their id. */
function lines_by(PDO $pdo, string $sql, array $scope): array
{
  $out = [];
  $st = $pdo->prepare($sql);
  $st->execute($scope);
  foreach ($st as $r) {
    $out[(int)$r['id']][] = ['product_id' => (int)$r['product_id'], 'qty' => (int)$r['quantity'], 'cost' => (float)$r['unit_cost']];
  }
  return $out;
}
function sum_units(array $items): int
{
  return array_sum(array_column($items, 'qty'));
}
function sum_value(array $items): float
{
  $t = 0.0;
  foreach ($items as $i) {
    $t += $i['qty'] * $i['cost'];
  }
  return $t;
}

$st = $pdo->prepare(
  "SELECT po.po_id, po.location_id, po.supplier_id, po.order_date, po.status, po.delivery_id,
          s.supplier_name, l.location_code, d.invoice_no, d.delivery_date
     FROM purchase_orders po
     JOIN suppliers s ON s.supplier_id = po.supplier_id
     JOIN locations l ON l.location_id = po.location_id
     LEFT JOIN deliveries d ON d.delivery_id = po.delivery_id
    WHERE po.location_id IN ($in)
    ORDER BY po.order_date DESC, po.po_id DESC"
);
$st->execute($scope);
$orders = $st->fetchAll();
$oLines = lines_by($pdo, "SELECT i.po_id AS id, i.product_id, i.quantity, i.unit_cost FROM purchase_order_items i
                            JOIN purchase_orders po ON po.po_id = i.po_id WHERE po.location_id IN ($in) ORDER BY i.po_item_id", $scope);

$st = $pdo->prepare(
  "SELECT d.delivery_id, d.location_id, d.supplier_id, d.invoice_no, d.delivery_date,
          s.supplier_name, l.location_code, po.po_id
     FROM deliveries d
     JOIN suppliers s ON s.supplier_id = d.supplier_id
     JOIN locations l ON l.location_id = d.location_id
     LEFT JOIN purchase_orders po ON po.delivery_id = d.delivery_id
    WHERE d.location_id IN ($in)
    ORDER BY d.delivery_date DESC, d.delivery_id DESC"
);
$st->execute($scope);
$deliveries = $st->fetchAll();
$dLines = lines_by($pdo, "SELECT i.delivery_id AS id, i.product_id, i.quantity, i.unit_cost FROM delivery_items i
                            JOIN deliveries d ON d.delivery_id = i.delivery_id WHERE d.location_id IN ($in) ORDER BY i.delivery_item_id", $scope);

foreach ($orders as &$o) {
  $o['code']  = doc_code('PO', (int)$o['po_id']);
  $o['items'] = $oLines[(int)$o['po_id']] ?? [];
}
unset($o);
foreach ($deliveries as &$d) {
  $d['code']    = doc_code('DEL', (int)$d['delivery_id']);
  $d['po_code'] = $d['po_id'] !== null ? doc_code('PO', (int)$d['po_id']) : '';
  $d['items']   = $dLines[(int)$d['delivery_id']] ?? [];
}
unset($d);

// summary boxes cover every order and delivery in view, not only the filtered rows
$sWaiting = count(array_filter($orders, fn($o) => $o['status'] === 'ordered'));
$sUnits   = 0;
$sValue   = 0.0;
foreach ($deliveries as $d) {
  $sUnits += sum_units($d['items']);
  $sValue += sum_value($d['items']);
}

$orderList = array_values(array_filter($orders, fn($o) => $fStatus === '' || $o['status'] === $fStatus));
$delList   = array_values(array_filter($deliveries, function ($d) use ($q, $fSup) {
  if ($fSup !== 0 && (int)$d['supplier_id'] !== $fSup) {
    return false;
  }
  return $q === '' || stripos($d['supplier_name'] . ' ' . $d['invoice_no'] . ' ' . $d['po_code'] . ' ' . $d['code'], $q) !== false;
}));

// stock on hand: every product at every location in view
$stockQty = [];
$st = $pdo->prepare("SELECT location_id, product_id, current_stock FROM location_stock WHERE location_id IN ($in)");
$st->execute($scope);
foreach ($st as $r) {
  $stockQty[(int)$r['location_id']][(int)$r['product_id']] = (int)$r['current_stock'];
}
$LABEL = ['ok' => 'In stock', 'low' => 'Low stock', 'out' => 'Out of stock'];
$BADGE = ['ok' => 'in', 'low' => 'lo', 'out' => 'no'];
$stockRows = [];
foreach ($scope as $lid) {
  foreach ($products as $p) {
    $n = $stockQty[$lid][(int)$p['product_id']] ?? 0;
    $stockRows[] = ['loc' => $lid, 'p' => $p, 'qty' => $n, 'status' => stock_status($n, (int)$p['min_stock'])];
  }
}

function pack_label($n): string
{
  return rtrim(rtrim(number_format((float)$n, 2, '.', ''), '0'), '.') . ' L';
}
function money($n): string
{
  return number_format((float)$n, 2);
}
function dmy(string $d): string
{
  return date('d/m/Y', strtotime($d));
}
function img_src(array $p): string
{
  if (empty($p['image_path'])) {
    return NOIMG;
  }
  $file = __DIR__ . '/../module 1/' . $p['image_path'];
  return is_file($file) ? IMG_BASE . $p['image_path'] . '?v=' . filemtime($file) : NOIMG;
}

// Data used by the dialogs (view, invoice, edit, print). Built from the same rows as the tables.
$js = [
  'today'    => $today,
  'user'     => $me['full_name'],
  'locs'     => [],
  'products' => [],
  'orders'   => [],
  'deliveries' => [],
  'stock'    => [],
  'reopen'   => $reopen,
];
foreach ($locations as $l) {
  $js['locs'][(int)$l['location_id']] = ['code' => $l['location_code'], 'name' => $l['location_name'], 'addr' => (string)$l['address']];
}
foreach ($products as $p) {
  $js['products'][(int)$p['product_id']] = [
    'sku'   => $p['sku'],
    'name'  => $p['brand'] . ' ' . $p['product_name'],
    'grade' => $p['viscosity_grade'],
    'pack'  => pack_label($p['pack_size']),
    'cost'  => number_format((float)$p['cost_price'], 2, '.', ''),
  ];
}
foreach ($orders as $o) {
  $js['orders'][(int)$o['po_id']] = [
    'code' => $o['code'], 'date' => $o['order_date'], 'loc' => (int)$o['location_id'], 'supplier' => $o['supplier_name'],
    'status' => $o['status'], 'del' => $o['delivery_id'] !== null ? doc_code('DEL', (int)$o['delivery_id']) : '',
    'invoice' => (string)$o['invoice_no'], 'received' => (string)$o['delivery_date'], 'items' => $o['items'],
  ];
}
foreach ($deliveries as $d) {
  $js['deliveries'][(int)$d['delivery_id']] = [
    'code' => $d['code'], 'date' => $d['delivery_date'], 'loc' => (int)$d['location_id'], 'supplier_id' => (int)$d['supplier_id'],
    'supplier' => $d['supplier_name'], 'invoice' => $d['invoice_no'], 'po' => $d['po_code'], 'items' => $d['items'],
  ];
}
foreach ($stockRows as $r) {
  $js['stock'][] = [
    'loc' => $r['loc'], 'sku' => $r['p']['sku'], 'name' => $r['p']['brand'] . ' ' . $r['p']['product_name'] . ' ' . $r['p']['viscosity_grade'],
    'type' => $r['p']['product_type'], 'pack' => pack_label($r['p']['pack_size']), 'min' => (int)$r['p']['min_stock'],
    'qty' => $r['qty'], 'status' => $LABEL[$r['status']], 'cost' => (float)$r['p']['cost_price'],
  ];
}
$formQuery = page_url($filters);
$canPickLoc = count($allowed) > 1;     // admin chooses the location of a new order or delivery
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>IPTB-LSMS · Stock Receiving</title>
  <?= session_bridge_script($me) ?>
  <script src="../lsms.js"></script>
  <style>
    :root{--red:#d4161c;--red-d:#a80f14;--yel:#f5c400;--ink:#17202a;--mut:#5c6670;--line:#dfe3e8;--bg:#f6f7f9;--ok:#1d7a3e;--okb:#e1f4e7;--lo:#8a5a00;--lob:#fdf0cc;--no:#b3261e;--nob:#fde4e2;--cv:#1a4fa0;--cvb:#e3ecfb}
    *{box-sizing:border-box}
    body{margin:0;font-family:"Segoe UI",system-ui,-apple-system,Roboto,Arial,sans-serif;color:var(--ink);background:#fff;line-height:1.45}
    header{padding:10px 0;border-bottom:5px solid var(--red);box-shadow:0 5px 0 var(--yel);margin-bottom:5px}
    .hin{padding:0 28px;display:flex;gap:14px;align-items:center}
    [hidden]{display:none!important}
    .logo-img{height:56px;width:auto;display:block}
    header h2{margin:0;font-size:17px;line-height:1.3}header p{margin:2px 0 0;color:var(--mut);font-size:13.5px}
    main{max-width:1360px;margin:0 auto;padding:30px clamp(16px,5vw,64px) 60px}
    h1{margin:0 0 6px;font-size:30px}h3{margin:44px 0 6px;font-size:21px}
    .lead{max-width:640px;color:var(--mut);margin:0 0 20px}
    .stats{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:22px}
    .stat{border:1px solid var(--line);border-left:4px solid var(--yel);border-radius:4px;padding:10px 20px 10px 14px;min-width:138px}
    .stat b{display:block;font-size:26px}.stat span{color:var(--mut);font-size:14px}
    .bar{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px}
    input,select{font:inherit;padding:9px 11px;border:1px solid #c7cdd4;border-radius:4px;background:#fff;color:var(--ink)}
    #q{flex:1 1 280px}
    button{font:inherit;cursor:pointer}
    .btn{background:var(--red);color:#fff;border:0;border-radius:4px;padding:9px 18px;font-weight:600}
    .btn:hover{background:var(--red-d)}
    .btn.sec{background:#fff;color:var(--ink);border:1px solid #c7cdd4}.btn.sec:hover{background:var(--bg)}
    .ib{width:38px;height:38px;border-radius:50%;border:1px solid var(--line);background:#fff;font-size:16px;margin-right:8px;padding:0;line-height:1}
    .ib:hover{background:var(--bg)}.ib.d{background:var(--nob);border-color:#f0b4b0}.ib.d:hover{background:#fbd3cf}
    .ib.ok{background:var(--okb);border-color:#b9e0c6}.ib.ok:hover{background:#cfeedb}
    .pimg img{max-width:100%;max-height:100%;object-fit:contain}
    .pimg{width:50px;height:50px;border:1px solid var(--line);border-radius:6px;display:flex;align-items:center;justify-content:center;background:#fff}
    :focus-visible{outline:3px solid #1a5fd0;outline-offset:2px}
    .wrap{overflow-x:auto;border:1px solid var(--line);border-radius:6px}
    table{width:100%;border-collapse:collapse;min-width:760px}
    th{background:var(--bg);text-align:left;font-size:14px;padding:12px 14px;border-bottom:1px solid var(--line);white-space:nowrap}
    td{padding:13px 14px;border-bottom:1px solid var(--line);font-size:15px;vertical-align:middle}
    tr:last-child td{border-bottom:0}
    .n{text-align:right}th.n{text-align:right}
    .sub{display:block;color:var(--mut);font-size:13px}
    .nw{white-space:nowrap}
    .badge{display:inline-flex;align-items:center;font-size:12px;font-weight:600;padding:3px 10px;border-radius:12px;white-space:nowrap}
    .dot{width:8px;height:8px;border-radius:50%;margin-right:6px;background:currentColor}
    .in .dot{background:#22a553}.lo .dot{background:#f5b700}.no .dot{background:#d92d20}
    .in{background:var(--okb);color:var(--ok)}.lo{background:var(--lob);color:var(--lo)}.no{background:var(--nob);color:var(--no)}
    .cv{background:var(--cvb);color:var(--cv)}.cx{background:var(--bg);color:var(--mut)}
    .empty{padding:34px;text-align:center;color:var(--mut)}
    @keyframes fl{from{background:#fff3b0}to{background:transparent}}
    .flash td{animation:fl 1.6s ease-out}
    dialog{border:0;border-radius:8px;padding:0;width:min(720px,94vw);box-shadow:0 12px 50px rgba(0,0,0,.3)}
    dialog.wide{width:min(860px,96vw)}
    dialog::backdrop{background:rgba(23,32,42,.5)}
    .dh{padding:16px 22px;border-bottom:1px solid var(--line);font-weight:700;font-size:18px}
    .db{padding:18px 22px;max-height:68vh;overflow:auto}
    .df{padding:14px 22px;border-top:1px solid var(--line);display:flex;justify-content:flex-end;gap:10px;background:var(--bg)}
    .g{display:grid;grid-template-columns:1fr 1fr;gap:12px 14px}
    .g label{display:flex;flex-direction:column;gap:4px;font-size:14px;font-weight:600}
    .g .full{grid-column:1/-1}
    .g .ro{font-weight:400;padding:9px 0}
    .ln{display:grid;grid-template-columns:minmax(0,1fr) 70px 96px 84px 30px;gap:8px;align-items:center;margin-bottom:8px}
    .ln select{width:100%;min-width:0}
    .ln span.t{text-align:right;font-size:14px}
    .ln .x{border:0;background:none;color:var(--red);font-size:20px}
    .lh{font-size:13px;color:var(--mut);margin:14px 0 6px;display:flex;justify-content:space-between;align-items:center}
    .err{color:var(--no);font-size:14px;margin-top:10px;min-height:20px;font-weight:600}
    .ferr{color:var(--no);font-size:13px;font-weight:400;min-height:1.1em}
    .tot{text-align:right;font-weight:700;margin-top:6px}
    .top{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap}
    .locsel{display:flex;flex-direction:column;gap:4px;font-size:14px;font-weight:600;min-width:260px}
    .locpill{display:inline-block;background:var(--bg);border:1px solid var(--line);border-left:4px solid var(--red);border-radius:4px;padding:8px 14px;font-size:14px}
    .locpill small{display:block;color:var(--mut);font-size:12px}
    .sh{display:flex;justify-content:space-between;align-items:flex-end;gap:12px;flex-wrap:wrap;margin:44px 0 12px}
    .sh h3{margin:0 0 4px}.sh .lead{margin:0}.sh .bar{margin:0}
    a.clear{color:var(--red);align-self:center;font-size:14px}
    #toast{position:fixed;bottom:22px;left:50%;transform:translateX(-50%);background:var(--ink);color:#fff;padding:11px 20px;border-radius:6px;font-size:14px;opacity:0;pointer-events:none;transition:opacity .2s;max-width:92vw;text-align:center}
    #toast.on{opacity:1}
    #toast.error{background:var(--no)}
    @media(max-width:860px){.hin{padding:0 16px;gap:12px}.logo-img{height:42px}}
    @media(max-width:620px){main{padding:22px 14px}.g{grid-template-columns:1fr}.ln{grid-template-columns:minmax(0,1fr) 60px 80px}.ln span.t,.ln .x{grid-row:2}.locsel{min-width:0;width:100%}}
    @media (prefers-reduced-motion:reduce){#toast{transition:none}.flash td{animation:none}}
  </style>
</head>

<body>
  <header>
    <div class="hin">
      <img class="logo-img" src="../images/buraqoil-logo.png" alt="Buraqoil - Stesen Komuniti Anda">
      <div>
        <h2>Lubricant Stock Management System</h2>
        <p>IPTB Sdn Bhd</p>
      </div>
    </div>
  </header>

  <!-- one GET form for every list filter; the controls below join it with form="ff" -->
  <form id="ff" method="get" action="stock-receiving.php" role="search"></form>

  <main>
    <div class="top">
      <div>
        <h1>Stock ordering and receiving</h1>
        <p class="lead">Order lubricants from suppliers, then record the delivery when it arrives. Receiving adds the quantities to the station's stock automatically.</p>
      </div>
      <div>
        <?php if ($isAdmin): ?>
          <label class="locsel">Location
            <select name="loc" form="ff" onchange="this.form.submit()">
              <option value="">All locations</option>
              <?php foreach ($locations as $l): ?>
                <option value="<?= e($l['location_code']) ?>" <?= $loc === $l['location_code'] ? ' selected' : '' ?>><?= e($l['location_name']) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
        <?php else: ?>
          <div class="locpill"><small>Your location</small><b><?= e($me['location_name']) ?></b></div>
        <?php endif; ?>
      </div>
    </div>

    <div class="stats" aria-label="Summary">
      <div class="stat"><b><?= $sWaiting ?></b><span>Orders awaiting delivery</span></div>
      <div class="stat"><b><?= count($deliveries) ?></b><span>Deliveries</span></div>
      <div class="stat"><b><?= $sUnits ?></b><span>Units received</span></div>
      <div class="stat"><b>RM <?= money($sValue) ?></b><span>Value received</span></div>
    </div>

    <!-- ---------- Purchase orders ---------- -->
    <div class="sh" style="margin-top:8px">
      <div>
        <h3>Purchase orders</h3>
        <p class="lead"><?= $isAdmin ? 'Every order has an invoice you can view and print.' : 'Orders are placed by the admin. Every order has an invoice you can view and print.' ?> Select Receive when the stock arrives.</p>
      </div>
      <div class="bar">
        <select name="ostatus" form="ff" aria-label="Filter orders by status" onchange="this.form.submit()">
          <option value="">All orders</option>
          <?php foreach (ORDER_STATUS as $k => $lab): ?>
            <option value="<?= $k ?>" <?= $fStatus === $k ? ' selected' : '' ?>><?= e($lab) ?></option>
          <?php endforeach; ?>
        </select>
        <?php if ($isAdmin): ?><button class="btn" type="button" id="addOrd">Order new stock</button><?php endif; ?>
      </div>
    </div>
    <div class="wrap">
      <table style="min-width:980px">
        <thead>
          <tr>
            <th>Order</th>
            <th>Date</th>
            <th>Supplier</th>
            <?php if ($many): ?><th>Location</th><?php endif; ?>
            <th class="n">Products</th>
            <th class="n">Units</th>
            <th class="n">Total (RM)</th>
            <th>Status</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody id="ob">
          <?php foreach ($orderList as $o):
            $s = ['ordered' => 'cv', 'received' => 'in', 'cancelled' => 'cx'][$o['status']]; ?>
            <tr class="<?= in_array($o['code'], $hl, true) ? 'flash' : '' ?>">
              <td><b><?= e($o['code']) ?></b></td>
              <td><?= e(dmy($o['order_date'])) ?></td>
              <td><?= e($o['supplier_name']) ?><?php if ($o['delivery_id'] !== null): ?><span class="sub">Received as <?= e(doc_code('DEL', (int)$o['delivery_id'])) ?></span><?php endif; ?></td>
              <?php if ($many): ?><td><?= e(SHORT_NAMES[$o['location_code']] ?? $o['location_code']) ?></td><?php endif; ?>
              <td class="n"><?= count($o['items']) ?></td>
              <td class="n"><?= sum_units($o['items']) ?></td>
              <td class="n"><?= money(sum_value($o['items'])) ?></td>
              <td><span class="badge <?= $s ?>"><?= e(ORDER_STATUS[$o['status']]) ?></span></td>
              <td style="white-space:nowrap">
                <button type="button" class="ib" data-a="inv" data-id="<?= (int)$o['po_id'] ?>" title="View and print invoice" aria-label="Invoice <?= e($o['code']) ?>">🧾</button>
                <?php if ($o['status'] === 'ordered'): ?>
                  <button type="button" class="ib ok" data-a="rcv" data-id="<?= (int)$o['po_id'] ?>" title="Receive stock" aria-label="Receive <?= e($o['code']) ?>">📦</button>
                  <?php if ($isAdmin): ?><button type="button" class="ib d" data-a="cx" data-id="<?= (int)$o['po_id'] ?>" title="Cancel order" aria-label="Cancel <?= e($o['code']) ?>">🚫</button><?php endif; ?>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$orderList): ?>
            <tr><td colspan="<?= $many ? 9 : 8 ?>" class="empty">No orders match. Select "Order new stock" to create one.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <!-- ---------- Deliveries ---------- -->
    <div class="sh">
      <div>
        <h3>Supplier deliveries</h3>
        <p class="lead">Every delivery received. Saving a delivery adds its quantities to stock.</p>
      </div>
    </div>
    <div class="bar">
      <input id="q" name="q" form="ff" type="search" value="<?= e($q) ?>" maxlength="100" placeholder="Search supplier, invoice, order or delivery number" aria-label="Search deliveries">
      <select name="sup" form="ff" aria-label="Filter by supplier" onchange="this.form.submit()">
        <option value="">All suppliers</option>
        <?php foreach ($suppliers as $s): ?>
          <option value="<?= (int)$s['supplier_id'] ?>" <?= $fSup === (int)$s['supplier_id'] ? ' selected' : '' ?>><?= e($s['supplier_name']) ?></option>
        <?php endforeach; ?>
      </select>
      <button class="btn sec" type="submit" form="ff">Search</button>
      <button class="btn sec" type="button" id="add">Add delivery without order</button>
      <?php if ($filters): ?><a class="clear" href="stock-receiving.php">Clear filters</a><?php endif; ?>
    </div>
    <div class="wrap">
      <table style="min-width:980px">
        <thead>
          <tr>
            <th>Delivery</th>
            <th>Date</th>
            <th>Supplier</th>
            <th>Invoice no.</th>
            <?php if ($many): ?><th>Location</th><?php endif; ?>
            <th class="n">Products</th>
            <th class="n">Units</th>
            <th class="n">Total (RM)</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody id="tb">
          <?php foreach ($delList as $d): ?>
            <tr class="<?= in_array($d['code'], $hl, true) ? 'flash' : '' ?>">
              <td><b><?= e($d['code']) ?></b><?php if ($d['po_code'] !== ''): ?><span class="sub">From <?= e($d['po_code']) ?></span><?php endif; ?></td>
              <td><?= e(dmy($d['delivery_date'])) ?></td>
              <td><?= e($d['supplier_name']) ?></td>
              <td><?= e($d['invoice_no']) ?></td>
              <?php if ($many): ?><td><?= e(SHORT_NAMES[$d['location_code']] ?? $d['location_code']) ?></td><?php endif; ?>
              <td class="n"><?= count($d['items']) ?></td>
              <td class="n"><?= sum_units($d['items']) ?></td>
              <td class="n"><?= money(sum_value($d['items'])) ?></td>
              <td style="white-space:nowrap">
                <button type="button" class="ib" data-a="v" data-id="<?= (int)$d['delivery_id'] ?>" title="View" aria-label="View <?= e($d['code']) ?>">👁️</button>
                <button type="button" class="ib" data-a="e" data-id="<?= (int)$d['delivery_id'] ?>" title="Edit" aria-label="Edit <?= e($d['code']) ?>">✏️</button>
                <button type="button" class="ib d" data-a="d" data-id="<?= (int)$d['delivery_id'] ?>" title="Cancel delivery" aria-label="Cancel <?= e($d['code']) ?>">🗑️</button>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$delList): ?>
            <tr><td colspan="<?= $many ? 9 : 8 ?>" class="empty">No deliveries match your search. Clear the filters or add a new delivery.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <!-- ---------- Stock on hand ---------- -->
    <div class="sh">
      <div>
        <h3>Stock on hand</h3>
        <p class="lead">Updated automatically whenever a delivery is added, edited or cancelled.</p>
      </div>
      <button class="btn sec" id="prStock" type="button">🖨️ Print stock on hand</button>
    </div>
    <div class="wrap">
      <table style="min-width:1180px">
        <thead>
          <tr>
            <th>Image</th>
            <th>SKU</th>
            <th>Product</th>
            <?php if ($many): ?><th>Location</th><?php endif; ?>
            <th>Type</th>
            <th class="n">Pack</th>
            <th class="n">Cost (RM)</th>
            <th class="n">Price (RM)</th>
            <th class="n">Min stock</th>
            <th class="n">Current stock</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($stockRows as $r): $p = $r['p']; ?>
            <tr class="<?= in_array($r['loc'] . ':' . $p['product_id'], $hl, true) ? 'flash' : '' ?>">
              <td><span class="pimg"><img src="<?= e(img_src($p)) ?>" alt="<?= e($p['brand'] . ' ' . $p['product_name']) ?>" onerror="this.onerror=null;this.src=NOIMG"></span></td>
              <td class="nw"><?= e($p['sku']) ?></td>
              <td><b><?= e($p['brand'] . ' ' . $p['product_name']) ?></b><span class="sub"><?= e($p['viscosity_grade']) ?></span></td>
              <?php if ($many): ?><td><?= e(loc_short($locById, $r['loc'])) ?></td><?php endif; ?>
              <td><?= e($p['product_type']) ?></td>
              <td class="n nw"><?= e(pack_label($p['pack_size'])) ?></td>
              <td class="n"><?= money($p['cost_price']) ?></td>
              <td class="n"><?= money($p['selling_price']) ?></td>
              <td class="n"><?= (int)$p['min_stock'] ?></td>
              <td class="n"><?= $r['qty'] ?></td>
              <td><span class="badge <?= $BADGE[$r['status']] ?>"><span class="dot"></span><?= e($LABEL[$r['status']]) ?></span></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$stockRows): ?>
            <tr><td colspan="11" class="empty">No products yet. Add products in Module 1 first.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </main>

  <!-- one product line; copied by the script for the order and delivery forms -->
  <template id="lnTpl">
    <div class="ln">
      <select class="ls" data-f="product_id" aria-label="Product">
        <option value="">Select product</option>
        <?php foreach ($products as $p): ?>
          <option value="<?= (int)$p['product_id'] ?>"><?= e($p['sku'] . ' · ' . $p['brand'] . ' ' . $p['product_name'] . ' ' . $p['viscosity_grade'] . ' (' . pack_label($p['pack_size']) . ')') ?></option>
        <?php endforeach; ?>
      </select>
      <input class="lq" data-f="qty" type="number" min="1" step="1" inputmode="numeric" placeholder="Qty" aria-label="Quantity">
      <input class="lc" data-f="cost" type="number" min="0" step="0.01" inputmode="decimal" placeholder="Cost RM" aria-label="Unit cost in RM">
      <span class="t">0.00</span><button class="x" type="button" aria-label="Remove product">&times;</button>
    </div>
  </template>

  <?php if ($isAdmin): ?>
  <dialog id="dOrd">
    <form method="post" action="<?= e($formQuery) ?>" novalidate>
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="order_create">
      <div class="dh">Order new stock</div>
      <div class="db">
        <div class="g">
          <label>Supplier<select name="supplier_id">
              <?php foreach ($suppliers as $s): ?><option value="<?= (int)$s['supplier_id'] ?>"><?= e($s['supplier_name']) ?></option><?php endforeach; ?>
            </select><span class="ferr" data-for="supplier_id"></span></label>
          <label>Order date<input name="order_date" type="date" max="<?= e($today) ?>"><span class="ferr" data-for="order_date"></span></label>
          <?php if ($canPickLoc): ?>
            <label class="full">Deliver to location<select name="location_id">
                <option value="">Select location</option>
                <?php foreach ($locations as $l): ?><option value="<?= (int)$l['location_id'] ?>"><?= e($l['location_name']) ?></option><?php endforeach; ?>
              </select><span class="ferr" data-for="location_id"></span></label>
          <?php endif; ?>
        </div>
        <div class="lh"><b>Products to order</b><button class="btn sec" type="button" data-addln>Add product</button></div>
        <div class="lines"></div>
        <div class="tot" data-label="Order total"></div>
        <div class="err" data-for="items" role="alert"></div>
      </div>
      <div class="df"><button class="btn sec" type="button" data-close>Cancel</button><button class="btn" type="submit">Place order</button></div>
    </form>
  </dialog>
  <?php endif; ?>

  <dialog id="dRecv">
    <form method="post" action="<?= e($formQuery) ?>" novalidate>
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="order_receive">
      <input type="hidden" name="po_id" value="0">
      <div class="dh" id="rTitle"></div>
      <div class="db">
        <div id="rBody"></div>
        <div class="g" style="margin-top:14px">
          <label>Supplier invoice number<input name="invoice_no" maxlength="<?= INVOICE_MAX ?>" placeholder="e.g. INV-A-21100" autocomplete="off"><span class="ferr" data-for="invoice_no"></span></label>
          <label>Delivery date<input name="delivery_date" type="date" max="<?= e($today) ?>"><span class="ferr" data-for="delivery_date"></span></label>
        </div>
      </div>
      <div class="df"><button class="btn sec" type="button" data-close>Cancel</button><button class="btn" type="submit">Receive stock</button></div>
    </form>
  </dialog>

  <dialog id="dForm">
    <form method="post" action="<?= e($formQuery) ?>" novalidate>
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="delivery_create">
      <input type="hidden" name="delivery_id" value="0">
      <div class="dh" id="fTitle">Add delivery</div>
      <div class="db">
        <div class="g">
          <label>Supplier<select name="supplier_id">
              <?php foreach ($suppliers as $s): ?><option value="<?= (int)$s['supplier_id'] ?>"><?= e($s['supplier_name']) ?></option><?php endforeach; ?>
            </select><span class="ferr" data-for="supplier_id"></span></label>
          <label>Invoice number<input name="invoice_no" maxlength="<?= INVOICE_MAX ?>" placeholder="e.g. INV-A-21100" autocomplete="off"><span class="ferr" data-for="invoice_no"></span></label>
          <label>Delivery date<input name="delivery_date" type="date" max="<?= e($today) ?>"><span class="ferr" data-for="delivery_date"></span></label>
          <?php if ($canPickLoc): ?>
            <label id="fLocL">Location<select name="location_id">
                <option value="">Select location</option>
                <?php foreach ($locations as $l): ?><option value="<?= (int)$l['location_id'] ?>"><?= e($l['location_name']) ?></option><?php endforeach; ?>
              </select><span class="ferr" data-for="location_id"></span></label>
          <?php endif; ?>
          <label id="fLocRo" hidden>Location<span class="ro"></span></label>
        </div>
        <div class="lh"><b>Products received</b><button class="btn sec" type="button" data-addln>Add product</button></div>
        <div class="lines"></div>
        <div class="tot" data-label="Delivery total"></div>
        <div class="err" data-for="items" role="alert"></div>
      </div>
      <div class="df"><button class="btn sec" type="button" data-close>Cancel</button><button class="btn" type="submit">Save delivery</button></div>
    </form>
  </dialog>

  <dialog id="dView">
    <div class="dh" id="vTitle"></div>
    <div class="db" id="vBody"></div>
    <div class="df"><button class="btn sec" type="button" data-close>Close</button></div>
  </dialog>

  <dialog id="dInv" class="wide">
    <div class="dh" id="iTitle"></div>
    <div class="db">
      <div class="doc" id="iBody"></div>
    </div>
    <div class="df"><button class="btn sec" type="button" data-close>Close</button><button class="btn" id="iPrint" type="button">🖨️ Print invoice</button></div>
  </dialog>

  <dialog id="dConf">
    <form method="post" action="<?= e($formQuery) ?>">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" id="kAction" value="">
      <input type="hidden" id="kId" value="0">
      <div class="dh" id="kTitle"></div>
      <div class="db" id="kBody"></div>
      <div class="df"><button class="btn sec" type="button" id="kNo" data-close></button><button class="btn" type="submit" id="kYes"></button></div>
    </form>
  </dialog>

  <div id="toast" role="status" class="<?= $flash ? 'on ' . e($flash['type']) : '' ?>"><?= $flash ? e($flash['msg']) : '' ?></div>

  <script>
    var NOIMG = <?= json_encode(NOIMG) ?>;
    const DATA = <?= json_encode($js, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;

    // ---------- Helpers ----------
    const $ = id => document.getElementById(id);
    const P = id => DATA.products[id] || {sku: '?', name: 'Product removed', grade: '', pack: '', cost: '0.00'};
    const money = n => Number(n).toLocaleString('en-MY', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    const dmy = d => d ? d.split('-').reverse().join('/') : '';
    const esc = s => String(s).replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    const units = d => d.items.reduce((a, i) => a + i.qty, 0);
    const total = d => d.items.reduce((a, i) => a + i.qty * i.cost, 0);
    const label = id => {const p = P(id); return `${p.sku} · ${p.name} ${p.grade} (${p.pack})`};
    const loc = id => DATA.locs[id] || {code: '', name: '', addr: ''};
    const STATUS = {ordered: 'Ordered', received: 'Received', cancelled: 'Cancelled'};
    let lineNo = 0, invId = null;

    function itemsTable(d, withTotal){
      return `<div class="wrap"><table style="min-width:0"><thead><tr><th>Product</th><th class="n">Qty</th><th class="n">Unit cost</th>${withTotal ? '<th class="n">Line total</th>' : ''}</tr></thead><tbody>` +
        d.items.map(i => `<tr><td>${esc(label(i.product_id))}</td><td class="n">${i.qty}</td><td class="n">${money(i.cost)}</td>${withTotal ? `<td class="n">${money(i.qty * i.cost)}</td>` : ''}</tr>`).join('') +
        `</tbody></table></div><p class="tot">Total: RM ${money(total(d))}</p>`;
    }

    // ---------- Form fields and error messages ----------
    function fill(form, values, errors){
      form.querySelectorAll('[data-for]').forEach(x => x.textContent = (errors || {})[x.dataset.for] || '');
      for(const k in values){
        const el = form.elements[k];
        if(el && el.type !== 'hidden' && typeof values[k] === 'string') el.value = values[k];
      }
    }
    function addLine(form, it = {}){
      const box = form.querySelector('.lines');
      const row = $('lnTpl').content.firstElementChild.cloneNode(true), n = lineNo++;
      row.querySelectorAll('[data-f]').forEach(el => {el.name = `items[${n}][${el.dataset.f}]`; el.value = it[el.dataset.f] ?? ''});
      box.appendChild(row);
      sumLines(form);
    }
    function setLines(form, items){
      form.querySelector('.lines').innerHTML = '';
      (items && items.length ? items : [{}]).forEach(it => addLine(form, it));
    }
    function sumLines(form){
      let t = 0;
      form.querySelectorAll('.ln').forEach(r => {const v = (+r.querySelector('.lq').value || 0) * (+r.querySelector('.lc').value || 0); t += v; r.querySelector('.t').textContent = money(v)});
      const tot = form.querySelector('.tot');
      tot.textContent = tot.dataset.label + ': RM ' + money(t);
    }
    document.querySelectorAll('#dOrd form, #dForm form').forEach(form => {
      form.querySelector('[data-addln]').onclick = () => addLine(form);
      form.addEventListener('click', e => {if(e.target.classList.contains('x')){e.target.closest('.ln').remove(); sumLines(form)}});
      form.addEventListener('input', e => {
        if(e.target.classList.contains('ls') && DATA.products[e.target.value]) e.target.closest('.ln').querySelector('.lc').value = DATA.products[e.target.value].cost;
        sumLines(form);
      });
    });
    document.querySelectorAll('[data-close]').forEach(b => b.onclick = () => b.closest('dialog').close());

    // ---------- Purchase orders ----------
    function openOrder(values, errors){
      const f = $('dOrd').querySelector('form');
      f.reset();
      fill(f, Object.assign({order_date: DATA.today}, values || {}), errors);
      setLines(f, values && values.items);
      $('dOrd').showModal();
    }
    function openReceive(id, values, errors){
      const o = DATA.orders[id], f = $('dRecv').querySelector('form');
      if(!o) return;
      f.reset();
      f.elements.po_id.value = id;
      $('rTitle').textContent = 'Receive order ' + o.code;
      $('rBody').innerHTML = `<p style="margin-top:0"><b>${esc(o.supplier)}</b><span class="sub">Ordered ${dmy(o.date)} · Deliver to ${esc(loc(o.loc).name)}</span></p>` + itemsTable(o, false);
      f.elements.delivery_date.min = o.date;
      fill(f, Object.assign({delivery_date: DATA.today}, values || {}), errors);
      $('dRecv').showModal();
      f.elements.invoice_no.focus();
    }
    function confirmBox(o){
      $('kTitle').textContent = o.title; $('kBody').innerHTML = o.body;
      $('kNo').textContent = o.no; $('kYes').textContent = o.yes;
      $('kAction').value = o.action; $('kId').name = o.idName; $('kId').value = o.id;
      $('dConf').showModal();
    }
    function cancelOrder(id){
      const o = DATA.orders[id];
      confirmBox({title: 'Cancel this order?', no: 'Keep order', yes: 'Cancel order', action: 'order_cancel', idName: 'po_id', id,
        body: `<p style="margin-top:0">Order <b>${o.code}</b> to ${esc(o.supplier)} (RM ${money(total(o))}) will be marked as cancelled. Stock does not change.</p>`});
    }

    // ---------- Invoice (one per order) ----------
    function invoiceHtml(o){
      const meta = [['Order no.', o.code], ['Order date', dmy(o.date)], ['Status', STATUS[o.status]]];
      if(o.del) meta.push(['Delivery', o.del], ['Supplier invoice', o.invoice], ['Received', dmy(o.received)]);
      const l = loc(o.loc);
      return LSMS.docHead(l.code, 'Invoice', meta) +
        `<div class="doc-to"><small>Supplier</small><b>${esc(o.supplier)}</b></div>
        <table class="doc-lines"><thead><tr><th>#</th><th>Product</th><th class="n">Qty</th><th class="n">Unit cost (RM)</th><th class="n">Amount (RM)</th></tr></thead><tbody>` +
        o.items.map((i, k) => {const p = P(i.product_id); return `<tr><td>${k + 1}</td><td>${esc(p.name)} ${esc(p.grade)}<span class="sub">${esc(p.sku)} · ${esc(p.pack)}</span></td><td class="n">${i.qty}</td><td class="n">${money(i.cost)}</td><td class="n">${money(i.qty * i.cost)}</td></tr>`}).join('') +
        `</tbody><tfoot><tr><td colspan="2">Total</td><td class="n">${units(o)}</td><td></td><td class="n">RM ${money(total(o))}</td></tr></tfoot></table>
        <p class="doc-note">Deliver to: ${esc(l.name)}${l.addr ? ', ' + esc(l.addr) : ''}. Generated by IPTB-LSMS on ${dmy(DATA.today)}.</p>`;
    }
    function openInvoice(id){
      invId = id;
      $('iTitle').textContent = 'Invoice · ' + DATA.orders[id].code;
      $('iBody').innerHTML = invoiceHtml(DATA.orders[id]);
      $('dInv').showModal();
    }

    // ---------- Print stock on hand (one page per location) ----------
    function printStock(){
      const locs = [...new Set(DATA.stock.map(r => r.loc))];
      const html = locs.map((id, k) => {
        let u = 0, v = 0;
        const rows = DATA.stock.filter(r => r.loc === id).map(r => {u += r.qty; v += r.qty * r.cost;
          return `<tr><td>${esc(r.sku)}</td><td>${esc(r.name)}</td><td>${esc(r.type)}</td><td class="n">${esc(r.pack)}</td><td class="n">${r.min}</td><td class="n"><b>${r.qty}</b></td><td>${esc(r.status)}</td><td class="n">${money(r.qty * r.cost)}</td></tr>`}).join('');
        return `<div class="${k ? 'doc-pb' : ''}">` + LSMS.docHead(loc(id).code, 'Stock on hand', [['Date', dmy(DATA.today)], ['Printed by', DATA.user || '-']]) +
          `<table class="doc-lines"><thead><tr><th>SKU</th><th>Product</th><th>Type</th><th class="n">Pack</th><th class="n">Min stock</th><th class="n">On hand</th><th>Status</th><th class="n">Value at cost (RM)</th></tr></thead><tbody>${rows}</tbody>
          <tfoot><tr><td colspan="5">Total</td><td class="n">${u}</td><td></td><td class="n">RM ${money(v)}</td></tr></tfoot></table>
          <p class="doc-note">Stock on hand at ${esc(loc(id).name)} as recorded in IPTB-LSMS.</p></div>`;
      }).join('');
      LSMS.print('Stock on hand ' + dmy(DATA.today), html);
    }

    // ---------- Add / edit / view / cancel delivery ----------
    function openForm(id, values, errors){
      const d = id ? DATA.deliveries[id] : null, f = $('dForm').querySelector('form');
      f.reset();
      f.elements.action.value = d ? 'delivery_update' : 'delivery_create';
      f.elements.delivery_id.value = d ? id : 0;
      $('fTitle').textContent = d ? 'Edit delivery ' + d.code : 'Add delivery';
      // a delivery's location cannot change: show it as text when editing
      if($('fLocL')) $('fLocL').hidden = !!d;
      $('fLocRo').hidden = !d;
      if(d) $('fLocRo').querySelector('.ro').textContent = loc(d.loc).name;
      const base = d ? {supplier_id: String(d.supplier_id), invoice_no: d.invoice, delivery_date: d.date} : {delivery_date: DATA.today};
      fill(f, Object.assign(base, values || {}), errors);
      setLines(f, values ? values.items : d && d.items);
      $('dForm').showModal();
    }
    function openView(id){
      const d = DATA.deliveries[id];
      $('vTitle').textContent = d.code + ' · ' + d.invoice;
      $('vBody').innerHTML = `<p style="margin-top:0"><b>${esc(d.supplier)}</b><span class="sub">Delivered ${dmy(d.date)} to ${esc(loc(d.loc).name)}${d.po ? ' · from order ' + esc(d.po) : ''}</span></p>` + itemsTable(d, true);
      $('dView').showModal();
    }
    function openDel(id){
      const d = DATA.deliveries[id];
      confirmBox({title: 'Cancel this delivery?', no: 'Keep delivery', yes: 'Cancel delivery', action: 'delivery_delete', idName: 'delivery_id', id,
        body: `<p style="margin-top:0">Delivery <b>${d.code}</b> (${esc(d.invoice)}) at ${esc(loc(d.loc).name)} will be removed and its stock taken out again:</p><ul>` +
          d.items.map(i => `<li>${esc(P(i.product_id).sku)}: &minus;${i.qty} units</li>`).join('') + '</ul>' +
          (d.po ? `<p>Order <b>${esc(d.po)}</b> will go back to <i>Ordered</i>.</p>` : '')});
    }

    // ---------- Events ----------
    if($('addOrd')) $('addOrd').onclick = () => openOrder();
    $('add').onclick = () => openForm();
    $('ob').onclick = e => {const b = e.target.closest('button[data-a]'); if(b) ({inv: openInvoice, rcv: openReceive, cx: cancelOrder})[b.dataset.a](b.dataset.id)};
    $('tb').onclick = e => {const b = e.target.closest('button[data-a]'); if(b) ({v: openView, e: openForm, d: openDel})[b.dataset.a](b.dataset.id)};
    $('iPrint').onclick = () => LSMS.print('Invoice ' + DATA.orders[invId].code, invoiceHtml(DATA.orders[invId]));
    $('prStock').onclick = printStock;

    // after a validation error the server sends the form back: open it again with the typed values
    const R = DATA.reopen;
    if(R){
      if(R.dlg === 'dOrd') openOrder(R.values, R.errors);
      if(R.dlg === 'dRecv') openReceive(R.values.po_id, R.values, R.errors);
      if(R.dlg === 'dForm') openForm(R.values.action === 'delivery_update' ? R.values.delivery_id : null, R.values, R.errors);
    }
    const t = $('toast');
    if(t.classList.contains('on')) setTimeout(() => t.classList.remove('on'), 5000);
  </script>
  <script src="../sidebar.js"></script>
</body>

</html>

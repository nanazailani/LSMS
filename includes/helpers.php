<?php
// Shared helpers for Module 1 (Lubricant Product Management).

const PRODUCT_TYPES   = ['Engine oil', 'Gear oil', 'Motorcycle oil', 'Grease', 'Other'];
const PACK_SIZES      = [0.5, 0.8, 1.0, 4.0, 18.0, 208.0];   // litres per pack
const MAX_IMAGE_BYTES = 2097152;                              // BR-11: 2 MB

/** Escape text for HTML output (prevents XSS). */
function e($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function text_len(string $s): int
{
    return function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : strlen($s);
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_ok(): bool
{
    return isset($_POST['csrf'], $_SESSION['csrf'])
        && hash_equals($_SESSION['csrf'], (string)$_POST['csrf']);
}

/** Stock status rule (BR-06). */
function stock_status(int $qty, int $min): string
{
    if ($qty <= 0) {
        return 'out';
    }
    return $qty <= $min ? 'low' : 'ok';
}

/**
 * Validate a product. Used by BOTH Create and Update so each rule is written once.
 * BR-01 required fields, BR-02 whole-number minimum stock, BR-03 price >= cost,
 * BR-04 no duplicate brand + name + grade + pack size.
 * The stock quantity is never read here (BR-05).
 *
 * @return array [array $errors (field => message), array $clean values]
 */
function validate_product(PDO $pdo, array $in, ?int $editingId): array
{
    $err = [];
    $v   = [];

    $v['brand']           = trim((string)($in['brand'] ?? ''));
    $v['product_name']    = trim((string)($in['product_name'] ?? ''));
    $v['viscosity_grade'] = trim((string)($in['viscosity_grade'] ?? ''));
    $v['product_type']    = (string)($in['product_type'] ?? '');

    if ($v['brand'] === '') {
        $err['brand'] = 'Enter the brand.';
    } elseif (text_len($v['brand']) > 50) {
        $err['brand'] = 'The brand must be 50 characters or less.';
    }
    if ($v['product_name'] === '') {
        $err['product_name'] = 'Enter the product name.';
    } elseif (text_len($v['product_name']) > 100) {
        $err['product_name'] = 'The product name must be 100 characters or less.';
    }
    if ($v['viscosity_grade'] === '') {
        $err['viscosity_grade'] = 'Enter the viscosity grade or type.';
    } elseif (text_len($v['viscosity_grade']) > 20) {
        $err['viscosity_grade'] = 'The grade must be 20 characters or less.';
    }
    if (!in_array($v['product_type'], PRODUCT_TYPES, true)) {
        $err['product_type'] = 'Choose a product type.';
    }

    $pack = trim((string)($in['pack_size'] ?? ''));
    if ($pack === '' || !is_numeric($pack) || !in_array((float)$pack, PACK_SIZES, true)) {
        $err['pack_size'] = 'Choose a pack size.';
    } else {
        $v['pack_size'] = (float)$pack;
    }

    $min = trim((string)($in['min_stock'] ?? ''));
    if (!preg_match('/^\d{1,9}$/', $min)) {
        $err['min_stock'] = 'Enter a whole number of 0 or more.';
    } else {
        $v['min_stock'] = (int)$min;
    }

    $cost = trim((string)($in['cost_price'] ?? ''));
    if (!preg_match('/^\d{1,8}(\.\d{1,2})?$/', $cost)) {
        $err['cost_price'] = 'Enter a cost price of 0 or more (up to 2 decimals).';
    } else {
        $v['cost_price'] = round((float)$cost, 2);
    }

    $price = trim((string)($in['selling_price'] ?? ''));
    if (!preg_match('/^\d{1,8}(\.\d{1,2})?$/', $price)) {
        $err['selling_price'] = 'Enter a selling price of 0 or more (up to 2 decimals).';
    } else {
        $v['selling_price'] = round((float)$price, 2);
    }

    // BR-03: selling price must not be lower than cost price (compare in sen to avoid float errors)
    if (
        !isset($err['cost_price']) && !isset($err['selling_price'])
        && (int)round($v['selling_price'] * 100) < (int)round($v['cost_price'] * 100)
    ) {
        $err['selling_price'] = 'Selling price cannot be lower than cost price.';
    }

    // BR-04: the same brand + product name + grade + pack size cannot be added twice
    if (!isset($err['brand']) && !isset($err['product_name']) && !isset($err['viscosity_grade']) && !isset($err['pack_size'])) {
        $st = $pdo->prepare(
            'SELECT COUNT(*) FROM products
             WHERE brand = ? AND product_name = ? AND viscosity_grade = ? AND pack_size = ? AND product_id <> ?'
        );
        $st->execute([
            $v['brand'],
            $v['product_name'],
            $v['viscosity_grade'],
            number_format($v['pack_size'], 2, '.', ''),
            $editingId ?? 0,
        ]);
        if ((int)$st->fetchColumn() > 0) {
            $err['product_name'] = 'This product with the same grade and pack size already exists.';
        }
    }

    return [$err, $v];
}

/**
 * BR-11: the image is optional; only JPG or PNG of 2 MB or less.
 * The type is checked from the file content, not from the file name.
 *
 * @return array [?string $error, ?string $extension]
 */
function validate_image(?array $f): array
{
    if ($f === null || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return [null, null];
    }
    if ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE) {
        return ['The image must be 2 MB or less.', null];
    }
    if ($f['error'] !== UPLOAD_ERR_OK) {
        return ['The image could not be uploaded. Try again.', null];
    }
    if ($f['size'] > MAX_IMAGE_BYTES) {
        return ['The image must be 2 MB or less.', null];
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    $map  = ['image/jpeg' => 'jpg', 'image/png' => 'png'];
    if (!isset($map[$mime]) || @getimagesize($f['tmp_name']) === false) {
        return ['Choose a JPG or PNG image.', null];
    }
    return [null, $map[$mime]];
}

/** Save the uploaded image as <SKU>.<ext> in $dir. Returns the path saved in the database, or null. */
function store_image(string $tmpFile, string $dir, string $sku, string $ext): ?string
{
    if (!preg_match('/^[A-Z0-9-]{1,10}$/', $sku)) {
        return null;
    }
    $other = $ext === 'jpg' ? 'png' : 'jpg';
    @unlink($dir . '/' . $sku . '.' . $other);
    if (!move_uploaded_file($tmpFile, $dir . '/' . $sku . '.' . $ext)) {
        return null;
    }
    return 'images/' . $sku . '.' . $ext;
}


// ---------------------------------------------------------------------
// Admin: staff accounts
// ---------------------------------------------------------------------
const PASSWORD_MIN = 6;
const PASSWORD_MAX = 72;      // bcrypt only uses the first 72 bytes

/**
 * Validate a staff account. Used by BOTH Add and Edit so each rule is written once.
 * Name and email are required, the email must be valid and not used by any other account
 * (also removed ones, because users.email is UNIQUE), the password needs 6 to 72 characters
 * (optional on Edit: blank keeps the current one) and the location must be an active location.
 *
 * @return array [array $errors (field => message), array $clean values]
 */
function validate_staff(PDO $pdo, array $in, ?int $editingId): array
{
    $err = [];
    $v   = [];

    $v['full_name'] = trim(preg_replace('/\s+/u', ' ', (string)($in['full_name'] ?? '')));
    $v['email']     = strtolower(trim((string)($in['email'] ?? '')));
    $password       = (string)($in['password'] ?? '');
    $loc            = trim((string)($in['location_id'] ?? ''));

    if ($v['full_name'] === '') {
        $err['full_name'] = 'Enter the full name.';
    } elseif (text_len($v['full_name']) > 100) {
        $err['full_name'] = 'The name must be 100 characters or less.';
    }

    if ($v['email'] === '') {
        $err['email'] = 'Enter the email address.';
    } elseif (strlen($v['email']) > 100 || filter_var($v['email'], FILTER_VALIDATE_EMAIL) === false) {
        $err['email'] = 'Enter a valid email address.';
    } else {
        $st = $pdo->prepare('SELECT is_active FROM users WHERE email = ? AND user_id <> ?');
        $st->execute([$v['email'], $editingId ?? 0]);
        $hit = $st->fetch();
        if ($hit) {
            $err['email'] = (int)$hit['is_active'] === 1
                ? 'This email already has an account.'
                : 'This email belongs to a removed account. Use another email.';
        }
    }

    $v['password'] = null;
    if ($password === '' && $editingId !== null) {
        // Edit: blank keeps the current password
    } elseif (text_len($password) < PASSWORD_MIN) {
        $err['password'] = 'The password must be at least ' . PASSWORD_MIN . ' characters.';
    } elseif (strlen($password) > PASSWORD_MAX) {
        $err['password'] = 'The password must be ' . PASSWORD_MAX . ' characters or less.';
    } else {
        $v['password'] = $password;
    }

    if (!preg_match('/^\d{1,9}$/', $loc)) {
        $err['location_id'] = 'Select the location.';
    } else {
        $st = $pdo->prepare('SELECT COUNT(*) FROM locations WHERE location_id = ? AND is_active = 1');
        $st->execute([(int)$loc]);
        if ((int)$st->fetchColumn() === 0) {
            $err['location_id'] = 'Select the location.';
        } else {
            $v['location_id'] = (int)$loc;
        }
    }

    return [$err, $v];
}

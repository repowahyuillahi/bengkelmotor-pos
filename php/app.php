<?php
// =====================================================================
// A3 Motor Service Station - Backend API Edisi PHP Native
// =====================================================================
// Porting 1:1 dari dist/_worker.js (versi Cloudflare). Semua endpoint
// /api/*, bentuk JSON, pesan error Bahasa Indonesia, dan kode status
// dibuat identik dengan versi Cloudflare.
//
// File ini TIDAK untuk diakses langsung dari browser. Ia dipanggil
// lewat router.php (php -S), index.php, atau api.php (Apache/Nginx).
if (!defined('A3_ENTRY')) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'Akses langsung tidak diizinkan']);
    exit;
}

require_once __DIR__ . '/config.php';

// ---------------------------------------------------------------------
// Mekanisme respons: helper melempar A3HttpResponse agar kode bisa
// berhenti dari fungsi mana pun, sama seperti `return json(...)` di JS.
// ---------------------------------------------------------------------
class A3HttpResponse extends Exception
{
    public int $status;
    public string $body;
    public string $contentType;
    /** @var string[] */
    public array $headers;

    public function __construct(int $status, string $body, string $contentType = 'application/json; charset=utf-8', array $headers = [])
    {
        parent::__construct('http-response');
        $this->status = $status;
        $this->body = $body;
        $this->contentType = $contentType;
        $this->headers = $headers;
    }
}

function json_out($data, int $status = 200, array $headers = []): void
{
    throw new A3HttpResponse(
        $status,
        json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'application/json; charset=utf-8',
        $headers
    );
}

function fail_out(string $message, int $status = 400): void
{
    json_out(['success' => false, 'error' => $message], $status);
}

// ---------------------------------------------------------------------
// Helper umum
// ---------------------------------------------------------------------
// Meniru parseInt ala JS: Math.trunc(Number(v)), NaN -> nilai bawaan.
function jint($v, int $d = 0): int
{
    if (is_int($v)) return $v;
    if (is_float($v)) return is_finite($v) ? (int) $v : $d;
    if (is_bool($v)) return $v ? 1 : 0;
    if (is_string($v) && is_numeric(trim($v))) return (int) (float) trim($v);
    return $d;
}

// Meniru parseFloat ala JS (mengambil awalan angka dari string).
function jfloat($v): float
{
    if (is_int($v) || is_float($v)) return (float) $v;
    if (is_string($v) && preg_match('/^\s*[+-]?(\d+\.?\d*|\.\d+)([eE][+-]?\d+)?/', $v, $m)) return (float) $m[0];
    return NAN;
}

// Meniru String(v).slice(0, n) di JS.
function jslice($v, int $n): string
{
    $s = is_string($v) ? $v : (is_scalar($v) ? (string) $v : '');
    return function_exists('mb_substr') ? mb_substr($s, 0, $n) : substr($s, 0, $n);
}

function jstr($v): string
{
    if (is_string($v)) return $v;
    if (is_int($v) || is_float($v)) return (string) $v;
    if (is_bool($v)) return $v ? 'true' : 'false';
    return '';
}

// Waktu sekarang dalam format persis JS `new Date().toISOString()`:
// UTC, milidetik 3 digit, akhiran 'Z'. Format seragam ini membuat
// perbandingan string (lexicographic) sah untuk SQLite & MySQL.
function now_iso(): string
{
    $t = microtime(true);
    $sec = (int) floor($t);
    $ms = (int) floor(($t - $sec) * 1000);
    return gmdate('Y-m-d\TH:i:s', $sec) . '.' . str_pad((string) $ms, 3, '0', STR_PAD_LEFT) . 'Z';
}

// Tanggal hari ini menurut WIB (Asia/Jakarta), format Y-m-d.
function wib_today(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta')))->format('Y-m-d');
}

// Ubah tanggal WIB (Y-m-d) menjadi batas bawah UTC ISO (inklusif).
function wib_day_start_utc(string $ymd): string
{
    $dt = new DateTimeImmutable($ymd . ' 00:00:00', new DateTimeZone('Asia/Jakarta'));
    return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s') . '.000Z';
}

// Ubah tanggal WIB (Y-m-d) menjadi batas atas UTC ISO (eksklusif):
// tengah malam WIB pada hari BERIKUTNYA.
function wib_day_end_utc(string $ymd): string
{
    $dt = new DateTimeImmutable($ymd . ' 00:00:00', new DateTimeZone('Asia/Jakarta'));
    return $dt->modify('+1 day')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s') . '.000Z';
}

// Tanggal WIB dari sebuah timestamp created_at (UTC ISO).
function wib_day_of(string $iso): string
{
    $dt = new DateTimeImmutable($iso);
    return $dt->setTimezone(new DateTimeZone('Asia/Jakarta'))->format('Y-m-d');
}

// ---------------------------------------------------------------------
// Koneksi database (PDO) + pembuatan tabel otomatis
// ---------------------------------------------------------------------
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    if (DB_DRIVER === 'mysql') {
        $dsn = 'mysql:host=' . DB_MYSQL_HOST . ';port=' . DB_MYSQL_PORT . ';dbname=' . DB_MYSQL_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, DB_MYSQL_USER, DB_MYSQL_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        db_create_tables_mysql($pdo);
    } else {
        $path = DB_SQLITE_PATH;
        $dir = dirname($path);
        if (!is_dir($dir)) mkdir($dir, 0775, true);
        $isNew = !file_exists($path);
        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        // Aman dijalankan berulang: semua CREATE memakai IF NOT EXISTS.
        db_create_tables_sqlite($pdo);
        unset($isNew);
    }
    return $pdo;
}

function db_create_tables_sqlite(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        type TEXT NOT NULL DEFAULT 'barang',
        sku TEXT DEFAULT '',
        price INTEGER NOT NULL DEFAULT 0,
        cost INTEGER NOT NULL DEFAULT 0,
        unit TEXT DEFAULT 'pcs',
        stock INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS transactions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        invoice_no TEXT NOT NULL UNIQUE,
        customer_name TEXT DEFAULT '',
        plate TEXT DEFAULT '',
        customer_phone TEXT DEFAULT '',
        subtotal INTEGER NOT NULL DEFAULT 0,
        discount INTEGER NOT NULL DEFAULT 0,
        total INTEGER NOT NULL DEFAULT 0,
        payment_method TEXT NOT NULL DEFAULT 'Tunai',
        amount_paid INTEGER NOT NULL DEFAULT 0,
        change_amount INTEGER NOT NULL DEFAULT 0,
        status TEXT NOT NULL DEFAULT 'selesai',
        shift_id INTEGER,
        created_at TEXT NOT NULL
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS transaction_items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        transaction_id INTEGER NOT NULL,
        item_id INTEGER,
        item_name TEXT NOT NULL,
        item_type TEXT NOT NULL DEFAULT 'barang',
        price INTEGER NOT NULL DEFAULT 0,
        qty INTEGER NOT NULL DEFAULT 1,
        line_total INTEGER NOT NULL DEFAULT 0
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
        key TEXT PRIMARY KEY,
        value TEXT NOT NULL
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS shifts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        opened_at TEXT NOT NULL,
        closed_at TEXT,
        opening_cash INTEGER NOT NULL DEFAULT 0,
        closing_cash INTEGER,
        expected_cash INTEGER,
        difference INTEGER,
        status TEXT NOT NULL DEFAULT 'buka',
        note TEXT DEFAULT ''
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_tx_items_tx ON transaction_items (transaction_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_tx_created ON transactions (created_at)");
}

function db_create_tables_mysql(PDO $pdo): void
{
    // Terjemahan DDL SQLite -> MySQL: VARCHAR/TEXT, INT, AUTO_INCREMENT.
    // settings.value memakai LONGTEXT karena logo data-URL bisa
    // mencapai 1.200.000 karakter (melebihi kapasitas TEXT biasa).
    $stmts = [
        "CREATE TABLE IF NOT EXISTS items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            type VARCHAR(20) NOT NULL DEFAULT 'barang',
            sku VARCHAR(255) DEFAULT '',
            price INT NOT NULL DEFAULT 0,
            cost INT NOT NULL DEFAULT 0,
            unit VARCHAR(50) DEFAULT 'pcs',
            stock INT NOT NULL DEFAULT 0,
            created_at VARCHAR(40) NOT NULL,
            updated_at VARCHAR(40) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS transactions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            invoice_no VARCHAR(60) NOT NULL UNIQUE,
            customer_name VARCHAR(255) DEFAULT '',
            plate VARCHAR(60) DEFAULT '',
            customer_phone VARCHAR(60) DEFAULT '',
            subtotal INT NOT NULL DEFAULT 0,
            discount INT NOT NULL DEFAULT 0,
            total INT NOT NULL DEFAULT 0,
            payment_method VARCHAR(20) NOT NULL DEFAULT 'Tunai',
            amount_paid INT NOT NULL DEFAULT 0,
            change_amount INT NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT 'selesai',
            shift_id INT NULL,
            created_at VARCHAR(40) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS transaction_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            transaction_id INT NOT NULL,
            item_id INT NULL,
            item_name VARCHAR(255) NOT NULL,
            item_type VARCHAR(20) NOT NULL DEFAULT 'barang',
            price INT NOT NULL DEFAULT 0,
            qty INT NOT NULL DEFAULT 1,
            line_total INT NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS settings (
            `key` VARCHAR(191) PRIMARY KEY,
            value LONGTEXT NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS shifts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            opened_at VARCHAR(40) NOT NULL,
            closed_at VARCHAR(40) NULL,
            opening_cash INT NOT NULL DEFAULT 0,
            closing_cash INT NULL,
            expected_cash INT NULL,
            difference INT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'buka',
            note VARCHAR(500) DEFAULT ''
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];
    foreach ($stmts as $sql) $pdo->exec($sql);
    // MySQL tidak mendukung CREATE INDEX IF NOT EXISTS: abaikan error
    // "sudah ada" saat tabel/index dibuat ulang pada kunjungan berikut.
    foreach ([
        "CREATE INDEX idx_tx_items_tx ON transaction_items (transaction_id)",
        "CREATE INDEX idx_tx_created ON transactions (created_at)",
    ] as $sql) {
        try { $pdo->exec($sql); } catch (PDOException $e) { /* index sudah ada */ }
    }
}

// ---------------------------------------------------------------------
// Casting baris hasil query: PDO (terutama MySQL) mengembalikan angka
// sebagai string, sedangkan frontend melakukan aritmetika di atasnya.
// ---------------------------------------------------------------------
function cast_ints(array $row, array $intFields, array $nullableIntFields = []): array
{
    foreach ($intFields as $f) {
        if (array_key_exists($f, $row)) $row[$f] = $row[$f] === null ? 0 : (int) $row[$f];
    }
    foreach ($nullableIntFields as $f) {
        if (array_key_exists($f, $row)) $row[$f] = $row[$f] === null ? null : (int) $row[$f];
    }
    return $row;
}

function cast_item(array $r): array
{
    return cast_ints($r, ['id', 'price', 'cost', 'stock']);
}

function cast_tx(array $r): array
{
    return cast_ints(
        $r,
        ['id', 'subtotal', 'discount', 'total', 'amount_paid', 'change_amount'],
        ['shift_id']
    );
}

function cast_tx_item(array $r): array
{
    return cast_ints($r, ['id', 'transaction_id', 'price', 'qty', 'line_total'], ['item_id']);
}

function cast_shift(array $r): array
{
    return cast_ints($r, ['id', 'opening_cash'], ['closing_cash', 'expected_cash', 'difference']);
}

// ---------------------------------------------------------------------
// Akses settings (upsert portabel: UPDATE dulu/Cek dulu, lalu INSERT,
// tanpa ON CONFLICT / ON DUPLICATE KEY yang khas satu driver saja).
// ---------------------------------------------------------------------
function get_setting(string $key): ?string
{
    $st = db()->prepare("SELECT value FROM settings WHERE `key` = ?");
    $st->execute([$key]);
    $row = $st->fetch();
    return $row ? (string) $row['value'] : null;
}

function set_setting(string $key, string $value): void
{
    $pdo = db();
    $st = $pdo->prepare("SELECT value FROM settings WHERE `key` = ?");
    $st->execute([$key]);
    if ($st->fetch()) {
        $up = $pdo->prepare("UPDATE settings SET value = ? WHERE `key` = ?");
        $up->execute([$value, $key]);
    } else {
        $in = $pdo->prepare("INSERT INTO settings (`key`, value) VALUES (?, ?)");
        $in->execute([$key, $value]);
    }
}

// Badan JSON dari request (null bila kosong / bukan JSON valid),
// sama seperti `await req.json().catch(() => null)` di worker.
function req_json(): ?array
{
    static $parsed = false;
    static $value = null;
    if (!$parsed) {
        $parsed = true;
        $raw = file_get_contents('php://input');
        if ($raw !== false && trim($raw) !== '') {
            $dec = json_decode($raw, true);
            $value = is_array($dec) ? $dec : null;
        }
    }
    return $value;
}

// ---------------------------------------------------------------------
// PIN lock (kunci sesi identik dengan worker)
// PIN disimpan sebagai sha256 hex dari "a3motor-pin:" . PIN.
// Cookie "a3session" = "<exp>.<hmac-sha256 hex>", kunci HMAC adalah
// string pin_hash itu sendiri, pesan yang ditandatangani "a3motor:<exp>".
// Masa berlaku 7 hari. Flag Secure HANYA ditambahkan bila request
// benar-benar HTTPS, agar login tetap bisa lewat HTTP di jaringan
// lokal XAMPP (http://192.168.x.x).
// ---------------------------------------------------------------------
const SESSION_COOKIE = 'a3session';
const SESSION_DAYS = 7;

function request_is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') return true;
    if (isset($_SERVER['SERVER_PORT']) && (string) $_SERVER['SERVER_PORT'] === '443') return true;
    return false;
}

function get_pin_hash(): ?string
{
    return get_setting('pin_hash');
}

function cookie_value(string $name): ?string
{
    $raw = $_SERVER['HTTP_COOKIE'] ?? '';
    foreach (explode(';', $raw) as $part) {
        $part = trim($part);
        if ($part === '') continue;
        $eq = strpos($part, '=');
        $k = $eq === false ? $part : substr($part, 0, $eq);
        if (trim($k) === $name) return $eq === false ? '' : substr($part, $eq + 1);
    }
    return null;
}

function is_authed(): bool
{
    $pinHash = get_pin_hash();
    if (!$pinHash) return false;
    $token = cookie_value(SESSION_COOKIE);
    if (!$token) return false;
    $parts = explode('.', $token);
    $expStr = $parts[0] ?? '';
    $sig = $parts[1] ?? null;
    $exp = is_numeric($expStr) ? (int) $expStr : 0;
    if (!$exp || $exp < time()) return false;
    $expected = hash_hmac('sha256', 'a3motor:' . $expStr, $pinHash);
    return $sig !== null && hash_equals($expected, $sig);
}

function make_session_cookie(): string
{
    $pinHash = get_pin_hash();
    $exp = time() + SESSION_DAYS * 86400;
    $sig = hash_hmac('sha256', 'a3motor:' . $exp, (string) $pinHash);
    $c = SESSION_COOKIE . '=' . $exp . '.' . $sig . '; Path=/; Max-Age=' . (SESSION_DAYS * 86400) . '; HttpOnly;';
    if (request_is_https()) $c .= ' Secure;';
    return $c . ' SameSite=Lax';
}

function clear_session_cookie(): string
{
    $c = SESSION_COOKIE . '=; Path=/; Max-Age=0; HttpOnly;';
    if (request_is_https()) $c .= ' Secure;';
    return $c . ' SameSite=Lax';
}

function valid_pin_format($pin): bool
{
    return is_string($pin) && preg_match('/^[0-9]{4,8}$/', $pin) === 1;
}

function handle_auth(string $path, string $method): void
{
    try {
        if ($path === '/api/auth/status' && $method === 'GET') {
            $pinHash = get_pin_hash();
            json_out(['success' => true, 'pin_set' => !!$pinHash, 'authed' => $pinHash ? is_authed() : false]);
        }
        if ($path === '/api/auth/setup' && $method === 'POST') {
            if (get_pin_hash()) fail_out('PIN sudah dibuat. Masuk dengan PIN yang ada.', 409);
            $b = req_json();
            if (!$b || !valid_pin_format($b['pin'] ?? null)) fail_out('PIN harus 4-8 digit angka');
            $hash = hash('sha256', 'a3motor-pin:' . $b['pin']);
            $st = db()->prepare("INSERT INTO settings (`key`, value) VALUES ('pin_hash', ?)");
            $st->execute([$hash]);
            json_out(['success' => true], 200, ['Set-Cookie: ' . make_session_cookie()]);
        }
        if ($path === '/api/auth/login' && $method === 'POST') {
            $pinHash = get_pin_hash();
            if (!$pinHash) fail_out('PIN belum dibuat', 409);
            $b = req_json();
            $hash = $b && isset($b['pin']) && is_string($b['pin']) ? hash('sha256', 'a3motor-pin:' . $b['pin']) : '';
            if (!hash_equals($pinHash, $hash)) fail_out('PIN salah', 401);
            json_out(['success' => true], 200, ['Set-Cookie: ' . make_session_cookie()]);
        }
        if ($path === '/api/auth/logout' && $method === 'POST') {
            json_out(['success' => true], 200, ['Set-Cookie: ' . clear_session_cookie()]);
        }
        if ($path === '/api/auth/change' && $method === 'POST') {
            if (!is_authed()) fail_out('Belum masuk', 401);
            $b = req_json();
            if (!$b || !valid_pin_format($b['new_pin'] ?? null)) fail_out('PIN baru harus 4-8 digit angka');
            $pinHash = get_pin_hash();
            $oldHash = isset($b['old_pin']) && is_string($b['old_pin']) ? hash('sha256', 'a3motor-pin:' . $b['old_pin']) : '';
            if (!hash_equals((string) $pinHash, $oldHash)) fail_out('PIN lama salah', 401);
            $hash = hash('sha256', 'a3motor-pin:' . $b['new_pin']);
            $st = db()->prepare("UPDATE settings SET value = ? WHERE `key` = 'pin_hash'");
            $st->execute([$hash]);
            json_out(['success' => true], 200, ['Set-Cookie: ' . make_session_cookie()]);
        }
        fail_out('Endpoint tidak ditemukan', 404);
    } catch (A3HttpResponse $r) {
        throw $r;
    } catch (Throwable $e) {
        fail_out('Server error: ' . $e->getMessage(), 500);
    }
}

// ---------------------------------------------------------------------
// Katalog & stok
// ---------------------------------------------------------------------
function list_items(): void
{
    $rows = db()->query("SELECT id, name, type, sku, price, cost, unit, stock, created_at, updated_at FROM items ORDER BY type ASC, name ASC")->fetchAll();
    json_out(['success' => true, 'items' => array_map('cast_item', $rows)]);
}

function create_item(): void
{
    $b = req_json();
    if (!$b || trim(jstr($b['name'] ?? '')) === '') fail_out('Nama wajib diisi');
    $type = ($b['type'] ?? null) === 'jasa' ? 'jasa' : 'barang';
    $now = now_iso();
    $unit = trim(jstr($b['unit'] ?? ($type === 'jasa' ? 'paket' : 'pcs')));
    if ($unit === '') $unit = 'pcs';
    $st = db()->prepare("INSERT INTO items (name, type, sku, price, cost, unit, stock, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $st->execute([
        trim(jstr($b['name'])), $type, trim(jstr($b['sku'] ?? '')),
        max(0, jint($b['price'] ?? null)), max(0, jint($b['cost'] ?? null)),
        $unit,
        $type === 'jasa' ? 0 : max(0, jint($b['stock'] ?? null)), $now, $now,
    ]);
    json_out(['success' => true, 'id' => (int) db()->lastInsertId()], 201);
}

function update_item(int $id): void
{
    $b = req_json();
    if (!$b || trim(jstr($b['name'] ?? '')) === '') fail_out('Nama wajib diisi');
    $type = ($b['type'] ?? null) === 'jasa' ? 'jasa' : 'barang';
    $unit = trim(jstr($b['unit'] ?? 'pcs'));
    if ($unit === '') $unit = 'pcs';
    $st = db()->prepare("UPDATE items SET name = ?, type = ?, sku = ?, price = ?, cost = ?, unit = ?, stock = ?, updated_at = ? WHERE id = ?");
    $st->execute([
        trim(jstr($b['name'])), $type, trim(jstr($b['sku'] ?? '')),
        max(0, jint($b['price'] ?? null)), max(0, jint($b['cost'] ?? null)),
        $unit,
        $type === 'jasa' ? 0 : max(0, jint($b['stock'] ?? null)), now_iso(), $id,
    ]);
    json_out(['success' => true]);
}

function delete_item(int $id): void
{
    $st = db()->prepare("DELETE FROM items WHERE id = ?");
    $st->execute([$id]);
    json_out(['success' => true]);
}

function adjust_stock(int $id): void
{
    $b = req_json();
    $qty = $b ? jint($b['qty'] ?? null) : 0;
    if (!$qty) fail_out('Jumlah stok tidak valid');
    $st = db()->prepare("SELECT id, type, stock FROM items WHERE id = ?");
    $st->execute([$id]);
    $item = $st->fetch();
    if (!$item) fail_out('Barang tidak ditemukan', 404);
    if ($item['type'] !== 'barang') fail_out('Jasa tidak memiliki stok');
    $stock = (int) $item['stock'];
    if ($stock + $qty < 0) fail_out('Stok tidak boleh minus');
    $up = db()->prepare("UPDATE items SET stock = stock + ?, updated_at = ? WHERE id = ?");
    $up->execute([$qty, now_iso(), $id]);
    json_out(['success' => true, 'stock' => $stock + $qty]);
}

// ---------------------------------------------------------------------
// Transaksi
// ---------------------------------------------------------------------
function list_transactions(array $query): void
{
    $q = trim(jstr($query['q'] ?? ''));
    if ($q !== '') {
        $like = '%' . $q . '%';
        $st = db()->prepare("SELECT * FROM transactions WHERE invoice_no LIKE ? OR customer_name LIKE ? OR plate LIKE ? OR customer_phone LIKE ? ORDER BY id DESC LIMIT 200");
        $st->execute([$like, $like, $like, $like]);
        $rows = $st->fetchAll();
    } else {
        $rows = db()->query("SELECT * FROM transactions ORDER BY id DESC LIMIT 200")->fetchAll();
    }
    json_out(['success' => true, 'transactions' => array_map('cast_tx', $rows)]);
}

function get_transaction(int $id): void
{
    $st = db()->prepare("SELECT * FROM transactions WHERE id = ?");
    $st->execute([$id]);
    $tx = $st->fetch();
    if (!$tx) fail_out('Transaksi tidak ditemukan', 404);
    $st2 = db()->prepare("SELECT * FROM transaction_items WHERE transaction_id = ? ORDER BY id ASC");
    $st2->execute([$id]);
    json_out(['success' => true, 'transaction' => cast_tx($tx), 'items' => array_map('cast_tx_item', $st2->fetchAll())]);
}

function checkout(): void
{
    $b = req_json();
    if (!$b || !isset($b['lines']) || !is_array($b['lines']) || count($b['lines']) === 0) fail_out('Keranjang kosong');
    $lines = [];
    foreach ($b['lines'] as $l) {
        if (!is_array($l)) continue;
        $lines[] = ['item_id' => jint($l['item_id'] ?? null), 'qty' => max(1, jint($l['qty'] ?? null, 1))];
    }
    $lines = array_values(array_filter($lines, fn($l) => $l['item_id'] > 0));
    if (count($lines) === 0) fail_out('Keranjang tidak valid');

    $ids = array_values(array_unique(array_map(fn($l) => $l['item_id'], $lines)));
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $st = db()->prepare("SELECT id, name, type, price, stock FROM items WHERE id IN ($ph)");
    $st->execute($ids);
    $byId = [];
    foreach ($st->fetchAll() as $r) $byId[(int) $r['id']] = cast_item($r);

    $subtotal = 0;
    $detail = [];
    foreach ($lines as $l) {
        $item = $byId[$l['item_id']] ?? null;
        if (!$item) fail_out('Ada barang yang tidak ditemukan di katalog');
        if ($item['type'] === 'barang' && $item['stock'] < $l['qty']) {
            fail_out('Stok ' . $item['name'] . ' tidak cukup (sisa ' . $item['stock'] . ')');
        }
        $lineTotal = $item['price'] * $l['qty'];
        $subtotal += $lineTotal;
        $detail[] = ['item' => $item, 'qty' => $l['qty'], 'lineTotal' => $lineTotal];
    }
    $discount = min(max(0, jint($b['discount'] ?? null)), $subtotal);
    $total = $subtotal - $discount;

    $shift = get_open_shift();
    if (!$shift) fail_out('Belum ada shift yang dibuka. Buka shift dulu sebelum transaksi.');

    $method = in_array($b['payment_method'] ?? null, ['Tunai', 'QRIS', 'Transfer', 'Debit'], true) ? $b['payment_method'] : 'Tunai';
    $paid = jint($b['amount_paid'] ?? null);
    if ($method !== 'Tunai' && $paid === 0) $paid = $total;
    if ($paid < $total) fail_out('Uang diterima kurang dari total');
    $change = $paid - $total;

    $today = wib_today();
    $ymd = str_replace('-', '', $today);
    $cst = db()->prepare("SELECT COUNT(*) AS c FROM transactions WHERE invoice_no LIKE ?");
    $cst->execute(['INV/' . $ymd . '/%']);
    $countRow = $cst->fetch();
    $invoiceNo = 'INV/' . $ymd . '/' . str_pad((string) (((int) ($countRow['c'] ?? 0)) + 1), 4, '0', STR_PAD_LEFT);
    $now = now_iso();

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $ins = $pdo->prepare("INSERT INTO transactions (invoice_no, customer_name, plate, customer_phone, subtotal, discount, total, payment_method, amount_paid, change_amount, status, shift_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'selesai', ?, ?)");
        $ins->execute([
            $invoiceNo,
            trim(jstr($b['customer_name'] ?? '')),
            strtoupper(trim(jstr($b['plate'] ?? ''))),
            trim(jstr($b['customer_phone'] ?? '')),
            $subtotal, $discount, $total, $method, $paid, $change, (int) $shift['id'], $now,
        ]);
        $txId = (int) $pdo->lastInsertId();
        $insItem = $pdo->prepare("INSERT INTO transaction_items (transaction_id, item_id, item_name, item_type, price, qty, line_total) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $updStock = $pdo->prepare("UPDATE items SET stock = stock - ?, updated_at = ? WHERE id = ?");
        foreach ($detail as $d) {
            $insItem->execute([$txId, $d['item']['id'], $d['item']['name'], $d['item']['type'], $d['item']['price'], $d['qty'], $d['lineTotal']]);
            if ($d['item']['type'] === 'barang') $updStock->execute([$d['qty'], $now, $d['item']['id']]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    $stx = db()->prepare("SELECT * FROM transactions WHERE invoice_no = ?");
    $stx->execute([$invoiceNo]);
    json_out(['success' => true, 'transaction' => cast_tx($stx->fetch())], 201);
}

function cancel_transaction(int $id): void
{
    $pdo = db();
    $st = $pdo->prepare("SELECT * FROM transactions WHERE id = ?");
    $st->execute([$id]);
    $tx = $st->fetch();
    if (!$tx) fail_out('Transaksi tidak ditemukan', 404);
    if ($tx['status'] === 'batal') fail_out('Transaksi sudah dibatalkan');
    $st2 = $pdo->prepare("SELECT item_id, item_type, qty FROM transaction_items WHERE transaction_id = ?");
    $st2->execute([$id]);
    $rows = $st2->fetchAll();
    $now = now_iso();
    $pdo->beginTransaction();
    try {
        $up = $pdo->prepare("UPDATE transactions SET status = 'batal' WHERE id = ?");
        $up->execute([$id]);
        $upStock = $pdo->prepare("UPDATE items SET stock = stock + ?, updated_at = ? WHERE id = ?");
        foreach ($rows as $r) {
            if ($r['item_type'] === 'barang' && $r['item_id']) {
                $upStock->execute([(int) $r['qty'], $now, (int) $r['item_id']]);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    json_out(['success' => true]);
}

// ---------------------------------------------------------------------
// Ringkasan & laporan
// ---------------------------------------------------------------------
function summary(): void
{
    $today = wib_today();
    $st = db()->prepare("SELECT COALESCE(SUM(total), 0) AS revenue, COUNT(*) AS count FROM transactions WHERE status = 'selesai' AND created_at >= ? AND created_at < ?");
    $st->execute([wib_day_start_utc($today), wib_day_end_utc($today)]);
    $row = $st->fetch();
    json_out(['success' => true, 'date' => $today, 'revenue' => (int) $row['revenue'], 'count' => (int) $row['count']]);
}

function report(array $query): void
{
    $dateRe = '/^\d{4}-\d{2}-\d{2}$/';
    $from = jstr($query['from'] ?? '');
    $to = jstr($query['to'] ?? '');
    $today = wib_today();
    if (!preg_match($dateRe, $from)) $from = $today;
    if (!preg_match($dateRe, $to)) $to = $today;
    if ($from > $to) { $t = $from; $from = $to; $to = $t; }
    // Rentang WIB -> batas UTC ISO; filter string berlaku sama di kedua driver.
    $start = wib_day_start_utc($from);
    $end = wib_day_end_utc($to);
    $range = "created_at >= ? AND created_at < ?";
    $pdo = db();

    $st = $pdo->prepare("SELECT COALESCE(SUM(total), 0) AS revenue, COUNT(*) AS count, COALESCE(SUM(discount), 0) AS discount FROM transactions WHERE status = 'selesai' AND $range");
    $st->execute([$start, $end]);
    $totals = $st->fetch();

    $st = $pdo->prepare("SELECT COUNT(*) AS count FROM transactions WHERE status = 'batal' AND $range");
    $st->execute([$start, $end]);
    $batal = $st->fetch();

    $st = $pdo->prepare("SELECT COALESCE(SUM((ti.price - COALESCE(i.cost, 0)) * ti.qty), 0) AS profit FROM transaction_items ti JOIN transactions t ON t.id = ti.transaction_id LEFT JOIN items i ON i.id = ti.item_id WHERE t.status = 'selesai' AND t.created_at >= ? AND t.created_at < ?");
    $st->execute([$start, $end]);
    $profitRow = $st->fetch();

    $st = $pdo->prepare("SELECT payment_method AS method, COUNT(*) AS count, COALESCE(SUM(total), 0) AS revenue FROM transactions WHERE status = 'selesai' AND $range GROUP BY payment_method ORDER BY revenue DESC");
    $st->execute([$start, $end]);
    $byMethod = array_map(fn($r) => cast_ints($r, ['count', 'revenue']), $st->fetchAll());

    // by_day dikelompokkan di PHP (menghindari fungsi date() khas driver):
    // hari WIB diturunkan dari created_at UTC di sisi PHP.
    $st = $pdo->prepare("SELECT created_at, total FROM transactions WHERE status = 'selesai' AND $range ORDER BY created_at ASC");
    $st->execute([$start, $end]);
    $dayMap = [];
    foreach ($st->fetchAll() as $r) {
        $day = wib_day_of((string) $r['created_at']);
        if (!isset($dayMap[$day])) $dayMap[$day] = ['day' => $day, 'count' => 0, 'revenue' => 0];
        $dayMap[$day]['count']++;
        $dayMap[$day]['revenue'] += (int) $r['total'];
    }
    ksort($dayMap);
    $byDay = array_values($dayMap);

    $st = $pdo->prepare("SELECT ti.item_name AS name, ti.item_type AS type, SUM(ti.qty) AS qty, COALESCE(SUM(ti.line_total), 0) AS revenue FROM transaction_items ti JOIN transactions t ON t.id = ti.transaction_id WHERE t.status = 'selesai' AND t.created_at >= ? AND t.created_at < ? GROUP BY ti.item_name, ti.item_type ORDER BY qty DESC, revenue DESC LIMIT 10");
    $st->execute([$start, $end]);
    $topItems = array_map(fn($r) => cast_ints($r, ['qty', 'revenue']), $st->fetchAll());

    $st = $pdo->prepare("SELECT ti.item_type AS type, SUM(ti.qty) AS qty, COALESCE(SUM(ti.line_total), 0) AS revenue FROM transaction_items ti JOIN transactions t ON t.id = ti.transaction_id WHERE t.status = 'selesai' AND t.created_at >= ? AND t.created_at < ? GROUP BY ti.item_type");
    $st->execute([$start, $end]);
    $byType = array_map(fn($r) => cast_ints($r, ['qty', 'revenue']), $st->fetchAll());

    $topByType = function (string $type) use ($pdo, $start, $end) {
        $st = $pdo->prepare("SELECT ti.item_name AS name, SUM(ti.qty) AS qty, COALESCE(SUM(ti.line_total), 0) AS revenue FROM transaction_items ti JOIN transactions t ON t.id = ti.transaction_id WHERE t.status = 'selesai' AND ti.item_type = ? AND t.created_at >= ? AND t.created_at < ? GROUP BY ti.item_name ORDER BY qty DESC, revenue DESC LIMIT 10");
        $st->execute([$type, $start, $end]);
        return array_map(fn($r) => cast_ints($r, ['qty', 'revenue']), $st->fetchAll());
    };
    $topBarang = $topByType('barang');
    $topJasa = $topByType('jasa');

    $st = $pdo->prepare("SELECT * FROM transactions WHERE $range ORDER BY id DESC LIMIT 500");
    $st->execute([$start, $end]);
    $transactions = array_map('cast_tx', $st->fetchAll());

    $revenue = (int) $totals['revenue'];
    $count = (int) $totals['count'];
    $discount = (int) $totals['discount'];
    json_out([
        'success' => true, 'from' => $from, 'to' => $to,
        'revenue' => $revenue, 'count' => $count, 'discount' => $discount,
        'avg' => $count ? (int) round($revenue / $count) : 0,
        'profit' => (int) $profitRow['profit'] - $discount, 'cancelled' => (int) $batal['count'],
        'by_method' => $byMethod, 'by_day' => $byDay, 'top_items' => $topItems,
        'by_type' => $byType, 'top_barang' => $topBarang, 'top_jasa' => $topJasa,
        'transactions' => $transactions,
    ]);
}

// ---------------------------------------------------------------------
// Shift kasir
// ---------------------------------------------------------------------
function get_open_shift(): ?array
{
    $row = db()->query("SELECT * FROM shifts WHERE status = 'buka' ORDER BY id DESC LIMIT 1")->fetch();
    return $row ? cast_shift($row) : null;
}

function shift_totals(int $shiftId): array
{
    $pdo = db();
    $st = $pdo->prepare("SELECT COUNT(*) AS count, COALESCE(SUM(total), 0) AS sales FROM transactions WHERE shift_id = ? AND status = 'selesai'");
    $st->execute([$shiftId]);
    $all = $st->fetch();
    $st = $pdo->prepare("SELECT COALESCE(SUM(total), 0) AS tunai FROM transactions WHERE shift_id = ? AND status = 'selesai' AND payment_method = 'Tunai'");
    $st->execute([$shiftId]);
    $tunai = $st->fetch();
    $st = $pdo->prepare("SELECT payment_method AS method, COUNT(*) AS count, COALESCE(SUM(total), 0) AS revenue FROM transactions WHERE shift_id = ? AND status = 'selesai' GROUP BY payment_method ORDER BY revenue DESC");
    $st->execute([$shiftId]);
    $byMethod = array_map(fn($r) => cast_ints($r, ['count', 'revenue']), $st->fetchAll());
    return ['count' => (int) $all['count'], 'sales' => (int) $all['sales'], 'tunai' => (int) $tunai['tunai'], 'by_method' => $byMethod];
}

function current_shift(): void
{
    $s = get_open_shift();
    if (!$s) json_out(['success' => true, 'shift' => null]);
    $t = shift_totals((int) $s['id']);
    json_out(['success' => true, 'shift' => $s, 'totals' => $t, 'expected_cash' => $s['opening_cash'] + $t['tunai']]);
}

function open_shift(): void
{
    if (get_open_shift()) fail_out('Masih ada shift yang terbuka. Tutup dulu shift itu.');
    $b = req_json();
    $opening = max(0, jint($b['opening_cash'] ?? null));
    $st = db()->prepare("INSERT INTO shifts (opened_at, opening_cash, status) VALUES (?, ?, 'buka')");
    $st->execute([now_iso(), $opening]);
    $id = (int) db()->lastInsertId();
    $st2 = db()->prepare("SELECT * FROM shifts WHERE id = ?");
    $st2->execute([$id]);
    json_out(['success' => true, 'shift' => cast_shift($st2->fetch())], 201);
}

function close_shift(): void
{
    $s = get_open_shift();
    if (!$s) fail_out('Tidak ada shift yang sedang terbuka');
    $b = req_json();
    if (!$b || !array_key_exists('closing_cash', $b) || $b['closing_cash'] === null
        || (is_string($b['closing_cash']) && trim($b['closing_cash']) === '')) {
        fail_out('Uang aktual di laci wajib diisi');
    }
    $closing = max(0, jint($b['closing_cash']));
    $t = shift_totals((int) $s['id']);
    $expected = $s['opening_cash'] + $t['tunai'];
    $st = db()->prepare("UPDATE shifts SET closed_at = ?, closing_cash = ?, expected_cash = ?, difference = ?, status = 'tutup', note = ? WHERE id = ?");
    $st->execute([now_iso(), $closing, $expected, $closing - $expected, trim(jstr($b['note'] ?? '')), (int) $s['id']]);
    $st2 = db()->prepare("SELECT * FROM shifts WHERE id = ?");
    $st2->execute([(int) $s['id']]);
    json_out(['success' => true, 'shift' => cast_shift($st2->fetch()), 'totals' => $t, 'expected_cash' => $expected]);
}

function list_shifts(): void
{
    $rows = db()->query("SELECT s.*, COALESCE(a.cnt, 0) AS tx_count, COALESCE(a.sales, 0) AS sales FROM shifts s LEFT JOIN (SELECT shift_id, COUNT(*) AS cnt, SUM(total) AS sales FROM transactions WHERE status = 'selesai' AND shift_id IS NOT NULL GROUP BY shift_id) a ON a.shift_id = s.id ORDER BY s.id DESC LIMIT 50")->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $r = cast_shift($r);
        $r['tx_count'] = (int) $r['tx_count'];
        $r['sales'] = (int) $r['sales'];
        $out[] = $r;
    }
    json_out(['success' => true, 'shifts' => $out]);
}

// ---------------------------------------------------------------------
// Data Pelanggan: pengelompokan union-find berdasarkan plat ATAU
// nomor WA yang dinormalisasi (transitif), sama seperti worker.
// ---------------------------------------------------------------------
function norm_plate(array $tx): string
{
    $p = strtoupper(trim(jstr($tx['plate'] ?? '')));
    return preg_replace('/\s+/', ' ', $p);
}

function norm_phone(array $tx): string
{
    $d = preg_replace('/\D/', '', jstr($tx['customer_phone'] ?? ''));
    if (str_starts_with($d, '62')) $d = '0' . substr($d, 2);
    elseif (str_starts_with($d, '8')) $d = '0' . $d;
    return (strlen($d) >= 9 && str_starts_with($d, '0')) ? $d : '';
}

// Mengembalikan daftar grup: [['key' => 'GRP:<id tx terkecil>', 'txs' => [...]], ...]
function group_customers(array $results): array
{
    return group_customers_impl($results);
}

function uf_find(array &$parent, $x)
{
    $r = $x;
    while ($parent[$r] !== $r) $r = $parent[$r];
    $c = $x;
    while ($parent[$c] !== $r) { $n = $parent[$c]; $parent[$c] = $r; $c = $n; }
    return $r;
}

function uf_union(array &$parent, $a, $b): void
{
    $ra = uf_find($parent, $a);
    $rb = uf_find($parent, $b);
    if ($ra !== $rb) $parent[$ra] = $rb;
}

function group_customers_impl(array $results): array
{
    $parent = [];
    $plateOwner = [];
    $phoneOwner = [];
    $eligible = [];
    $nameGroups = []; // nama (lowercase) => daftar tx, urutan kemunculan pertama
    foreach ($results as $tx) {
        $plate = norm_plate($tx);
        $phone = norm_phone($tx);
        $name = strtolower(trim(jstr($tx['customer_name'] ?? '')));
        if ($plate === '' && $phone === '') {
            if ($name === '') continue;
            if (!isset($nameGroups[$name])) $nameGroups[$name] = [];
            $nameGroups[$name][] = $tx;
            continue;
        }
        $eligible[] = $tx;
        $parent[$tx['id']] = $tx['id'];
        if ($plate !== '') {
            if (isset($plateOwner[$plate])) uf_union($parent, $tx['id'], $plateOwner[$plate]);
            else $plateOwner[$plate] = $tx['id'];
        }
        if ($phone !== '') {
            if (isset($phoneOwner[$phone])) uf_union($parent, $tx['id'], $phoneOwner[$phone]);
            else $phoneOwner[$phone] = $tx['id'];
        }
    }
    $buckets = []; // root => daftar tx, urutan kemunculan root pertama
    foreach ($eligible as $tx) {
        $r = uf_find($parent, $tx['id']);
        if (!isset($buckets[$r])) $buckets[$r] = [];
        $buckets[$r][] = $tx;
    }
    $groups = [];
    foreach (array_merge(array_values($buckets), array_values($nameGroups)) as $txs) {
        $minId = min(array_map(fn($t) => (int) $t['id'], $txs));
        $groups[] = ['key' => 'GRP:' . $minId, 'txs' => $txs];
    }
    return $groups;
}

function list_customers(): void
{
    $rows = db()->query("SELECT id, invoice_no, customer_name, plate, customer_phone, total, status, created_at FROM transactions ORDER BY id DESC LIMIT 2000")->fetchAll();
    $rows = array_map(fn($r) => cast_tx($r), $rows);
    $customers = [];
    foreach (group_customers($rows) as $g) {
        $txs = $g['txs'];
        $c = [
            'key' => $g['key'], 'name' => '', 'plate' => '', 'plates' => [], 'phone' => '',
            'visits' => 0, 'total_spent' => 0,
            'last_visit' => $txs[0]['created_at'], 'first_visit' => $txs[count($txs) - 1]['created_at'],
        ];
        foreach ($txs as $tx) {
            if ($c['name'] === '' && !empty($tx['customer_name'])) $c['name'] = $tx['customer_name'];
            $p = norm_plate($tx);
            if ($p !== '' && !in_array($p, $c['plates'], true)) $c['plates'][] = $p;
            if ($c['phone'] === '' && !empty($tx['customer_phone'])) $c['phone'] = $tx['customer_phone'];
            if ($tx['status'] === 'selesai') { $c['visits']++; $c['total_spent'] += (int) $tx['total']; }
        }
        $c['plate'] = $c['plates'][0] ?? '';
        $customers[] = $c;
    }
    usort($customers, function ($a, $b) {
        if ($a['last_visit'] === $b['last_visit']) return 0;
        return $a['last_visit'] < $b['last_visit'] ? 1 : -1;
    });
    json_out(['success' => true, 'customers' => $customers]);
}

function customer_detail(array $query): void
{
    $key = jstr($query['key'] ?? '');
    $rows = db()->query("SELECT id, invoice_no, customer_name, plate, customer_phone, total, status, payment_method, created_at FROM transactions ORDER BY id DESC LIMIT 2000")->fetchAll();
    $rows = array_map(fn($r) => cast_tx($r), $rows);
    $group = null;
    foreach (group_customers($rows) as $g) {
        if ($g['key'] === $key) { $group = $g; break; }
    }
    if (!$group) fail_out('Pelanggan tidak ditemukan', 404);
    $txs = $group['txs'];
    $ids = array_map(fn($t) => (int) $t['id'], $txs);
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $st = db()->prepare("SELECT transaction_id, item_name, item_type, qty, price FROM transaction_items WHERE transaction_id IN ($ph)");
    $st->execute($ids);
    $byTx = [];
    foreach ($st->fetchAll() as $l) {
        $l = cast_ints($l, ['transaction_id', 'qty', 'price']);
        $byTx[$l['transaction_id']][] = $l;
    }
    $phone = '';
    foreach ($txs as $t) {
        if (!empty($t['customer_phone'])) { $phone = $t['customer_phone']; break; }
    }
    $outTxs = [];
    foreach ($txs as $t) {
        $t['items'] = $byTx[(int) $t['id']] ?? [];
        $outTxs[] = $t;
    }
    json_out(['success' => true, 'phone' => $phone, 'transactions' => $outTxs]);
}

function get_services(): void
{
    $rows = db()->query("SELECT name, price FROM items WHERE type = 'jasa' ORDER BY name ASC")->fetchAll();
    $rows = array_map(fn($r) => cast_ints($r, ['price']), $rows);
    json_out(['success' => true, 'services' => $rows]);
}

// ---------------------------------------------------------------------
// Profil toko (settings) & logo
// ---------------------------------------------------------------------
const STORE_DEFAULTS = [
    'store_name' => 'A3 Motor Service Station',
    'store_address' => 'Jalan Piai Tangah, Bandar Buat, Kec. Lubuk Kilangan, Kota Padang 25231',
    'store_phone' => '0813-7466-3567',
    'theme_accent' => '#4FB2FF',
    'theme_accent_strong' => '#1B7BD4',
    'theme_sidebar' => '#0E1930',
    'theme_sidebar2' => '#1D2D4D',
    'store_lat' => '-0.9504468',
    'store_lng' => '100.4300209',
];
const HEX_RE = '/^#[0-9a-fA-F]{6}$/';

function read_store_profile(): array
{
    $keys = "'store_name', 'store_address', 'store_phone', 'theme_accent', 'theme_accent_strong', 'theme_sidebar', 'theme_sidebar2', 'store_lat', 'store_lng', 'store_logo'";
    $rows = db()->query("SELECT `key`, value FROM settings WHERE `key` IN ($keys)")->fetchAll();
    $out = STORE_DEFAULTS;
    $out['has_custom_logo'] = false;
    foreach ($rows as $r) {
        if ($r['key'] === 'store_logo') { $out['has_custom_logo'] = true; continue; }
        $out[$r['key']] = $r['value'];
    }
    return $out;
}

function get_settings(): void
{
    json_out(['success' => true, 'profile' => read_store_profile()]);
}

function update_settings(): void
{
    $b = req_json();
    if (!$b || trim(jstr($b['store_name'] ?? '')) === '') fail_out('Nama toko wajib diisi');
    $vals = [
        'store_name' => jslice(trim(jstr($b['store_name'])), 80),
        'store_address' => jslice(trim(jstr($b['store_address'] ?? '')), 300),
        'store_phone' => jslice(trim(jstr($b['store_phone'] ?? '')), 30),
    ];
    foreach (['theme_accent', 'theme_accent_strong', 'theme_sidebar', 'theme_sidebar2'] as $k) {
        if (isset($b[$k]) && is_string($b[$k]) && preg_match(HEX_RE, $b[$k])) $vals[$k] = $b[$k];
    }
    $lat = jfloat($b['store_lat'] ?? null);
    $lng = jfloat($b['store_lng'] ?? null);
    if (is_finite($lat) && is_finite($lng) && $lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180) {
        $vals['store_lat'] = jslice(trim(jstr($b['store_lat'])), 24);
        $vals['store_lng'] = jslice(trim(jstr($b['store_lng'])), 24);
    }
    foreach ($vals as $k => $v) set_setting($k, $v);
    json_out(['success' => true, 'profile' => read_store_profile()]);
}

function get_logo(): void
{
    $value = get_setting('store_logo');
    if (!$value) fail_out('Tidak ada logo khusus', 404);
    if (!preg_match('/^data:(image\/(?:png|jpeg|webp));base64,(.+)$/', $value, $m)) {
        fail_out('Logo tersimpan tidak valid', 500);
    }
    $bin = base64_decode($m[2], true);
    if ($bin === false) $bin = base64_decode($m[2]);
    throw new A3HttpResponse(200, $bin === false ? '' : $bin, $m[1], ['Cache-Control: no-cache']);
}

function update_logo(): void
{
    $b = req_json();
    $data = $b && isset($b['data_url']) && is_string($b['data_url']) ? $b['data_url'] : '';
    if (!preg_match('/^data:image\/(?:png|jpeg|webp);base64,/', $data)) {
        fail_out('Logo harus berupa gambar PNG, JPG, atau WebP');
    }
    if (strlen($data) > 1200000) fail_out('Logo terlalu besar. Pakai gambar persegi yang lebih sederhana.');
    set_setting('store_logo', $data);
    json_out(['success' => true]);
}

function delete_logo(): void
{
    $st = db()->prepare("DELETE FROM settings WHERE `key` = 'store_logo'");
    $st->execute();
    json_out(['success' => true]);
}

// ---------------------------------------------------------------------
// Router API utama (meniru blok fetch() pada worker)
// ---------------------------------------------------------------------
function handle_api(string $path, string $method, array $query): void
{
    try {
        if (preg_match('#^/api/items$#', $path) && $method === 'GET') list_items();
        if ($path === '/api/items' && $method === 'POST') create_item();
        if (preg_match('#^/api/items/(\d+)$#', $path, $mm)) {
            if ($method === 'PUT') update_item((int) $mm[1]);
            if ($method === 'DELETE') delete_item((int) $mm[1]);
        }
        if (preg_match('#^/api/items/(\d+)/stock$#', $path, $mm) && $method === 'POST') adjust_stock((int) $mm[1]);
        if ($path === '/api/transactions' && $method === 'GET') list_transactions($query);
        if ($path === '/api/transactions' && $method === 'POST') checkout();
        if (preg_match('#^/api/transactions/(\d+)$#', $path, $mm) && $method === 'GET') get_transaction((int) $mm[1]);
        if (preg_match('#^/api/transactions/(\d+)/cancel$#', $path, $mm) && $method === 'POST') cancel_transaction((int) $mm[1]);
        if ($path === '/api/summary' && $method === 'GET') summary();
        if ($path === '/api/report' && $method === 'GET') report($query);
        if ($path === '/api/shift' && $method === 'GET') current_shift();
        if ($path === '/api/shift/open' && $method === 'POST') open_shift();
        if ($path === '/api/shift/close' && $method === 'POST') close_shift();
        if ($path === '/api/shifts' && $method === 'GET') list_shifts();
        if ($path === '/api/settings' && $method === 'PUT') update_settings();
        if ($path === '/api/settings/logo' && $method === 'PUT') update_logo();
        if ($path === '/api/settings/logo' && $method === 'DELETE') delete_logo();
        if ($path === '/api/customers' && $method === 'GET') list_customers();
        if ($path === '/api/customers/detail' && $method === 'GET') customer_detail($query);
        fail_out('Endpoint tidak ditemukan', 404);
    } catch (A3HttpResponse $r) {
        throw $r;
    } catch (Throwable $e) {
        fail_out('Server error: ' . $e->getMessage(), 500);
    }
}

function a3_run(): void
{
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    $path = parse_url($uri, PHP_URL_PATH);
    if (!is_string($path) || $path === '') $path = '/';
    $query = [];
    $qs = parse_url($uri, PHP_URL_QUERY);
    if (is_string($qs) && $qs !== '') parse_str($qs, $query);
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

    try {
        if (str_starts_with($path, '/api/')) {
            // Endpoint publik (tanpa PIN): auth, profil toko, logo, jasa.
            if (str_starts_with($path, '/api/auth/')) handle_auth($path, $method);
            if ($path === '/api/settings' && $method === 'GET') get_settings();
            if ($path === '/api/logo' && $method === 'GET') get_logo();
            if ($path === '/api/services' && $method === 'GET') get_services();
            if (!is_authed()) fail_out('Terkunci. Masukkan PIN dulu.', 401);
            handle_api($path, $method, $query);
            fail_out('Endpoint tidak ditemukan', 404);
        }
        fail_out('Endpoint tidak ditemukan', 404);
    } catch (A3HttpResponse $r) {
        http_response_code($r->status);
        header('Content-Type: ' . $r->contentType);
        foreach ($r->headers as $h) header($h);
        echo $r->body;
    }
}

a3_run();

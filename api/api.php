<?php
declare(strict_types=1);

/* ============================================================
   api/api.php  -  Delivery System (PHP + SQL Server via PDO)
   Actions: me, register, login, logout, ping
   ============================================================ */

// ---------- CONFIG (แก้ตรงนี้) ----------
const DB_DSN  = 'sqlsrv:Server=localhost\SQLEXPRESS;Database=ParcelDelivery;Encrypt=yes;TrustServerCertificate=yes';
const DB_USER = 'web_app';
const DB_PASS = '12345678';   // <-- แก้แล้ว: เพิ่มค่าที่ขาดไป (เดิมไม่ได้ประกาศ ทำให้ระบบพัง)
const DEBUG   = true;                              // ใช้งานจริงให้เปลี่ยนเป็น false
// ----------------------------------------

/* กัน PHP warning/notice ไปปนกับ JSON (ทำให้หน้าเว็บอ่านผลไม่ได้) */
ini_set('display_errors', '0');
error_reporting(E_ALL);

session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'path' => '/']);
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function db(): PDO {
    static $pdo = null;
    if (!$pdo) {
        /* แก้แล้ว: เช็กว่ามีไดรเวอร์ SQL Server และแจ้งข้อความที่เข้าใจง่าย */
        if (!extension_loaded('pdo_sqlsrv')) {
            throw new RuntimeException(
                'PHP ยังไม่มีไดรเวอร์ pdo_sqlsrv (ต้องติดตั้ง Microsoft Drivers for PHP for SQL Server แล้วเปิดใน php.ini)'
            );
        }
        $pdo = new PDO(DB_DSN, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::SQLSRV_ATTR_ENCODING    => PDO::SQLSRV_ENCODING_UTF8,
        ]);
    }
    return $pdo;
}

function out(array $d, int $code = 200): never {
    http_response_code($code);
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}

function fail(string $msg, int $code = 200): never {
    out(['error' => $msg], $code);
}

function throttle_check(): void {
    $until = $_SESSION['lock_until'] ?? 0;
    if ($until > time()) {
        fail('ลองผิดหลายครั้งเกินไป กรุณารอ ' . ($until - time()) . ' วินาที');
    }
}
function throttle_fail(): void {
    $_SESSION['fails'] = ($_SESSION['fails'] ?? 0) + 1;
    if ($_SESSION['fails'] >= 5) {
        $_SESSION['lock_until'] = time() + 60;
        $_SESSION['fails'] = 0;
    }
}
function throttle_reset(): void {
    unset($_SESSION['fails'], $_SESSION['lock_until']);
}

function login_user(int $uid, string $role): void {
    session_regenerate_id(true);
    $_SESSION['uid']  = $uid;
    $_SESSION['role'] = $role;
    throttle_reset();
}

function require_role(string $role): int {
    if (empty($_SESSION['uid']) || ($_SESSION['role'] ?? '') !== $role) {
        fail('กรุณาเข้าสู่ระบบ', 401);
    }
    return (int)$_SESSION['uid'];
}

$in = json_decode(file_get_contents('php://input') ?: '[]', true);
if (!is_array($in)) $in = [];
$a = $_GET['a'] ?? '';

try {
    if (str_starts_with($a, 'r_')) { require __DIR__ . '/rider.php'; exit; }
    switch ($a) {

    case 'me':
        if (empty($_SESSION['uid'])) out(['guest' => true]);
        out(['guest' => false, 'role' => $_SESSION['role'], 'id' => $_SESSION['uid']]);

    /* ---------- ทดสอบการเชื่อมต่อฐานข้อมูล (เพิ่มใหม่) ----------
       เปิด http://localhost/delivery/api/api.php?a=ping เพื่อตรวจสอบ */
    case 'ping':
        db()->query('SELECT 1');
        out(['ok' => true, 'db' => 'connected']);

    case 'register':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('method not allowed', 405);

        $role  = ($in['role'] ?? '') === 'rider' ? 'rider' : 'customer';
        $name  = trim((string)($in['name']  ?? ''));
        $phone = preg_replace('/[\s\-]/', '', trim((string)($in['phone'] ?? '')));
        $email = strtolower(trim((string)($in['email'] ?? '')));
        $pw    = (string)($in['password'] ?? '');

        if ($name === '' || mb_strlen($name) > 100) fail('กรุณากรอกชื่อ (ไม่เกิน 100 ตัวอักษร)');
        if (strlen($pw) < 8)                        fail('รหัสผ่านต้องมีอย่างน้อย 8 ตัวอักษร');
        if (strlen($pw) > 72)                       fail('รหัสผ่านยาวเกินไป (สูงสุด 72 ตัวอักษร)');
        $hash = password_hash($pw, PASSWORD_DEFAULT);

        if ($role === 'rider') {
            if (!preg_match('/^[0-9+]{9,15}$/', $phone)) fail('เบอร์โทรไม่ถูกต้อง');

            $chk = db()->prepare('SELECT 1 FROM dbo.driver WHERE phone = ?');
            $chk->execute([$phone]);
            if ($chk->fetch()) fail('เบอร์นี้ถูกใช้แล้ว');

            $st = db()->prepare(
                "INSERT INTO dbo.driver (name, phone, status, password_hash)
                 OUTPUT INSERTED.driver_id
                 VALUES (?, ?, 'inactive', ?)"
            );
            $st->execute([$name, $phone, $hash]);
        } else {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) fail('อีเมลไม่ถูกต้อง');
            if ($phone !== '' && !preg_match('/^[0-9+]{9,15}$/', $phone))           fail('เบอร์โทรไม่ถูกต้อง');

            $chk = db()->prepare('SELECT 1 FROM dbo.customer WHERE email = ?');
            $chk->execute([$email]);
            if ($chk->fetch()) fail('อีเมลนี้ถูกใช้แล้ว');

            $st = db()->prepare(
                'INSERT INTO dbo.customer (name, phone, email, password_hash)
                 OUTPUT INSERTED.customer_id
                 VALUES (?, ?, ?, ?)'
            );
            $st->execute([$name, $phone !== '' ? $phone : null, $email, $hash]);
        }

        login_user((int)$st->fetchColumn(), $role);
        out(['ok' => true, 'role' => $role]);

    /* ---------- เข้าสู่ระบบ ---------- */
    case 'login':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('method not allowed', 405);
        throttle_check();

        $role = ($in['role'] ?? '') === 'rider' ? 'rider' : 'customer';
        $id   = trim((string)($in['id'] ?? ''));
        $pw   = (string)($in['password'] ?? '');

        if ($role === 'rider') {
            $phone = preg_replace('/[\s\-]/', '', $id);
            $st = db()->prepare('SELECT driver_id AS uid, password_hash FROM dbo.driver WHERE phone = ?');
            $st->execute([$phone]);
        } else {
            $st = db()->prepare('SELECT customer_id AS uid, password_hash FROM dbo.customer WHERE email = ?');
            $st->execute([strtolower($id)]);
        }
        $u = $st->fetch();

        // ข้อความเดียวกันทุกกรณี ไม่บอกว่าผิดที่ id หรือรหัสผ่าน
        if (!$u || empty($u['password_hash']) || !password_verify($pw, $u['password_hash'])) {
            throttle_fail();
            fail('ข้อมูลเข้าสู่ระบบไม่ถูกต้อง');
        }

        if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
            $tbl = $role === 'rider' ? 'dbo.driver SET password_hash = ? WHERE driver_id = ?'
                                     : 'dbo.customer SET password_hash = ? WHERE customer_id = ?';
            db()->prepare("UPDATE $tbl")->execute([password_hash($pw, PASSWORD_DEFAULT), $u['uid']]);
        }

        login_user((int)$u['uid'], $role);
        out(['ok' => true, 'role' => $role]);

    /* ---------- ออกจากระบบ ---------- */
    case 'logout':
        $_SESSION = [];
        session_destroy();
        out(['ok' => true]);

    default:
        fail('unknown action', 404);
    }

} catch (PDOException $e) {
    error_log('[api.php] ' . $e->getMessage());
    // 2627 / 2601 = ข้อมูลซ้ำ (UNIQUE) กรณีสมัครพร้อมกัน
    if (in_array((int)($e->errorInfo[1] ?? 0), [2627, 2601], true)) {
        fail('ข้อมูลนี้ถูกใช้แล้ว');
    }
    fail('เกิดข้อผิดพลาดที่ฐานข้อมูล' . (DEBUG ? ': ' . $e->getMessage() : ''), 500);
} catch (Throwable $e) {
    error_log('[api.php] ' . $e->getMessage());
    fail('เกิดข้อผิดพลาดที่เซิร์ฟเวอร์' . (DEBUG ? ': ' . $e->getMessage() : ''), 500);
}
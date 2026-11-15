<?php
declare(strict_types=1);

/* ============================================================
   api/parcel.php  -  สร้างออเดอร์ส่งพัสดุจากหน้า customer.html
   GET  ?a=init (หรือ pricing)        ส่งตารางราคา (pricing_rule) ให้หน้าเว็บ  [ไม่ต้องล็อกอิน]
   POST ?a=create (หรือ create_parcel) บันทึก parcel + tracking_event + payment (transaction เดียว) [ต้องล็อกอินเป็น customer]
   GET  ?a=order&id=<parcel_id>        ดึงรายละเอียดออเดอร์ + สถานะชำระเงิน + สถานะพัสดุ + ผู้ส่ง (rider) + คะแนนที่ให้ไว้ (เฉพาะเจ้าของออเดอร์)
   GET  ?a=list                        รายการออเดอร์ของลูกค้า ใช้กับ My orders ใน customer.html
   POST ?a=pay {parcel_id}             ยืนยันการชำระเงิน (จำลอง) -> payment.status='paid', paid_at=now
   POST ?a=rate {parcel_id,star,comment}  ให้คะแนนไรเดอร์หลังพัสดุส่งถึงที่หมาย -> dbo.rating
   ใช้ session เดียวกับ api.php ($_SESSION['uid'], $_SESSION['role'])
   ============================================================ */

// ---------- CONFIG: ต้องตรงกับค่าใน api.php ----------
const DB_DSN  = 'sqlsrv:Server=localhost\SQLEXPRESS;Database=ParcelDelivery;Encrypt=yes;TrustServerCertificate=yes';
const DB_USER = 'web_app';
const DB_PASS = '12345678';
const DEBUG   = true;     // ใช้งานจริงให้เปลี่ยนเป็น false
// -----------------------------------------------------

ini_set('display_errors', '0');
error_reporting(E_ALL);

ini_set('session.gc_maxlifetime', (string)(60 * 60 * 24 * 30));
session_set_cookie_params(['lifetime' => 60 * 60 * 24 * 30, 'httponly' => true, 'samesite' => 'Lax', 'path' => '/']);
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function out(array $d, int $code = 200): never {
    http_response_code($code);
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}
function fail(string $msg, int $code = 400): never { out(['error' => $msg], $code); }

function db(): PDO {
    static $pdo = null;
    if (!$pdo) {
        if (!extension_loaded('pdo_sqlsrv')) throw new RuntimeException('PHP ยังไม่มีไดรเวอร์ pdo_sqlsrv');
        $pdo = new PDO(DB_DSN, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::SQLSRV_ATTR_ENCODING    => PDO::SQLSRV_ENCODING_UTF8,
        ]);
    }
    return $pdo;
}

/* ราคาล่าสุดที่เปิดใช้งานของแต่ละขนาด: [ 'S' => [...], 'M' => [...], 'L' => [...] ] */
function load_rules(): array {
    $rules = [];
    $q = db()->query(
        "SELECT rule_id, size, base_fee, per_km_rate, min_fare
         FROM dbo.pricing_rule
         WHERE is_active = 1 AND effective_from <= SYSDATETIME()
         ORDER BY effective_from DESC, rule_id DESC"
    );
    foreach ($q as $r) {
        if (!isset($rules[$r['size']])) $rules[$r['size']] = $r;   // แถวแรก = ใหม่สุด
    }
    return $rules;
}

/* ระยะทางเส้นตรงระหว่างสองพิกัด (กม.) */
function haversine_km(float $la1, float $lo1, float $la2, float $lo2): float {
    $r = M_PI / 180;
    $dLa = ($la2 - $la1) * $r;
    $dLo = ($lo2 - $lo1) * $r;
    $a = sin($dLa / 2) ** 2 + cos($la1 * $r) * cos($la2 * $r) * sin($dLo / 2) ** 2;
    return 6371.0 * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

/* วันที่/เวลา -> dd/mm/yyyy HH:mm (รองรับทั้ง DateTime และ string) */
function fmt_dt(mixed $v): string {
    if ($v instanceof DateTimeInterface) return $v->format('d/m/Y H:i');
    $t = strtotime((string)$v);
    return $t ? date('d/m/Y H:i', $t) : (string)$v;
}

/* หา rider (dbo.driver) ที่รับพัสดุนี้ ผ่าน route_parcel -> delivery_route -> driver
   ถ้ายังไม่มีใครรับงานหรือเกิดข้อผิดพลาด จะคืนค่าว่าง (ไม่ทำให้หน้าอื่นพัง) */
function find_driver(int $pid): array {
    $none = ['id' => 0, 'name' => '', 'phone' => ''];
    try {
        $st = db()->prepare(
            "SELECT TOP 1 d.driver_id, d.name, d.phone
             FROM dbo.route_parcel rp
             JOIN dbo.delivery_route r ON r.route_id = rp.route_id
             JOIN dbo.driver d ON d.driver_id = r.driver_id
             WHERE rp.parcel_id = ? AND r.status <> 'cancelled'
             ORDER BY r.route_id DESC"
        );
        $st->execute([$pid]);
        $d = $st->fetch();
        if ($d) return ['id' => (int)$d['driver_id'], 'name' => (string)$d['name'], 'phone' => (string)($d['phone'] ?? '')];
    } catch (Throwable $e) {
        error_log('[parcel.php] find_driver: ' . $e->getMessage());
    }
    return $none;
}

$a = $_GET['a'] ?? '';
// รองรับชื่อ action เดิมของหน้าเว็บด้วย
if ($a === 'pricing')       $a = 'init';
if ($a === 'create_parcel') $a = 'create';

try {

    /* ---------- init: ส่งราคาให้หน้าเว็บ (ไม่ต้องล็อกอิน) ---------- */
    if ($a === 'init') {
        $rules = load_rules();
        $pub = [];
        foreach ($rules as $size => $r) {
            $pub[$size] = [
                'base_fee'    => (float)$r['base_fee'],
                'per_km_rate' => (float)$r['per_km_rate'],
                'min_fare'    => (float)$r['min_fare'],
            ];
        }
        out(['rules' => $pub]);
    }

    /* ---------- create: บันทึกออเดอร์ (ต้องล็อกอินเป็นลูกค้า) ---------- */
    if ($a === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (empty($_SESSION['uid']) || ($_SESSION['role'] ?? '') !== 'customer') {
            out(['error' => 'กรุณาเข้าสู่ระบบ', 'login' => true], 401);
        }
        $uid = (int)$_SESSION['uid'];

        $in = json_decode(file_get_contents('php://input') ?: '[]', true);
        if (!is_array($in)) fail('ข้อมูลไม่ถูกต้อง');
        $s = fn(string $k): string => trim((string)($in[$k] ?? ''));

        $size   = $s('size');
        $method = $s('method');
        $pa     = $s('pickup_address');
        $da     = $s('dropoff_address');
        $rn     = $s('receiver_name');
        // รับได้ทั้ง receiver_phone และ phone
        $rp     = preg_replace('/[\s\-]/', '', $s('receiver_phone') !== '' ? $s('receiver_phone') : $s('phone'));

        // ---- ตรวจข้อมูลที่ลูกค้าส่งมา ----
        if (!in_array($size, ['S', 'M', 'L'], true))         fail('กรุณาเลือกขนาดพัสดุ');
        $methodMap = ['promptpay' => 'qr', 'qr' => 'qr', 'card' => 'card', 'cash' => 'cash', 'transfer' => 'transfer'];
        if (!isset($methodMap[$method]))                     fail('กรุณาเลือกวิธีชำระเงิน');
        $payMethod = $methodMap[$method];

        if ($pa === '' || mb_strlen($pa) > 500)              fail('กรุณากรอกที่อยู่รับพัสดุ (ไม่เกิน 500 ตัวอักษร)');
        if ($da === '' || mb_strlen($da) > 500)              fail('กรุณากรอกที่อยู่ปลายทาง (ไม่เกิน 500 ตัวอักษร)');
        if ($rn === '' || mb_strlen($rn) > 100)              fail('กรุณากรอกชื่อผู้รับ (ไม่เกิน 100 ตัวอักษร)');
        if (!preg_match('/^\+?[0-9]{9,12}$/', $rp))          fail('เบอร์โทรผู้รับไม่ถูกต้อง');

        foreach (['pickup_lat', 'pickup_lng', 'dropoff_lat', 'dropoff_lng'] as $k) {
            if (!isset($in[$k]) || !is_numeric($in[$k]))     fail('ยังไม่มีพิกัด กรุณากดปุ่ม Locate ทั้งสองที่อยู่');
        }
        $pLat = round((float)$in['pickup_lat'], 6);   $pLng = round((float)$in['pickup_lng'], 6);
        $dLat = round((float)$in['dropoff_lat'], 6);  $dLng = round((float)$in['dropoff_lng'], 6);
        if (abs($pLat) > 90 || abs($dLat) > 90 || abs($pLng) > 180 || abs($dLng) > 180) fail('พิกัดไม่ถูกต้อง');

        // ---- คำนวณราคาฝั่งเซิร์ฟเวอร์ (ไม่เชื่อค่าจากเบราว์เซอร์) ----
        $rules = load_rules();
        if (!isset($rules[$size])) fail('ยังไม่มีราคาสำหรับขนาดนี้ กรุณาติดต่อผู้ดูแลระบบ', 500);
        $rule = $rules[$size];

        $km          = round(haversine_km($pLat, $pLng, $dLat, $dLng), 2);
        $base        = (float)$rule['base_fee'];
        $distanceFee = round($km * (float)$rule['per_km_rate'], 2);
        $total       = max((float)$rule['min_fare'], $base + $distanceFee);   // สูตรเดียวกับ view v_parcel_price

        // ---- บันทึกทั้ง 3 ตารางใน transaction เดียว ----
        $pdo = db();
        $pdo->beginTransaction();
        try {
            // สร้างเลขพัสดุ (tracking_no เป็น UNIQUE จึงลองใหม่ถ้าซ้ำ)
            $trk = '';
            for ($i = 0; $i < 5; $i++) {
                $trk = 'TH' . date('ymd') . str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                $chk = $pdo->prepare('SELECT 1 FROM dbo.parcel WHERE tracking_no = ?');
                $chk->execute([$trk]);
                if (!$chk->fetch()) break;
            }

            $ins = $pdo->prepare(
                "INSERT INTO dbo.parcel
                   (phone, sender_id, pricing_rule_id, tracking_no, receiver_name,
                    pickup_address,  pickup_lat,  pickup_lng,
                    dropoff_address, dropoff_lat, dropoff_lng,
                    size, distance_km, base_fee, distance_fee, status)
                 OUTPUT INSERTED.parcel_id
                 VALUES (?,?,?,?,?, ?,?,?, ?,?,?, ?,?,?,?, 'pending')"
            );
            $ins->execute([
                $rp, $uid, (int)$rule['rule_id'], $trk, $rn,
                $pa, $pLat, $pLng,
                $da, $dLat, $dLng,
                $size, $km, $base, $distanceFee,
            ]);
            $pid = (int)$ins->fetchColumn();
            $ins->closeCursor();

            $pdo->prepare(
                "INSERT INTO dbo.tracking_event (parcel_id, event_seq, status, event_time, lat, lng, note)
                 VALUES (?, 1, 'pending', SYSDATETIME(), ?, ?, N'Order placed')"
            )->execute([$pid, $pLat, $pLng]);

            $pdo->prepare(
                "INSERT INTO dbo.payment (parcel_id, status, amount, method)
                 VALUES (?, 'pending', ?, ?)"
            )->execute([$pid, $total, $payMethod]);

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        out([
            'ok'          => true,
            'parcel_id'   => $pid,
            'tracking_no' => $trk,
            'distance_km' => $km,
            'total_price' => $total,
            'total'       => $total,   // alias สำหรับโค้ดเก่า
        ]);
    }

    /* ---------- order: ดึงรายละเอียดออเดอร์ + การชำระเงิน + สถานะ + rider + คะแนน (เฉพาะเจ้าของออเดอร์) ---------- */
    if ($a === 'order') {
        if (empty($_SESSION['uid']) || ($_SESSION['role'] ?? '') !== 'customer') {
            out(['error' => 'กรุณาเข้าสู่ระบบ', 'login' => true], 401);
        }
        $pid = (int)($_GET['id'] ?? 0);
        $st = db()->prepare(
            "SELECT p.parcel_id, p.tracking_no, p.receiver_name, p.pickup_address, p.dropoff_address,
                    p.size, p.distance_km, p.base_fee, p.distance_fee, p.created_at, p.status,
                    pay.amount, pay.method, pay.status AS pay_status
             FROM dbo.parcel p
             JOIN dbo.payment pay ON pay.parcel_id = p.parcel_id
             WHERE p.parcel_id = ? AND p.sender_id = ?"
        );
        $st->execute([$pid, (int)$_SESSION['uid']]);
        $row = $st->fetch();
        if (!$row) fail('ไม่พบออเดอร์นี้', 404);
        foreach (['distance_km', 'base_fee', 'distance_fee', 'amount'] as $k) $row[$k] = (float)$row[$k];
        // วันและเวลาที่ลูกค้าสั่ง (parcel.created_at) -> dd/mm/yyyy HH:mm
        $row['created_at'] = fmt_dt($row['created_at']);

        // ประวัติสถานะ (tracking_event) เรียงตามลำดับ
        $ev = db()->prepare(
            "SELECT status, event_time, note FROM dbo.tracking_event
             WHERE parcel_id = ? ORDER BY event_seq"
        );
        $ev->execute([$pid]);
        $row['events'] = [];
        foreach ($ev as $e) {
            $row['events'][] = [
                'status' => $e['status'],
                'note'   => $e['note'],
                'time'   => fmt_dt($e['event_time']),
            ];
        }

        // ผู้ส่ง (rider) ถ้ามีคนรับงานแล้ว
        $d = find_driver($pid);
        $row['rider_id']    = $d['id'];
        $row['rider_name']  = $d['name'];
        $row['rider_phone'] = $d['phone'];

        // คะแนนที่ลูกค้าคนนี้ให้พัสดุชิ้นนี้ไว้แล้ว (ถ้ามี)
        $rt = db()->prepare(
            "SELECT TOP 1 star, [comment] AS comment
             FROM dbo.rating WHERE parcel_id = ? AND customer_id = ? ORDER BY rating_id DESC"
        );
        $rt->execute([$pid, (int)$_SESSION['uid']]);
        $r = $rt->fetch();
        $row['rating'] = $r ? ['star' => (int)$r['star'], 'comment' => (string)($r['comment'] ?? '')] : null;

        out(['order' => $row]);
    }

    /* ---------- rate: ให้คะแนนไรเดอร์ (หลังพัสดุส่งถึงที่หมาย, ให้ได้ครั้งเดียวต่อพัสดุ) ---------- */
    if ($a === 'rate' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (empty($_SESSION['uid']) || ($_SESSION['role'] ?? '') !== 'customer') {
            out(['error' => 'กรุณาเข้าสู่ระบบ', 'login' => true], 401);
        }
        $uid = (int)$_SESSION['uid'];
        $in  = json_decode(file_get_contents('php://input') ?: '[]', true);
        if (!is_array($in)) fail('ข้อมูลไม่ถูกต้อง');
        $pid     = (int)($in['parcel_id'] ?? 0);
        $star    = (int)($in['star'] ?? 0);
        $comment = trim((string)($in['comment'] ?? ''));

        if ($star < 1 || $star > 5)      fail('กรุณาเลือกคะแนนดาว 1–5');
        if (mb_strlen($comment) > 500)   fail('ความเห็นยาวเกินไป (ไม่เกิน 500 ตัวอักษร)');

        $st = db()->prepare('SELECT status FROM dbo.parcel WHERE parcel_id = ? AND sender_id = ?');
        $st->execute([$pid, $uid]);
        $status = $st->fetchColumn();
        $st->closeCursor();
        if ($status === false)       fail('ไม่พบออเดอร์นี้', 404);
        if ($status !== 'delivered') fail('ให้คะแนนได้หลังพัสดุส่งถึงที่หมายแล้ว');

        $d = find_driver($pid);
        if ($d['id'] < 1) fail('ไม่พบข้อมูลไรเดอร์ของพัสดุนี้');

        $chk = db()->prepare('SELECT 1 FROM dbo.rating WHERE parcel_id = ? AND customer_id = ?');
        $chk->execute([$pid, $uid]);
        if ($chk->fetch()) fail('คุณให้คะแนนพัสดุนี้ไปแล้ว');
        $chk->closeCursor();

        db()->prepare(
            'INSERT INTO dbo.rating (customer_id, driver_id, parcel_id, star, [comment]) VALUES (?, ?, ?, ?, ?)'
        )->execute([$uid, $d['id'], $pid, $star, $comment !== '' ? $comment : null]);

        out(['ok' => true]);
    }

    /* ---------- list: ออเดอร์ของลูกค้า (ใช้กับ My orders ใน customer.html) ---------- */
    if ($a === 'list') {
        if (empty($_SESSION['uid']) || ($_SESSION['role'] ?? '') !== 'customer') {
            out(['error' => 'กรุณาเข้าสู่ระบบ', 'login' => true], 401);
        }
        $st = db()->prepare(
            "SELECT TOP 30 p.parcel_id, p.tracking_no, p.receiver_name, p.status, p.created_at,
                    pay.amount, pay.method, pay.status AS pay_status
             FROM dbo.parcel p
             JOIN dbo.payment pay ON pay.parcel_id = p.parcel_id
             WHERE p.sender_id = ?
             ORDER BY p.created_at DESC, p.parcel_id DESC"
        );
        $st->execute([(int)$_SESSION['uid']]);
        $rows = [];
        foreach ($st as $r) {
            $r['amount']     = (float)$r['amount'];
            $r['created_at'] = fmt_dt($r['created_at']);
            $rows[] = $r;
        }
        out(['orders' => $rows]);
    }

    /* ---------- pay: ยืนยันการชำระเงิน (จำลอง - ยังไม่ได้เชื่อมระบบชำระเงินจริง) ---------- */
    if ($a === 'pay' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (empty($_SESSION['uid']) || ($_SESSION['role'] ?? '') !== 'customer') {
            out(['error' => 'กรุณาเข้าสู่ระบบ', 'login' => true], 401);
        }
        $in  = json_decode(file_get_contents('php://input') ?: '[]', true);
        $pid = (int)($in['parcel_id'] ?? 0);
        $up = db()->prepare(
            "UPDATE dbo.payment SET status = 'paid', paid_at = SYSDATETIME()
             WHERE parcel_id = ? AND status = 'pending' AND method <> 'cash'
               AND EXISTS (SELECT 1 FROM dbo.parcel p WHERE p.parcel_id = ? AND p.sender_id = ?)"
        );
        $up->execute([$pid, $pid, (int)$_SESSION['uid']]);
        if ($up->rowCount() < 1) fail('ไม่สามารถชำระเงินได้ (ชำระแล้ว, เป็นเงินสด หรือไม่ใช่ออเดอร์ของคุณ)');
        out(['ok' => true]);
    }

    fail('unknown action', 404);

} catch (PDOException $e) {
    error_log('[parcel.php] ' . $e->getMessage());
    fail('เกิดข้อผิดพลาดที่ฐานข้อมูล' . (DEBUG ? ': ' . $e->getMessage() : ''), 500);
} catch (Throwable $e) {
    error_log('[parcel.php] ' . $e->getMessage());
    fail('เกิดข้อผิดพลาดที่เซิร์ฟเวอร์' . (DEBUG ? ': ' . $e->getMessage() : ''), 500);
}
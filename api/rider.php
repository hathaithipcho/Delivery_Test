<?php
declare(strict_types=1);

/* ============================================================
   api/rider.php  -  ฝั่งไรเดอร์ (ถูกเรียกจาก api.php เมื่อ ?a=r_...)
   ใช้ db(), out(), fail(), require_role(), $in, $a จาก api.php
   GET  r_home          ข้อมูลหน้าแรกของไรเดอร์ (โปรไฟล์ สถิติ รถ เที่ยววิ่ง จุดแวะ พัสดุที่รอ เรตติ้ง)
   POST r_status        {status: available|inactive, lat?, lng?}
   POST r_vehicle_add   {type, plate_no, max_size}
   POST r_route_create  {vehicle_id, parcel_ids[]}
   POST r_start         {route_id}
   POST r_pickup        {route_id, parcel_id, lat?, lng?}
   POST r_deliver       {route_id, parcel_id, photo?, lat?, lng?}
   ============================================================ */

$rid = require_role('rider');
$pdo = db();

if ($a !== 'r_home' && $_SERVER['REQUEST_METHOD'] !== 'POST') fail('method not allowed', 405);

/* ---------- helpers ---------- */
function r_geo(array $in): array {
    $lat = $in['lat'] ?? null;
    $lng = $in['lng'] ?? null;
    if (is_numeric($lat) && is_numeric($lng) && abs((float)$lat) <= 90 && abs((float)$lng) <= 180) {
        return [round((float)$lat, 6), round((float)$lng, 6)];
    }
    return [null, null];
}

function r_cast(array $rows, array $floats = [], array $bools = []): array {
    foreach ($rows as &$r) {
        foreach ($floats as $k) if (array_key_exists($k, $r) && $r[$k] !== null) $r[$k] = (float)$r[$k];
        foreach ($bools as $k)  if (array_key_exists($k, $r)) $r[$k] = (bool)(int)$r[$k];
    }
    return $rows;
}

function r_event(PDO $pdo, int $pid, string $status, ?float $lat, ?float $lng, string $note): void {
    $s = $pdo->prepare('SELECT COALESCE(MAX(event_seq), 0) + 1 FROM dbo.tracking_event WHERE parcel_id = ?');
    $s->execute([$pid]);
    $seq = (int)$s->fetchColumn();
    $pdo->prepare(
        'INSERT INTO dbo.tracking_event (parcel_id, event_seq, status, event_time, lat, lng, note)
         VALUES (?, ?, ?, SYSDATETIME(), ?, ?, ?)'
    )->execute([$pid, $seq, $status, $lat, $lng, $note]);
}

function r_touch_driver(PDO $pdo, int $rid, ?float $lat, ?float $lng): void {
    if ($lat === null || $lng === null) return;
    $pdo->prepare('UPDATE dbo.driver SET current_lat = ?, lng = ? WHERE driver_id = ?')->execute([$lat, $lng, $rid]);
}

/* เที่ยววิ่งต้องเป็นของไรเดอร์คนนี้ */
function r_route(PDO $pdo, int $rid, int $routeId): array {
    $st = $pdo->prepare('SELECT route_id, status FROM dbo.delivery_route WHERE route_id = ? AND driver_id = ?');
    $st->execute([$routeId, $rid]);
    $r = $st->fetch();
    if (!$r) fail('ไม่พบเที่ยววิ่งนี้', 404);
    return $r;
}

/* เงื่อนไข SQL: พัสดุที่ยังว่าง (ไม่อยู่ในเที่ยวที่กำลังใช้งาน) และชำระแล้วหรือจ่ายเงินสด */
const R_OPEN_SQL =
    "p.status = 'pending'
     AND NOT EXISTS (SELECT 1 FROM dbo.route_parcel rp
                     JOIN dbo.delivery_route r ON r.route_id = rp.route_id
                     WHERE rp.parcel_id = p.parcel_id AND r.status IN ('planned','in_progress'))
     AND EXISTS (SELECT 1 FROM dbo.payment pay
                 WHERE pay.parcel_id = p.parcel_id AND (pay.status = 'paid' OR pay.method = 'cash'))";

/* ---------- actions ---------- */
switch ($a) {

    case 'r_home': {
        $st = $pdo->prepare(
            'SELECT d.name, d.phone, d.status, v.rating_avg
             FROM dbo.driver d JOIN dbo.v_driver_rating v ON v.driver_id = d.driver_id
             WHERE d.driver_id = ?'
        );
        $st->execute([$rid]);
        $me = $st->fetch();
        if (!$me) fail('กรุณาเข้าสู่ระบบ', 401);
        $me['rating_avg'] = $me['rating_avg'] === null ? null : (float)$me['rating_avg'];

        $st = $pdo->prepare(
            'SELECT COUNT(*) AS delivered, COALESCE(SUM(p.total_price), 0) AS value
             FROM dbo.route_parcel rp
             JOIN dbo.delivery_route r ON r.route_id = rp.route_id
             JOIN dbo.v_parcel_price p ON p.parcel_id = rp.parcel_id
             WHERE r.driver_id = ? AND rp.delivery_at IS NOT NULL'
        );
        $st->execute([$rid]);
        $stats = $st->fetch();
        $st = $pdo->prepare("SELECT COALESCE(SUM(total_km), 0) FROM dbo.delivery_route WHERE driver_id = ? AND status = 'completed'");
        $st->execute([$rid]);
        $stats = ['delivered' => (int)$stats['delivered'], 'value' => (float)$stats['value'], 'km' => (float)$st->fetchColumn()];

        $st = $pdo->prepare('SELECT vehicle_id, type, plate_no, is_active, max_size FROM dbo.vehicle WHERE driver_id = ? ORDER BY vehicle_id');
        $st->execute([$rid]);
        $vehicles = r_cast($st->fetchAll(), [], ['is_active']);

        $st = $pdo->prepare(
            'SELECT TOP 10 route_id, vehicle_id, route_date, status, started_at, ended_at, total_km
             FROM dbo.delivery_route WHERE driver_id = ? ORDER BY route_id DESC'
        );
        $st->execute([$rid]);
        $routes = r_cast($st->fetchAll(), ['total_km']);

        $st = $pdo->prepare(
            'SELECT rp.route_id, rp.stop_seq, rp.parcel_id, rp.picked_at, rp.delivery_at, rp.proof_photo_url,
                    p.tracking_no, p.status, p.size, p.distance_km, p.total_price,
                    p.pickup_address, p.pickup_lat, p.pickup_lng,
                    p.dropoff_address, p.dropoff_lat, p.dropoff_lng,
                    p.receiver_name, p.phone
             FROM dbo.route_parcel rp
             JOIN dbo.delivery_route r ON r.route_id = rp.route_id
             JOIN dbo.v_parcel_price p ON p.parcel_id = rp.parcel_id
             WHERE r.driver_id = ?
               AND r.route_id IN (SELECT TOP 10 route_id FROM dbo.delivery_route WHERE driver_id = ? ORDER BY route_id DESC)
             ORDER BY rp.route_id, rp.stop_seq'
        );
        $st->execute([$rid, $rid]);
        $stops = r_cast($st->fetchAll(), ['distance_km', 'total_price', 'pickup_lat', 'pickup_lng', 'dropoff_lat', 'dropoff_lng']);

        $open = $pdo->query(
            'SELECT TOP 50 p.parcel_id, p.tracking_no, p.size, p.distance_km, p.total_price, p.pickup_address, p.dropoff_address
             FROM dbo.v_parcel_price p WHERE ' . R_OPEN_SQL . ' ORDER BY p.created_at'
        )->fetchAll();
        $open = r_cast($open, ['distance_km', 'total_price']);

        $st = $pdo->prepare('SELECT TOP 10 star, [comment] AS comment, created_at FROM dbo.rating WHERE driver_id = ? ORDER BY created_at DESC, rating_id DESC');
        $st->execute([$rid]);
        $ratings = $st->fetchAll();

        out(['me' => $me, 'stats' => $stats, 'vehicles' => $vehicles, 'routes' => $routes,
             'stops' => $stops, 'open' => $open, 'ratings' => $ratings]);
    }

    case 'r_status': {
        $new = (string)($in['status'] ?? '');
        if (!in_array($new, ['available', 'inactive'], true)) fail('สถานะไม่ถูกต้อง');
        $st = $pdo->prepare('SELECT status FROM dbo.driver WHERE driver_id = ?');
        $st->execute([$rid]);
        if ($st->fetchColumn() === 'delivering') fail('กำลังส่งของอยู่ เปลี่ยนสถานะไม่ได้');
        $pdo->prepare('UPDATE dbo.driver SET status = ? WHERE driver_id = ?')->execute([$new, $rid]);
        [$lat, $lng] = r_geo($in);
        r_touch_driver($pdo, $rid, $lat, $lng);
        out(['ok' => true]);
    }

    case 'r_vehicle_add': {
        $type  = trim((string)($in['type'] ?? ''));
        $plate = trim((string)($in['plate_no'] ?? ''));
        $max   = (string)($in['max_size'] ?? '');
        if ($type === '' || mb_strlen($type) > 30)   fail('กรุณากรอกประเภทรถ (ไม่เกิน 30 ตัวอักษร)');
        if ($plate === '' || mb_strlen($plate) > 20) fail('กรุณากรอกทะเบียนรถ (ไม่เกิน 20 ตัวอักษร)');
        if (!in_array($max, ['S', 'M', 'L'], true))  fail('ขนาดพัสดุสูงสุดไม่ถูกต้อง');
        $pdo->prepare('INSERT INTO dbo.vehicle (driver_id, type, plate_no, is_active, max_size) VALUES (?, ?, ?, 1, ?)')
            ->execute([$rid, $type, $plate, $max]);   // ทะเบียนซ้ำ -> api.php จะตอบ "ข้อมูลนี้ถูกใช้แล้ว"
        out(['ok' => true]);
    }

    case 'r_route_create': {
        $vid = (int)($in['vehicle_id'] ?? 0);
        $ids = array_values(array_unique(array_filter(array_map('intval', (array)($in['parcel_ids'] ?? [])), fn($x) => $x > 0)));
        if (!$ids)            fail('กรุณาเลือกพัสดุอย่างน้อย 1 ชิ้น');
        if (count($ids) > 20) fail('เลือกพัสดุได้ไม่เกิน 20 ชิ้นต่อเที่ยว');

        $pdo->beginTransaction();
        $bail = function (string $m) use ($pdo): never { if ($pdo->inTransaction()) $pdo->rollBack(); fail($m); };
        try {
            $st = $pdo->prepare('SELECT status FROM dbo.driver WHERE driver_id = ?');
            $st->execute([$rid]);
            $ds = $st->fetchColumn();
            $st->closeCursor();
            if ($ds !== 'available') $bail('คุณออฟไลน์อยู่ กรุณากด Go online ก่อนรับงาน');

            $st = $pdo->prepare('SELECT max_size FROM dbo.vehicle WHERE vehicle_id = ? AND driver_id = ? AND is_active = 1');
            $st->execute([$vid, $rid]);
            $max = $st->fetchColumn();
            if (!$max) $bail('ไม่พบรถคันนี้ หรือรถไม่พร้อมใช้งาน');

            $st = $pdo->prepare("SELECT 1 FROM dbo.delivery_route WHERE driver_id = ? AND status IN ('planned','in_progress')");
            $st->execute([$rid]);
            if ($st->fetch()) $bail('คุณมีเที่ยววิ่งที่ยังไม่จบอยู่แล้ว');

            $ph = implode(',', array_fill(0, count($ids), '?'));
            $st = $pdo->prepare("SELECT p.parcel_id, p.size FROM dbo.parcel p WITH (UPDLOCK, HOLDLOCK)
                                 WHERE p.parcel_id IN ($ph) AND " . R_OPEN_SQL);
            $st->execute($ids);
            $sizes = [];
            foreach ($st->fetchAll() as $r) $sizes[(int)$r['parcel_id']] = (string)$r['size'];
            if (count($sizes) !== count($ids)) $bail('มีพัสดุที่ถูกรับไปแล้วหรือยังไม่พร้อมส่ง กรุณารีเฟรชแล้วเลือกใหม่');

            $rank = ['S' => 1, 'M' => 2, 'L' => 3];
            foreach ($sizes as $s) if ($rank[$s] > $rank[(string)$max]) $bail('มีพัสดุที่ใหญ่เกินขนาดที่รถรับได้');

            $st = $pdo->prepare(
                "INSERT INTO dbo.delivery_route (driver_id, vehicle_id, route_date, status, total_km)
                 OUTPUT INSERTED.route_id
                 VALUES (?, ?, CAST(SYSDATETIME() AS DATE), 'planned', 0)"
            );
            $st->execute([$rid, $vid]);
            $routeId = (int)$st->fetchColumn();
            $st->closeCursor();

            $ins = $pdo->prepare('INSERT INTO dbo.route_parcel (route_id, parcel_id, stop_seq) VALUES (?, ?, ?)');
            foreach ($ids as $i => $pid) $ins->execute([$routeId, $pid, $i + 1]);

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        out(['ok' => true, 'route_id' => $routeId]);
    }

    case 'r_start': {
        $route = r_route($pdo, $rid, (int)($in['route_id'] ?? 0));
        if ($route['status'] !== 'planned') fail('เริ่มเที่ยวนี้ไม่ได้');
        $st = $pdo->prepare('SELECT status FROM dbo.driver WHERE driver_id = ?');
        $st->execute([$rid]);
        $ds = $st->fetchColumn();
        $st->closeCursor();
        if ($ds !== 'available') fail('คุณออฟไลน์อยู่ กรุณากด Go online ก่อนเริ่มเที่ยว');
        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE dbo.delivery_route SET status = 'in_progress', started_at = SYSDATETIME()
                           WHERE route_id = ? AND status = 'planned'")->execute([(int)$route['route_id']]);
            $pdo->prepare("UPDATE dbo.driver SET status = 'delivering' WHERE driver_id = ?")->execute([$rid]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        out(['ok' => true]);
    }

    case 'r_pickup': {
        $route = r_route($pdo, $rid, (int)($in['route_id'] ?? 0));
        if ($route['status'] !== 'in_progress') fail('ต้องกดเริ่มเที่ยววิ่งก่อน');
        $pid = (int)($in['parcel_id'] ?? 0);
        $st = $pdo->prepare('SELECT picked_at FROM dbo.route_parcel WHERE route_id = ? AND parcel_id = ?');
        $st->execute([(int)$route['route_id'], $pid]);
        $rp = $st->fetch();
        if (!$rp) fail('ไม่พบพัสดุนี้ในเที่ยววิ่ง', 404);
        if ($rp['picked_at'] !== null) fail('รับพัสดุชิ้นนี้แล้ว');
        [$lat, $lng] = r_geo($in);

        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE dbo.route_parcel SET picked_at = SYSDATETIME() WHERE route_id = ? AND parcel_id = ? AND picked_at IS NULL')
                ->execute([(int)$route['route_id'], $pid]);
            $pdo->prepare("UPDATE dbo.parcel SET status = 'picked_up' WHERE parcel_id = ?")->execute([$pid]);
            r_event($pdo, $pid, 'picked_up', $lat, $lng, 'Parcel picked up');
            r_touch_driver($pdo, $rid, $lat, $lng);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        out(['ok' => true]);
    }

    case 'r_deliver': {
        $route = r_route($pdo, $rid, (int)($in['route_id'] ?? 0));
        if ($route['status'] !== 'in_progress') fail('ต้องกดเริ่มเที่ยววิ่งก่อน');
        $routeId = (int)$route['route_id'];
        $pid = (int)($in['parcel_id'] ?? 0);
        $st = $pdo->prepare('SELECT picked_at, delivery_at FROM dbo.route_parcel WHERE route_id = ? AND parcel_id = ?');
        $st->execute([$routeId, $pid]);
        $rp = $st->fetch();
        if (!$rp) fail('ไม่พบพัสดุนี้ในเที่ยววิ่ง', 404);
        if ($rp['picked_at'] === null)   fail('ต้องกดรับพัสดุก่อนจึงจะส่งได้');
        if ($rp['delivery_at'] !== null) fail('ส่งพัสดุชิ้นนี้แล้ว');

        $photo = trim((string)($in['photo'] ?? ''));
        if ($photo !== '' && (strlen($photo) > 2048 || !preg_match('~^https?://\S+$~i', $photo))) fail('ลิงก์รูปหลักฐานไม่ถูกต้อง');
        [$lat, $lng] = r_geo($in);

        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE dbo.route_parcel SET delivery_at = SYSDATETIME(), proof_photo_url = ?
                           WHERE route_id = ? AND parcel_id = ? AND delivery_at IS NULL')
                ->execute([$photo !== '' ? $photo : null, $routeId, $pid]);
            $pdo->prepare("UPDATE dbo.parcel SET status = 'delivered' WHERE parcel_id = ?")->execute([$pid]);
            r_event($pdo, $pid, 'delivered', $lat, $lng, 'Parcel delivered');
            // เงินสด: ถือว่าชำระเมื่อส่งสำเร็จ
            $pdo->prepare("UPDATE dbo.payment SET status = 'paid', paid_at = SYSDATETIME()
                           WHERE parcel_id = ? AND method = 'cash' AND status = 'pending'")->execute([$pid]);
            r_touch_driver($pdo, $rid, $lat, $lng);

            // ส่งครบทุกชิ้น -> จบเที่ยว
            $st = $pdo->prepare('SELECT COUNT(*) FROM dbo.route_parcel WHERE route_id = ? AND delivery_at IS NULL');
            $st->execute([$routeId]);
            if ((int)$st->fetchColumn() === 0) {
                $pdo->prepare(
                    "UPDATE dbo.delivery_route
                     SET status = 'completed', ended_at = SYSDATETIME(),
                         total_km = (SELECT COALESCE(SUM(p.distance_km), 0)
                                     FROM dbo.route_parcel rp JOIN dbo.parcel p ON p.parcel_id = rp.parcel_id
                                     WHERE rp.route_id = ?)
                     WHERE route_id = ?"
                )->execute([$routeId, $routeId]);
                $pdo->prepare("UPDATE dbo.driver SET status = 'available' WHERE driver_id = ?")->execute([$rid]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        out(['ok' => true]);
    }

    default:
        fail('unknown action', 404);
}
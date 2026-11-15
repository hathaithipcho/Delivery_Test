/* =====================================================================
   js/demo.js  -  โหมดเดโม (ไม่ต้องมี PHP / SQL Server)
   - ทำงานเฉพาะตอนเปิดผ่าน github.io, เปิดไฟล์ตรงๆ (file://) หรือใส่ ?demo=1 ท้าย URL
   - ดักจับ fetch() ที่เรียก api/api.php, api/parcel.php, api/rider.php แล้วตอบด้วยข้อมูลจำลอง
     ที่เก็บใน localStorage ของเบราว์เซอร์ (รีเฟรช/เปลี่ยนหน้าแล้วข้อมูลยังอยู่ ลบได้ด้วยปุ่ม Reset)
   - บนเครื่องของคุณ (localhost) ไม่ทำอะไรเลย ระบบยังใช้ SQL จริงเหมือนเดิม
   - ปิดโหมดเดโมที่เคยบังคับเปิด: ใส่ ?demo=0
   วาง <script> ของไฟล์นี้ไว้ก่อน script อื่นๆ ในทุกหน้า
   ===================================================================== */
(function () {
  'use strict';

  var Q = new URLSearchParams(location.search);
  function ls(fn) { try { return fn(); } catch (e) { return null; } }
  if (Q.get('demo') === '1') ls(function () { localStorage.setItem('demo_force', '1'); });
  if (Q.get('demo') === '0') ls(function () { localStorage.removeItem('demo_force'); });
  var ON = /\.github\.io$/i.test(location.hostname) || location.protocol === 'file:' ||
           ls(function () { return localStorage.getItem('demo_force'); }) === '1';
  if (!ON || window.__demoLoaded) return;
  window.__demoLoaded = true;

  /* ---------- ค่าคงที่ (ตรงกับตาราง pricing_rule ในไฟล์ SQL) ---------- */
  var RULES = { S: { base_fee: 30, per_km_rate: 5, min_fare: 40 },
                M: { base_fee: 40, per_km_rate: 7, min_fare: 60 },
                L: { base_fee: 60, per_km_rate: 10, min_fare: 90 } };
  var RANK = { S: 1, M: 2, L: 3 };
  var KEY = 'parcel_demo_db_v1';

  /* ---------- ตัวช่วยเรื่องเวลา / เบอร์ / ระยะทาง ---------- */
  function pad(n) { return (n < 10 ? '0' : '') + n; }
  function day(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
  function iso(d) { return day(d) + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds()); }
  function fmt(s) { var d = new Date(s); return pad(d.getDate()) + '/' + pad(d.getMonth() + 1) + '/' + d.getFullYear() + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes()); }
  function cleanPhone(p) { return String(p == null ? '' : p).replace(/[^\d+]/g, ''); }
  function validPhone(p) { return /^\+?\d{9,12}$/.test(p); }
  function haversine(a, b, c, d) {
    var r = Math.PI / 180, dl = (c - a) * r, dg = (d - b) * r;
    var x = Math.pow(Math.sin(dl / 2), 2) + Math.cos(a * r) * Math.cos(c * r) * Math.pow(Math.sin(dg / 2), 2);
    return Math.round(6371 * 2 * Math.atan2(Math.sqrt(x), Math.sqrt(1 - x)) * 100) / 100;
  }
  function price(size, km) {
    var r = RULES[size], base = r.base_fee, df = Math.round(km * r.per_km_rate * 100) / 100;
    return { base: base, dist: df, total: Math.max(r.min_fare, base + df) };
  }
  function trackingNo() { return 'TH' + String(new Date().getFullYear()).slice(2) + pad(new Date().getMonth() + 1) + pad(new Date().getDate()) +
                                 String(Math.floor(Math.random() * 1000000)).padStart(6, '0'); }

  /* ---------- ที่เก็บข้อมูล (localStorage; ถ้าใช้ไม่ได้จะเก็บในหน่วยความจำ) ---------- */
  var mem = null;
  function get() {
    var s = ls(function () { return localStorage.getItem(KEY); });
    if (s) { try { return JSON.parse(s); } catch (e) { /* seed ใหม่ */ } }
    if (mem) return mem;
    return seed();
  }
  function save(db) { mem = db; ls(function () { localStorage.setItem(KEY, JSON.stringify(db)); }); }

  function mkParcel(db, o) {
    var km = haversine(o.pickup_lat, o.pickup_lng, o.dropoff_lat, o.dropoff_lng), pr = price(o.size, km);
    return { parcel_id: o.id || db.seq.parcel++, sender_id: o.sender_id, tracking_no: o.tracking_no || trackingNo(),
      receiver_name: o.receiver_name, phone: o.phone, pickup_address: o.pickup_address, pickup_lat: o.pickup_lat, pickup_lng: o.pickup_lng,
      dropoff_address: o.dropoff_address, dropoff_lat: o.dropoff_lat, dropoff_lng: o.dropoff_lng, size: o.size,
      distance_km: km, base_fee: pr.base, distance_fee: pr.dist, amount: pr.total, status: o.status || 'pending',
      created_at: o.created_at || iso(new Date()), method: o.method, pay_status: o.pay_status || 'pending', events: o.events || [] };
  }
  function ev(p, status, note, time) { p.events.push({ status: status, note: note, time: time || iso(new Date()) }); }

  /* ข้อมูลตั้งต้น: ลูกค้า 1 คน ไรเดอร์ 1 คน พัสดุที่ส่งแล้ว 1 ชิ้น (มีคะแนน) และพัสดุเงินสดที่รอไรเดอร์ 1 ชิ้น */
  function seed() {
    var now = new Date(), y = new Date(now.getTime() - 26 * 3600e3);
    function at(d, m) { return iso(new Date(d.getTime() + m * 60000)); }
    var db = { seq: { customer: 2, rider: 2, vehicle: 2, parcel: 1, route: 2, rating: 2 }, session: null,
      customers: [{ id: 1, name: 'Demo Customer', email: 'demo@example.com', phone: '0810000001', password: 'demo1234' }],
      riders: [{ id: 1, name: 'Demo Rider', phone: '0812345678', password: 'demo1234', status: 'inactive' }],
      vehicles: [{ vehicle_id: 1, driver_id: 1, type: 'motorcycle', plate_no: '1กข 1234', is_active: true, max_size: 'M' }],
      parcels: [], routes: [], ratings: [] };

    var p1 = mkParcel(db, { sender_id: 1, receiver_name: 'มาลี ตัวอย่าง', phone: '0830000001', size: 'S', method: 'qr', pay_status: 'paid',
      status: 'delivered', created_at: at(y, -30), pickup_address: 'จุดรับ A กรุงเทพมหานคร', pickup_lat: 13.7563, pickup_lng: 100.5018,
      dropoff_address: 'จุดส่ง B กรุงเทพมหานคร', dropoff_lat: 13.7463, dropoff_lng: 100.5518 });
    ev(p1, 'pending', 'Order placed', at(y, -30)); ev(p1, 'picked_up', 'Parcel picked up', at(y, 10)); ev(p1, 'delivered', 'Parcel delivered', at(y, 60));
    var p2 = mkParcel(db, { sender_id: 1, receiver_name: 'ผู้รับ ตัวอย่าง', phone: '0830000002', size: 'M', method: 'cash',
      pickup_address: 'ศรีปทุม บางเขน กรุงเทพมหานคร', pickup_lat: 13.8626, pickup_lng: 100.5967,
      dropoff_address: 'สยามพารากอน ปทุมวัน กรุงเทพมหานคร', dropoff_lat: 13.7461, dropoff_lng: 100.5347 });
    ev(p2, 'pending', 'Order placed');
    db.parcels.push(p1, p2);
    db.routes.push({ route_id: 1, driver_id: 1, vehicle_id: 1, route_date: day(y), status: 'completed', started_at: at(y, 0), ended_at: at(y, 120),
      total_km: p1.distance_km, stops: [{ parcel_id: p1.parcel_id, stop_seq: 1, picked_at: at(y, 10), delivery_at: at(y, 60), proof_photo_url: null }] });
    db.ratings.push({ rating_id: 1, customer_id: 1, driver_id: 1, parcel_id: p1.parcel_id, star: 5, comment: 'ส่งตรงเวลา', created_at: at(y, 150) });
    return db;
  }

  /* ---------- ตัวช่วยตอบกลับ ---------- */
  function E(msg, code, extra) { var o = { error: msg }; if (extra) for (var k in extra) o[k] = extra[k]; return [o, code || 400]; }
  var NEED_C = [{ error: 'Please sign in', login: true }, 401];
  var NEED_R = [{ error: 'Please sign in' }, 401];
  function sess(db, role) { return db.session && db.session.role === role ? db.session : null; }
  function byId(list, key, id) { for (var i = 0; i < list.length; i++) if (list[i][key] === id) return list[i]; return null; }
  function activeRoute(r) { return r.status === 'planned' || r.status === 'in_progress'; }
  function inActiveRoute(db, pid) {
    return db.routes.some(function (r) { return activeRoute(r) && r.stops.some(function (s) { return s.parcel_id === pid; }); });
  }
  function riderOf(db, pid) {
    for (var i = db.routes.length - 1; i >= 0; i--) {
      var r = db.routes[i];
      if (r.status !== 'cancelled' && r.stops.some(function (s) { return s.parcel_id === pid; })) return byId(db.riders, 'id', r.driver_id);
    }
    return null;
  }
  function ratingAvg(db, rid) {
    var l = db.ratings.filter(function (x) { return x.driver_id === rid; });
    if (!l.length) return null;
    return Math.round(l.reduce(function (a, x) { return a + x.star; }, 0) / l.length * 100) / 100;
  }

  /* ---------- action ต่างๆ (ชื่อและรูปแบบข้อมูลตรงกับ api.php / parcel.php / rider.php) ---------- */
  var H = {};

  H.me = function (db) { var s = db.session; return s ? { guest: false, role: s.role, name: s.name } : { guest: true }; };
  H.logout = function (db) { db.session = null; return { ok: true }; };

  H.register = function (db, b) {
    var role = b.role === 'rider' ? 'rider' : 'customer', name = String(b.name || '').trim(), pw = String(b.password || ''), ph = cleanPhone(b.phone);
    if (!name) return E('Please enter your name');
    if (pw.length < 6) return E('Password must be at least 6 characters');
    if (role === 'rider') {
      if (!validPhone(ph)) return E('Please enter a valid phone number');
      if (db.riders.some(function (x) { return x.phone === ph; })) return E('This phone number is already registered');
      var r = { id: db.seq.rider++, name: name, phone: ph, password: pw, status: 'inactive' };
      db.riders.push(r); db.session = { role: 'rider', uid: r.id, name: name };
    } else {
      var em = String(b.email || '').trim();
      if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(em)) return E('Please enter a valid email');
      if (ph !== '' && !validPhone(ph)) return E('Invalid phone number');
      if (db.customers.some(function (x) { return x.email.toLowerCase() === em.toLowerCase(); })) return E('This email is already registered');
      var c = { id: db.seq.customer++, name: name, email: em, phone: ph, password: pw };
      db.customers.push(c); db.session = { role: 'customer', uid: c.id, name: name };
    }
    return { ok: true };
  };

  H.login = function (db, b) {
    var role = b.role === 'rider' ? 'rider' : 'customer', id = String(b.id || '').trim(), u;
    if (role === 'rider') { id = cleanPhone(id); u = db.riders.filter(function (x) { return x.phone === id; })[0]; }
    else u = db.customers.filter(function (x) { return x.email.toLowerCase() === id.toLowerCase(); })[0];
    if (!u || u.password !== String(b.password || '')) return E('Incorrect email/phone or password', 401);
    db.session = { role: role, uid: u.id, name: u.name };
    return { ok: true };
  };

  /* ----- ลูกค้า ----- */
  H.pricing = function () { return { rules: RULES }; };
  H.init = H.pricing;

  H.create_parcel = function (db, b) {
    var s = sess(db, 'customer'); if (!s) return NEED_C;
    var phone = cleanPhone(b.receiver_phone || b.phone), size = b.size;
    var rn = String(b.receiver_name || '').trim(), pa = String(b.pickup_address || '').trim(), da = String(b.dropoff_address || '').trim();
    if (!RULES[size]) return E('Please choose a parcel size');
    if (!pa || !da) return E('Please enter both addresses');
    if (!rn) return E('Please enter the receiver name');
    if (!validPhone(phone)) return E('Invalid receiver phone');
    var c = ['pickup_lat', 'pickup_lng', 'dropoff_lat', 'dropoff_lng'];
    for (var i = 0; i < c.length; i++) if (b[c[i]] === '' || b[c[i]] == null || isNaN(+b[c[i]])) return E('Missing coordinates. Press Locate for both addresses.');
    if (Math.abs(+b.pickup_lat) > 90 || Math.abs(+b.dropoff_lat) > 90 || Math.abs(+b.pickup_lng) > 180 || Math.abs(+b.dropoff_lng) > 180) return E('Invalid coordinates');
    var m = { promptpay: 'qr', qr: 'qr', card: 'card', cash: 'cash', transfer: 'transfer' }[b.method];
    if (!m) return E('Please choose a payment method');
    var p = mkParcel(db, { sender_id: s.uid, receiver_name: rn.slice(0, 100), phone: phone, size: size, method: m,
      pickup_address: pa.slice(0, 500), pickup_lat: +b.pickup_lat, pickup_lng: +b.pickup_lng,
      dropoff_address: da.slice(0, 500), dropoff_lat: +b.dropoff_lat, dropoff_lng: +b.dropoff_lng });
    ev(p, 'pending', 'Order placed');
    db.parcels.push(p);
    return { ok: true, parcel_id: p.parcel_id, tracking_no: p.tracking_no, distance_km: p.distance_km, total_price: p.amount, total: p.amount };
  };
  H.create = H.create_parcel;

  H.order = function (db, b, qp) {
    var s = sess(db, 'customer'); if (!s) return NEED_C;
    var p = byId(db.parcels, 'parcel_id', +qp.get('id'));
    if (!p || p.sender_id !== s.uid) return E('Order not found', 404);
    var rd = riderOf(db, p.parcel_id), rt = db.ratings.filter(function (x) { return x.parcel_id === p.parcel_id && x.customer_id === s.uid; })[0];
    return { order: { parcel_id: p.parcel_id, tracking_no: p.tracking_no, receiver_name: p.receiver_name, pickup_address: p.pickup_address,
      dropoff_address: p.dropoff_address, size: p.size, distance_km: p.distance_km, base_fee: p.base_fee, distance_fee: p.distance_fee,
      created_at: fmt(p.created_at), status: p.status, amount: p.amount, method: p.method, pay_status: p.pay_status,
      events: p.events.map(function (e) { return { status: e.status, note: e.note, time: fmt(e.time) }; }),
      rider_id: rd ? rd.id : 0, rider_name: rd ? rd.name : '', rider_phone: rd ? rd.phone : '',
      rating: rt ? { star: rt.star, comment: rt.comment || '' } : null } };
  };

  H.list = function (db) {
    var s = sess(db, 'customer'); if (!s) return NEED_C;
    return { orders: db.parcels.filter(function (p) { return p.sender_id === s.uid; }).reverse().slice(0, 30).map(function (p) {
      return { parcel_id: p.parcel_id, tracking_no: p.tracking_no, receiver_name: p.receiver_name, status: p.status,
               created_at: fmt(p.created_at), amount: p.amount, method: p.method, pay_status: p.pay_status }; }) };
  };

  H.pay = function (db, b) {
    var s = sess(db, 'customer'); if (!s) return NEED_C;
    var p = byId(db.parcels, 'parcel_id', +b.parcel_id);
    if (!p || p.sender_id !== s.uid || p.pay_status !== 'pending' || p.method === 'cash')
      return E('Cannot pay this order (already paid, cash, or not yours)');
    p.pay_status = 'paid';
    return { ok: true };
  };

  H.rate = function (db, b) {
    var s = sess(db, 'customer'); if (!s) return NEED_C;
    var p = byId(db.parcels, 'parcel_id', +b.parcel_id), star = parseInt(b.star, 10) || 0, cm = String(b.comment || '').trim();
    if (star < 1 || star > 5) return E('Please choose 1–5 stars');
    if (cm.length > 500) return E('Comment is too long (max 500 characters)');
    if (!p || p.sender_id !== s.uid) return E('Order not found', 404);
    if (p.status !== 'delivered') return E('You can rate after the parcel is delivered');
    var rd = riderOf(db, p.parcel_id); if (!rd) return E('Rider not found for this parcel');
    if (db.ratings.some(function (x) { return x.parcel_id === p.parcel_id && x.customer_id === s.uid; })) return E('You already rated this parcel');
    db.ratings.push({ rating_id: db.seq.rating++, customer_id: s.uid, driver_id: rd.id, parcel_id: p.parcel_id, star: star, comment: cm || null, created_at: iso(new Date()) });
    return { ok: true };
  };

  /* ----- ไรเดอร์ ----- */
  function openParcels(db) {
    return db.parcels.filter(function (p) { return p.status === 'pending' && !inActiveRoute(db, p.parcel_id) && (p.pay_status === 'paid' || p.method === 'cash'); });
  }

  H.r_home = function (db) {
    var s = sess(db, 'rider'); if (!s) return NEED_R;
    var me = byId(db.riders, 'id', s.uid); if (!me) return NEED_R;
    var mine = db.routes.filter(function (r) { return r.driver_id === me.id; });
    var delivered = 0, value = 0, km = 0;
    mine.forEach(function (r) {
      if (r.status === 'completed') km += r.total_km;
      r.stops.forEach(function (st) { if (st.delivery_at) { delivered++; value += byId(db.parcels, 'parcel_id', st.parcel_id).amount; } });
    });
    var last = mine.slice().sort(function (a, b) { return b.route_id - a.route_id; }).slice(0, 10), stops = [];
    last.slice().reverse().forEach(function (r) {
      r.stops.slice().sort(function (a, b) { return a.stop_seq - b.stop_seq; }).forEach(function (st) {
        var p = byId(db.parcels, 'parcel_id', st.parcel_id);
        stops.push({ route_id: r.route_id, stop_seq: st.stop_seq, parcel_id: p.parcel_id, picked_at: st.picked_at, delivery_at: st.delivery_at,
          proof_photo_url: st.proof_photo_url, tracking_no: p.tracking_no, status: p.status, size: p.size, distance_km: p.distance_km,
          total_price: p.amount, pickup_address: p.pickup_address, pickup_lat: p.pickup_lat, pickup_lng: p.pickup_lng,
          dropoff_address: p.dropoff_address, dropoff_lat: p.dropoff_lat, dropoff_lng: p.dropoff_lng, receiver_name: p.receiver_name, phone: p.phone });
      });
    });
    return { me: { name: me.name, phone: me.phone, status: me.status, rating_avg: ratingAvg(db, me.id) },
      stats: { delivered: delivered, value: Math.round(value * 100) / 100, km: Math.round(km * 100) / 100 },
      vehicles: db.vehicles.filter(function (v) { return v.driver_id === me.id; }),
      routes: last.map(function (r) { return { route_id: r.route_id, vehicle_id: r.vehicle_id, route_date: r.route_date, status: r.status,
        started_at: r.started_at || null, ended_at: r.ended_at || null, total_km: r.total_km }; }),
      stops: stops,
      open: openParcels(db).slice(0, 50).map(function (p) { return { parcel_id: p.parcel_id, tracking_no: p.tracking_no, size: p.size,
        distance_km: p.distance_km, total_price: p.amount, pickup_address: p.pickup_address, dropoff_address: p.dropoff_address }; }),
      ratings: db.ratings.filter(function (x) { return x.driver_id === me.id; }).reverse().slice(0, 10)
        .map(function (x) { return { star: x.star, comment: x.comment, created_at: x.created_at }; }) };
  };

  H.r_status = function (db, b) {
    var s = sess(db, 'rider'); if (!s) return NEED_R;
    var me = byId(db.riders, 'id', s.uid);
    if (b.status !== 'available' && b.status !== 'inactive') return E('Invalid status');
    if (me.status === 'delivering') return E('You are delivering right now. Status cannot be changed.');
    me.status = b.status;
    return { ok: true };
  };

  H.r_vehicle_add = function (db, b) {
    var s = sess(db, 'rider'); if (!s) return NEED_R;
    var type = String(b.type || '').trim(), plate = String(b.plate_no || '').trim();
    if (!type || type.length > 30) return E('Please enter the vehicle type (max 30 characters)');
    if (!plate || plate.length > 20) return E('Please enter the plate number (max 20 characters)');
    if (!RANK[b.max_size]) return E('Invalid max size');
    if (db.vehicles.some(function (v) { return v.plate_no.toLowerCase() === plate.toLowerCase(); })) return E('This plate number is already registered');
    db.vehicles.push({ vehicle_id: db.seq.vehicle++, driver_id: s.uid, type: type, plate_no: plate, is_active: true, max_size: b.max_size });
    return { ok: true };
  };

  H.r_route_create = function (db, b) {
    var s = sess(db, 'rider'); if (!s) return NEED_R;
    var me = byId(db.riders, 'id', s.uid), ids = [];
    (b.parcel_ids || []).forEach(function (x) { x = parseInt(x, 10); if (x > 0 && ids.indexOf(x) < 0) ids.push(x); });
    if (!ids.length) return E('Please select at least 1 parcel');
    if (ids.length > 20) return E('You can select up to 20 parcels per route');
    if (me.status !== 'available') return E('You are offline. Press Go online first.');
    var v = byId(db.vehicles, 'vehicle_id', +b.vehicle_id);
    if (!v || v.driver_id !== me.id || !v.is_active) return E('Vehicle not found or not available');
    if (db.routes.some(function (r) { return r.driver_id === me.id && activeRoute(r); })) return E('You already have an unfinished route');
    var open = openParcels(db).map(function (p) { return p.parcel_id; });
    if (ids.some(function (id) { return open.indexOf(id) < 0; })) return E('Some parcels were taken or are not ready. Refresh and choose again.');
    if (ids.some(function (id) { return RANK[byId(db.parcels, 'parcel_id', id).size] > RANK[v.max_size]; })) return E('Some parcels are too large for this vehicle');
    var r = { route_id: db.seq.route++, driver_id: me.id, vehicle_id: v.vehicle_id, route_date: day(new Date()), status: 'planned',
      started_at: null, ended_at: null, total_km: 0, stops: ids.map(function (id, i) { return { parcel_id: id, stop_seq: i + 1, picked_at: null, delivery_at: null, proof_photo_url: null }; }) };
    db.routes.push(r);
    ids.forEach(function (id) {
      var p = byId(db.parcels, 'parcel_id', id);
      p.status = 'accepted'; ev(p, 'accepted', 'Rider accepted');
    });
    return { ok: true, route_id: r.route_id };
  };

  function myRoute(db, s, id) { var r = byId(db.routes, 'route_id', +id); return r && r.driver_id === s.uid ? r : null; }

  H.r_start = function (db, b) {
    var s = sess(db, 'rider'); if (!s) return NEED_R;
    var r = myRoute(db, s, b.route_id), me = byId(db.riders, 'id', s.uid);
    if (!r) return E('Route not found', 404);
    if (r.status !== 'planned') return E('This route cannot be started');
    if (me.status !== 'available') return E('You are offline. Press Go online first.');
    r.status = 'in_progress'; r.started_at = iso(new Date()); me.status = 'delivering';
    return { ok: true };
  };

  H.r_pickup = function (db, b) {
    var s = sess(db, 'rider'); if (!s) return NEED_R;
    var r = myRoute(db, s, b.route_id); if (!r) return E('Route not found', 404);
    if (r.status !== 'in_progress') return E('Start the route first');
    var st = r.stops.filter(function (x) { return x.parcel_id === +b.parcel_id; })[0];
    if (!st) return E('Parcel not in this route', 404);
    if (st.picked_at) return E('Already picked up');
    var p = byId(db.parcels, 'parcel_id', st.parcel_id);
    st.picked_at = iso(new Date()); p.status = 'picked_up'; ev(p, 'picked_up', 'Parcel picked up');
    return { ok: true };
  };

  H.r_deliver = function (db, b) {
    var s = sess(db, 'rider'); if (!s) return NEED_R;
    var r = myRoute(db, s, b.route_id); if (!r) return E('Route not found', 404);
    if (r.status !== 'in_progress') return E('Start the route first');
    var st = r.stops.filter(function (x) { return x.parcel_id === +b.parcel_id; })[0];
    if (!st) return E('Parcel not in this route', 404);
    if (!st.picked_at) return E('Pick up the parcel before delivering');
    if (st.delivery_at) return E('Already delivered');
    var photo = String(b.photo || '').trim();
    if (photo && (photo.length > 2048 || !/^https?:\/\/\S+$/i.test(photo))) return E('Invalid proof photo link');
    var p = byId(db.parcels, 'parcel_id', st.parcel_id);
    st.delivery_at = iso(new Date()); st.proof_photo_url = photo || null; p.status = 'delivered'; ev(p, 'delivered', 'Parcel delivered');
    if (p.method === 'cash' && p.pay_status === 'pending') p.pay_status = 'paid';
    if (r.stops.every(function (x) { return x.delivery_at; })) {
      r.status = 'completed'; r.ended_at = iso(new Date());
      r.total_km = Math.round(r.stops.reduce(function (a, x) { return a + byId(db.parcels, 'parcel_id', x.parcel_id).distance_km; }, 0) * 100) / 100;
      byId(db.riders, 'id', s.uid).status = 'available';
    }
    return { ok: true };
  };

  /* ---------- ดักจับ fetch ---------- */
  var realFetch = window.fetch ? window.fetch.bind(window) : null;
  function handle(url, init) {
    var u = new URL(url, location.href), a = u.searchParams.get('a') || '', b = {};
    try { b = init && init.body ? JSON.parse(init.body) : {}; } catch (e) { b = {}; }
    var db = get(), r;
    try { r = H.hasOwnProperty(a) ? H[a](db, b, u.searchParams) : E('unknown action', 404); }
    catch (e) { r = E('Demo error: ' + e.message, 500); }
    save(db);
    var body = Array.isArray(r) ? r[0] : r, code = Array.isArray(r) ? r[1] : 200;
    return new Promise(function (res) {
      setTimeout(function () { res(new Response(JSON.stringify(body), { status: code, headers: { 'Content-Type': 'application/json' } })); }, 120);
    });
  }
  window.fetch = function (input, init) {
    var url = typeof input === 'string' ? input : (input && input.url) || '';
    if (/api\/(api|parcel|rider)\.php/.test(url)) return handle(url, init);
    return realFetch ? realFetch(input, init) : Promise.reject(new Error('fetch unavailable'));
  };

  /* ---------- แถบแจ้งว่าเป็นโหมดเดโม ---------- */
  function bar() {
    if (ls(function () { return sessionStorage.getItem('demo_bar_hide'); }) === '1' || !document.body) return;
    var d = document.createElement('div');
    d.setAttribute('role', 'note');
    d.style.cssText = 'position:fixed;left:12px;bottom:12px;z-index:99999;max-width:300px;padding:12px 14px;border-radius:14px;' +
      'background:rgba(255,255,255,.94);border:1px solid #cfc9ce;box-shadow:0 10px 24px -10px rgba(120,50,90,.4);' +
      'font:13px/1.5 system-ui,"Noto Sans Thai",sans-serif;color:#3a3532';
    var btn = 'font:inherit;font-size:12px;padding:3px 12px;border-radius:14px;border:1px solid #a9afbd;color:#3a3f4a;cursor:pointer;' +
      'background:linear-gradient(180deg,#fff 0%,#eceef3 42%,#cfd3dc 58%,#e6e9ef 100%)';
    d.innerHTML = '<b>Demo mode</b> · ข้อมูลจำลองในเบราว์เซอร์นี้ ไม่มีการต่อฐานข้อมูล<br>' +
      'Customer: <code>demo@example.com</code> / <code>demo1234</code><br>' +
      'Rider: <code>0812345678</code> / <code>demo1234</code><br>' +
      '<span style="color:#8a7f86">หรือสมัครใหม่ได้ (อย่าใช้รหัสผ่านจริง)</span><br>' +
      '<span style="display:flex;gap:6px;margin-top:6px"><button type="button" id="demoReset" style="' + btn + '">Reset data</button>' +
      '<button type="button" id="demoHide" style="' + btn + '">Hide</button></span>';
    document.body.appendChild(d);
    document.getElementById('demoReset').onclick = function () { ls(function () { localStorage.removeItem(KEY); }); mem = null; location.href = location.pathname; };
    document.getElementById('demoHide').onclick = function () { ls(function () { sessionStorage.setItem('demo_bar_hide', '1'); }); d.remove(); };
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', bar); else bar();
})();

// Mock API untuk PREVIEW/UJI UI saja — meniru kontrak jejak-wangi-backend (Laravel) lengkap dengan
// endpoint tambahan di backend-patch/. Data awal diambil dari database SQLite proyek (dev/seed.json).
// Jalankan:  npm run mock   (http://localhost:8000/api)  lalu  npm run dev
// Login: admin@jejakwangi.id / jejakwangi123.   BUKAN pengganti backend asli.
import http from "node:http";
import fs from "node:fs";
import { randomBytes, createHash } from "node:crypto";

const PORT = Number(process.env.PORT || 8000);
const seed = JSON.parse(fs.readFileSync(new URL("./seed.json", import.meta.url)));
const now = () => new Date();
const iso = (d) => d.toISOString();
const ago = (min) => new Date(Date.now() - min * 60000);
const day = (n) => iso(new Date(Date.now() + n * 86400000)).slice(0, 10);
let rnd = 42; const rand = () => ((rnd = (rnd * 16807) % 2147483647) / 2147483647);

// ---- state ----
const tokens = new Map();
let uid = 1;
const users = [{ id: uid++, name: "Ahmad Fauzi", email: "admin@jejakwangi.id", password: "jejakwangi123", is_admin: true, is_active: true, created_at: ago(60 * 24 * 30) }];
["Andi Setiawan", "Siti Aisyah", "Budi Santoso", "Dewi Lestari", "Rizky Pratama", "Putri Ayu", "Hendra Wijaya", "Lina Marlina", "Joko Susilo", "Nur Hidayah", "Agus Salim", "Maya Sari"].forEach((n, i) => {
  users.push({ id: uid++, name: n, email: n.toLowerCase().replace(/ /g, ".") + "@email.com", password: "x", is_admin: false, is_active: i !== 4 && i !== 9, created_at: ago(60 * 24 * (i * 3 + 1)) });
});
const festivals = seed.festival_models.map((f, i) => ({ ...f, event_date: day([5, 14, 22][i]), is_active: !!f.is_active }));
festivals.push({ id: 4, name: "Festival Kuwung (selesai)", location_type: "panggung", location_name: "Taman Blambangan", category: "pariwisata", address: "Taman Blambangan", event_date: day(-12), start_time: "16:00", end_time: "21:00", description: "Pameran kerajinan dan kuliner.", image_url: null, is_active: true });
let fid = 5;
const zones = seed.geofences.map((g) => ({ ...g, is_active: !!g.is_active }));
zones.push({ id: 4, name: "Kawasan Ijen", latitude: -8.0583, longitude: 114.2422, radius_meter: 150, is_active: false, soundscape_id: null, welcome_title: null, welcome_desc: null, koridor_id: null });
let zid = 5;
const notifs = seed.admin_notifications.map((n) => ({ ...n, is_read: !!n.is_read, created_at: n.created_at.replace(" ", "T") + "Z" }));
let nid = 100;
const notify = (category, title, description) => notifs.unshift({ id: nid++, category, title, description, is_read: false, created_at: iso(now()) });
[["lingkungan", "Peringatan Kualitas Udara Koridor 5: Pecinan", "AQI mencapai 112 (Tidak Sehat). Imbau kelompok sensitif membatasi aktivitas."], ["sensor", "Sensor IoT ESP32-KOR4-TEST Offline", "Tidak ada data masuk sejak kemarin 14:15."], ["geofence", "Zona Geofence Baru", "Zona Pantai Boom ditambahkan."]].forEach(([a, b, c], i) => { notify(a, b, c); notifs[0].created_at = iso(ago(20 + i * 90)); });

const sha = (s) => createHash("sha256").update(s).digest("hex");
const keyOf = () => "svg_" + randomBytes(22).toString("hex");
let did = 1;
const devices = [
  { id: did++, device_code: "ESP32-KOR1-DIORAMA", name: "AQI - RTH Sritanjung", koridor_id: "1", is_active: true, is_maintenance: false, last_seen_at: ago(1), key_hash: sha("a") },
  { id: did++, device_code: "ESP32-KOR4-TEST", name: "Suhu - Blambangan", koridor_id: "4", is_active: true, is_maintenance: false, last_seen_at: ago(60 * 20), key_hash: sha("b") },
  { id: did++, device_code: "ESP32-KOR6-BOOM", name: "Kebisingan - Marina Boom", koridor_id: "6", is_active: true, is_maintenance: true, last_seen_at: ago(60 * 5), key_hash: sha("c") },
];

const settingsDefaults = {
  "app.name": "JEJAK WANGI", "app.region_label": "Banyuwangi Mandiri", "app.language": "id", "app.timezone": "Asia/Jakarta",
  "sensor.offline_minutes": 5, "sensor.retention_days": 30, "sensor.auto_refresh_seconds": 30, "geofence.default_radius": 70, "geofence.default_active": true,
  "notify.push": true, "notify.email": true, "notify.sms": false, "notify.cat.lingkungan": true, "notify.cat.sensor": true, "notify.cat.pengguna": false,
  "notify.cat.geofence": true, "notify.cat.festival": true, "notify.cat.mood": true,
  "integration.festival.enabled": true, "integration.sensor.enabled": true, "integration.geofence.enabled": true, "integration.email.enabled": true,
  "integration.email.recipients": "", "integration.email.digest": "harian",
};
const settings = { ...settingsDefaults };
let lastBackup = null;
const backups = new Map();

// mood logs 30 hari
const moods = [];
for (let d = 0; d < 30; d++) for (let i = 0; i < 6 + Math.floor(rand() * 10); i++) {
  const r = rand(); const score = r < 0.28 ? 5 : r < 0.62 ? 4 : r < 0.84 ? 3 : r < 0.95 ? 2 : 1;
  moods.push({ score, space: rand() < 0.65 ? 1 : 2, at: new Date(Date.now() - d * 86400000 - rand() * 40000000), who: rand() < 0.4 ? "u" + (1 + Math.floor(rand() * 8)) : rand() < 0.6 ? "s" + Math.floor(rand() * 40) : null });
}

// ---- helpers ----
const send = (res, code, body, headers = {}) => { res.writeHead(code, { "Content-Type": "application/json", "Access-Control-Allow-Origin": "*", "Access-Control-Allow-Headers": "*", "Access-Control-Allow-Methods": "*", ...headers }); res.end(JSON.stringify(body)); };
const wrap = (data) => ({ data });
const koridorRes = (k) => ({ id: k.id, green_space_id: k.green_space_id, short_name: k.short_name, formal_name: k.formal_name, route_label: k.route_label, desc: k.desc, thumbnail: null, hero_image: null, hijau_level: k.hijau_level, hijau_score: k.hijau_score, umkm_count: k.umkm_count, status: k.status, sort_order: k.sort_order ?? k.id, distance_meter: k.distance_meter, estimate_minutes: k.estimate_minutes, path: k.path, shade_score: k.shade_score, highlights: k.highlights ?? null, condition_title: k.condition_title, condition_desc: k.condition_desc, sensor: k.sensor_snapshot });
const festRes = (f) => ({ id: f.id, bfest_name: f.name, location_type: f.location_type, location_name: f.location_name, category: f.category, address: f.address, date: f.event_date, start_time: f.start_time, end_time: f.end_time, description: f.description, image: f.image_url, is_active: f.is_active });
const zoneRes = (z) => ({ id: z.id, name: z.name, latitude: z.latitude, longitude: z.longitude, radius_meter: z.radius_meter, is_active: z.is_active, soundscape_id: z.soundscape_id, welcome_title: z.welcome_title, welcome_desc: z.welcome_desc, koridor_id: z.koridor_id, background_image: null });
const BULAN = ["Januari", "Februari", "Maret", "April", "Mei", "Juni", "Juli", "Agustus", "September", "Oktober", "November", "Desember"];
const userRes = (u) => ({ id: u.id, nama: u.name, email: u.email, peran: u.is_admin ? "Admin" : "User", is_admin: u.is_admin, is_active: u.is_active, status: u.is_active ? "Aktif" : "NonAktif", bergabung: `${u.created_at.getDate()} ${BULAN[u.created_at.getMonth()]} ${u.created_at.getFullYear()}` });

// sensor live untuk koridor 1 (ESP32 diorama)
const LIVE = { temperature: 29.4, air_quality: 42, pm10: 38, so2: 21, co: 2400, o3: 90, no2: 46 };
const UNIT = { temperature: "°C", air_quality: "AQI", pm10: "µg/m³", so2: "µg/m³", co: "µg/m³", o3: "µg/m³", no2: "µg/m³" };
const liveReadings = (jit = true) => Object.fromEntries(Object.entries(LIVE).map(([t, v]) => [t, { sensor_type: t, value: +(v + (jit ? (rand() - 0.5) * 2 : 0)).toFixed(1), unit: UNIT[t], recorded_at: iso(ago(1)), is_stale: false }]));
const histVal = (type, i, days) => (type === "air_quality" ? 38 + 10 * Math.sin(i / 1.7) + i * 0.4 : 27 + 3 * Math.sin(i / 2));

async function body(req) { const chunks = []; for await (const c of req) chunks.push(c); try { return JSON.parse(Buffer.concat(chunks).toString() || "{}"); } catch { return {}; } }
const err = (res, code, message, errors) => send(res, code, errors ? { message, errors } : { message });
const paginate = (list, page, per) => ({ items: list.slice((page - 1) * per, page * per), page, last: Math.max(1, Math.ceil(list.length / per)), per, total: list.length });

const server = http.createServer(async (req, res) => {
  if (req.method === "OPTIONS") return send(res, 204, {});
  const url = new URL(req.url, "http://x");
  const p = url.pathname.replace(/^\/api/, "");
  const q = Object.fromEntries(url.searchParams);
  const m = req.method;
  const b = ["POST", "PUT"].includes(m) ? await body(req) : {};
  const tok = (req.headers.authorization || "").replace("Bearer ", "");
  const me = tokens.get(tok) ? users.find((u) => u.id === tokens.get(tok)) : null;
  const needAdmin = () => { if (!me) { err(res, 401, "Perlu login untuk mengakses endpoint ini."); return false; } if (!me.is_admin) { err(res, 403, "Endpoint ini khusus admin."); return false; } return true; };
  let mt;

  // --- publik ---
  if (m === "GET" && p === "/koridors") return send(res, 200, wrap([...seed.koridors].sort((a, b) => (a.sort_order ?? a.id) - (b.sort_order ?? b.id) || a.id - b.id).map(koridorRes)));
  if (m === "GET" && p === "/green-spaces") return send(res, 200, wrap(seed.green_spaces.map((g) => ({ id: g.id, name: g.name, location_type: g.location_type, description: g.description, latitude: g.latitude, longitude: g.longitude, address: g.address, shade_score: g.shade_score, is_active: !!g.is_active }))));
  if (m === "GET" && p === "/soundscapes") return send(res, 200, wrap(seed.soundscapes.map((s) => ({ id: s.id, title: s.title, category: s.category, duration: s.duration, audio_url: s.audio_url }))));
  if (m === "GET" && p === "/umkm") return send(res, 200, { data: [], meta: {} });
  if (m === "GET" && p === "/geofences") return send(res, 200, wrap((q.all ? zones : zones.filter((z) => z.is_active)).map(zoneRes)));
  if (m === "GET" && p === "/festivals") {
    let l = q.all ? [...festivals] : festivals.filter((f) => f.is_active);
    if (q.upcoming) l = l.filter((f) => f.event_date >= day(0));
    if (q.category) l = l.filter((f) => f.category === q.category);
    l.sort((a, b2) => a.event_date.localeCompare(b2.event_date));
    const pg = paginate(l, 1, +q.per_page || 10);
    return send(res, 200, { data: pg.items.map(festRes), meta: { current_page: 1, last_page: 1, per_page: pg.per, total: pg.total } });
  }
  if (m === "GET" && p === "/sensors/snapshot") {
    const k = q.koridor_id;
    if (!k || k === "1") return send(res, 200, { koridor_id: k ?? null, updated_at: iso(ago(1)), is_stale: false, readings: liveReadings() });
    return send(res, 200, { koridor_id: k, updated_at: null, is_stale: true, readings: {} });
  }
  if (m === "GET" && p === "/sensors/snapshots") {
    return send(res, 200, { snapshots: { 1: { koridor_id: "1", updated_at: iso(ago(1)), is_stale: false, readings: liveReadings() } } });
  }
  if (m === "GET" && p === "/sensors/active") return send(res, 200, { koridor_id: "1", updated_at: iso(ago(1)), is_stale: false });
  if (m === "GET" && p === "/sensors/history") {
    const days = +q.days || 7; const has = !q.koridor_id || q.koridor_id === "1";
    const daily = Array.from({ length: days }, (_, i) => { const d = new Date(Date.now() - (days - 1 - i) * 86400000); return { date: iso(d).slice(0, 10), average_value: has ? +histVal(q.sensor_type, i, days).toFixed(1) : null, count: has ? 120 : 0 }; });
    return send(res, 200, { sensor_type: q.sensor_type, days, daily });
  }
  if (m === "GET" && p === "/mood-logs/summary") {
    const days = +q.days || 7; const since = new Date(); since.setHours(0, 0, 0, 0); since.setDate(since.getDate() - (days - 1));
    const logs = moods.filter((x) => x.at >= since && (!q.green_space_id || x.space === +q.green_space_id));
    const daily = Array.from({ length: days }, (_, i) => { const d = new Date(since); d.setDate(d.getDate() + i); const k = d.toLocaleDateString("sv"); const dl = logs.filter((x) => x.at.toLocaleDateString("sv") === k); const ps = { 1: 0, 2: 0, 3: 0, 4: 0, 5: 0 }; dl.forEach((x) => ps[x.score]++); return { date: k, average_score: dl.length ? +(dl.reduce((a, x) => a + x.score, 0) / dl.length).toFixed(2) : null, count: dl.length, per_score: ps }; });
    const dist = { 1: 0, 2: 0, 3: 0, 4: 0, 5: 0 }; logs.forEach((x) => dist[x.score]++);
    const ident = logs.map((x) => x.who); const uniq = new Set(ident.filter(Boolean)).size + ident.filter((v) => !v).length;
    return send(res, 200, { green_space_id: q.green_space_id ? +q.green_space_id : null, unique_contributors: uniq, days, total_entries: logs.length, average_score: logs.length ? +(logs.reduce((a, x) => a + x.score, 0) / logs.length).toFixed(2) : null, daily, distribution: dist });
  }

  // --- auth ---
  if (m === "POST" && p === "/auth/login") {
    const u = users.find((x) => x.email === b.email);
    if (!b.email || !b.password) return err(res, 422, "Email atau kata sandi tidak valid", { email: ["wajib"] });
    if (!u || u.password !== b.password) return err(res, 401, "Email atau kata sandi salah.");
    const t = `${u.id}|${randomBytes(16).toString("hex")}`; tokens.set(t, u.id);
    return send(res, 200, { user: { id: u.id, name: u.name, email: u.email, is_admin: u.is_admin }, token: t });
  }
  if (p === "/auth/me" && m === "GET") return me ? send(res, 200, wrap({ id: me.id, name: me.name, email: me.email, is_admin: me.is_admin })) : err(res, 401, "Perlu login untuk mengakses endpoint ini.");
  if (p === "/auth/logout" && m === "POST") { tokens.delete(tok); return send(res, 200, { message: "Berhasil keluar." }); }

  // --- admin ---
  const adminPaths = ["/admin/", "/geofences", "/festivals"];
  if (adminPaths.some((a) => p.startsWith(a)) && m !== "GET" && !needAdmin()) return;
  if (p.startsWith("/admin/") && !needAdmin()) return;

  if (p === "/admin/users" && m === "GET") {
    let l = [...users].sort((a, b2) => b2.created_at - a.created_at);
    if (q.q) l = l.filter((u) => (u.name + u.email).toLowerCase().includes(q.q.toLowerCase()));
    if (q.status === "aktif") l = l.filter((u) => u.is_active); if (q.status === "nonaktif") l = l.filter((u) => !u.is_active);
    const pg = paginate(l, +q.page || 1, Math.min(100, +q.per_page || 5));
    return send(res, 200, { data: pg.items.map(userRes), page: pg.page, last_page: pg.last, per_page: pg.per, filtered_total: pg.total, total: users.length, aktif: users.filter((u) => u.is_active).length, nonaktif: users.filter((u) => !u.is_active).length, baru_7_hari: users.filter((u) => u.created_at > ago(7 * 1440)).length });
  }
  if (p === "/admin/users" && m === "POST") {
    const e = {}; if (!b.name) e.name = ["Nama wajib diisi."]; if (!b.email) e.email = ["Email wajib diisi."]; else if (users.some((u) => u.email === b.email)) e.email = ["Email sudah dipakai."]; if (!b.password || b.password.length < 6) e.password = ["Kata sandi minimal 6 karakter."];
    if (Object.keys(e).length) return err(res, 422, "Data pengguna tidak valid", e);
    const u = { id: uid++, name: b.name, email: b.email, password: b.password, is_admin: !!b.is_admin, is_active: b.is_active !== false, created_at: now() }; users.push(u);
    notify("pengguna", "Pengguna Baru Ditambahkan", `Akun ${u.email} dibuat oleh admin.`);
    return send(res, 201, { message: "Pengguna berhasil dibuat", data: userRes(u) });
  }
  if ((mt = p.match(/^\/admin\/users\/(\d+)$/))) {
    const u = users.find((x) => x.id === +mt[1]); if (!u) return err(res, 404, "Data tidak ditemukan.");
    if (m === "PUT") {
      if (b.email && users.some((x) => x.email === b.email && x.id !== u.id)) return err(res, 422, "Data pengguna tidak valid", { email: ["Email sudah dipakai."] });
      if (u.id === me.id && b.is_admin === false) return err(res, 422, "Tidak bisa mencabut hak admin akun sendiri.");
      if (u.id === me.id && b.is_active === false) return err(res, 422, "Tidak bisa menonaktifkan akun sendiri.");
      for (const k of ["name", "email", "is_admin", "is_active"]) if (k in b) u[k] = b[k]; if (b.password) u.password = b.password;
      return send(res, 200, { message: "Pengguna berhasil diperbarui", data: userRes(u) });
    }
    if (m === "DELETE") { if (u.id === me.id) return err(res, 422, "Tidak bisa menghapus akun sendiri."); users.splice(users.indexOf(u), 1); return send(res, 200, { message: "Pengguna berhasil dihapus" }); }
  }
  if (p === "/admin/activity-monthly") {
    const n = +q.months || 5; const BL = ["Jan", "Feb", "Mar", "Apr", "Mei", "Jun", "Jul", "Agu", "Sep", "Okt", "Nov", "Des"];
    const out = Array.from({ length: n }, (_, i) => { const d = new Date(); d.setMonth(d.getMonth() - (n - 1 - i)); return { bulan: BL[d.getMonth()], aktif: i === n - 1 ? users.filter((u) => u.created_at.getMonth() === d.getMonth()).length : 6 + ((i * 7) % 9), catatan_mood: i === n - 1 ? moods.length : 180 + i * 55 }; });
    return send(res, 200, wrap(out));
  }
  if (p === "/admin/notifications" && m === "GET") {
    const per = Math.min(100, +q.per_page || 20); let l = notifs.filter((n) => !q.category || n.category === q.category).sort((a, b2) => b2.created_at.localeCompare(a.created_at));
    const ICON = { lingkungan: "Activity", sensor: "Cpu", festival: "Calendar", pengguna: "Users", mood: "Smile", geofence: "MapPin" };
    const counts = {}; notifs.forEach((n) => (counts[n.category] = (counts[n.category] || 0) + 1));
    return send(res, 200, { data: l.slice(0, per).map((n) => ({ id: n.id, kategori: n.category, icon: ICON[n.category] || "Bell", tone: "neutral", title: n.title, desc: n.description || "", time: n.created_at, unread: !n.is_read })), total: notifs.length, filtered_total: l.length, counts, belum_dibaca: notifs.filter((n) => !n.is_read).length, hari_ini: notifs.filter((n) => n.created_at.slice(0, 10) === day(0)).length });
  }
  if (p === "/admin/notifications/mark-read" && m === "POST") { notifs.forEach((n) => (n.is_read = true)); return send(res, 200, { message: "Semua notifikasi ditandai terbaca." }); }

  // festivals & geofences (mutasi)
  if (p === "/festivals" && m === "POST") {
    const e = {}; if (!b.name) e.name = ["Nama wajib diisi."]; if (!b.location_name) e.location_name = ["Lokasi wajib diisi."]; if (!b.event_date) e.event_date = ["Tanggal wajib diisi."];
    if (Object.keys(e).length) return err(res, 422, "Data event tidak valid", e);
    const f = { id: fid++, location_type: "panggung", is_active: true, image_url: null, ...b }; festivals.push(f); notify("festival", "Event Festival Baru Ditambahkan", `${f.name} dijadwalkan ${f.event_date} di ${f.location_name}.`);
    return send(res, 201, { message: "Event berhasil dibuat", data: festRes(f) });
  }
  if ((mt = p.match(/^\/festivals\/(\d+)$/)) && (m === "PUT" || m === "DELETE")) {
    const f = festivals.find((x) => x.id === +mt[1]); if (!f) return err(res, 404, "Data tidak ditemukan.");
    if (m === "DELETE") { festivals.splice(festivals.indexOf(f), 1); return send(res, 200, { message: "Event berhasil dihapus" }); }
    Object.assign(f, b); return send(res, 200, { message: "Event berhasil diperbarui", data: festRes(f) });
  }
  if (p === "/geofences" && m === "POST") {
    const e = {}; if (!b.name) e.name = ["Nama wajib diisi."]; if (!(b.radius_meter >= 1)) e.radius_meter = ["Radius minimal 1."]; if (typeof b.latitude !== "number") e.latitude = ["Latitude wajib."];
    if (Object.keys(e).length) return err(res, 422, "Data zona tidak valid", e);
    const z = { id: zid++, is_active: true, ...b }; zones.push(z); notify("geofence", "Zona Geofence Baru", `Zona ${z.name} (radius ${z.radius_meter} m) ditambahkan.`);
    return send(res, 201, { message: "Zona berhasil dibuat", data: zoneRes(z) });
  }
  if ((mt = p.match(/^\/geofences\/(\d+)$/)) && (m === "PUT" || m === "DELETE")) {
    const z = zones.find((x) => x.id === +mt[1]); if (!z) return err(res, 404, "Data tidak ditemukan.");
    if (m === "DELETE") { zones.splice(zones.indexOf(z), 1); return send(res, 200, { message: "Zona berhasil dihapus" }); }
    Object.assign(z, b); return send(res, 200, { message: "Zona berhasil diperbarui", data: zoneRes(z) });
  }

  // sensor devices
  const status = (d) => (!d.is_active ? "nonaktif" : d.is_maintenance ? "perawatan" : !d.last_seen_at || d.last_seen_at < ago(settings["sensor.offline_minutes"]) ? "offline" : "aktif");
  const devRes = (d) => { const live = d.device_code === "ESP32-KOR1-DIORAMA" && status(d) === "aktif"; const r = live ? liveReadings(false) : {}; const kn = seed.koridors.find((k) => String(k.id) === String(d.koridor_id)); return { id: d.id, device_code: d.device_code, name: d.name, koridor_id: d.koridor_id, koridor_name: kn?.formal_name ?? null, is_active: d.is_active, is_maintenance: d.is_maintenance, status: status(d), last_seen_at: d.last_seen_at ? iso(d.last_seen_at) : null, types: Object.keys(r), readings: r }; };
  if (p === "/admin/sensor-devices" && m === "GET") { const rows = devices.map(devRes); const c = (s) => rows.filter((r) => r.status === s).length; return send(res, 200, { data: rows, summary: { total: rows.length, aktif: c("aktif"), offline: c("offline"), perawatan: c("perawatan"), nonaktif: c("nonaktif") } }); }
  if (p === "/admin/sensor-devices" && m === "POST") {
    const e = {}; if (!/^[A-Za-z0-9_.-]{3,100}$/.test(b.device_code || "")) e.device_code = ["Kode perangkat tidak valid."]; else if (devices.some((d) => d.device_code === b.device_code)) e.device_code = ["Kode perangkat sudah terdaftar."];
    if (Object.keys(e).length) return err(res, 422, "Data sensor tidak valid", e);
    const key = keyOf(); const d = { id: did++, device_code: b.device_code, name: b.name ?? null, koridor_id: b.koridor_id != null ? String(b.koridor_id) : null, is_active: b.is_active !== false, is_maintenance: !!b.is_maintenance, last_seen_at: null, key_hash: sha(key) }; devices.push(d);
    notify("sensor", "Sensor IoT Baru Terdaftar", `Perangkat ${d.device_code} ditambahkan dan menunggu data pertama.`);
    return send(res, 201, { message: "Sensor berhasil didaftarkan", data: devRes(d), api_key: key });
  }
  if ((mt = p.match(/^\/admin\/sensor-devices\/(\d+)(\/rotate-key)?$/))) {
    const d = devices.find((x) => x.id === +mt[1]); if (!d) return err(res, 404, "Data tidak ditemukan.");
    if (mt[2] && m === "POST") { const key = keyOf(); d.key_hash = sha(key); return send(res, 200, { message: "Kunci API diputar ulang", data: devRes(d), api_key: key }); }
    if (m === "PUT") { for (const k of ["name", "is_active", "is_maintenance"]) if (k in b) d[k] = b[k]; if ("koridor_id" in b) d.koridor_id = b.koridor_id != null ? String(b.koridor_id) : null; return send(res, 200, { message: "Sensor berhasil diperbarui", data: devRes(d) }); }
    if (m === "DELETE") { devices.splice(devices.indexOf(d), 1); return send(res, 200, { message: "Sensor berhasil dihapus" }); }
  }

  // settings & backup
  if (p === "/admin/settings" && m === "GET") return send(res, 200, { data: settings, system: { version: "v1.0.0", updated_at: iso(ago(60 * 24 * 3)), last_backup_at: lastBackup?.created_at ?? null, last_backup_file: lastBackup?.file ?? null, laravel: "13.0", php: "8.3", database: "sqlite", integrations: { festival: { count: festivals.length, active: festivals.filter((f) => f.is_active).length }, sensor: { count: devices.length, last_seen_at: iso(ago(1)) }, geofence: { count: zones.length, active: zones.filter((z) => z.is_active).length }, email: { mailer: "log" } } } });
  if (p === "/admin/settings" && m === "PUT") {
    const s = b.settings; if (!s || typeof s !== "object") return err(res, 422, "Tidak ada pengaturan yang dikirim.");
    const unknown = Object.keys(s).filter((k) => !(k in settingsDefaults)); if (unknown.length) return err(res, 422, "Pengaturan tidak dikenal.", Object.fromEntries(unknown.map((k) => [k, ["Pengaturan tidak dikenal."]])));
    const e = {}; const rng = (k, lo, hi) => { if (k in s && !(Number.isInteger(s[k]) && s[k] >= lo && s[k] <= hi)) e[k] = [`Harus ${lo}–${hi}.`]; };
    rng("sensor.offline_minutes", 1, 120); rng("sensor.retention_days", 1, 365); rng("sensor.auto_refresh_seconds", 10, 300); rng("geofence.default_radius", 1, 5000);
    if (Object.keys(e).length) return err(res, 422, "Pengaturan tidak valid.", e);
    Object.assign(settings, s); return send(res, 200, { message: "Pengaturan disimpan.", data: settings });
  }
  if (p === "/admin/backup" && m === "POST") {
    const file = `jejak-wangi-backup-${iso(now()).replace(/[-:T]/g, "").slice(0, 8)}-${iso(now()).replace(/[-:T]/g, "").slice(8, 14)}.json`;
    const content = JSON.stringify({ users: users.length, festivals, zones, notifs }); backups.set(file, content); lastBackup = { file, created_at: iso(now()) };
    return send(res, 200, { message: "Backup selesai.", data: { file, size: content.length, created_at: lastBackup.created_at } });
  }
  if (p === "/admin/backup/download" && m === "GET") { const f = backups.get(q.file); if (!f) return err(res, 404, "Berkas backup tidak ditemukan."); res.writeHead(200, { "Content-Type": "application/json", "Access-Control-Allow-Origin": "*" }); return res.end(f); }

  return err(res, 404, "Data tidak ditemukan.");
});
server.listen(PORT, () => console.log(`Mock API JEJAK WANGI → http://localhost:${PORT}/api  (admin@jejakwangi.id / jejakwangi123)`));

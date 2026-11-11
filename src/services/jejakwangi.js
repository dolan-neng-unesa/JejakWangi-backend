// Seluruh pemanggilan endpoint jejak-wangi-backend dikumpulkan di sini supaya halaman
// tidak perlu tahu bentuk URL/response Laravel (Resource membungkus di `data`).
import { api, ApiError, unwrap } from "../lib/api";
import { invalidateStatic } from "../lib/staticCache";

export const auth = {
  login: (email, password) => api.post("/auth/login", { email, password }, { auth: false }), // → { user, token }
  me: async () => unwrap(await api.get("/auth/me")),
  logout: () => api.post("/auth/logout"),
};

export const users = {
  list: (params) => api.get("/admin/users", params), // → { data, page, last_page, per_page, filtered_total, total, aktif, nonaktif, baru_7_hari }
  create: (body) => api.post("/admin/users", body),
  update: (id, body) => api.put(`/admin/users/${id}`, body),
  remove: (id) => api.del(`/admin/users/${id}`),
  activityMonthly: async (months = 5) => unwrap(await api.get("/admin/activity-monthly", { months })),
};

export const notifications = {
  list: (params) => api.get("/admin/notifications", params),
  markAllRead: () => api.post("/admin/notifications/mark-read"),
};

export const festivals = {
  list: (params) => api.get("/festivals", { all: 1, per_page: 100, ...params }), // → { data, meta }
  create: (body) => api.post("/festivals", body),
  update: (id, body) => api.put(`/festivals/${id}`, body),
  remove: (id) => api.del(`/festivals/${id}`),
};

// Zona geofence ikut di cache data statis useEnvironment — buang cache setelah tiap mutasi.
const afterMutation = (res) => { invalidateStatic(); return res; };

export const geofences = {
  list: async () => unwrap(await api.get("/geofences", { all: 1 })),
  create: (body) => api.post("/geofences", body).then(afterMutation),
  update: (id, body) => api.put(`/geofences/${id}`, body).then(afterMutation),
  remove: (id) => api.del(`/geofences/${id}`).then(afterMutation),
};

export const content = {
  soundscapes: async () => unwrap(await api.get("/soundscapes")),
  greenSpaces: async () => unwrap(await api.get("/green-spaces")),
  koridors: async () => unwrap(await api.get("/koridors")),
  umkm: async () => unwrap(await api.get("/umkm", { per_page: 100 })),
};

export const sensors = {
  snapshot: (koridorId) => api.get("/sensors/snapshot", { koridor_id: koridorId }),
  snapshots: () => api.get("/sensors/snapshots"), // → { snapshots: { "<koridor_id>": {...}, ... } } (semua koridor, 1 request)
  active: () => api.get("/sensors/active"),
  history: (params) => api.get("/sensors/history", params), // sensor_type, koridor_id, days
};

// Jejak Wangi: ringkasan agregat program (tanpa identitas pengguna). Lihat AdminJejakWangiController.
export const jejakWangi = {
  summary: async () => unwrap(await api.get("/admin/jejak-wangi/summary")),
};

export const mood = {
  summary: (params) => api.get("/mood-logs/summary", params),
};

// --- Endpoint tambahan khusus admin (lihat folder backend-patch/) ---
export const devices = {
  list: () => api.get("/admin/sensor-devices"), // → { data, summary }
  create: (body) => api.post("/admin/sensor-devices", body), // → { data, api_key }
  update: (id, body) => api.put(`/admin/sensor-devices/${id}`, body),
  rotateKey: (id) => api.post(`/admin/sensor-devices/${id}/rotate-key`), // → { data, api_key }
  remove: (id) => api.del(`/admin/sensor-devices/${id}`),
};

export const settings = {
  get: () => api.get("/admin/settings"), // → { data: {...}, system: {...} }
  save: (values) => api.put("/admin/settings", { settings: values }),
  backup: () => api.post("/admin/backup"),
  downloadBackup: (file) => api.download("/admin/backup/download", { file }, file),
};

const emptySnapshot = (id) => ({ koridor_id: String(id), updated_at: null, is_stale: true, readings: {} });

/**
 * Kondisi lingkungan semua koridor. Memakai endpoint massal /sensors/snapshots (1 request,
 * 1 query di server) alih-alih satu request per koridor di setiap polling. Backend lama yang
 * belum punya endpoint itu (404) otomatis jatuh ke mode per-koridor. Koridor tanpa bacaan
 * mendapat snapshot kosong (is_stale: true) — sama seperti respons per-koridor sebelumnya.
 */
export async function loadKoridorSnapshots(koridors) {
  try {
    const all = (await sensors.snapshots())?.snapshots ?? {};
    return Object.fromEntries(koridors.map((k) => [k.id, all[String(k.id)] ?? emptySnapshot(k.id)]));
  } catch (err) {
    if (!(err instanceof ApiError) || err.status !== 404) {
      return Object.fromEntries(koridors.map((k) => [k.id, null])); // server bermasalah: kondisi baseline
    }
  }
  const results = await Promise.all(
    koridors.map((k) => sensors.snapshot(k.id).then((s) => [k.id, s]).catch(() => [k.id, null]))
  );
  return Object.fromEntries(results);
}

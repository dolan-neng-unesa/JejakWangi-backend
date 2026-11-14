// Klien HTTP untuk jejak-wangi-backend (Laravel + Sanctum token).
// Bentuk response mengikuti controller yang ada di backend — lihat komentar tiap fungsi.

export const API_BASE = (import.meta.env.VITE_API_URL || "http://localhost:8000/api").replace(/\/+$/, "");
const TOKEN_KEY = "jejak-wangi-admin:token";

export class ApiError extends Error {
  constructor(message, status = 0, errors = null, retryAfter = null) {
    super(message);
    this.name = "ApiError";
    this.status = status;
    this.errors = errors; // { field: [pesan, ...] } untuk 422
    this.retryAfter = retryAfter; // detik, dari header Retry-After pada 429
  }
  /** true untuk kegagalan tanpa respons HTTP (server mati / jaringan putus / timeout). */
  get isNetwork() { return this.status === 0; }
  /** Pesan validasi pertama (kalau ada) — dipakai untuk toast ringkas. */
  get firstError() {
    if (!this.errors) return this.message;
    const first = Object.values(this.errors)[0];
    return Array.isArray(first) ? first[0] : this.message;
  }
}

export const getToken = () => {
  try { return localStorage.getItem(TOKEN_KEY); } catch { return null; }
};
export const setToken = (t) => {
  try { t ? localStorage.setItem(TOKEN_KEY, t) : localStorage.removeItem(TOKEN_KEY); } catch { /* storage diblokir */ }
};

let onUnauthorized = null;
export const setUnauthorizedHandler = (fn) => { onUnauthorized = fn; };

function buildUrl(path, query) {
  const url = new URL(API_BASE + path, window.location.origin);
  Object.entries(query || {}).forEach(([k, v]) => {
    if (v !== undefined && v !== null && v !== "") url.searchParams.set(k, v);
  });
  return url.toString();
}

const DEFAULT_TIMEOUT_MS = 15_000;

async function send(method, path, { query, body, auth = true, signal, timeoutMs = DEFAULT_TIMEOUT_MS } = {}) {
  const headers = { Accept: "application/json" };
<<<<<<< HEAD
  const isForm = typeof FormData !== "undefined" && body instanceof FormData;
  if (body !== undefined && !isForm) headers["Content-Type"] = "application/json"; // FormData: browser mengisi boundary sendiri
=======
  if (body !== undefined) headers["Content-Type"] = "application/json";
>>>>>>> 1ccb9e09cce22ea6fabbe45184c619d6f9f4d433
  const token = getToken();
  if (auth && token) headers.Authorization = `Bearer ${token}`;

  // Timeout: tanpa ini server yang menggantung membuat tombol/halaman "memuat" selamanya.
  // Pembatalan dari pemanggil (signal) tetap diteruskan sebagai AbortError biasa.
  const controller = new AbortController();
  let timedOut = false;
  const timer = setTimeout(() => { timedOut = true; controller.abort(); }, timeoutMs);
  const onCallerAbort = () => controller.abort();
  if (signal) {
    if (signal.aborted) controller.abort();
    else signal.addEventListener("abort", onCallerAbort, { once: true });
  }

  let res;
  try {
    res = await fetch(buildUrl(path, query), {
      method,
      headers,
<<<<<<< HEAD
      body: body === undefined ? undefined : isForm ? body : JSON.stringify(body),
=======
      body: body !== undefined ? JSON.stringify(body) : undefined,
>>>>>>> 1ccb9e09cce22ea6fabbe45184c619d6f9f4d433
      signal: controller.signal,
    });
  } catch (err) {
    if (err?.name === "AbortError" && !timedOut) throw err;
    throw new ApiError(
      timedOut
        ? "Server terlalu lama merespons. Coba lagi."
        : `Tidak dapat terhubung ke server (${API_BASE}). Pastikan backend Laravel sedang berjalan.`,
      0
    );
  } finally {
    clearTimeout(timer);
    signal?.removeEventListener("abort", onCallerAbort);
  }
  return res;
}

async function parse(res) {
  const text = await res.text();
  let data = null;
  try { data = text ? JSON.parse(text) : null; } catch { /* bukan JSON */ }
  return data;
}

/** 429 dari rate limiter Laravel: pesan bawaannya berbahasa Inggris & tanpa hitung mundur. */
function throttleMessage(res) {
  const wait = Number(res.headers.get("Retry-After")) || null;
  return [wait ? `Terlalu banyak percobaan. Coba lagi dalam ${wait} detik.` : "Terlalu banyak percobaan. Coba lagi sebentar lagi.", wait];
}

async function request(method, path, opts = {}) {
  const res = await send(method, path, opts);
  const data = await parse(res);
  if (!res.ok) {
    if (res.status === 401 && opts.auth !== false && onUnauthorized) onUnauthorized();
    if (res.status === 429) {
      const [message, wait] = throttleMessage(res);
      throw new ApiError(message, 429, null, wait);
    }
    throw new ApiError(data?.message || `Permintaan gagal (${res.status}).`, res.status, data?.errors ?? null);
  }
  return data;
}

export const api = {
  get: (path, query, opts) => request("GET", path, { ...opts, query }),
  post: (path, body, opts) => request("POST", path, { ...opts, body: body ?? {} }),
  put: (path, body, opts) => request("PUT", path, { ...opts, body: body ?? {} }),
  del: (path, opts) => request("DELETE", path, opts),

  /** Unduh file biner (mis. backup) dengan header Authorization lalu simpan lewat <a download>. */
  async download(path, query, filename = "download") {
    const res = await send("GET", path, { query, timeoutMs: 120_000 }); // backup bisa besar
    if (!res.ok) {
      const data = await parse(res);
      if (res.status === 401 && onUnauthorized) onUnauthorized();
      throw new ApiError(data?.message || `Unduhan gagal (${res.status}).`, res.status);
    }
    const blob = await res.blob();
    const href = URL.createObjectURL(blob);
    const a = document.createElement("a");
    a.href = href;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(href), 1000);
  },
};

/** Resource Laravel membungkus array/objek di `data`; response custom tidak. */
export const unwrap = (r) => (r && typeof r === "object" && "data" in r ? r.data : r);

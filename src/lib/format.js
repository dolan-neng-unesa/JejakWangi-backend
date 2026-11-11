// Format tanggal/angka bahasa Indonesia. Zona waktu mengikuti Pengaturan → Aplikasi.

const BULAN = ["Januari", "Februari", "Maret", "April", "Mei", "Juni", "Juli", "Agustus", "September", "Oktober", "November", "Desember"];
export const BULAN_PENDEK = ["Jan", "Feb", "Mar", "Apr", "Mei", "Jun", "Jul", "Agu", "Sep", "Okt", "Nov", "Des"];
export const HARI = ["Minggu", "Senin", "Selasa", "Rabu", "Kamis", "Jumat", "Sabtu"];

let timeZone = "Asia/Jakarta";
export const setTimeZone = (tz) => { if (tz) timeZone = tz; };
export const getTimeZone = () => timeZone;
export const TZ_LABEL = { "Asia/Jakarta": "WIB (UTC+7)", "Asia/Makassar": "WITA (UTC+8)", "Asia/Jayapura": "WIT (UTC+9)" };
const tzShort = () => (TZ_LABEL[timeZone] || "WIB").split(" ")[0];

/** Pecah tanggal ke komponen pada zona waktu aktif. */
function parts(input) {
  const d = input instanceof Date ? input : new Date(input);
  if (Number.isNaN(d.getTime())) return null;
  const f = new Intl.DateTimeFormat("en-GB", {
    timeZone, year: "numeric", month: "numeric", day: "numeric", hour: "2-digit", minute: "2-digit", hourCycle: "h23", weekday: "short",
  });
  const o = {};
  f.formatToParts(d).forEach((p) => { o[p.type] = p.value; });
  return { y: +o.year, m: +o.month, d: +o.day, hh: o.hour, mm: o.minute };
}

/** "10 Mei 2026" — untuk tanggal murni "YYYY-MM-DD" tidak digeser zona waktu. */
export function formatTanggal(input, { short = false } = {}) {
  if (!input) return "—";
  if (typeof input === "string" && /^\d{4}-\d{2}-\d{2}$/.test(input)) {
    const [y, m, d] = input.split("-").map(Number);
    return `${d} ${(short ? BULAN_PENDEK : BULAN)[m - 1]} ${y}`;
  }
  const p = parts(input);
  if (!p) return "—";
  return `${p.d} ${(short ? BULAN_PENDEK : BULAN)[p.m - 1]} ${p.y}`;
}

export function formatJam(input) {
  const p = parts(input);
  return p ? `${p.hh}:${p.mm}` : "—";
}

export const formatTanggalJam = (input) => (input ? `${formatTanggal(input)}, ${formatJam(input)} ${tzShort()}` : "—");

/** Hari ini sebagai "YYYY-MM-DD" pada zona waktu aktif. */
export function todayISO() {
  const p = parts(new Date());
  return `${p.y}-${String(p.m).padStart(2, "0")}-${String(p.d).padStart(2, "0")}`;
}

/** "10 Menit yang lalu" / "3 Jam yang lalu" / "Kemarin". */
export function relativeTime(input) {
  if (!input) return "—";
  const t = new Date(input).getTime();
  if (Number.isNaN(t)) return "—";
  const s = Math.max(0, Math.round((Date.now() - t) / 1000));
  if (s < 45) return "Baru saja";
  const m = Math.round(s / 60);
  if (m < 60) return `${m} Menit yang lalu`;
  const h = Math.round(m / 60);
  if (h < 24) return `${h} Jam yang lalu`;
  const d = Math.round(h / 24);
  if (d === 1) return "Kemarin";
  if (d < 30) return `${d} Hari yang lalu`;
  return formatTanggal(input);
}

/** Versi ringkas untuk tabel: "5 Menit Lalu". */
export function ago(input) {
  if (!input) return "Belum pernah";
  const t = new Date(input).getTime();
  if (Number.isNaN(t)) return "—";
  const m = Math.max(0, Math.round((Date.now() - t) / 60000));
  if (m < 1) return "Baru saja";
  if (m < 60) return `${m} Menit Lalu`;
  const h = Math.round(m / 60);
  if (h < 24) return `${h} Jam Lalu`;
  const d = Math.round(h / 24);
  return d === 1 ? "Kemarin" : `${d} Hari Lalu`;
}

export const nf = new Intl.NumberFormat("id-ID");
export const fmtNum = (n, digits = 0) =>
  n == null || Number.isNaN(Number(n)) ? "—" : new Intl.NumberFormat("id-ID", { maximumFractionDigits: digits, minimumFractionDigits: digits }).format(Number(n));

export const pct = (part, total) => (total ? Math.round((part / total) * 100) : 0);

export function formatDistance(m) {
  if (m == null) return "—";
  return m < 1000 ? `${Math.round(m / 10) * 10} m` : `${(m / 1000).toFixed(1).replace(".", ",")} km`;
}

/** Hitung label "YYYY-MM" → "Mei 2026". */
export function monthLabel(year, month0) {
  return `${BULAN[month0]} ${year}`;
}

export function bulanNama(i) { return BULAN[i]; }

/** Tanggal "YYYY-MM-DD" dari komponen lokal (tanpa geser UTC). */
export const isoDate = (y, m0, d) => `${y}-${String(m0 + 1).padStart(2, "0")}-${String(d).padStart(2, "0")}`;

/** CSV aman Excel (BOM + escape). */
export function downloadCsv(filename, rows) {
  const esc = (v) => {
    const s = v == null ? "" : String(v);
    return /[",\n;]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s;
  };
  const csv = "\uFEFF" + rows.map((r) => r.map(esc).join(",")).join("\n");
  const blob = new Blob([csv], { type: "text/csv;charset=utf-8" });
  const href = URL.createObjectURL(blob);
  const a = document.createElement("a");
  a.href = href; a.download = filename;
  document.body.appendChild(a); a.click(); a.remove();
  setTimeout(() => URL.revokeObjectURL(href), 1000);
}

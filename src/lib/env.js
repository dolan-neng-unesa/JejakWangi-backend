// Logika kondisi lingkungan: kategori AQI/ISPU, label suhu/kelembapan/UV/kebisingan,
// serta penggabungan data live sensor (Laravel) dengan snapshot baseline koridor.
import { calcIspu, ispuCategory, toIspuIndex } from "./ispu";

/** Warna per "tone" — mengikuti palet mockup admin. */
export const TONE = {
  ok: { fg: "#2e7d32", bg: "#e8f3e8", solid: "#2e7d32" },
  warn: { fg: "#ed6c02", bg: "#fef1e4", solid: "#ed6c02" },
  info: { fg: "#0288d1", bg: "#e6f4fb", solid: "#0288d1" },
  danger: { fg: "#d32f2f", bg: "#fdeeee", solid: "#d32f2f" },
  dark: { fg: "#ffffff", bg: "#2b2f33", solid: "#2b2f33" },
  muted: { fg: "#647664", bg: "#eef2ee", solid: "#8fa38f" },
};

/** AQI/ISPU → { value, label, tone }. Label mengikuti kategori ISPU resmi. */
export function aqiInfo(value) {
  if (value == null || Number.isNaN(Number(value))) return { value: null, label: "Tidak ada data", tone: "muted" };
  const v = Math.round(Number(value));
  const cat = ispuCategory(v);
  const tone = v <= 50 ? "ok" : v <= 100 ? "warn" : v <= 300 ? "danger" : "dark";
  return { value: v, label: cat.label, tone };
}

export function suhuInfo(c) {
  if (c == null) return { label: "—", tone: "muted" };
  if (c <= 24) return { label: "Sejuk", tone: "info" };
  if (c <= 30) return { label: "Nyaman", tone: "info" };
  if (c <= 33) return { label: "Hangat", tone: "warn" };
  return { label: "Panas", tone: "danger" };
}
export function kelembapanInfo(p) {
  if (p == null) return { label: "—", tone: "muted" };
  if (p < 40) return { label: "Kering", tone: "warn" };
  if (p <= 70) return { label: "Nyaman", tone: "info" };
  return { label: "Lembab", tone: "warn" };
}
export function uvInfo(u) {
  if (u == null) return { label: "—", tone: "muted" };
  if (u <= 3) return { label: "Rendah", tone: "ok" };
  if (u <= 5) return { label: "Sedang", tone: "warn" };
  if (u <= 7) return { label: "Tinggi", tone: "danger" };
  return { label: "Sangat Tinggi", tone: "danger" };
}
export function bisingInfo(db) {
  if (db == null) return { label: "—", tone: "muted" };
  if (db <= 55) return { label: "Tenang", tone: "ok" };
  if (db <= 70) return { label: "Sedang", tone: "warn" };
  return { label: "Bising", tone: "danger" };
}

/** Mood 1..5 → label & warna (sama dengan MOODS di MoodTrackerPage jejak-wangi-app). */
export const MOOD_LEVELS = [
  { score: 5, label: "Sangat Baik", color: "#2e7d32" },
  { score: 4, label: "Baik", color: "#3f6e45" },
  { score: 3, label: "Biasa Saja", color: "#0288d1" },
  { score: 2, label: "Buruk", color: "#ed6c02" },
  { score: 1, label: "Sangat Buruk", color: "#d32f2f" },
];
export const moodLabel = (avg) => {
  if (avg == null) return "—";
  if (avg >= 4.5) return "Sangat Baik";
  if (avg >= 3.5) return "Baik";
  if (avg >= 2.5) return "Biasa Saja";
  if (avg >= 1.5) return "Buruk";
  return "Sangat Buruk";
};

/** Pembacaan snapshot → angka (atau null). */
const val = (readings, key) => (readings?.[key]?.value != null ? Number(readings[key].value) : null);

/**
 * Gabungkan snapshot live + baseline koridor menjadi satu objek kondisi.
 * - AQI: sensor `air_quality` → ISPU dari polutan → ISPU dari PM2.5 baseline.
 * - Suhu: sensor `temperature` → baseline.
 * - Kelembapan / UV / Kebisingan: belum ada sensor di sensor_readings, jadi baseline koridor.
 */
export function mergeCondition(koridor, snapshot) {
  const readings = snapshot?.readings || {};
  const base = koridor?.sensor || {};

  const pollutants = {
    pm25: val(readings, "pm25") ?? null,
    pm10: val(readings, "pm10"),
    so2: val(readings, "so2"),
    co: val(readings, "co"),
    o3: val(readings, "o3"),
    no2: val(readings, "no2"),
  };
  const ispu = calcIspu(pollutants);

  let aqi = val(readings, "air_quality");
  let aqiSource = aqi != null ? "sensor" : null;
  if (aqi == null && ispu) { aqi = ispu.value; aqiSource = "ispu"; }
  if (aqi == null && base.pm25 != null) { aqi = toIspuIndex("pm25", base.pm25); aqiSource = "baseline"; }
  if (aqi != null) aqi = Math.round(aqi);

  let suhu = val(readings, "temperature");
  let suhuSource = suhu != null ? "sensor" : null;
  if (suhu == null && base.suhu != null) { suhu = Number(base.suhu); suhuSource = "baseline"; }

  const hasLive = Object.keys(readings).length > 0;
  const live = hasLive && !snapshot?.is_stale;

  return {
    koridor,
    aqi, aqiSource, aqiInfo: aqiInfo(aqi),
    suhu, suhuSource, suhuInfo: suhuInfo(suhu),
    kelembaban: base.kelembaban != null ? Number(base.kelembaban) : null,
    kebisingan: base.kebisingan != null ? Number(base.kebisingan) : null,
    uv: base.uv_index != null ? Number(base.uv_index) : null,
    pollutants, ispu,
    hasLive, live,
    updatedAt: snapshot?.updated_at ?? null,
  };
}

/** Rata-rata aman (abaikan null). */
export function avg(list) {
  const v = list.filter((x) => x != null && !Number.isNaN(x));
  return v.length ? v.reduce((a, b) => a + b, 0) / v.length : null;
}

/** Posisi peta koridor: titik awal jalur → green space → zona geofence terkait. */
export function koridorPosition(k, greenSpaces = [], geofences = []) {
  if (Array.isArray(k.path) && k.path.length) return k.path[0];
  const gs = greenSpaces.find((g) => g.id === k.green_space_id);
  if (gs) return [gs.latitude, gs.longitude];
  const gf = geofences.find((g) => String(g.koridor_id) === String(k.id));
  if (gf) return [gf.latitude, gf.longitude];
  return null;
}

export const SENSOR_LABEL = {
  temperature: "Suhu", air_quality: "Kualitas Udara", pm25: "PM2.5", pm10: "PM10", so2: "SO₂", co: "CO", o3: "O₃", no2: "NO₂", co2: "CO₂", o2: "O₂",
};
export const SENSOR_UNIT = { temperature: "°C", air_quality: "AQI", pm25: "µg/m³", pm10: "µg/m³", so2: "µg/m³", co: "µg/m³", o3: "µg/m³", no2: "µg/m³", co2: "ppm", o2: "%" };

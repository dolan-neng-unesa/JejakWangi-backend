// Indeks Standar Pencemar Udara (ISPU) — mengacu pada Permen LHK
// No. P.14/MENLHK/SETJEN/KUM.1/7/2020. Warna mengikuti papan ISPU resmi:
// hijau (Baik), biru (Sedang), kuning (Tidak Sehat), merah (Sangat Tidak
// Sehat), hitam (Berbahaya).

export const ISPU_CATEGORIES = [
  { key: "baik", label: "Baik", range: "0–50", max: 50, bg: "#1FA463", fg: "#10201A" },
  { key: "sedang", label: "Sedang", range: "51–100", max: 100, bg: "#4DA3E8", fg: "#10201A" },
  { key: "tidak-sehat", label: "Tidak Sehat", range: "101–200", max: 200, bg: "#F6C41C", fg: "#10201A" },
  { key: "sangat-tidak-sehat", label: "Sangat Tidak Sehat", range: "201–300", max: 300, bg: "#D92B22", fg: "#FFFFFF" },
  { key: "berbahaya", label: "Berbahaya", range: "300+", max: Infinity, bg: "#14161A", fg: "#FFFFFF" },
];

// Batas bawah indeks tiap segmen (0, 50, 100, 200, 300, 500).
const INDEX_BOUNDS = [0, 50, 100, 200, 300, 500];

// `bp` = batas atas konsentrasi untuk indeks 50, 100, 200, 300, 500.
// Semua dalam µg/m³ (CO juga µg/m³, bukan ppm — kalau sensor memberi ppm,
// konversi dulu sebelum dikirim ke API).
export const POLLUTANTS = {
  pm25: { sym: "PM", sub: "2.5", name: "Partikulat PM2.5", unit: "µg/m³", bp: [15.5, 55.4, 150.4, 250.4, 500] },
  pm10: { sym: "PM", sub: "10", name: "Partikulat PM10", unit: "µg/m³", bp: [50, 150, 350, 420, 500] },
  so2: { sym: "SO", sub: "2", name: "Sulfur dioksida", unit: "µg/m³", bp: [52, 180, 400, 800, 1200] },
  co: { sym: "CO", sub: "", name: "Karbon monoksida", unit: "µg/m³", bp: [4000, 8000, 15000, 30000, 45000] },
  o3: { sym: "O", sub: "3", name: "Ozon", unit: "µg/m³", bp: [120, 235, 400, 800, 1000] },
  no2: { sym: "NO", sub: "2", name: "Nitrogen dioksida", unit: "µg/m³", bp: [80, 200, 1130, 2260, 3000] },
};

export const POLLUTANT_KEYS = Object.keys(POLLUTANTS);

/** Konsentrasi (µg/m³) -> sub-indeks ISPU 0–500 (interpolasi linear per segmen). */
export function toIspuIndex(pollutantKey, concentration) {
  const p = POLLUTANTS[pollutantKey];
  if (!p || concentration == null || Number.isNaN(Number(concentration))) return null;
  const x = Math.max(0, Number(concentration));
  const uppers = p.bp;
  if (x >= uppers[uppers.length - 1]) return 500;

  let lowerX = 0;
  for (let i = 0; i < uppers.length; i++) {
    if (x <= uppers[i]) {
      const lowerI = INDEX_BOUNDS[i];
      const upperI = INDEX_BOUNDS[i + 1];
      return Math.round(lowerI + ((x - lowerX) / (uppers[i] - lowerX)) * (upperI - lowerI));
    }
    lowerX = uppers[i];
  }
  return 500;
}

/** Sub-indeks ISPU -> kategori (label + warna papan ISPU). */
export function ispuCategory(index) {
  if (index == null) return null;
  return ISPU_CATEGORIES.find((c) => index <= c.max) ?? ISPU_CATEGORIES[ISPU_CATEGORIES.length - 1];
}

/**
 * ISPU gabungan dari bacaan sensor ({ pm25, pm10, so2, co, o3, no2 } dalam µg/m³).
 * Nilai ISPU = sub-indeks tertinggi (parameter dominan). null kalau tak ada polutan.
 */
export function calcIspu(values) {
  const subs = POLLUTANT_KEYS.map((key) => ({ key, sub: toIspuIndex(key, values?.[key]) })).filter((s) => s.sub != null);
  if (!subs.length) return null;
  const dominant = subs.reduce((a, b) => (b.sub > a.sub ? b : a));
  return { value: dominant.sub, dominant: dominant.key, category: ispuCategory(dominant.sub), subs };
}

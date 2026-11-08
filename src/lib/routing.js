// Mesin Smart Green Route untuk admin.
// 1) Jalur dicocokkan ke jalan nyata lewat layanan routing OSM (sama dengan jejak-wangi-app/roadSnap.js).
//    Kalau layanan tak terjangkau → jatuh balik ke garis lurus antar titik (snapped:false).
// 2) Tiap jalur dinilai dari data lingkungan koridor/taman terdekat: AQI, kebisingan, keteduhan.
import { haversineMeters, pathLengthMeters, estimateMinutes } from "./geo";

const BASE = import.meta.env.VITE_ROUTING_URL_BASE || "https://routing.openstreetmap.de";
const PROFILE = { jalan: ["routed-foot", "foot"], sepeda: ["routed-bike", "bike"], bus: ["routed-car", "driving"] };
const cache = new Map();

export async function snap(waypoints, mode) {
  const key = mode + "|" + waypoints.map((p) => p.map((n) => n.toFixed(5)).join(",")).join(";");
  if (cache.has(key)) return cache.get(key);
  const [svc, prof] = PROFILE[mode] || PROFILE.jalan;
  const coords = waypoints.map(([la, ln]) => `${ln},${la}`).join(";");
  let out = { path: waypoints, snapped: false };
  try {
    const ctl = new AbortController();
    const t = setTimeout(() => ctl.abort(), 7000);
    const res = await fetch(`${BASE}/${svc}/route/v1/${prof}/${coords}?overview=full&geometries=geojson`, { signal: ctl.signal });
    clearTimeout(t);
    const j = await res.json();
    const line = j?.routes?.[0]?.geometry?.coordinates;
    if (line?.length > 1) {
      const path = line.map(([ln, la]) => [la, ln]);
      // Tolak hasil yang menyimpang jauh dari garis lurus (salah tempel titik ke jalan lain).
      if (pathLengthMeters(path) <= pathLengthMeters(waypoints) * 2.5) out = { path, snapped: true };
    }
  } catch { /* offline / diblokir → garis lurus */ }
  cache.set(key, out);
  return out;
}

/** Titik sampel tiap ±step meter sepanjang polyline. */
function sample(path, step = 60) {
  const pts = [path[0]];
  let acc = 0;
  for (let i = 0; i < path.length - 1; i++) {
    const seg = haversineMeters(path[i], path[i + 1]);
    let d = step - acc;
    while (d < seg) {
      const t = d / seg;
      pts.push([path[i][0] + (path[i + 1][0] - path[i][0]) * t, path[i][1] + (path[i + 1][1] - path[i][1]) * t]);
      d += step;
    }
    acc = (acc + seg) % step;
  }
  pts.push(path.at(-1));
  return pts;
}

/**
 * nodes: [{ pos:[lat,lng], aqi?, noise?, shade? }]. Tiap titik sampel memakai node terdekat
 * (≤ radius); di luar jangkauan memakai rata-rata kota supaya jalur tidak "tanpa data".
 */
export function scoreRoute(path, nodes, fallback, radius = 700) {
  const acc = { aqi: [], noise: [], shade: [] };
  sample(path).forEach((p) => {
    let best = null, bd = Infinity;
    nodes.forEach((n) => { const d = haversineMeters(p, n.pos); if (d < bd) { bd = d; best = n; } });
    const use = best && bd <= radius ? best : fallback;
    ["aqi", "noise", "shade"].forEach((k) => { const v = use?.[k] ?? fallback?.[k]; if (v != null) acc[k].push(v); });
  });
  const mean = (a) => (a.length ? a.reduce((x, y) => x + y, 0) / a.length : null);
  return { aqi: mean(acc.aqi), noise: mean(acc.noise), shade: mean(acc.shade) };
}

export const shadeLabel = (s) => (s == null ? "—" : s >= 75 ? "Sangat Rindang" : s >= 55 ? "Rindang" : s >= 35 ? "Cukup Teduh" : "Minim Peneduh");

/**
 * Bangun pilihan rute antara start & end. Kandidat: langsung + lewat tiap titik perantara
 * (taman / koridor) yang tidak membuat jalur lebih dari 1,9× lebih panjang dari jalur langsung.
 */
export async function buildRoutes({ start, end, mode, vias, nodes, fallback, priority }) {
  const direct = haversineMeters(start.pos, end.pos);
  const cands = [{ key: "langsung", via: null, wps: [start.pos, end.pos] }];
  vias
    .filter((v) => v.name !== start.name && v.name !== end.name)
    .map((v) => ({ v, extra: haversineMeters(start.pos, v.pos) + haversineMeters(v.pos, end.pos) }))
    .filter((x) => x.extra <= Math.max(direct * 1.9, direct + 250))
    .sort((a, b) => a.extra - b.extra)
    .slice(0, 3)
    .forEach(({ v }) => cands.push({ key: v.name, via: v, wps: [start.pos, v.pos, end.pos] }));

  const routes = await Promise.all(cands.map(async (c) => {
    const { path, snapped } = await snap(c.wps, mode);
    const meters = pathLengthMeters(path);
    return { ...c, path, snapped, meters, minutes: estimateMinutes(meters, mode), ...scoreRoute(path, nodes, fallback) };
  }));

  const min = (k) => Math.min(...routes.map((r) => r[k] ?? Infinity));
  const max = (k) => Math.max(...routes.map((r) => r[k] ?? -Infinity));
  const norm = (v, lo, hi, invert) => (hi === lo ? 0 : invert ? (hi - v) / (hi - lo) : (v - lo) / (hi - lo));
  const scoreOf = (r) => {
    const clean = norm(r.aqi ?? 0, min("aqi"), max("aqi"), true);
    const fast = norm(r.minutes, min("minutes"), max("minutes"), true);
    const shade = norm(r.shade ?? 0, min("shade"), max("shade"), false);
    const quiet = norm(r.noise ?? 0, min("noise"), max("noise"), true);
    return { bersih: clean * 0.7 + quiet * 0.3, tercepat: fast, teduh: shade * 0.8 + quiet * 0.2, seimbang: (clean + fast + shade + quiet) / 4 }[priority];
  };
  const ranked = [...routes].sort((a, b) => scoreOf(b) - scoreOf(a));
  const cleanest = routes.reduce((a, b) => ((b.aqi ?? 1e9) < (a.aqi ?? 1e9) ? b : a));
  const fastest = routes.reduce((a, b) => (b.minutes < a.minutes ? b : a));
  const shadiest = routes.reduce((a, b) => ((b.shade ?? -1) > (a.shade ?? -1) ? b : a));

  return ranked.map((r, i) => ({
    ...r,
    id: r.key,
    recommended: i === 0,
    title: (r === cleanest ? "Rute Terbersih" : r === fastest ? "Rute Tercepat" : r === shadiest ? "Rute Paling Teduh" : r.via ? `Rute Alternatif ${r.via.name}` : "Rute Alternatif") + (i === 0 ? " (Rekomendasi)" : ""),
    kind: r === fastest ? "tercepat" : r === cleanest ? "bersih" : r === shadiest ? "teduh" : "alt",
  }));
}

// Util geometri peta (sama dengan jejak-wangi-app/src/lib/geo.js + kecepatan per moda).
const R = 6371000;

export const SPEED_M_PER_MIN = { jalan: 80, sepeda: 250, bus: 333 }; // 4,8 / 15 / 20 km/jam

export function haversineMeters(a, b) {
  const rad = (d) => (d * Math.PI) / 180;
  const dLat = rad(b[0] - a[0]);
  const dLng = rad(b[1] - a[1]);
  const h = Math.sin(dLat / 2) ** 2 + Math.cos(rad(a[0])) * Math.cos(rad(b[0])) * Math.sin(dLng / 2) ** 2;
  return 2 * R * Math.asin(Math.sqrt(h));
}

export function pathLengthMeters(path) {
  let t = 0;
  for (let i = 0; i < path.length - 1; i++) t += haversineMeters(path[i], path[i + 1]);
  return t;
}

export const estimateMinutes = (meters, mode = "jalan") => Math.max(1, Math.round(meters / (SPEED_M_PER_MIN[mode] || 80)));

/** Luas lingkaran geofence (hektar). */
export const circleAreaHa = (radiusM) => (Math.PI * radiusM * radiusM) / 10000;

export function toLatLng(lat, lng) {
  const a = Number(lat), b = Number(lng);
  return lat == null || lng == null || Number.isNaN(a) || Number.isNaN(b) ? null : [a, b];
}

/** Jarak terdekat titik ke polyline (meter, aproksimasi lewat titik sampel). */
export function distanceToPath(point, path) {
  let best = Infinity;
  for (let i = 0; i < path.length - 1; i++) {
    const [a, b] = [path[i], path[i + 1]];
    const steps = 6;
    for (let s = 0; s <= steps; s++) {
      const t = s / steps;
      best = Math.min(best, haversineMeters(point, [a[0] + (b[0] - a[0]) * t, a[1] + (b[1] - a[1]) * t]));
    }
  }
  return best;
}

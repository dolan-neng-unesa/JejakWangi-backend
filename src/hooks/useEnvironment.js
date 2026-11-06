import { useAsync } from "./useAsync";
import { content, geofences as geofencesApi, loadKoridorSnapshots } from "../services/svarga";
import { avg, mergeCondition } from "../lib/env";
import { cachedStatic } from "../lib/staticCache";

/** Nama pendek koridor untuk label: "Koridor 2: Kalilo" → "Kalilo". */
export const koridorLabel = (k) => (k?.formal_name?.includes(":") ? k.formal_name.split(":").pop().trim() : k?.short_name || `Koridor ${k?.id}`);

/**
 * Muat koridor + green space + zona geofence + snapshot sensor tiap koridor,
 * lalu gabungkan live/baseline (lihat lib/env.js → mergeCondition).
 * Tiga daftar pertama jarang berubah, jadi di-cache 60 dtk (lib/staticCache.js); hanya snapshot
 * sensor yang diunduh ulang di tiap polling.
 */
export function useEnvironment({ interval = 0 } = {}) {
  return useAsync(async () => {
    const [koridors, greenSpaces, zones] = await cachedStatic(() => Promise.all([
      content.koridors(),
      content.greenSpaces().catch(() => []),
      geofencesApi.list().catch(() => []),
    ]));
    const snaps = await loadKoridorSnapshots(koridors);
    const conditions = koridors.map((k) => mergeCondition(k, snaps[k.id]));
    return { koridors, greenSpaces, zones, snaps, conditions };
  }, [], { interval });
}

/** Agregat tingkat kota dari kondisi tiap koridor. */
export function cityAggregate(conditions) {
  const withAqi = conditions.filter((c) => c.aqi != null);
  const best = withAqi.length ? withAqi.reduce((a, b) => (b.aqi < a.aqi ? b : a)) : null;
  const worst = withAqi.length ? withAqi.reduce((a, b) => (b.aqi > a.aqi ? b : a)) : null;
  const suhuList = conditions.map((c) => c.suhu).filter((v) => v != null);
  const bising = conditions.filter((c) => c.kebisingan != null);
  return {
    aqi: avg(conditions.map((c) => c.aqi)),
    suhu: avg(conditions.map((c) => c.suhu)),
    kelembaban: avg(conditions.map((c) => c.kelembaban)),
    kebisingan: avg(conditions.map((c) => c.kebisingan)),
    uv: avg(conditions.map((c) => c.uv)),
    best, worst,
    suhuMin: suhuList.length ? Math.min(...suhuList) : null,
    suhuMax: suhuList.length ? Math.max(...suhuList) : null,
    loudest: bising.length ? bising.reduce((a, b) => (b.kebisingan > a.kebisingan ? b : a)) : null,
  };
}

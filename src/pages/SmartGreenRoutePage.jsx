import { useEffect, useMemo, useState } from "react";
import { Bike, Bus, CheckCircle2, Footprints, Map as MapIcon, Navigation, Clock, Sun, Volume2, Wind, ArrowDownUp } from "lucide-react";
import { Polyline } from "react-leaflet";
import { PageHeader } from "../components/ui/PageHeader";
import { Card, CardTitle } from "../components/ui/Card";
import { Badge } from "../components/ui/Badge";
import { Button } from "../components/ui/Button";
import { Field, Select } from "../components/ui/Field";
import { EmptyState, Skeleton } from "../components/ui/States";
import { FitTo, MapView, PinLabel } from "../components/map/MapView";
import { useEnvironment } from "../hooks/useEnvironment";
import { aqiInfo, bisingInfo, koridorPosition } from "../lib/env";
import { buildRoutes, shadeLabel } from "../lib/routing";
import { formatDistance, fmtNum } from "../lib/format";

const MODES = [{ k: "jalan", icon: Footprints, label: "Jalan kaki" }, { k: "sepeda", icon: Bike, label: "Sepeda" }, { k: "bus", icon: Bus, label: "Bus / kendaraan" }];
const PRIORITIES = [{ v: "bersih", l: "Rute Terbersih" }, { v: "tercepat", l: "Rute Tercepat" }, { v: "teduh", l: "Rute Paling Teduh" }, { v: "seimbang", l: "Seimbang" }];

export default function SmartGreenRoutePage() {
  const env = useEnvironment();
  const d = env.data;

  // Titik yang bisa dipilih: green space + zona geofence (punya koordinat).
  const points = useMemo(() => {
    if (!d) return [];
    const gs = d.greenSpaces.map((g) => ({ id: `gs-${g.id}`, name: g.name, pos: [g.latitude, g.longitude], shade: g.shade_score }));
    const zs = d.zones.map((z) => ({ id: `z-${z.id}`, name: z.name, pos: [z.latitude, z.longitude] }));
    const seen = new Set();
    return [...gs, ...zs].filter((p) => (seen.has(p.name) ? false : seen.add(p.name)));
  }, [d]);

  // Node penilaian lingkungan: tiap koridor (jalur/posisi) + green space (keteduhan).
  const { nodes, fallback } = useMemo(() => {
    if (!d) return { nodes: [], fallback: {} };
    const n = [];
    d.conditions.forEach((c) => {
      const base = { aqi: c.aqi, noise: c.kebisingan, shade: c.koridor.shade_score };
      const pts = Array.isArray(c.koridor.path) && c.koridor.path.length ? c.koridor.path : [koridorPosition(c.koridor, d.greenSpaces, d.zones)].filter(Boolean);
      pts.forEach((pos) => n.push({ pos, ...base }));
    });
    d.greenSpaces.forEach((g) => n.push({ pos: [g.latitude, g.longitude], shade: g.shade_score }));
    const m = (k) => { const v = d.conditions.map((c) => ({ aqi: c.aqi, noise: c.kebisingan, shade: c.koridor.shade_score })[k]).filter((x) => x != null); return v.length ? v.reduce((a, b) => a + b, 0) / v.length : null; };
    return { nodes: n, fallback: { aqi: m("aqi"), noise: m("noise"), shade: m("shade") } };
  }, [d]);

  // Titik perantara tambahan: awal & tengah jalur tiap koridor (jalur hijau resmi).
  const koridorVias = useMemo(() => {
    if (!d) return [];
    return d.koridors.flatMap((k) => {
      const nm = k.formal_name?.includes(":") ? k.formal_name.split(":").pop().trim() : k.short_name;
      const path = Array.isArray(k.path) && k.path.length ? k.path : null;
      const pos = path ? path[Math.floor(path.length / 2)] : koridorPosition(k, d.greenSpaces, d.zones);
      return pos ? [{ id: `k-${k.id}`, name: `Koridor ${nm}`, pos }] : [];
    });
  }, [d]);

  const [startId, setStartId] = useState("");
  const [endId, setEndId] = useState("");
  const [mode, setMode] = useState("jalan");
  const [priority, setPriority] = useState("bersih");
  const [state, setState] = useState({ loading: false, routes: [], error: "", sel: null });

  useEffect(() => {
    if (points.length >= 2 && !startId) { setStartId(points[0].id); setEndId(points[1].id); }
  }, [points, startId]);

  async function search(over = {}) {
    const s = points.find((p) => p.id === (over.startId ?? startId));
    const e = points.find((p) => p.id === (over.endId ?? endId));
    if (!s || !e) return;
    if (s.id === e.id) { setState((x) => ({ ...x, error: "Titik awal dan tujuan tidak boleh sama." })); return; }
    setState((x) => ({ ...x, loading: true, error: "" }));
    try {
      const routes = await buildRoutes({ start: s, end: e, mode: over.mode ?? mode, priority: over.priority ?? priority, vias: [...points, ...koridorVias], nodes, fallback });
      setState({ loading: false, routes, error: "", sel: routes[0]?.id ?? null });
    } catch (err) { setState({ loading: false, routes: [], error: err.message || "Gagal menghitung rute.", sel: null }); }
  }

  // Hitung otomatis begitu data siap, lalu saat mode/prioritas diganti.
  useEffect(() => { if (startId && endId && nodes.length) search(); /* eslint-disable-next-line */ }, [startId && endId && nodes.length > 0, mode, priority]);

  const start = points.find((p) => p.id === startId), end = points.find((p) => p.id === endId);
  const sel = state.routes.find((r) => r.id === state.sel);
  const swap = () => { setStartId(endId); setEndId(startId); setTimeout(() => search({ startId: endId, endId: startId }), 0); };
  const allPts = state.routes.flatMap((r) => r.path);

  return (
    <>
      <PageHeader title="Smart Green Route" chip="Smart Navigation" subtitle="Navigasi ramah lingkungan berdasarkan kualitas udara, tingkat keteduhan, dan tingkat kebisingan kota." showRegion />

      <div className="grid gap-4 grid-cols-1 xl:grid-cols-[320px_1fr_340px]">
        <Card className="p-5">
          <CardTitle>Cari Rute Hijau</CardTitle>
          {env.loading && !d ? <Skeleton className="h-72 mt-4" /> : points.length < 2 ? (
            <EmptyState title="Titik belum cukup" message="Dibutuhkan minimal 2 taman/zona geofence berkoordinat." />
          ) : (
            <div className="space-y-4 mt-4">
              <Field label="Titik Awal" htmlFor="r-s"><Select id="r-s" value={startId} onChange={(e) => setStartId(e.target.value)}>{points.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}</Select></Field>
              <div className="flex justify-center -my-1"><button onClick={swap} aria-label="Tukar titik" className="h-8 w-8 rounded-full bg-brand-100 text-brand-700 hover:bg-brand-200 inline-flex items-center justify-center"><ArrowDownUp size={15} /></button></div>
              <Field label="Titik Tujuan" htmlFor="r-e"><Select id="r-e" value={endId} onChange={(e) => setEndId(e.target.value)}>{points.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}</Select></Field>
              <div>
                <p className="text-[13px] font-semibold text-brand-700 mb-1.5">Mode Transportasi</p>
                <div className="grid grid-cols-3 gap-2">
                  {MODES.map(({ k, icon: Icon, label }) => (
                    <button key={k} onClick={() => setMode(k)} aria-pressed={mode === k} aria-label={label} title={label}
                      className={`h-11 rounded-xl border inline-flex items-center justify-center transition-colors ${mode === k ? "border-brand-700 bg-brand-50 text-brand-700" : "border-brand-200 text-brand-700 hover:bg-canvas"}`}><Icon size={18} /></button>
                  ))}
                </div>
              </div>
              <Field label="Prioritas Rute" htmlFor="r-p"><Select id="r-p" value={priority} onChange={(e) => setPriority(e.target.value)}>{PRIORITIES.map((p) => <option key={p.v} value={p.v}>Prioritas: {p.l}</option>)}</Select></Field>
              {state.error && <p role="alert" className="text-xs text-danger">{state.error}</p>}
              <Button icon={Navigation} loading={state.loading} onClick={() => search()}>Cari Rute</Button>
            </div>
          )}
        </Card>

        <Card className="p-2 min-h-[420px] relative">
          <MapView height="100%" className="h-full min-h-[420px]" zoom={15}>
            {allPts.length > 0 && <FitTo points={allPts} padding={[60, 60]} maxZoom={17} />}
            {state.routes.filter((r) => r.id !== state.sel).map((r) => (
              <Polyline key={r.id} positions={r.path} eventHandlers={{ click: () => setState((x) => ({ ...x, sel: r.id })) }} pathOptions={{ color: r.kind === "tercepat" ? "#ed6c02" : "#8fa38f", weight: 5, opacity: 0.75, dashArray: "6 8" }} />
            ))}
            {sel && <Polyline key={sel.id} positions={sel.path} pathOptions={{ color: "#2e7d32", weight: 7, opacity: 0.95 }} />}
            {sel && start && <PinLabel position={sel.path[0]} text={`Mulai: ${start.name}`} color="#3f6e45" />}
            {sel && end && <PinLabel position={sel.path.at(-1)} text={`Tujuan: ${end.name}`} color="#d32f2f" />}
          </MapView>
          <div className="absolute top-5 right-5 z-[500] bg-white/95 rounded-lg shadow px-3 py-2 text-xs text-brand-700 space-y-1">
            <p className="flex items-center gap-2"><span className="h-1 w-4 rounded bg-[#2e7d32]" />Dipilih</p>
            <p className="flex items-center gap-2"><span className="h-1 w-4 rounded bg-[#ed6c02]" />Tercepat</p>
            <p className="flex items-center gap-2"><span className="h-1 w-4 rounded bg-[#8fa38f]" />Alternatif</p>
          </div>
          {sel && !sel.snapped && <p className="absolute bottom-5 left-5 right-5 z-[500] text-[11px] bg-white/95 rounded-lg shadow px-3 py-1.5 text-muted">Layanan routing jalan tidak terjangkau — jalur ditampilkan sebagai garis lurus antar titik; jarak & waktu berupa estimasi.</p>}
        </Card>

        <Card className="p-5">
          <CardTitle>Pilihan Rute Tersedia</CardTitle>
          <div className="mt-4 space-y-3">
            {state.loading && Array.from({ length: 3 }).map((_, i) => <Skeleton key={i} className="h-20" />)}
            {!state.loading && state.routes.length === 0 && <EmptyState icon={MapIcon} title="Belum ada rute" message="Pilih titik awal & tujuan lalu tekan Cari Rute." />}
            {!state.loading && state.routes.map((r) => {
              const a = aqiInfo(r.aqi !== null ? Math.round(r.aqi) : null);
              const on = r.id === state.sel;
              return (
                <button key={r.id} onClick={() => setState((x) => ({ ...x, sel: r.id }))} aria-pressed={on}
                  className={`w-full text-left rounded-xl border px-4 py-3 transition-colors ${on ? "border-brand-700 bg-brand-50/60" : "border-brand-200 hover:bg-canvas"}`}>
                  <div className="flex items-center justify-between gap-2"><p className="font-semibold text-brand-700 text-sm">{r.title}</p>{on && <CheckCircle2 size={18} className="text-ok shrink-0" />}</div>
                  <div className="flex items-center justify-between gap-2 mt-1.5">
                    <p className="text-xs text-muted">{formatDistance(r.meters)} • Estimasi {r.minutes} mnt</p>
                    <Badge tone={a.tone}>AQI {a.value ?? "—"} ({a.label})</Badge>
                  </div>
                </button>
              );
            })}
          </div>
        </Card>
      </div>

      <Card className="p-5 mt-4">
        <CardTitle>Detail Parameter {sel ? sel.title : "Rute"}</CardTitle>
        {!sel ? <p className="text-sm text-muted mt-3">Pilih salah satu rute untuk melihat parameternya.</p> : (
          <div className="grid gap-3 mt-4 grid-cols-1 sm:grid-cols-2 xl:grid-cols-5">
            {[
              [MapIcon, "#3f6e45", "Jarak Tempuh", formatDistance(sel.meters)],
              [Clock, "#0288d1", "Estimasi Waktu", `${sel.minutes} menit`],
              [Wind, "#2e7d32", "Kualitas Udara", `AQI ${fmtNum(sel.aqi)} (${aqiInfo(sel.aqi).label})`],
              [Volume2, "#ed6c02", "Tingkat Kebisingan", `${fmtNum(sel.noise)} dB (${bisingInfo(sel.noise).label})`],
              [Sun, "#3f6e45", "Tingkat Keteduhan", `${fmtNum(sel.shade)}% (${shadeLabel(sel.shade)})`],
            ].map(([Icon, color, label, value]) => (
              <div key={label} className="rounded-xl bg-canvas px-4 py-3.5 flex items-center gap-3 min-w-0">
                <Icon size={19} style={{ color }} className="shrink-0" />
                <div className="min-w-0"><p className="text-xs text-brand-700">{label}</p><p className="font-bold text-brand-700 text-sm leading-snug">{value}</p></div>
              </div>
            ))}
          </div>
        )}
        <p className="text-[11px] text-muted mt-3">Nilai AQI, kebisingan, dan keteduhan dihitung dari koridor & taman terdekat di sepanjang jalur (sensor live bila ada, selain itu snapshot baseline).</p>
      </Card>
    </>
  );
}

import { Link } from "react-router-dom";
import { Droplet, ImageOff, Sun, Thermometer, Volume2, Wind } from "lucide-react";
import { PageHeader } from "../components/ui/PageHeader";
import { StatCard } from "../components/ui/StatCard";
import { Card, CardTitle } from "../components/ui/Card";
import { Badge } from "../components/ui/Badge";
import { EmptyState, Skeleton } from "../components/ui/States";
import { BarChart, Donut, Legend } from "../components/charts/Charts";
import { FitTo, MapView, PinLabel } from "../components/map/MapView";
import { useSettings } from "../context/SettingsContext";
import { useAsync } from "../hooks/useAsync";
import { cityAggregate, koridorLabel, useEnvironment } from "../hooks/useEnvironment";
import { devices, festivals, mood, users } from "../services/jejakwangi";
import { MOOD_LEVELS, TONE, aqiInfo, bisingInfo, kelembapanInfo, koridorPosition, suhuInfo, uvInfo } from "../lib/env";
import { formatTanggal, fmtNum, pct, todayISO } from "../lib/format";
import { circleAreaHa } from "../lib/geo";

const safe = (p, fallback = null) => p.catch(() => fallback);

function useDashboardData() {
  return useAsync(async () => {
    const [events, moodSummary, activity, dev] = await Promise.all([
      safe(festivals.list({ all: "", upcoming: 1, per_page: 3 })),
      safe(mood.summary({ days: 30 })),
      safe(users.activityMonthly(5), []),
      safe(devices.list()),
    ]);
    return { events: events?.data ?? [], moodSummary, activity, dev };
  }, [], { interval: 60_000 });
}

export default function DashboardPage() {
  const env = useEnvironment({ interval: useSettings().values["sensor.auto_refresh_seconds"] * 1000 });
  const dash = useDashboardData();

  const conditions = env.data?.conditions ?? [];
  const agg = cityAggregate(conditions);
  const loadingEnv = env.loading && !env.data;

  const aqi = aqiInfo(agg.aqi);
  const uv = uvInfo(agg.uv);
  const suhu = suhuInfo(agg.suhu);
  const bising = bisingInfo(agg.kebisingan);
  const lembab = kelembapanInfo(agg.kelembaban);

  const stat = (v, d = 0) => (v == null ? "—" : fmtNum(v, d));

  return (
    <>
      <PageHeader title="Dashboard" chip={formatTanggal(todayISO())} subtitle="Ringkasan kondisi lingkungan, aktivitas pengguna, dan data wellbeing masyarakat." showRegion />

      {env.error && !env.data && (
        <Card className="p-4 mb-5 border-danger/30 bg-danger-bg text-sm text-danger">{env.error.message}</Card>
      )}

      <div className="grid gap-4 grid-cols-1 sm:grid-cols-2 xl:grid-cols-5">
        <StatCard label="Kualitas Udara (AQI)" icon={Wind} iconColor="#2e7d32" loading={loadingEnv} value={stat(agg.aqi)} badge={{ text: aqi.label, tone: aqi.tone }}
          sub={agg.best ? `Terbaik di ${koridorLabel(agg.best.koridor)}` : "Belum ada data sensor"} />
        <StatCard label="Indeks UV" icon={Sun} iconColor="#2e7d32" loading={loadingEnv} value={stat(agg.uv)} badge={{ text: uv.label, tone: uv.tone }}
          sub={uv.tone === "ok" ? "Aman beraktivitas" : "Gunakan pelindung matahari"} />
        <StatCard label="Suhu Rata-rata" icon={Thermometer} iconColor="#277cb1" loading={loadingEnv} value={stat(agg.suhu)} unit="°C" badge={{ text: suhu.label, tone: suhu.tone }}
          sub={agg.suhuMin != null ? `Rentang ${fmtNum(agg.suhuMin)}–${fmtNum(agg.suhuMax)}°C` : "—"} />
        <StatCard label="Kebisingan" icon={Volume2} iconColor="#ed6c02" loading={loadingEnv} value={stat(agg.kebisingan)} unit=" dB" badge={{ text: bising.label, tone: bising.tone }}
          sub={agg.loudest ? `Tertinggi: ${koridorLabel(agg.loudest.koridor)}` : "—"} />
        <StatCard label="Kelembapan" icon={Droplet} iconColor="#277cb1" loading={loadingEnv} value={stat(agg.kelembaban)} unit="%" badge={{ text: lembab.label, tone: lembab.tone }}
          sub={lembab.label === "Nyaman" ? "Sirkulasi udara ideal" : lembab.label === "Lembab" ? "Udara cenderung lembab" : "Udara cenderung kering"} />
      </div>

      <div className="grid gap-4 mt-4 grid-cols-1 lg:grid-cols-3">
        <MapCard env={env} />
        <ActivityCard dash={dash} />
        <MoodCard dash={dash} />
      </div>

      <div className="grid gap-4 mt-4 grid-cols-1 lg:grid-cols-3">
        <EventsCard dash={dash} />
        <GeofenceCard env={env} />
        <SensorCard dash={dash} />
      </div>
    </>
  );
}

function MapCard({ env }) {
  const d = env.data;
  const pins = (d?.conditions ?? [])
    .map((c) => ({ c, pos: koridorPosition(c.koridor, d.greenSpaces, d.zones) }))
    .filter((p) => p.pos && p.c.aqi != null);
  const fit = pins.map((p) => p.pos);
  return (
    <Card className="p-5 flex flex-col">
      <CardTitle right={<Link to="/monitoring" className="text-xs font-semibold text-brand-700 hover:underline">Detail</Link>}>Peta Kondisi Lingkungan Banyuwangi</CardTitle>
      <div className="mt-4 flex-1 min-h-[300px]">
        {env.loading && !d ? <Skeleton className="h-full min-h-[300px] rounded-xl" /> : (
          <MapView height="100%" className="min-h-[300px] h-full">
            {fit.length > 0 && <FitTo points={fit} maxZoom={15} padding={[60, 80]} />}
            {pins.map(({ c, pos }) => (
              <PinLabel key={c.koridor.id} position={pos} color={TONE[c.aqiInfo.tone].solid} text={`${koridorLabel(c.koridor)}: ${c.aqi}`} />
            ))}
          </MapView>
        )}
      </div>
    </Card>
  );
}

function ActivityCard({ dash }) {
  const rows = dash.data?.activity ?? [];
  return (
    <Card className="p-5">
      <CardTitle right={<Legend items={[{ label: "Aktif", color: "#3f6e45" }, { label: "Catatan Mood", color: "#ed6c02" }]} />}>Aktivitas Pengguna</CardTitle>
      <div className="mt-4">
        {dash.loading && !dash.data ? <Skeleton className="h-[300px]" /> : rows.length === 0 ? <EmptyState title="Belum ada aktivitas" /> : (
          <BarChart height={300} colors={["#3f6e45", "#ed6c02"]} names={["Pengguna baru", "Catatan mood"]} data={rows.map((r) => ({ label: r.bulan, values: [r.aktif, r.catatan_mood] }))} />
        )}
      </div>
    </Card>
  );
}

function MoodCard({ dash }) {
  const s = dash.data?.moodSummary;
  const total = s?.total_entries ?? 0;
  const segs = MOOD_LEVELS.map((m) => ({ label: m.label, value: s?.distribution?.[m.score] ?? 0, color: m.color === "#3f6e45" ? "#3f6e45" : m.color }));
  return (
    <Card className="p-5">
      <CardTitle right={<Link to="/mood-wellbeing" className="text-xs font-semibold text-brand-700 hover:underline">Detail</Link>}>Mood Masyarakat</CardTitle>
      {dash.loading && !dash.data ? <Skeleton className="h-[300px] mt-4" /> : (
        <>
          <div className="flex justify-center mt-5 mb-5">
            <Donut size={170} thickness={30} segments={segs} center={{ value: fmtNum(total), label: "Catatan" }} />
          </div>
          <ul className="space-y-2.5 text-[13px]">
            {segs.map((m) => (
              <li key={m.label} className="flex items-center justify-between text-brand-700">
                <span className="inline-flex items-center gap-2"><span className="h-2 w-2 rounded-full" style={{ background: m.color }} />{m.label} ({pct(m.value, total)}%)</span>
                <b style={{ color: m.color }}>{fmtNum(m.value)}</b>
              </li>
            ))}
          </ul>
          <p className="text-[11px] text-muted mt-3">30 hari terakhir, seluruh lokasi.</p>
        </>
      )}
    </Card>
  );
}

function EventsCard({ dash }) {
  const list = dash.data?.events ?? [];
  return (
    <Card className="p-5">
      <CardTitle right={<Link to="/kalender-festival" className="text-xs font-semibold text-brand-700 hover:underline">Kalender</Link>}>Event Festival Mendatang</CardTitle>
      <div className="mt-3">
        {dash.loading && !dash.data ? <Skeleton className="h-40" /> : list.length === 0 ? <EmptyState title="Belum ada event mendatang" message="Tambahkan event di menu Kalender Festival BWI." /> : (
          <ul className="divide-y divide-brand-200">
            {list.map((e) => (
              <li key={e.id} className="flex items-center gap-3 py-3">
                {e.image ? <img src={e.image} alt="" className="h-12 w-12 rounded-lg object-cover shrink-0" /> : (
                  <span className="h-12 w-12 rounded-lg bg-brand-100 text-brand-500 inline-flex items-center justify-center shrink-0"><ImageOff size={18} /></span>
                )}
                <div className="min-w-0">
                  <p className="font-semibold text-sm text-brand-700 truncate">{e.bfest_name}</p>
                  <p className="text-xs text-muted truncate">{formatTanggal(e.date)} • {e.location_name || "—"}</p>
                </div>
              </li>
            ))}
          </ul>
        )}
      </div>
    </Card>
  );
}

function GeofenceCard({ env }) {
  const zones = [...(env.data?.zones ?? [])].sort((a, b) => Number(b.is_active) - Number(a.is_active)).slice(0, 4);
  const koridors = env.data?.koridors ?? [];
  return (
    <Card className="p-5">
      <CardTitle right={<Link to="/geofencing" className="text-xs font-semibold text-brand-700 hover:underline">Kelola</Link>}>Geofencing Aktif</CardTitle>
      <div className="mt-3">
        {env.loading && !env.data ? <Skeleton className="h-40" /> : zones.length === 0 ? <EmptyState title="Belum ada zona" message="Buat zona pertama di menu Geofencing." /> : (
          <ul className="divide-y divide-brand-200">
            {zones.map((z) => {
              const k = koridors.find((x) => String(x.id) === String(z.koridor_id));
              return (
                <li key={z.id} className="flex items-center justify-between gap-3 py-3">
                  <div className="min-w-0">
                    <p className="font-semibold text-sm text-brand-700 truncate">{z.name}</p>
                    <p className="text-xs text-muted truncate">Radius {z.radius_meter} m • {fmtNum(circleAreaHa(z.radius_meter), 1)} ha{k ? ` • ${koridorLabel(k)}` : ""}</p>
                  </div>
                  <Badge tone={z.is_active ? "ok" : "danger"}>{z.is_active ? "Aktif" : "Nonaktif"}</Badge>
                </li>
              );
            })}
          </ul>
        )}
      </div>
    </Card>
  );
}

function SensorCard({ dash }) {
  const s = dash.data?.dev?.summary;
  const aktif = s?.aktif ?? 0, perawatan = s?.perawatan ?? 0, off = (s?.offline ?? 0) + (s?.nonaktif ?? 0);
  return (
    <Card className="p-5">
      <CardTitle right={<Link to="/sensor-iot" className="text-xs font-semibold text-brand-700 hover:underline">Kelola</Link>}>Sensor IoT Aktif</CardTitle>
      {dash.loading && !dash.data ? <Skeleton className="h-48 mt-4" /> : !s ? <EmptyState title="Data sensor tidak tersedia" message="Endpoint /admin/sensor-devices belum aktif di backend." /> : (
        <>
          <div className="flex justify-center my-4">
            <Donut size={150} thickness={22} segments={[{ label: "Aktif", value: aktif, color: "#2e7d32" }, { label: "Maintenance", value: perawatan, color: "#ed6c02" }, { label: "Offline", value: off, color: "#d32f2f" }]} center={{ value: fmtNum(s.total), label: "Sensor" }} />
          </div>
          <ul className="space-y-2 text-[13px]">
            <li className="flex justify-between text-brand-700">Aktif <b className="text-ok">{aktif} Unit</b></li>
            <li className="flex justify-between text-brand-700">Maintenance <b className="text-warn">{perawatan} Unit</b></li>
            <li className="flex justify-between text-brand-700">Offline <b className="text-danger">{off} Unit</b></li>
          </ul>
        </>
      )}
    </Card>
  );
}

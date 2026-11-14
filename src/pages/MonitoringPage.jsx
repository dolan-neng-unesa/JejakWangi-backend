import { useMemo, useState } from "react";
import { Link } from "react-router-dom";
import { Droplet, Info, Sun, Thermometer, Volume2, Wind } from "lucide-react";
import { PageHeader } from "../components/ui/PageHeader";
import { StatCard } from "../components/ui/StatCard";
import { Card, CardTitle } from "../components/ui/Card";
import { Badge } from "../components/ui/Badge";
import { Button } from "../components/ui/Button";
import { Modal } from "../components/ui/Modal";
import { Tabs } from "../components/ui/Tabs";
import { FilterSelect } from "../components/ui/Field";
import { EmptyState, ErrorState, Skeleton } from "../components/ui/States";
import { Donut, Legend, LineChart } from "../components/charts/Charts";
import { useSettings } from "../context/SettingsContext";
import { useAsync } from "../hooks/useAsync";
import { cityAggregate, koridorLabel, useEnvironment } from "../hooks/useEnvironment";
import { sensors } from "../services/jejakwangi";
import { SENSOR_UNIT, TONE, aqiInfo, avg, bisingInfo, kelembapanInfo, suhuInfo, uvInfo } from "../lib/env";
import { POLLUTANTS, toIspuIndex, ispuCategory } from "../lib/ispu";
import { ago, fmtNum, pct } from "../lib/format";

const COLORS = ["#0288d1", "#3f6e45", "#ed6c02", "#8e24aa", "#d32f2f", "#00897b"];
const TABS = [
  { value: "ringkasan", label: "Ringkasan" },
  { value: "aqi", label: "Kualitas Udara (AQI)" },
  { value: "suhu", label: "Suhu" },
  { value: "kelembapan", label: "Kelembapan" },
  { value: "uv", label: "UV Index" },
  { value: "kebisingan", label: "Kebisingan" },
];
const PERIODS = [{ value: 7, label: "7 Hari Terakhir" }, { value: 14, label: "14 Hari Terakhir" }, { value: 30, label: "30 Hari Terakhir" }];

const shortDate = (iso) => { const [, m, d] = iso.split("-").map(Number); return `${d} ${["Jan", "Feb", "Mar", "Apr", "Mei", "Jun", "Jul", "Agu", "Sep", "Okt", "Nov", "Des"][m - 1]}`; };

/** Teks tren dari deret harian: bandingkan dua hari terakhir yang punya data. */
function trendText(dailyLists, { lowerIsBetter = false, goodWord = "Membaik", badWord = "Memburuk", flatWord = "Stabil" } = {}) {
  const perDay = {};
  dailyLists.forEach((list) => list.forEach((d) => { if (d.average_value != null) (perDay[d.date] ||= []).push(d.average_value); }));
  const days = Object.keys(perDay).sort();
  if (days.length < 2) return "Belum ada tren";
  const last = avg(perDay[days.at(-1)]), prev = avg(perDay[days.at(-2)]);
  if (!prev) return "Belum ada tren";
  const delta = ((last - prev) / prev) * 100;
  if (Math.abs(delta) < 1) return `→ ${flatWord}`;
  const up = delta > 0;
  const good = lowerIsBetter ? !up : up;
  return `${up ? "↑" : "↓"} ${Math.abs(Math.round(delta))}% ${good ? goodWord : badWord}`;
}

export default function MonitoringPage() {
  const [tab, setTab] = useState("ringkasan");
  const [days, setDays] = useState(7);
  const [loc, setLoc] = useState("all");
  const [detail, setDetail] = useState(null);

  const env = useEnvironment({ interval: useSettings().values["sensor.auto_refresh_seconds"] * 1000 });
  const all = env.data?.conditions ?? [];
  const conditions = loc === "all" ? all : all.filter((c) => String(c.koridor.id) === String(loc));
  const agg = cityAggregate(conditions);

  const histType = tab === "suhu" ? "temperature" : "air_quality";
  const needsHistory = ["ringkasan", "aqi", "suhu"].includes(tab);
  const koridorIds = conditions.map((c) => c.koridor.id).join(",");
  const hist = useAsync(async () => {
    const res = await Promise.all(conditions.map((c) =>
      sensors.history({ sensor_type: histType, koridor_id: c.koridor.id, days }).then((r) => [c.koridor.id, r.daily]).catch(() => [c.koridor.id, []])
    ));
    return Object.fromEntries(res);
  }, [koridorIds, histType, days], { enabled: needsHistory && conditions.length > 0, interval: 60_000 });

  const aqi = aqiInfo(agg.aqi), suhu = suhuInfo(agg.suhu), uv = uvInfo(agg.uv), bising = bisingInfo(agg.kebisingan), lembab = kelembapanInfo(agg.kelembaban);

  const aqiHist = histType === "air_quality" ? Object.values(hist.data ?? {}) : [];
  const tempHist = histType === "temperature" ? Object.values(hist.data ?? {}) : [];

  const options = [{ value: "all", label: "Semua Lokasi" }, ...all.map((c) => ({ value: c.koridor.id, label: koridorLabel(c.koridor) }))];
  const loading = env.loading && !env.data;

  return (
    <>
      <PageHeader title="Monitoring Lingkungan" chip="Real-time" showRegion>
        <FilterSelect label="Periode" value={days} onChange={(v) => setDays(Number(v))} options={PERIODS} />
        <FilterSelect label="Lokasi" value={loc} onChange={setLoc} options={options} />
      </PageHeader>

      <Tabs tabs={TABS} value={tab} onChange={setTab} className="mb-5" />

      {env.error && !env.data ? <Card><ErrorState error={env.error} onRetry={env.reload} /></Card> : (
        <>
          {(tab === "ringkasan") && (
            <div className="grid gap-4 grid-cols-1 sm:grid-cols-2 xl:grid-cols-5 mb-4">
              <StatCard label="Kualitas Udara (AQI)" icon={Wind} iconColor="#2e7d32" loading={loading} value={agg.aqi == null ? "—" : fmtNum(agg.aqi)} badge={{ text: aqi.label, tone: aqi.tone }} sub={trendText(aqiHist, { lowerIsBetter: true })} subTone={trendText(aqiHist, { lowerIsBetter: true }).includes("Membaik") ? "ok" : undefined} />
              <StatCard label="Indeks UV" icon={Sun} iconColor="#2e7d32" loading={loading} value={agg.uv == null ? "—" : fmtNum(agg.uv)} badge={{ text: uv.label, tone: uv.tone }} sub="Snapshot baseline koridor" />
              <StatCard label="Suhu Rata-rata" icon={Thermometer} iconColor="#277cb1" loading={loading} value={agg.suhu == null ? "—" : fmtNum(agg.suhu)} unit="°C" badge={{ text: suhu.label, tone: suhu.tone }} sub={agg.suhuMin != null ? `Rentang ${fmtNum(agg.suhuMin)}–${fmtNum(agg.suhuMax)}°C` : "—"} />
              <StatCard label="Kebisingan" icon={Volume2} iconColor="#ed6c02" loading={loading} value={agg.kebisingan == null ? "—" : fmtNum(agg.kebisingan)} unit=" dB" badge={{ text: bising.label, tone: bising.tone }} sub="Snapshot baseline koridor" />
              <StatCard label="Kelembapan" icon={Droplet} iconColor="#277cb1" loading={loading} value={agg.kelembaban == null ? "—" : fmtNum(agg.kelembaban)} unit="%" badge={{ text: lembab.label, tone: lembab.tone }} sub="Snapshot baseline koridor" />
            </div>
          )}

          {(tab === "ringkasan" || tab === "aqi") && (
            <>
              <div className="grid gap-4 grid-cols-1 xl:grid-cols-[1fr_320px] mb-4">
                <ChartCard title={`Grafik Kualitas Udara (AQI) - ${days} Hari Terakhir`} conditions={conditions} hist={hist} unit="" />
                <DistributionCard conditions={conditions} loading={loading} />
              </div>
              {tab === "aqi" && <PollutantPanel conditions={conditions} />}
              <LocationTable conditions={conditions} loading={loading} onDetail={setDetail} />
            </>
          )}

          {tab === "suhu" && (
            <>
              <div className="mb-4">
                <ChartCard title={`Grafik Suhu (°C) - ${days} Hari Terakhir`} conditions={conditions} hist={hist} unit="°C" />
              </div>
              <ValueTable title="Suhu per Lokasi" conditions={conditions} loading={loading}
                columns={[{ h: "SUHU", v: (c) => (c.suhu == null ? "—" : `${fmtNum(c.suhu, 1)}°C`) }, { h: "STATUS", v: (c) => <Badge tone={c.suhuInfo.tone}>{c.suhuInfo.label}</Badge> }, { h: "SUMBER", v: (c) => <SourceBadge source={c.suhuSource} c={c} /> }]} onDetail={setDetail} />
            </>
          )}

          {tab === "kelembapan" && <BaselineTab title="Kelembapan per Lokasi" unit="%" pick={(c) => c.kelembaban} info={kelembapanInfo} max={100} conditions={conditions} loading={loading} onDetail={setDetail} />}
          {tab === "uv" && <BaselineTab title="Indeks UV per Lokasi" unit="" pick={(c) => c.uv} info={uvInfo} max={11} conditions={conditions} loading={loading} onDetail={setDetail} digits={1} />}
          {tab === "kebisingan" && <BaselineTab title="Kebisingan per Lokasi" unit=" dB" pick={(c) => c.kebisingan} info={bisingInfo} max={100} conditions={conditions} loading={loading} onDetail={setDetail} />}
        </>
      )}

      <DetailModal c={detail} onClose={() => setDetail(null)} days={days} />
    </>
  );
}

function SourceBadge({ source }) {
  if (source === "sensor" || source === "ispu") return <Badge tone="ok">Sensor live</Badge>;
  if (source === "baseline") return <Badge tone="muted">Baseline</Badge>;
  return <span className="text-muted">—</span>;
}

function ChartCard({ title, conditions, hist, unit }) {
  const data = hist.data ?? {};
  const dates = useMemo(() => {
    const first = Object.values(data)[0] ?? [];
    return first.map((d) => d.date);
  }, [data]);
  const series = conditions
    .map((c, i) => ({ name: koridorLabel(c.koridor), color: COLORS[i % COLORS.length], values: (data[c.koridor.id] ?? []).map((d) => d.average_value) }))
    .filter((s) => s.values.some((v) => v != null));
  return (
    <Card className="p-5">
      <CardTitle right={<Legend items={series.map((s) => ({ label: s.name, color: s.color }))} />}>{title}</CardTitle>
      <div className="mt-4">
        {hist.loading && !hist.data ? <Skeleton className="h-[300px]" /> : hist.error ? <ErrorState error={hist.error} onRetry={hist.reload} compact /> : (
          <LineChart height={300} labels={dates.map(shortDate)} series={series} unit={unit} markers={dates.length <= 10} />
        )}
      </div>
      {!hist.loading && series.length === 0 && !hist.error && (
        <p className="text-xs text-muted mt-2">Belum ada pembacaan sensor live untuk lokasi terpilih pada periode ini. Grafik terisi otomatis begitu ESP32 mengirim data.</p>
      )}
    </Card>
  );
}

function DistributionCard({ conditions, loading }) {
  const withAqi = conditions.filter((c) => c.aqi != null);
  const count = (fn) => withAqi.filter((c) => fn(c.aqi)).length;
  const baik = count((v) => v <= 50), sedang = count((v) => v > 50 && v <= 100), buruk = count((v) => v > 100);
  const total = withAqi.length;
  const rows = [
    { label: "Baik", n: baik, color: "#2e7d32" },
    { label: "Sedang", n: sedang, color: "#ed6c02" },
    { label: "Tidak Sehat", n: buruk, color: "#d32f2f" },
  ];
  return (
    <Card className="p-5">
      <CardTitle>Distribusi Kualitas Udara</CardTitle>
      {loading ? <Skeleton className="h-[250px] mt-4" /> : (
        <>
          <div className="flex justify-center my-5"><Donut size={150} thickness={22} segments={rows.map((r) => ({ label: r.label, value: r.n, color: r.color }))} center={{ value: total, label: "Titik Sensor" }} /></div>
          <ul className="space-y-2.5 text-[13px]">
            {rows.map((r) => (
              <li key={r.label} className="flex items-center justify-between text-brand-700">
                <span>{r.label} ({r.n} Titik)</span><b style={{ color: r.color }}>{pct(r.n, total)}%</b>
              </li>
            ))}
          </ul>
        </>
      )}
    </Card>
  );
}

function PollutantPanel({ conditions }) {
  const sel = conditions.find((c) => c.hasLive && c.ispu) ?? conditions.find((c) => c.hasLive);
  return (
    <Card className="p-5 mb-4">
      <CardTitle right={sel && <Badge tone="brand">{koridorLabel(sel.koridor)}</Badge>}>Parameter Polutan (ISPU)</CardTitle>
      {!sel ? <EmptyState title="Belum ada data polutan" message="Polutan tampil setelah sensor ESP32 mengirim PM10, SO₂, CO, O₃, atau NO₂." /> : (
        <div className="grid gap-3 grid-cols-2 md:grid-cols-3 xl:grid-cols-6 mt-4">
          {Object.keys(POLLUTANTS).map((key) => {
            const p = POLLUTANTS[key];
            const v = sel.pollutants[key];
            const sub = toIspuIndex(key, v);
            const cat = sub != null ? ispuCategory(sub) : null;
            return (
              <div key={key} className="rounded-xl bg-canvas px-4 py-3">
                <p className="text-xs text-muted">{p.name}</p>
                <p className="text-xl font-bold text-brand-700 mt-1">{v == null ? "—" : fmtNum(v, 1)} <span className="text-[11px] font-medium text-muted">{p.unit}</span></p>
                {cat && <span className="inline-block mt-1.5 text-[11px] font-semibold rounded px-1.5 py-0.5" style={{ background: cat.bg, color: cat.fg }}>ISPU {sub} · {cat.label}</span>}
              </div>
            );
          })}
        </div>
      )}
    </Card>
  );
}

const Th = ({ children }) => <th className="text-left text-xs font-bold text-brand-700 uppercase tracking-wide px-4 py-3.5 whitespace-nowrap">{children}</th>;

function LocationTable({ conditions, loading, onDetail }) {
  return (
    <Card className="p-5">
      <CardTitle>Data Kualitas Udara per Lokasi</CardTitle>
      <div className="mt-4 overflow-x-auto">
        <table className="w-full text-sm min-w-[640px]">
          <thead className="bg-canvas"><tr><Th>Lokasi</Th><Th>AQI</Th><Th>Status</Th><Th>Suhu</Th><Th>Update Terakhir</Th><Th>Aksi</Th></tr></thead>
          <tbody>
            {loading && Array.from({ length: 4 }).map((_, i) => <tr key={i} className="border-b border-brand-200/70"><td colSpan={6} className="p-3"><Skeleton className="h-6" /></td></tr>)}
            {!loading && conditions.map((c) => (
              <tr key={c.koridor.id} className="border-b border-brand-200/70 last:border-0">
                <td className="px-4 py-3.5 font-semibold text-brand-700">{koridorLabel(c.koridor)}</td>
                <td className="px-4 py-3.5 font-bold text-brand-700">{c.aqi ?? "—"}</td>
                <td className="px-4 py-3.5"><Badge tone={c.aqiInfo.tone}>{c.aqiInfo.label}</Badge></td>
                <td className="px-4 py-3.5 text-muted-2">{c.suhu == null ? "—" : `${fmtNum(c.suhu)}°C`}</td>
                <td className="px-4 py-3.5 text-muted-2">{c.hasLive ? ago(c.updatedAt) : <Badge tone="muted">Baseline</Badge>}</td>
                <td className="px-4 py-3.5"><button onClick={() => onDetail(c)} className="font-semibold text-brand-700 hover:underline">Detail</button></td>
              </tr>
            ))}
          </tbody>
        </table>
        {!loading && conditions.length === 0 && <EmptyState title="Tidak ada lokasi" />}
      </div>
    </Card>
  );
}

function ValueTable({ title, conditions, columns, loading, onDetail }) {
  return (
    <Card className="p-5">
      <CardTitle>{title}</CardTitle>
      <div className="mt-4 overflow-x-auto">
        <table className="w-full text-sm min-w-[560px]">
          <thead className="bg-canvas"><tr><Th>Lokasi</Th>{columns.map((c) => <Th key={c.h}>{c.h}</Th>)}<Th>Update Terakhir</Th><Th>Aksi</Th></tr></thead>
          <tbody>
            {loading && <tr><td colSpan={columns.length + 3} className="p-3"><Skeleton className="h-6" /></td></tr>}
            {!loading && conditions.map((c) => (
              <tr key={c.koridor.id} className="border-b border-brand-200/70 last:border-0">
                <td className="px-4 py-3.5 font-semibold text-brand-700">{koridorLabel(c.koridor)}</td>
                {columns.map((col) => <td key={col.h} className="px-4 py-3.5 text-brand-700">{col.v(c)}</td>)}
                <td className="px-4 py-3.5 text-muted-2">{c.hasLive ? ago(c.updatedAt) : "—"}</td>
                <td className="px-4 py-3.5"><button onClick={() => onDetail(c)} className="font-semibold text-brand-700 hover:underline">Detail</button></td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </Card>
  );
}

/** Kelembapan/UV/Kebisingan: belum ada sensor di sensor_readings → tampilkan snapshot baseline koridor. */
function BaselineTab({ title, unit, pick, info, max, conditions, loading, onDetail, digits = 0 }) {
  return (
    <>
      <div className="flex gap-3 items-start rounded-xl bg-info-bg text-info px-4 py-3 text-[13px] mb-4">
        <Info size={17} className="mt-0.5 shrink-0" />
        <p>Parameter ini belum punya sensor live di backend (<code>sensor_readings</code> baru mencakup suhu, AQI, dan polutan). Nilai di bawah berasal dari <b>snapshot baseline koridor</b> dan akan otomatis berganti begitu sensornya terpasang.</p>
      </div>
      <Card className="p-5 mb-4">
        <CardTitle>{title}</CardTitle>
        <ul className="mt-4 space-y-3">
          {loading && <Skeleton className="h-24" />}
          {!loading && conditions.map((c) => {
            const v = pick(c); const i = info(v);
            return (
              <li key={c.koridor.id} className="grid grid-cols-[130px_1fr_auto] sm:grid-cols-[180px_1fr_auto] items-center gap-3 text-sm">
                <span className="font-semibold text-brand-700 truncate">{koridorLabel(c.koridor)}</span>
                <div className="h-3 rounded-full bg-brand-100 overflow-hidden"><div className="h-full rounded-full" style={{ width: `${Math.min(100, ((v ?? 0) / max) * 100)}%`, background: TONE[i.tone].solid }} /></div>
                <span className="flex items-center gap-2 justify-end min-w-[130px]"><b className="text-brand-700">{v == null ? "—" : `${fmtNum(v, digits)}${unit}`}</b><Badge tone={i.tone}>{i.label}</Badge></span>
              </li>
            );
          })}
        </ul>
      </Card>
      <ValueTable title="Detail per Lokasi" conditions={conditions} loading={loading} onDetail={onDetail}
        columns={[{ h: "NILAI", v: (c) => { const v = pick(c); return v == null ? "—" : `${fmtNum(v, digits)}${unit}`; } }, { h: "STATUS", v: (c) => { const i = info(pick(c)); return <Badge tone={i.tone}>{i.label}</Badge>; } }, { h: "SUMBER", v: () => <Badge tone="muted">Baseline</Badge> }]} />
    </>
  );
}

function DetailModal({ c, onClose, days }) {
  const k = c?.koridor;
  const hist = useAsync(() => sensors.history({ sensor_type: "air_quality", koridor_id: k.id, days }).then((r) => r.daily), [k?.id, days], { enabled: Boolean(k) });
  if (!c) return null;
  const daily = hist.data ?? [];
  return (
    <Modal open onClose={onClose} size="lg" title={koridorLabel(k)} subtitle={`${k.formal_name} — ${k.route_label || ""}`}
      footer={<><Link to={`/monitoring/koridor/${k.id}`}><Button variant="soft">Buka halaman koridor →</Button></Link><Button variant="secondary" onClick={onClose}>Tutup</Button></>}>
      <div className="grid gap-3 grid-cols-2 md:grid-cols-5 mb-5">
        <Mini label="AQI" value={c.aqi ?? "—"} badge={<Badge tone={c.aqiInfo.tone}>{c.aqiInfo.label}</Badge>} />
        <Mini label="Suhu" value={c.suhu == null ? "—" : `${fmtNum(c.suhu, 1)}°C`} badge={<Badge tone={c.suhuInfo.tone}>{c.suhuInfo.label}</Badge>} />
        <Mini label="Kelembapan" value={c.kelembaban == null ? "—" : `${fmtNum(c.kelembaban)}%`} badge={<Badge tone={kelembapanInfo(c.kelembaban).tone}>{kelembapanInfo(c.kelembaban).label}</Badge>} />
        <Mini label="Indeks UV" value={c.uv ?? "—"} badge={<Badge tone={uvInfo(c.uv).tone}>{uvInfo(c.uv).label}</Badge>} />
        <Mini label="Kebisingan" value={c.kebisingan == null ? "—" : `${fmtNum(c.kebisingan)} dB`} badge={<Badge tone={bisingInfo(c.kebisingan).tone}>{bisingInfo(c.kebisingan).label}</Badge>} />
      </div>
      <p className="text-xs text-muted mb-4">
        {c.hasLive ? `Data live dari sensor — diperbarui ${ago(c.updatedAt)}.` : "Belum ada data live; AQI & suhu memakai snapshot baseline koridor."} Kelembapan, UV, dan kebisingan dari snapshot baseline.
      </p>
      {Object.values(c.pollutants).some((v) => v != null) && (
        <div className="mb-5">
          <p className="text-sm font-semibold text-brand-700 mb-2">Polutan terakhir</p>
          <div className="flex flex-wrap gap-2">
            {Object.keys(POLLUTANTS).filter((key) => c.pollutants[key] != null).map((key) => (
              <span key={key} className="rounded-lg bg-canvas px-3 py-1.5 text-xs text-brand-700"><b>{POLLUTANTS[key].sym}{POLLUTANTS[key].sub}</b>: {fmtNum(c.pollutants[key], 1)} {SENSOR_UNIT[key]}</span>
            ))}
          </div>
        </div>
      )}
      <p className="text-sm font-semibold text-brand-700 mb-2">Tren AQI {days} hari</p>
      {hist.loading ? <Skeleton className="h-[220px]" /> : <LineChart height={220} labels={daily.map((d) => shortDate(d.date))} series={[{ name: koridorLabel(k), color: "#3f6e45", values: daily.map((d) => d.average_value) }]} markers={daily.length <= 10} />}
    </Modal>
  );
}

const Mini = ({ label, value, badge }) => (
  <div className="rounded-xl bg-canvas px-4 py-3"><p className="text-xs text-muted">{label}</p><p className="text-xl font-bold text-brand-700 mt-0.5">{value}</p><div className="mt-1.5">{badge}</div></div>
);

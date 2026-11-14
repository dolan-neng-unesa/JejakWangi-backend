import { useState } from "react";
import { Link, useNavigate, useParams } from "react-router-dom";
import { ArrowLeft } from "lucide-react";
import { PageHeader } from "../components/ui/PageHeader";
import { Card, CardTitle } from "../components/ui/Card";
import { Badge } from "../components/ui/Badge";
import { Button } from "../components/ui/Button";
import { Select } from "../components/ui/Field";
import { Tabs } from "../components/ui/Tabs";
import { ErrorState, Skeleton } from "../components/ui/States";
import { BarChart } from "../components/charts/Charts";
import { useAsync } from "../hooks/useAsync";
import { koridorLabel, useEnvironment } from "../hooks/useEnvironment";
import { reports, sensors } from "../services/jejakwangi";
import { aqiInfo, avg, bisingInfo } from "../lib/env";
import { BULAN_PENDEK, downloadCsv, fmtNum, todayISO } from "../lib/format";

const KELAS = [["mobil", "Mobil"], ["motor", "Motor"], ["truk", "Truk"], ["bus", "Bus"]];
const dayLabel = (iso) => { const d = new Date(`${iso}T00:00:00`); return `${["Min", "Sen", "Sel", "Rab", "Kam", "Jum", "Sab"][d.getDay()]}, ${String(d.getDate()).padStart(2, "0")}`; };
const rangeText = (a, b) => { const x = new Date(`${a}T00:00:00`), y = new Date(`${b}T00:00:00`); return `${x.getDate()}–${y.getDate()} ${BULAN_PENDEK[y.getMonth()]} ${y.getFullYear()}`; };
const mean = (xs) => { const v = xs.filter((n) => n != null); return v.length ? avg(v) : null; };

/** Detail satu koridor: tren AQI, suhu, lalu lintas & inventaris sumber emisi (dari carbon_inputs koridor). */
export default function MonitoringKoridorPage() {
  const { id } = useParams();
  const nav = useNavigate();
  const [days, setDays] = useState(7);
  const env = useEnvironment();
  const list = env.data?.conditions ?? [];
  const c = list.find((x) => String(x.koridor.id) === String(id)) ?? (id ? null : list[0]);
  const kid = c?.koridor.id;

  const aqi = useAsync(() => sensors.history({ sensor_type: "air_quality", koridor_id: kid, days }).then((r) => r.daily), [kid, days], { enabled: Boolean(kid) });
  const temp = useAsync(() => sensors.history({ sensor_type: "temperature", koridor_id: kid, days }).then((r) => r.daily), [kid, days], { enabled: Boolean(kid) });
  const open = useAsync(() => reports.list({ koridor_id: kid, status: "diproses", per_page: 1 }).catch(() => ({ data: [] })), [kid], { enabled: Boolean(kid) });

  if (env.error) return <ErrorState error={env.error} onRetry={env.reload} />;
  if (!env.data) return <Skeleton className="h-96" />;
  if (!c) return <><Link to="/monitoring" className="text-sm font-semibold text-brand-700">← Monitoring</Link><ErrorState error={new Error("Koridor tidak ditemukan.")} /></>;

  const daily = aqi.data ?? [];
  const vals = daily.map((d) => d.average_value);
  const present = vals.filter((v) => v != null);
  const avgAqi = mean(vals), avgTemp = mean((temp.data ?? []).map((d) => d.average_value));
  const hi = present.length ? daily.reduce((a, b) => ((b.average_value ?? -1) > (a.average_value ?? -1) ? b : a)) : null;
  const ci = c.koridor.carbon_inputs ?? {}, kend = ci.kendaraanPerHari ?? {}, toko = ci.toko ?? [];
  const totalKend = KELAS.reduce((a, [k]) => a + (kend[k] || 0), 0);
  const unitAC = toko.reduce((a, t) => a + (t.unitAC || 0), 0);
  const a = avgAqi == null ? null : Math.round(avgAqi), info = aqiInfo(a), bis = bisingInfo(c.kebisingan);
  const laporan = open.data?.data?.[0];
  const period = daily.length ? rangeText(daily[0].date, daily[daily.length - 1].date) : "";

  function exportCsv() {
    downloadCsv(`monitoring-${koridorLabel(c.koridor)}-${todayISO()}.csv`, [["Tanggal", "AQI rata-rata", "Suhu rata-rata (°C)"], ...daily.map((d, i) => [d.date, d.average_value ?? "", temp.data?.[i]?.average_value ?? ""]),
      [], ["Kebisingan (dB, baseline)", c.kebisingan ?? ""], ["Kelembapan (%, baseline)", c.kelembaban ?? ""], ["Toko ber-AC", toko.length], ["Kendaraan/hari (estimasi)", totalKend]]);
  }

  return (
    <>
      <Link to="/" className="inline-flex items-center gap-1.5 text-sm font-semibold text-brand-700 hover:underline mb-4"><ArrowLeft size={15} />Dashboard</Link>
      <PageHeader title="Monitoring Lingkungan per Koridor" subtitle="Tinjau tren lingkungan, lalu lintas, dan sumber emisi dalam satu koridor." />
      <Card className="p-4 mb-3 flex flex-wrap items-end gap-4">
        <div className="min-w-56"><p className="text-[13px] font-semibold text-brand-700 mb-1.5">Pilih koridor</p>
          <Select aria-label="Pilih koridor" value={kid} onChange={(e) => nav(`/monitoring/koridor/${e.target.value}`)}>{list.map((x) => <option key={x.koridor.id} value={x.koridor.id}>{koridorLabel(x.koridor)}</option>)}</Select></div>
        <div><p className="text-[13px] font-semibold text-brand-700 mb-1.5">Periode</p><Tabs value={days} onChange={setDays} tabs={[{ value: 7, label: "Minggu" }, { value: 30, label: "Bulan" }]} /></div>
        <p className="text-sm text-brand-700 pb-3">{period}</p>
        <Button variant="secondary" className="ml-auto" onClick={exportCsv}>Unduh ringkasan</Button>
      </Card>
      <p className="text-xs font-semibold text-warn mb-4">{c.hasLive ? "Data sensor langsung • kelembapan, kebisingan, lalu lintas & inventaris: baseline/ilustrasi" : "Data contoh • Belum terhubung ke sensor/CCTV langsung"}</p>

      <div className="grid gap-4 grid-cols-2 xl:grid-cols-4 mb-6">
        <Card className="p-4"><p className="text-[13px] text-muted">Kualitas udara rata-rata</p><p className="text-3xl font-bold text-brand-700 mt-2">{a ?? "—"} <span className="text-lg">AQI</span></p><p className="text-sm font-semibold mt-1" style={{ color: "var(--color-ok)" }}>{a == null ? "Belum ada data" : info.label}</p></Card>
        <Card className="p-4"><p className="text-[13px] text-muted">Suhu rata-rata</p><p className="text-3xl font-bold text-brand-700 mt-2">{avgTemp == null ? "—" : `${fmtNum(avgTemp)}°C`}</p><p className="text-sm text-muted mt-1">Rata-rata periode</p></Card>
        <Card className="p-4"><p className="text-[13px] text-muted">Kebisingan rata-rata</p><p className="text-3xl font-bold text-brand-700 mt-2">{c.kebisingan == null ? "—" : `${fmtNum(c.kebisingan)} dB`}</p><p className="text-sm text-warn font-semibold mt-1">{bis.label} <span className="text-muted font-normal">• baseline</span></p></Card>
        <Card className="p-4"><p className="text-[13px] text-muted">Kelembapan rata-rata</p><p className="text-3xl font-bold text-brand-700 mt-2">{c.kelembaban == null ? "—" : `${fmtNum(c.kelembaban)}%`}</p><p className="text-sm text-muted mt-1">Baseline koridor</p></Card>
      </div>

      <div className="grid gap-6 xl:grid-cols-[1fr_340px] mb-6 items-start">
        <Card className="p-5">
          <CardTitle>Tren kualitas udara</CardTitle>
          <p className="text-sm text-muted -mt-2 mb-3">AQI harian • {period}</p>
          {aqi.loading && !aqi.data ? <Skeleton className="h-64" /> : present.length === 0 ? <p className="text-sm text-muted py-10 text-center">Belum ada pembacaan sensor pada periode ini. Hari tanpa data tidak dihitung sebagai nol.</p> : <BarChart height={280} colors={["#3f6e45"]} names={["AQI"]} data={daily.map((d) => ({ label: dayLabel(d.date), values: [d.average_value] }))} />}
          <p className="text-xs text-muted mt-2">{a != null && `Rata-rata ${a} AQI • `}Nilai lebih rendah menunjukkan kualitas udara lebih baik.</p>
        </Card>
        <Card className="p-5">
          <CardTitle>Ringkasan koridor</CardTitle>
          {present.length ? <><p className="font-semibold text-brand-700">{Math.max(...present) - Math.min(...present) <= 10 ? "Kualitas udara relatif stabil" : "Kualitas udara berfluktuasi"}</p>
            <p className="text-sm text-muted mt-1">Rentang periode ini {Math.round(Math.min(...present))}–{Math.round(Math.max(...present))} AQI. Nilai tertinggi pada {dayLabel(hi.date)}.</p></> : <p className="text-sm text-muted">Belum cukup data untuk meringkas tren.</p>}
          <p className="font-semibold text-warn mt-5">Perlu tindak lanjut</p>
          {laporan ? <><p className="text-sm text-muted mt-1">{laporan.title} sedang ditangani {laporan.assignee ?? "DLH"}.</p><Link to={`/lapor-koridor/${laporan.id}`}><Button variant="soft" className="w-full mt-3">Lihat laporan {laporan.code} →</Button></Link></> : <p className="text-sm text-muted mt-1">Tidak ada laporan yang sedang diproses di koridor ini.</p>}
        </Card>
      </div>

      <div className="grid gap-6 xl:grid-cols-[1fr_340px] mb-6 items-start">
        <Card className="p-5">
          <CardTitle>Lalu lintas CCTV</CardTitle>
          <p className="text-sm text-muted -mt-2 mb-2">Estimasi kendaraan per hari (data ilustrasi, bukan cuplikan CCTV langsung)</p>
          <p className="text-3xl font-bold text-brand-700">{fmtNum(totalKend)} kendaraan</p>
          <div className="grid grid-cols-2 md:grid-cols-4 gap-3 mt-4">{KELAS.map(([k, l]) => <div key={k} className="rounded-xl bg-canvas px-4 py-3"><p className="text-xs text-muted">{l}</p><p className="text-xl font-bold text-brand-700">{fmtNum(kend[k] || 0)}</p></div>)}</div>
        </Card>
        <Card className="p-5">
          <CardTitle>Inventaris sumber GRK</CardTitle>
          <p className="text-3xl font-bold text-brand-700">{toko.length} toko ber-AC</p>
          <p className="text-sm text-muted mt-2">{unitAC} unit AC • data inventaris, bukan nilai emisi CO₂e.</p>
          <p className="text-sm text-muted mt-2">Perlu kapasitas AC, jam operasi, konsumsi energi, dan faktor emisi.</p>
        </Card>
      </div>

      <Card className="p-5">
        <CardTitle>Sumber, cakupan, dan batasan</CardTitle>
        <p className="text-sm text-muted">Tren: rata-rata harian pada periode terpilih. Sensor tidak aktif dan data yang tidak tersedia tidak dihitung sebagai nol. Emisi CO₂e belum dihitung dari jumlah kendaraan/toko saja.</p>
        <div className="text-right mt-3"><Link to="/cara-menghitung"><Button variant="soft">Cara Kami Menghitung →</Button></Link></div>
      </Card>
    </>
  );
}

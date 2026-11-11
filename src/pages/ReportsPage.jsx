import { useState } from "react";
import { Activity, BarChart3, Download, Printer, Smile, Users, Wind } from "lucide-react";
import { PageHeader } from "../components/ui/PageHeader";
import { StatCard } from "../components/ui/StatCard";
import { Card, CardTitle } from "../components/ui/Card";
import { Badge } from "../components/ui/Badge";
import { Button } from "../components/ui/Button";
import { FilterSelect } from "../components/ui/Field";
import { ErrorState, Skeleton } from "../components/ui/States";
import { BarChart, Legend, LineChart } from "../components/charts/Charts";
import { useAsync } from "../hooks/useAsync";
import { cityAggregate, koridorLabel, useEnvironment } from "../hooks/useEnvironment";
import { devices, mood, sensors, users } from "../services/jejakwangi";
import { aqiInfo, moodLabel } from "../lib/env";
import { downloadCsv, formatTanggal, fmtNum, todayISO } from "../lib/format";

const PERIODS = [{ value: 7, label: "7 Hari" }, { value: 30, label: "30 Hari" }, { value: 90, label: "90 Hari" }];
const MONTHS = [{ value: 5, label: "5 Bulan" }, { value: 6, label: "6 Bulan" }, { value: 12, label: "12 Bulan" }];
const short = (iso) => formatTanggal(iso, { short: true }).replace(/ \d{4}$/, "");
const ok = (p) => p.catch(() => null);

export default function ReportsPage() {
  const [days, setDays] = useState(30);
  const [months, setMonths] = useState(5);
  const env = useEnvironment();
  const agg = cityAggregate(env.data?.conditions ?? []);

  const data = useAsync(async () => {
    const [activity, moodS, aqi, temp, userStat, dev] = await Promise.all([
      ok(users.activityMonthly(months)), ok(mood.summary({ days })),
      ok(sensors.history({ sensor_type: "air_quality", days })), ok(sensors.history({ sensor_type: "temperature", days })),
      ok(users.list({ per_page: 1 })), ok(devices.list()),
    ]);
    return { activity: activity ?? [], moodS, aqi: aqi?.daily ?? [], temp: temp?.daily ?? [], userStat, dev };
  }, [days, months]);
  const d = data.data;

  const csvActivity = () => downloadCsv("aktivitas-bulanan.csv", [["Bulan", "Pengguna Baru", "Catatan Mood"], ...d.activity.map((r) => [r.bulan, r.aktif, r.catatan_mood])]);
  const csvEnv = () => downloadCsv(`lingkungan-${days}hari.csv`, [["Tanggal", "AQI rata-rata", "Suhu rata-rata (°C)", "Jumlah bacaan AQI"], ...d.aqi.map((r, i) => [r.date, r.average_value ?? "", d.temp[i]?.average_value ?? "", r.count])]);
  const csvMood = () => downloadCsv(`mood-${days}hari.csv`, [["Tanggal", "Catatan", "Rata-rata"], ...d.moodS.daily.map((r) => [r.date, r.count, r.average_score ?? ""])]);
  const csvLoc = () => downloadCsv("ringkasan-lokasi.csv", [["Lokasi", "AQI", "Status", "Suhu", "Kelembapan", "Kebisingan (dB)", "UV"], ...(env.data?.conditions ?? []).map((c) => [koridorLabel(c.koridor), c.aqi ?? "", c.aqiInfo.label, c.suhu ?? "", c.kelembaban ?? "", c.kebisingan ?? "", c.uv ?? ""])]);

  return (
    <>
      <PageHeader title="Laporan & Analitik" chip={formatTanggal(todayISO())} subtitle="Ringkasan aktivitas pengguna, mood masyarakat, dan kondisi lingkungan — siap diunduh atau dicetak." showRegion>
        <FilterSelect label="Periode" value={days} onChange={(v) => setDays(Number(v))} options={PERIODS} />
        <Button variant="secondary" icon={Printer} onClick={() => window.print()}>Cetak / PDF</Button>
      </PageHeader>

      {data.error && !d ? <Card><ErrorState error={data.error} onRetry={data.reload} /></Card> : (
        <>
          <div className="grid gap-4 grid-cols-1 sm:grid-cols-2 xl:grid-cols-5 mb-4">
            <StatCard label="Total Pengguna" icon={Users} loading={!d} value={fmtNum(d?.userStat?.total)} sub={`+${fmtNum(d?.userStat?.baru_7_hari)} dalam 7 hari`} />
            <StatCard label="Catatan Mood" icon={BarChart3} loading={!d} value={fmtNum(d?.moodS?.total_entries)} sub={`${days} hari terakhir`} />
            <StatCard label="Rata-rata Mood" icon={Smile} loading={!d} value={d?.moodS?.average_score == null ? "—" : fmtNum(d.moodS.average_score, 1)} unit="/5" badge={{ text: moodLabel(d?.moodS?.average_score), tone: "ok" }} sub="Kondisi psikologis kolektif" />
            <StatCard label="AQI Rata-rata Kota" icon={Wind} iconColor="#2e7d32" loading={env.loading && !env.data} value={agg.aqi == null ? "—" : fmtNum(agg.aqi)} badge={{ text: aqiInfo(agg.aqi).label, tone: aqiInfo(agg.aqi).tone }} sub="Gabungan semua koridor" />
            <StatCard label="Sensor Aktif" icon={Activity} loading={!d} value={d?.dev ? `${d.dev.summary.aktif}/${d.dev.summary.total}` : "—"} sub="Perangkat mengirim data" />
          </div>

          <div className="grid gap-4 grid-cols-1 xl:grid-cols-2 mb-4">
            <Card className="p-5">
              <CardTitle right={<><FilterSelect label="Rentang" value={months} onChange={(v) => setMonths(Number(v))} options={MONTHS} className="!h-9 !text-xs no-print" /><Button variant="soft" size="sm" icon={Download} onClick={csvActivity} disabled={!d} className="no-print">CSV</Button></>}>Aktivitas Pengguna vs Catatan Mood</CardTitle>
              <Legend className="mt-2" items={[{ label: "Pengguna baru", color: "#3f6e45" }, { label: "Catatan mood", color: "#ed6c02" }]} />
              <div className="mt-3">{!d ? <Skeleton className="h-[280px]" /> : <BarChart height={280} colors={["#3f6e45", "#ed6c02"]} names={["Pengguna baru", "Catatan mood"]} data={d.activity.map((r) => ({ label: r.bulan, values: [r.aktif, r.catatan_mood] }))} />}</div>
            </Card>
            <Card className="p-5">
              <CardTitle right={<Button variant="soft" size="sm" icon={Download} onClick={csvMood} disabled={!d?.moodS} className="no-print">CSV</Button>}>Rata-rata Mood Harian</CardTitle>
              <div className="mt-4">{!d ? <Skeleton className="h-[300px]" /> : <LineChart height={300} yMin={1} yMax={5} markers={d.moodS?.daily.length <= 14} labels={(d.moodS?.daily ?? []).map((x) => short(x.date))} series={[{ name: "Rata-rata mood", color: "#3f6e45", values: (d.moodS?.daily ?? []).map((x) => x.average_score) }]} />}</div>
            </Card>
          </div>

          <Card className="p-5 mb-4">
            <CardTitle right={<Button variant="soft" size="sm" icon={Download} onClick={csvEnv} disabled={!d} className="no-print">CSV</Button>}>Tren Lingkungan Kota ({days} hari)</CardTitle>
            <Legend className="mt-2" items={[{ label: "AQI", color: "#2e7d32" }, { label: "Suhu (°C)", color: "#0288d1" }]} />
            <div className="mt-3">{!d ? <Skeleton className="h-[280px]" /> : <LineChart height={280} markers={d.aqi.length <= 14} labels={d.aqi.map((x) => short(x.date))} series={[{ name: "AQI", color: "#2e7d32", values: d.aqi.map((x) => x.average_value) }, { name: "Suhu", color: "#0288d1", values: d.temp.map((x) => x.average_value) }]} />}</div>
          </Card>

          <Card className="p-5">
            <CardTitle right={<Button variant="soft" size="sm" icon={Download} onClick={csvLoc} disabled={!env.data} className="no-print">CSV</Button>}>Ringkasan Kondisi per Lokasi</CardTitle>
            <div className="mt-4 overflow-x-auto">
              <table className="w-full text-sm min-w-[640px]">
                <thead className="bg-canvas"><tr>{["Lokasi", "AQI", "Status", "Suhu", "Kelembapan", "Kebisingan", "UV"].map((h) => <th key={h} className="text-left text-xs font-bold text-brand-700 uppercase px-4 py-3">{h}</th>)}</tr></thead>
                <tbody>
                  {(env.data?.conditions ?? []).map((c) => (
                    <tr key={c.koridor.id} className="border-b border-brand-200/70 last:border-0">
                      <td className="px-4 py-3 font-semibold text-brand-700">{koridorLabel(c.koridor)}</td>
                      <td className="px-4 py-3 font-bold text-brand-700">{c.aqi ?? "—"}</td>
                      <td className="px-4 py-3"><Badge tone={c.aqiInfo.tone}>{c.aqiInfo.label}</Badge></td>
                      <td className="px-4 py-3 text-brand-700">{c.suhu == null ? "—" : `${fmtNum(c.suhu, 1)}°C`}</td>
                      <td className="px-4 py-3 text-brand-700">{c.kelembaban == null ? "—" : `${fmtNum(c.kelembaban)}%`}</td>
                      <td className="px-4 py-3 text-brand-700">{c.kebisingan == null ? "—" : `${fmtNum(c.kebisingan)} dB`}</td>
                      <td className="px-4 py-3 text-brand-700">{c.uv ?? "—"}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </Card>
        </>
      )}
    </>
  );
}

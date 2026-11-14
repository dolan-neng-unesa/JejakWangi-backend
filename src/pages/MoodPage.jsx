import { useState } from "react";
import { BookOpen, Download, Smile, TrendingUp, Activity as Pulse } from "lucide-react";
import { PageHeader } from "../components/ui/PageHeader";
import { StatCard } from "../components/ui/StatCard";
import { Card, CardTitle } from "../components/ui/Card";
import { Badge } from "../components/ui/Badge";
import { Button } from "../components/ui/Button";
import { Modal } from "../components/ui/Modal";
import { FilterSelect } from "../components/ui/Field";
import { ErrorState, Skeleton } from "../components/ui/States";
import { Donut, Legend, LineChart } from "../components/charts/Charts";
import { useAsync } from "../hooks/useAsync";
import { content, mood } from "../services/jejakwangi";
import { MOOD_LEVELS, moodLabel } from "../lib/env";
import { downloadCsv, formatTanggal, fmtNum, pct } from "../lib/format";

const PERIODS = [{ value: 7, label: "7 Hari Terakhir" }, { value: 14, label: "14 Hari Terakhir" }, { value: 30, label: "30 Hari Terakhir" }, { value: 90, label: "90 Hari Terakhir" }];
const short = (iso) => formatTanggal(iso, { short: true }).replace(/ \d{4}$/, "");

export default function MoodPage() {
  const [days, setDays] = useState(7);
  const [space, setSpace] = useState("");
  const [report, setReport] = useState(false);
  const spaces = useAsync(() => content.greenSpaces().catch(() => []), []);
  const sum = useAsync(() => mood.summary({ days, green_space_id: space }), [days, space], { interval: 60_000 });
  const d = sum.data;

  const total = d?.total_entries ?? 0;
  const dist = d?.distribution ?? {};
  const top = MOOD_LEVELS.reduce((a, m) => ((dist[m.score] ?? 0) > (dist[a.score] ?? 0) ? m : a), MOOD_LEVELS[0]);
  const lines = MOOD_LEVELS.filter((m) => m.score >= 2).map((m) => ({ name: m.label, color: m.color, values: (d?.daily ?? []).map((x) => x.per_score?.[m.score] ?? 0) }));
  const loading = sum.loading && !d;

  return (
    <>
      <PageHeader title="Mood & Wellbeing" chip="Real-time Analitik" subtitle="Laporan emosi, indeks kebahagiaan, dan analisis korelasi kesejahteraan warga terhadap iklim lingkungan." showRegion>
        <FilterSelect label="Periode" value={days} onChange={(v) => setDays(Number(v))} options={PERIODS} />
        <FilterSelect label="Lokasi" value={space} onChange={setSpace} options={[{ value: "", label: "Semua Lokasi" }, ...(spaces.data ?? []).map((g) => ({ value: g.id, label: g.name }))]} />
        <Button variant="info" icon={BookOpen} onClick={() => setReport(true)} disabled={!d}>Daftar Laporan</Button>
      </PageHeader>

      {sum.error && !d ? <Card><ErrorState error={sum.error} onRetry={sum.reload} /></Card> : (
        <>
          <div className="grid gap-4 grid-cols-1 xl:grid-cols-[1fr_330px] mb-4">
            <Card className="p-5">
              <CardTitle right={<Legend items={lines.map((l) => ({ label: l.name, color: l.color }))} />}>Tren Kondisi Mood Masyarakat</CardTitle>
              <p className="text-xs text-muted mt-1">Jumlah catatan per tingkat mood setiap hari.</p>
              <div className="mt-3">{loading ? <Skeleton className="h-[320px]" /> : <LineChart height={320} markers labels={(d?.daily ?? []).map((x) => short(x.date))} series={lines} yMin={0} />}</div>
            </Card>
            <Card className="p-5">
              <CardTitle>Distribusi Mood</CardTitle>
              {loading ? <Skeleton className="h-[300px] mt-4" /> : (
                <>
                  <div className="flex justify-center my-5"><Donut size={160} thickness={26} segments={MOOD_LEVELS.map((m) => ({ label: m.label, value: dist[m.score] ?? 0, color: m.color }))} center={{ value: fmtNum(total), label: "Catatan" }} /></div>
                  <ul className="space-y-2.5 text-[13px]">
                    {MOOD_LEVELS.map((m) => (
                      <li key={m.score} className="flex justify-between text-brand-700"><span>{m.label} ({pct(dist[m.score] ?? 0, total)}%)</span><b style={{ color: m.color }}>{fmtNum(dist[m.score] ?? 0)}</b></li>
                    ))}
                  </ul>
                </>
              )}
            </Card>
          </div>

          <div className="grid gap-4 grid-cols-1 sm:grid-cols-2 xl:grid-cols-4">
            <StatCard label="Rata-rata Mood Harian" icon={Smile} loading={loading} value={d?.average_score == null ? "—" : fmtNum(d.average_score, 1)} unit="/5" badge={{ text: moodLabel(d?.average_score), tone: "ok" }} sub="Kondisi psikologis kolektif" />
            <StatCard label="Total Catatan Mood" icon={BookOpen} loading={loading} value={fmtNum(total)} badge={{ text: `${days} hari`, tone: "info" }} sub="Sumbangan data dari masyarakat" />
            <StatCard label="Pengguna Mencatat" icon={Pulse} loading={loading} value={d?.unique_contributors == null ? "—" : fmtNum(d.unique_contributors)} badge={d?.unique_contributors != null && total ? { text: `${fmtNum(total / Math.max(1, d.unique_contributors), 1)}x/orang`, tone: "info" } : undefined} sub="Akun & sesi tamu unik yang mencatat" />
            <StatCard label="Mood Terbanyak" icon={TrendingUp} loading={loading} value={total ? top.label : "—"} badge={total ? { text: `${pct(dist[top.score] ?? 0, total)}%`, tone: "ok" } : undefined} sub="Didominasi emosi paling sering dipilih" />
          </div>
        </>
      )}
      {report && d && <ReportModal d={d} days={days} spaceName={space ? spaces.data?.find((g) => String(g.id) === String(space))?.name : "Semua Lokasi"} onClose={() => setReport(false)} />}
    </>
  );
}

function ReportModal({ d, days, spaceName, onClose }) {
  const rows = [...d.daily].reverse();
  const csv = () => downloadCsv(`laporan-mood-${days}hari.csv`, [
    ["Tanggal", "Jumlah Catatan", "Rata-rata", ...MOOD_LEVELS.map((m) => m.label)],
    ...d.daily.map((x) => [x.date, x.count, x.average_score ?? "", ...MOOD_LEVELS.map((m) => x.per_score?.[m.score] ?? 0)]),
  ]);
  return (
    <Modal open onClose={onClose} size="xl" title="Daftar Laporan Mood" subtitle={`${spaceName} · ${days} hari terakhir · ${fmtNum(d.total_entries)} catatan (agregat anonim)`}
      footer={<><Button variant="secondary" onClick={onClose}>Tutup</Button><Button icon={Download} onClick={csv}>Unduh CSV</Button></>}>
      <div className="overflow-x-auto">
        <table className="w-full text-sm min-w-[640px]">
          <thead className="bg-canvas"><tr>{["Tanggal", "Catatan", "Rata-rata", ...MOOD_LEVELS.map((m) => m.label)].map((h) => <th key={h} className="text-left text-xs font-bold text-brand-700 uppercase px-3 py-3 whitespace-nowrap">{h}</th>)}</tr></thead>
          <tbody>
            {rows.map((x) => (
              <tr key={x.date} className="border-b border-brand-200/70">
                <td className="px-3 py-2.5 font-semibold text-brand-700 whitespace-nowrap">{formatTanggal(x.date)}</td>
                <td className="px-3 py-2.5">{x.count}</td>
                <td className="px-3 py-2.5">{x.average_score == null ? <span className="text-muted">—</span> : <Badge tone={x.average_score >= 3.5 ? "ok" : x.average_score >= 2.5 ? "info" : "warn"}>{fmtNum(x.average_score, 1)}</Badge>}</td>
                {MOOD_LEVELS.map((m) => <td key={m.score} className="px-3 py-2.5 text-brand-700">{x.per_score?.[m.score] ?? 0}</td>)}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <p className="text-xs text-muted mt-3">Catatan individual tidak ditampilkan — sesuai batasan privasi proposal, hanya agregat harian.</p>
    </Modal>
  );
}

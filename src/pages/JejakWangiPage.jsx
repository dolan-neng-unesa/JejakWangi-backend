import { Footprints, Leaf, Recycle, Ticket, Users, Wallet } from "lucide-react";
import { PageHeader } from "../components/ui/PageHeader";
import { StatCard } from "../components/ui/StatCard";
import { Card, CardTitle } from "../components/ui/Card";
import { ErrorState, Skeleton } from "../components/ui/States";
import { BarChart, Donut } from "../components/charts/Charts";
import { useAsync } from "../hooks/useAsync";
import { jejakWangi } from "../services/svarga";
import { fmtNum } from "../lib/format";

const MODA = [
  { key: "motor", label: "Motor", color: "#3f6e45" },
  { key: "mobil", label: "Mobil", color: "#d4a039" },
  { key: "jalan", label: "Jalan kaki", color: "#5f8f64" },
  { key: "sepeda", label: "Sepeda pancal", color: "#3e6e8e" },
];

export default function JejakWangiPage() {
  const res = useAsync(() => jejakWangi.summary(), [], { interval: 60_000 });
  const d = res.data;
  const loading = res.loading && !d;
  const moda = d?.moda_tiba ?? {};
  const modaTotal = MODA.reduce((n, m) => n + (moda[m.key] ?? 0), 0);
  const kupon = d?.kupon ?? { tersedia: 0, digunakan: 0, kedaluwarsa: 0 };
  const kuponTotal = kupon.tersedia + kupon.digunakan + kupon.kedaluwarsa;
  const perTingkat = d?.kupon_per_tingkat ?? {};

  return (
    <>
      <PageHeader title="Jejak Wangi" chip="Data agregat" subtitle="Ringkasan aktivitas jalan kaki warga, emisi yang terhindar, dan penukaran kupon UMKM. Tanpa identitas pengguna.">
      </PageHeader>

      {res.error && !d ? <Card><ErrorState error={res.error} onRetry={res.reload} /></Card> : (
        <>
          <div className="grid gap-4 grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 mb-4">
            <StatCard label="Aktivitas Tercatat" icon={Footprints} loading={loading} value={fmtNum(d?.total_aktivitas ?? 0)} sub={`${fmtNum(d?.total_km ?? 0, 1)} km total`} />
            <StatCard label="Pengguna Aktif" icon={Users} loading={loading} value={fmtNum(d?.pengguna_aktif ?? 0)} sub="Akun dengan aktivitas nyata" />
            <StatCard label="Emisi Terhindar" icon={Leaf} loading={loading} value={fmtNum(d?.total_co2_kg ?? 0, 1)} unit=" kg CO₂" sub="Estimasi vs kendaraan pembanding" />
            <StatCard label="Hemat BBM" icon={Wallet} loading={loading} value={`Rp${fmtNum(d?.total_rupiah ?? 0)}`} sub="Estimasi, bukan transaksi" />
          </div>
          <div className="grid gap-4 grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 mb-4">
            <StatCard label="Bawa Tempat Sendiri" icon={Recycle} loading={loading} value={fmtNum(d?.byo_terkonfirmasi ?? 0)} sub="Transaksi terkonfirmasi lewat QR kasir" />
          </div>

          <div className="grid gap-4 grid-cols-1 xl:grid-cols-2">
            <Card className="p-5">
              <CardTitle>Moda Saat Tiba di Koridor</CardTitle>
              <p className="text-xs text-muted mt-1">Jawaban pengguna setelah scan QR titik awal.</p>
              {loading ? <Skeleton className="h-[200px] mt-4" /> : modaTotal === 0 ? <p className="text-sm text-muted mt-6">Belum ada data.</p> : (
                <div className="flex items-center gap-6 mt-4 flex-wrap">
                  <Donut size={150} thickness={24} segments={MODA.map((m) => ({ label: m.label, value: moda[m.key] ?? 0, color: m.color }))} center={{ value: fmtNum(modaTotal), label: "Jawaban" }} />
                  <ul className="space-y-2 text-[13px] text-brand-700">
                    {MODA.map((m) => <li key={m.key} className="flex items-center gap-2"><span className="h-2.5 w-2.5 rounded-full" style={{ background: m.color }} />{m.label}: <b>{fmtNum(moda[m.key] ?? 0)}</b></li>)}
                  </ul>
                </div>
              )}
            </Card>

            <Card className="p-5">
              <CardTitle right={<Ticket size={18} className="text-brand-700" />}>Kupon UMKM</CardTitle>
              <p className="text-xs text-muted mt-1">Dari misi Jejak Wangi Challenge.</p>
              {loading ? <Skeleton className="h-[200px] mt-4" /> : (
                <BarChart height={200} colors={["#3f6e45"]} names={["Kupon"]} data={[
                  { label: "Tersedia", values: [kupon.tersedia] },
                  { label: "Digunakan", values: [kupon.digunakan] },
                  { label: "Kedaluwarsa", values: [kupon.kedaluwarsa] },
                ]} />
              )}
              {!loading && kuponTotal > 0 && <p className="text-[13px] text-brand-700 mt-2">Tingkat penukaran: <b>{Math.round((kupon.digunakan / kuponTotal) * 100)}%</b></p>}
            </Card>

            <Card className="p-5">
              <CardTitle>Kupon per Tingkat</CardTitle>
              <p className="text-xs text-muted mt-1">Jumlah kupon yang pernah diklaim.</p>
              {loading ? <Skeleton className="h-[200px] mt-4" /> : (
                <BarChart height={200} colors={["#d4a039"]} names={["Diklaim"]} data={[
                  { label: "Mager (1 km)", values: [perTingkat[1] ?? 0] },
                  { label: "Niat (3 km)", values: [perTingkat[2] ?? 0] },
                  { label: "Kalcer (5 km)", values: [perTingkat[3] ?? 0] },
                ]} />
              )}
            </Card>

            <Card className="p-5">
              <CardTitle>Aktivitas per Koridor</CardTitle>
              {loading ? <Skeleton className="h-[220px] mt-4" /> : (d?.per_koridor ?? []).length === 0 ? <p className="text-sm text-muted mt-4">Belum ada aktivitas yang terhubung ke koridor.</p> : (
                <BarChart height={220} colors={["#3f6e45", "#d4a039"]} names={["Aktivitas", "Km"]} data={d.per_koridor.map((k) => ({ label: `Koridor ${k.koridor_id}`, values: [k.aktivitas, Math.round(k.km)] }))} />
              )}
            </Card>
          </div>
        </>
      )}
    </>
  );
}

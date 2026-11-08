import { Link } from "react-router-dom";
import { ArrowLeft } from "lucide-react";
import { PageHeader } from "../components/ui/PageHeader";
import { Card, CardTitle } from "../components/ui/Card";
import { useAsync } from "../hooks/useAsync";
import { jejakWangi } from "../services/jejakwangi";
import { fmtNum } from "../lib/format";

const SAMPLE_AVOIDED = 840, SAMPLE_OFFSET = 400; // data contoh dari desain; offset belum ada di backend

const ROWS = [
  ["Sensor lingkungan", "AQI, suhu, kelembapan, kebisingan", "Rata-rata dari pembacaan valid dalam koridor dan periode terpilih. Data hilang tidak dianggap nol."],
  ["Lalu lintas CCTV", "Hitungan kendaraan per kelas", "Jumlah kendaraan bukan emisi. Perlu jarak, jenis energi, dan faktor emisi yang sesuai."],
  ["Inventaris toko ber-AC", "Jumlah unit/sumber potensial", "Jumlah toko bukan emisi. Perlu energi terpakai atau kapasitas, jam operasi, dan faktor terkait."],
];
const SOURCES = [
  ["Jarak & moda", "Rekaman perjalanan yang telah divalidasi; keluarkan duplikasi dan perjalanan tidak lengkap."],
  ["Faktor emisi", "Belum ditetapkan. Harus menyertakan penerbit, wilayah, tahun, versi, dan satuan sebelum publikasi."],
  ["Lingkungan", "Sensor/CCTV belum terhubung pada rancangan ini; tren dan inventaris ditandai sebagai data contoh."],
  ["Bukti offset", "Belum tersedia. Simpan identitas proyek, periode, bukti verifikasi, dan status realisasi."],
];

export default function CaraMenghitungPage() {
  const res = useAsync(() => jejakWangi.summary().catch(() => null), []);
  const real = res.data?.total_co2_kg > 0;
  const avoided = real ? res.data.total_co2_kg : SAMPLE_AVOIDED;
  const f = (n) => `${fmtNum(n, n % 1 ? 1 : 0)} kg CO₂e`;
  return (
    <>
      <Link to="/" className="inline-flex items-center gap-1.5 text-sm font-semibold text-brand-700 hover:underline mb-4"><ArrowLeft size={15} />Dashboard</Link>
      <PageHeader title="Cara Kami Menghitung" subtitle="Info dan panduan / Metodologi karbon & data lingkungan" />
      <div className="rounded-2xl bg-[#fff3e0] px-5 py-4 mb-6"><p className="font-bold text-warn">Transparan sejak sumber data</p><p className="text-sm text-warn mt-1">Rancangan metodologi • Angka pada dasbor masih berupa data contoh. Faktor emisi lokal dan bukti offset belum tersedia; bukan klaim pengurangan emisi terverifikasi.</p></div>

      <Card className="p-5 mb-6">
        <CardTitle>Tiga angka, tiga makna berbeda</CardTitle>
        <div className="grid gap-4 md:grid-cols-3">
          <div className="rounded-xl bg-canvas p-4"><p className="text-sm font-semibold text-brand-700">Emisi dihindari</p><p className="text-2xl font-bold text-brand-700 my-1">{f(avoided)}</p><p className="text-xs text-muted">Estimasi selisih emisi perjalanan, bukan kredit karbon.{real ? " Dari aktivitas Jejak Wangi." : " (data contoh)"}</p></div>
          <div className="rounded-xl bg-canvas p-4"><p className="text-sm font-semibold text-brand-700">Dukungan offset</p><p className="text-2xl font-bold text-brand-700 my-1">{f(SAMPLE_OFFSET)}</p><p className="text-xs text-muted">Nilai sponsorship; belum berarti offset telah terealisasi. (data contoh)</p></div>
          <div className="rounded-xl bg-canvas p-4"><p className="text-sm font-semibold text-brand-700">Tampilan gabungan</p><p className="text-2xl font-bold text-brand-700 my-1">{f(avoided + SAMPLE_OFFSET)}</p><p className="text-xs text-muted">{fmtNum(avoided, 0)} + {SAMPLE_OFFSET} pada dasbor; jangan dibaca sebagai pengurangan terverifikasi.</p></div>
        </div>
      </Card>

      <div className="grid gap-6 xl:grid-cols-[1.4fr_1fr] mb-6 items-start">
        <Card className="p-5">
          <CardTitle>01 Emisi perjalanan yang dihindari</CardTitle>
          <p className="font-bold text-brand-700 mb-2">Jarak valid × (faktor moda pembanding − faktor moda aktual)</p>
          <p className="text-sm text-muted mb-4">Jarak dalam km; faktor dalam kg CO₂e/km. Hasil dinyatakan dalam kg CO₂e. Moda pembanding harus dikonfirmasi, bukan otomatis dianggap mobil.</p>
          <div className="rounded-xl bg-canvas p-4"><p className="text-xs font-semibold text-brand-700">Contoh ilustratif, bukan faktor resmi</p><p className="text-xl font-bold text-brand-700 my-1">10 km × (0,12 − 0) kg CO₂e/km = 1,20 kg CO₂e</p><p className="text-xs text-muted">Angka 0,12 hanya untuk menjelaskan rumus; bukan faktor produksi.</p></div>
        </Card>
        <Card className="p-5">
          <CardTitle>02 Dukungan offset</CardTitle>
          <p className="font-bold text-brand-700 mb-3">Tampilkan terpisah dari emisi dihindari.</p>
          <p className="text-sm text-muted mb-3">Sponsorship baru menjadi offset terealisasi setelah proyek, periode, bukti penyerapan/pengurangan, dan verifikasi tersedia.</p>
          <p className="text-sm text-muted">Satu bukti tidak boleh dihitung dua kali. Penanaman pohon bukan penyerapan instan.</p>
        </Card>
      </div>

      <Card className="p-5 mb-6"><CardTitle>03 Monitoring lingkungan & inventaris koridor</CardTitle>
        {ROWS.map(([a, b, c]) => <div key={a} className="grid gap-1 md:grid-cols-[200px_260px_1fr] py-2.5 text-sm border-t first:border-0 border-brand-100"><b className="text-brand-700">{a}</b><b className="text-brand-700">{b}</b><span className="text-muted">{c}</span></div>)}
      </Card>
      <Card className="p-5 mb-6"><CardTitle>Sumber, faktor, dan kualitas data</CardTitle>
        {SOURCES.map(([a, b]) => <div key={a} className="grid gap-1 md:grid-cols-[200px_1fr] py-2 text-sm"><b className="text-brand-700">{a}</b><span className="text-muted">{b}</span></div>)}
      </Card>
      <Card className="p-5 mb-2"><CardTitle>Batasan yang perlu diketahui</CardTitle>
        <p className="text-sm text-muted">Estimasi bergantung pada kelengkapan input dan asumsi. Jangan menggabungkan periode atau koridor yang berbeda. Perubahan faktor harus memiliki riwayat versi; perhitungan ulang perlu diberi penanda kepada pengguna.</p>
        <div className="flex flex-wrap items-center justify-between gap-3 mt-4 text-sm text-muted"><span>Ada data yang tidak sesuai? Laporkan untuk ditinjau admin dan DLH.</span><Link to="/lapor-koridor/baru" className="font-semibold text-brand-700 hover:underline">Laporkan koreksi →</Link></div>
      </Card>
    </>
  );
}

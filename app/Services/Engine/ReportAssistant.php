<?php

namespace App\Services\Engine;

use App\Models\AiReport;
use App\Models\HourlyMetric;
use App\Models\KoridorModel;
use App\Models\Proposal;
use App\Models\WasteWeighingModel;
use App\Services\EcoCoach\EcoCoach;
use App\Services\EcoCoach\EcoCoachData;
use Illuminate\Support\Carbon;

/**
 * Asisten Laporan AI (flowchart: Database per jam + Kg sisa pangan → "3 temuan dan 3 usulan" → Dashboard pengelola).
 *
 * Temuan & usulan DISUSUN OLEH ATURAN di server dari angka nyata (bukan dikarang AI); AI hanya menjelaskan
 * dalam bahasa sehari-hari lewat Eco-Coach (dengan cadangan template). Tiap usulan disimpan sebagai
 * `proposals` berstatus "menunggu" sampai pengelola memutuskan ("Usulan disetujui?").
 */
class ReportAssistant
{
    public function __construct(private EcoCoach $coach)
    {
    }

    /** @return array{report: AiReport, usulan: \Illuminate\Support\Collection} */
    public function buat(?int $userId = null): array
    {
        $kandidat = $this->kandidat();
        // Pilih 3 teratas dengan kategori berbeda dulu; sisanya mengisi bila kurang dari 3.
        usort($kandidat, fn ($a, $b) => $b['skor'] <=> $a['skor']);
        $dipilih = [];
        foreach ($kandidat as $c) {
            if (count($dipilih) < 3 && ! in_array($c['kategori'], array_column($dipilih, 'kategori'), true)) {
                $dipilih[] = $c;
            }
        }
        foreach ($kandidat as $c) {
            if (count($dipilih) < 3 && ! in_array($c, $dipilih, true)) {
                $dipilih[] = $c;
            }
        }

        $weekKey = Carbon::now(config('jejakwangi.timezone'))->format('o-\WW');
        $data = EcoCoachData::untukLaporan();
        $data['temuanSistem'] = array_map(fn ($c) => ['temuan' => $c['temuan'], 'usulan' => $c['usulan']], $dipilih);
        $hasilAi = $this->coach->jawab('laporan', $data);
        $report = AiReport::create([
            'week_key' => $weekKey,
            'sumber' => $hasilAi['sumber'] ?? 'template',
            'teks' => $hasilAi['teks'] ?? '',
            'temuan' => array_map(fn ($c) => ['kategori' => $c['kategori'], 'koridor_id' => $c['koridor_id'], 'temuan' => $c['temuan']], $dipilih),
            'created_by' => $userId,
        ]);

        $usulan = collect();
        foreach ($dipilih as $c) {
            // Usulan yang sama (kategori+koridor) dan masih menunggu tidak digandakan.
            $dedupe = $c['kategori'].':'.($c['koridor_id'] ?? 0).':'.$weekKey;
            $ada = Proposal::where('dedupe_key', $dedupe)->where('status', 'menunggu')->first();
            $usulan->push($ada ?? Proposal::create([
                'status' => 'menunggu',
                'ai_report_id' => $report->id,
                'dedupe_key' => $dedupe,
                'kategori' => $c['kategori'],
                'koridor_id' => $c['koridor_id'],
                'temuan' => $c['temuan'],
                'usulan' => $c['usulan'],
                'dampak' => $c['dampak'],
                'parameter' => $c['parameter'],
            ]));
        }

        return ['report' => $report, 'usulan' => $usulan];
    }

    /** @return list<array{kategori:string,koridor_id:?int,temuan:string,usulan:string,dampak:array,parameter:?array,skor:float}> */
    private function kandidat(): array
    {
        $out = [];
        $rapor = Baseline::rapor(7)['koridor'];
        $sampai = Carbon::now();
        $dari = $sampai->copy()->subDays(7);

        foreach (KoridorModel::orderBy('id')->get() as $k) {
            $n = CarbonBalance::hitung($k);
            if ($n['totalEmisi'] <= 0 && $n['serapanPohon'] <= 0) {
                continue;
            }
            $nama = $k->short_name;

            // 1) Defisit serapan → tanam pohon.
            if ($n['defisit'] > 0 && $n['pohonTambahanUntukNetral']) {
                $tambah = min($n['pohonTambahanUntukNetral'], 200);
                $out[] = [
                    'kategori' => 'pohon', 'koridor_id' => $k->id,
                    'temuan' => sprintf('%s hanya menyerap %s%% dari emisinya (defisit %s kg CO₂/hari).', $nama, $n['rasioSerapanPersen'] ?? 0, $n['defisit']),
                    'usulan' => sprintf('Tanam %d pohon mahoni di %s agar serapan mendekati netral.', $tambah, $nama),
                    'dampak' => ['co2_kg_per_hari' => round($tambah * CarbonBalance::dayaSerap('mahoni') / 365, 2)],
                    'parameter' => ['tambah_pohon' => ['jenis' => 'mahoni', 'jumlah' => $tambah]],
                    'skor' => $n['defisit'],
                ];
            }
            // 2) Emisi kendaraan dominan.
            if ($n['emisiKendaraan'] > 0 && $n['emisiKendaraan'] >= $n['emisiAC']) {
                $hemat = round($n['emisiKendaraan'] * 0.10, 2);
                $out[] = [
                    'kategori' => 'kendaraan', 'koridor_id' => $k->id,
                    'temuan' => sprintf('Kendaraan menyumbang %s kg CO₂/hari di %s — sumber emisi terbesar koridor ini.', $n['emisiKendaraan'], $nama),
                    'usulan' => sprintf('Dorong 10%% pengendara motor di %s beralih jalan kaki lewat QR kantong parkir dan rute teduh.', $nama),
                    'dampak' => ['co2_kg_per_hari' => $hemat],
                    'parameter' => ['persen_motor_jalan_kaki' => 10],
                    'skor' => $hemat * 3,
                ];
            }
            // 3) AC toko.
            if ($n['emisiAC'] > 0 && $n['emisiAC'] > $n['emisiKendaraan']) {
                $hemat = round($n['emisiAC'] * 0.15, 2);
                $out[] = [
                    'kategori' => 'ac', 'koridor_id' => $k->id,
                    'temuan' => sprintf('AC toko di %s mengemisi %s kg CO₂/hari, lebih besar dari kendaraan.', $nama, $n['emisiAC']),
                    'usulan' => sprintf('Jalankan program hemat AC 15%% (set 25 °C, servis berkala) untuk toko di %s.', $nama),
                    'dampak' => ['co2_kg_per_hari' => $hemat],
                    'parameter' => ['persen_hemat_ac' => 15],
                    'skor' => $hemat * 3,
                ];
            }
            // 4) Udara tidak sehat pada jam tertentu.
            $buruk = HourlyMetric::where('koridor_id', $k->id)->where('hour_at', '>=', $dari->copy()->utc())->where('ispu', '>', config('jejakwangi.engine.ispu_sehat_maks'))->get();
            if ($buruk->count() >= 2) {
                $jam = $buruk->groupBy(fn ($m) => $m->hour_at->copy()->setTimezone(config('jejakwangi.timezone'))->hour)->map->count()->sortDesc()->keys()->first();
                $out[] = [
                    'kategori' => 'udara', 'koridor_id' => $k->id,
                    'temuan' => sprintf('ISPU di %s tidak sehat selama %d jam minggu ini, paling sering sekitar pukul %02d.00.', $nama, $buruk->count(), $jam),
                    'usulan' => sprintf('Kirim saran geser waktu/rute lain ke warga yang scan QR parkir di %s sekitar pukul %02d.00.', $nama, $jam),
                    'dampak' => ['warga_terlindungi_jam' => $buruk->count()],
                    'parameter' => null,
                    'skor' => 10 + $buruk->count(),
                ];
            }
        }

        // 5) Suhu: kanopi kurang menyejukkan.
        foreach ($rapor as $r) {
            if ($r['selisih_suhu_rata'] !== null && $r['selisih_suhu_rata'] < 1.0) {
                $out[] = [
                    'kategori' => 'suhu', 'koridor_id' => $r['koridor_id'],
                    'temuan' => sprintf('Selisih suhu kanopi dan area terbuka di %s hanya %s °C (kanopi kurang efektif).', $r['koridor'], $r['selisih_suhu_rata']),
                    'usulan' => sprintf('Tambah 20 pohon peneduh di %s untuk menaikkan efek pendinginan.', $r['koridor']),
                    'dampak' => ['co2_kg_per_hari' => round(20 * CarbonBalance::dayaSerap('mahoni') / 365, 2)],
                    'parameter' => ['tambah_pohon' => ['jenis' => 'mahoni', 'jumlah' => 20]],
                    'skor' => 8,
                ];
            }
        }

        // 6) Sampah & sisa pangan menumpuk.
        $menunggu = (float) WasteWeighingModel::where('kind', 'sampah')->where('status', 'terkonfirmasi')->whereNull('processed_at')->sum('total_kg');
        if ($menunggu > 0) {
            $out[] = [
                'kategori' => 'sampah', 'koridor_id' => null,
                'temuan' => sprintf('%s kg sampah terpilah menunggu diolah/diangkut.', round($menunggu, 1)),
                'usulan' => 'Jadwalkan penjemputan sampah terpilah ke TPS3R besok pagi.',
                'dampak' => ['sampah_kg' => round($menunggu, 1)],
                'parameter' => null,
                'skor' => 6 + min(20, $menunggu / 10),
            ];
        }
        $pangan = (float) WasteWeighingModel::where('kind', 'pangan')->where('status', 'terkonfirmasi')->where('weighed_at', '>=', $dari->copy()->utc())->sum('total_kg');
        if ($pangan > 0) {
            $out[] = [
                'kategori' => 'pangan', 'koridor_id' => null,
                'temuan' => sprintf('Pasar menyisakan %s kg pangan layak dalam 7 hari terakhir.', round($pangan, 1)),
                'usulan' => 'Aktifkan pemilahan sampah mandiri di lapak pasar dan salurkan pangan layak ke bank pangan.',
                'dampak' => ['pangan_kg' => round($pangan, 1)],
                'parameter' => null,
                'skor' => 7 + min(20, $pangan / 5),
            ];
        }

        return $out;
    }
}

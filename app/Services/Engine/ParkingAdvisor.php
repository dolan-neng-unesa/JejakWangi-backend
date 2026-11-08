<?php

namespace App\Services\Engine;

use App\Models\HourlyMetric;
use App\Models\KoridorModel;
use App\Models\QrPoint;
use Illuminate\Support\Carbon;

/**
 * Alur "Scan QR kantong parkir → Cek ISPU, suhu, jam ramai → ISPU sehat?" pada flowchart.
 *   Ya    → langsung jalan ke pasar atau taman.
 *   Tidak → Saran waktu atau rute lain → jalan ke pasar atau taman.
 */
class ParkingAdvisor
{
    public function __construct(private HourlyAggregator $agg)
    {
    }

    public function periksa(QrPoint $titik): array
    {
        $cfg = config('jejakwangi.engine');
        $tz = config('jejakwangi.timezone');
        $koridor = $titik->koridor_id ? KoridorModel::find($titik->koridor_id) : null;
        $m = $koridor ? $this->terkini($koridor) : null;

        $ispu = $m?->ispu;
        $suhu = $m?->suhu_kanopi ?? $m?->suhu_terbuka;
        $sehatUdara = $ispu === null ? null : $ispu <= $cfg['ispu_sehat_maks'];
        $sehatSuhu = $suhu === null ? null : $suhu <= $cfg['suhu_nyaman_maks'];
        // Data kosong ≠ tidak sehat: sehat hanya jika tidak ada indikator buruk; `data_lengkap` memberi tahu UI.
        $sehat = $sehatUdara !== false && $sehatSuhu !== false;

        $ramai = $koridor ? $this->jamRamai($koridor, $m) : null;

        return [
            'titik' => ['id' => $titik->id, 'kode' => $titik->code, 'nama' => $titik->name, 'tipe' => $titik->type],
            'koridor' => $koridor ? ['id' => $koridor->id, 'nama' => $koridor->short_name] : null,
            'diperiksa_pada' => now()->toIso8601String(),
            'ispu' => $m && $m->ispu !== null ? ['nilai' => (int) $m->ispu, 'kategori' => $m->ispu_kategori, 'dominan' => $m->polutan_dominan, 'sumber' => $m->sumber['ispu'] ?? null] : null,
            'suhu_c' => $suhu !== null ? round((float) $suhu, 1) : null,
            'selisih_suhu_c' => $m?->selisih_suhu,
            'hari_festival' => $m ? ['aktif' => (bool) $m->hari_festival, 'nama' => $m->festival_nama] : null,
            'jam_ramai' => $ramai,
            'sehat' => $sehat,
            'data_lengkap' => $ispu !== null && $suhu !== null,
            'alasan_tidak_sehat' => array_values(array_filter([
                $sehatUdara === false ? "ISPU {$ispu} ({$m->ispu_kategori})" : null,
                $sehatSuhu === false ? sprintf('suhu %.1f °C', $suhu) : null,
            ])),
            'saran' => $sehat ? null : $this->saran($koridor, $m, $tz),
            'langkah_berikut' => 'jalan_ke_pasar_atau_taman',
        ];
    }

    private function terkini(KoridorModel $k): ?HourlyMetric
    {
        $this->agg->jalankan(now(), $k->id); // pastikan jam berjalan tercatat dari sensor terbaru
        $m = HourlyMetric::where('koridor_id', $k->id)->where('hour_at', '>=', now()->subHours(3)->utc())
            ->whereNotNull('ispu')->orderByDesc('hour_at')->first();

        return $m ?? HourlyMetric::where('koridor_id', $k->id)->orderByDesc('hour_at')->first();
    }

    /** Level keramaian jam ini dibanding pola 7 hari terakhir (kendaraan + check-in). */
    private function jamRamai(KoridorModel $k, ?HourlyMetric $m): ?array
    {
        $tz = config('jejakwangi.timezone');
        $rows = HourlyMetric::where('koridor_id', $k->id)->where('hour_at', '>=', now()->subDays(7)->utc())->get();
        if ($rows->isEmpty()) {
            return null;
        }
        $perJam = $rows->groupBy(fn ($r) => $r->hour_at->copy()->setTimezone($tz)->hour)
            ->map(fn ($g) => $g->avg(fn ($r) => $r->kendaraan_motor + $r->kendaraan_mobil + $r->checkin_jumlah * 5));
        $maks = max(1, $perJam->max());
        $jamIni = now($tz)->hour;
        $rasio = ($perJam[$jamIni] ?? 0) / $maks;
        $terpadat = $perJam->sortDesc()->keys()->first();

        return [
            'level' => $rasio >= 0.66 ? 'ramai' : ($rasio >= 0.33 ? 'sedang' : 'sepi'),
            'jam_terpadat' => sprintf('%02d.00–%02d.00', $terpadat, ($terpadat + 1) % 24),
            'indeks' => round($rasio, 2),
        ];
    }

    /** Saran waktu (jam dengan ISPU & keramaian terendah) dan rute lain (koridor dengan ISPU terendah saat ini). */
    private function saran(?KoridorModel $k, ?HourlyMetric $m, string $tz): array
    {
        $out = ['waktu' => null, 'rute_lain' => null, 'teks' => []];
        if ($k) {
            $rows = HourlyMetric::where('koridor_id', $k->id)->where('hour_at', '>=', now()->subDays(7)->utc())->whereNotNull('ispu')->get();
            $jamIni = now($tz)->hour;
            $terbaik = $rows->groupBy(fn ($r) => $r->hour_at->copy()->setTimezone($tz)->hour)
                ->filter(fn ($g, $h) => $h >= 6 && $h <= 19 && $h !== $jamIni)
                ->map(fn ($g) => $g->avg('ispu'))->sort();
            if ($terbaik->isNotEmpty()) {
                $h = $terbaik->keys()->first();
                $out['waktu'] = ['jam' => sprintf('%02d.00–%02d.00', $h, $h + 1), 'ispu_rata' => (int) round($terbaik->first())];
                $out['teks'][] = sprintf('Udara biasanya paling bersih sekitar pukul %02d.00 (ISPU rata-rata %d).', $h, (int) round($terbaik->first()));
            }
        }
        $lain = HourlyMetric::where('hour_at', '>=', now()->subHours(3)->utc())->whereNotNull('ispu')
            ->when($k, fn ($q) => $q->where('koridor_id', '!=', $k->id))
            ->orderByDesc('hour_at')->get()->unique('koridor_id')->sortBy('ispu')->first();
        if ($lain && (! $m || $lain->ispu < ($m->ispu ?? PHP_INT_MAX))) {
            $nama = KoridorModel::find($lain->koridor_id)?->short_name;
            $out['rute_lain'] = ['koridor_id' => $lain->koridor_id, 'nama' => $nama, 'ispu' => (int) $lain->ispu];
            $out['teks'][] = sprintf('Coba rute lain: %s (ISPU %d) lebih nyaman saat ini.', $nama, (int) $lain->ispu);
        }
        if (! $out['teks']) {
            $out['teks'][] = 'Kurangi aktivitas berat di luar ruang dan pakai masker bila perlu.';
        }

        return $out;
    }
}

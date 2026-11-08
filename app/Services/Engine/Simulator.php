<?php

namespace App\Services\Engine;

use App\Models\KoridorModel;
use Illuminate\Support\Carbon;

/**
 * Simulator skenario ("what-if") — port terapkanSkenario/bandingkanSkenario dari carbonBalance.js,
 * ditambah panel Solar Bench, nilai ekonomi, dan pencari parameter "perbaiki dampak" bila usulan ditolak.
 *
 * parameter: {
 *   tambah_pohon:           {jenis, jumlah},
 *   persen_motor_jalan_kaki: 0..100,
 *   persen_hemat_ac:         0..100,
 *   tambah_solar_bench:      unit
 * }
 */
class Simulator
{
    public static function bersih(array $p): array
    {
        $pers = fn ($x) => min(100, max(0, (float) $x)) / 100;
        $pohonTambah = $p['tambah_pohon'] ?? null;

        return [
            'tambah_pohon' => $pohonTambah && (int) ($pohonTambah['jumlah'] ?? 0) > 0
                ? ['jenis' => (string) ($pohonTambah['jenis'] ?? 'mahoni'), 'jumlah' => (int) $pohonTambah['jumlah']] : null,
            'persen_motor_jalan_kaki' => round($pers($p['persen_motor_jalan_kaki'] ?? 0) * 100, 1),
            'persen_hemat_ac' => round($pers($p['persen_hemat_ac'] ?? 0) * 100, 1),
            'tambah_solar_bench' => max(0, (int) ($p['tambah_solar_bench'] ?? 0)),
        ];
    }

    /** Neraca harian (kg CO2/hari) sebelum & sesudah skenario. */
    public static function jalankan(KoridorModel $k, array $parameter): array
    {
        $p = self::bersih($parameter);
        $in = CarbonBalance::masukan($k);
        $cfg = config('jejakwangi.engine');

        $awal = self::hitungMasukan($in, $k);
        $baru = $in;
        $baru['kendaraanPerHari']['motor'] = ($in['kendaraanPerHari']['motor'] ?? 0) * (1 - $p['persen_motor_jalan_kaki'] / 100);
        $baru['toko'] = array_map(fn ($t) => [...$t, 'kWPerUnit' => ($t['kWPerUnit'] ?? 0) * (1 - $p['persen_hemat_ac'] / 100)], $in['toko']);
        if ($p['tambah_pohon']) {
            $baru['pohon'][] = $p['tambah_pohon'];
        }
        $sesudah = self::hitungMasukan($baru, $k);
        $suryaTambah = $p['tambah_solar_bench'] * $cfg['solar_bench_kwh_hari'] * $cfg['faktor_grid'];
        $sesudah['terhindar'] += $suryaTambah;
        $sesudah['bersih'] = round($sesudah['totalEmisi'] - $sesudah['serapan'] - $sesudah['terhindar'], 3);

        $turun = round($awal['bersih'] - $sesudah['bersih'], 3);
        $mahoni = CarbonBalance::dayaSerap('mahoni') / 365;

        $hasil = [
            'koridor_id' => $k->id,
            'koridor' => $k->short_name,
            'parameter' => $p,
            'awal' => $awal,
            'sesudah' => $sesudah,
            'selisih' => [
                'emisi_bersih_turun_kg_hari' => $turun,
                'emisi_bersih_turun_kg_tahun' => round($turun * 365, 1),
                'persen_turun' => $awal['bersih'] != 0 ? round($turun / abs($awal['bersih']) * 100, 1) : null,
                'setara_pohon_mahoni' => $mahoni > 0 ? (int) round($turun / $mahoni) : null,
                'menjadi_netral' => $sesudah['bersih'] <= 0,
            ],
            'dibuat' => now()->toIso8601String(),
        ];
        $hasil['ekonomi_tahun'] = GreenEconomy::nilaiSkenario($hasil);

        return $hasil;
    }

    private static function hitungMasukan(array $in, KoridorModel $k): array
    {
        $serapan = CarbonBalance::serapanPohon($in['pohon']);
        $kend = CarbonBalance::emisiKendaraan($in['kendaraanPerHari'], $in['panjangKm']);
        $ac = CarbonBalance::emisiAc($in['toko']);
        // Emisi terhindar nyata (jalan kaki, surya, plastik) rata-rata 7 hari terakhir dari Database per jam.
        $r = Neraca::ringkas($k->id, Carbon::now()->subDays(7), Carbon::now());
        $terhindar = $r['jam_tercatat'] >= 24 ? $r['terhindar_kg'] / max(1, $r['jam_tercatat'] / 24) : 0.0;

        return [
            'serapan' => round($serapan, 3),
            'emisi_kendaraan' => round($kend, 3),
            'emisi_ac' => round($ac, 3),
            'totalEmisi' => round($kend + $ac, 3),
            'terhindar' => round($terhindar, 3),
            'bersih' => round($kend + $ac - $serapan - $terhindar, 3),
        ];
    }

    /**
     * "Perbaiki dampak": dari parameter yang ditolak, cari tambahan pohon minimum agar emisi bersih ≤ targetKgHari,
     * lalu naikkan pengalihan motor/hemat AC bila perlu. Mengembalikan parameter baru + hasil simulasinya.
     */
    public static function perbaiki(KoridorModel $k, array $parameter, ?float $targetKgHari = null): array
    {
        $p = self::bersih($parameter);
        $awal = self::jalankan($k, $p);
        $target = $targetKgHari ?? min(0.0, $awal['awal']['bersih'] * 0.8); // default: turun 20% dari baseline atau netral
        if ($awal['sesudah']['bersih'] <= $target) {
            return ['parameter' => $p, 'hasil' => $awal, 'catatan' => 'Parameter sudah memenuhi target.'];
        }
        $p['persen_motor_jalan_kaki'] = min(30, max($p['persen_motor_jalan_kaki'], 10) + 10);
        $p['persen_hemat_ac'] = min(30, max($p['persen_hemat_ac'], 10) + 5);
        $hasil = self::jalankan($k, $p);
        $kurang = $hasil['sesudah']['bersih'] - $target;
        if ($kurang > 0) {
            $perPohon = CarbonBalance::dayaSerap($p['tambah_pohon']['jenis'] ?? 'mahoni') / 365;
            $tambah = (int) ceil($kurang / max($perPohon, 0.0001));
            $p['tambah_pohon'] = ['jenis' => $p['tambah_pohon']['jenis'] ?? 'mahoni', 'jumlah' => ($p['tambah_pohon']['jumlah'] ?? 0) + min($tambah, 500)];
            $hasil = self::jalankan($k, $p);
        }

        return ['parameter' => $p, 'hasil' => $hasil, 'catatan' => 'Parameter diperbaiki agar mendekati target emisi bersih.'];
    }
}

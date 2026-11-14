<?php

namespace App\Services\Engine;

use App\Models\KoridorModel;

/**
 * Neraca karbon koridor di sisi server — port dari jejak-wangi-app/src/lib/carbonBalance.js
 * (rumus & konstanta identik) sehingga server menjadi sumber kebenaran untuk Database per jam.
 * Satuan: kg CO2 per hari kecuali disebut lain.
 *
 *   Serapan pohon   S      = Σ (jumlah_i × daya serap_i [kg/th]) ÷ 365
 *   Emisi kendaraan E_kend = Σ (kendaraan/hari_j × panjang [km] × L/km_j × faktor BBM)
 *   Emisi AC        E_AC   = Σ toko (unit × kW × jam × faktor beban × faktor grid)
 */
class CarbonBalance
{
    public const RASIO_O2_PER_CO2 = 32 / 44;

    /** @return array{pohon: array, kendaraanPerHari: array, toko: array, panjangKm: float, sumberData: array} */
    public static function masukan(KoridorModel $k): array
    {
        $ci = (array) $k->carbon_inputs;

        return [
            'pohon' => (array) ($ci['pohon'] ?? []),
            'kendaraanPerHari' => (array) ($ci['kendaraanPerHari'] ?? []),
            'toko' => (array) ($ci['toko'] ?? []),
            'panjangKm' => (float) ($ci['panjangKm'] ?? ($k->distance_meter ?? 0) / 1000),
            'sumberData' => (array) ($ci['sumberData'] ?? []),
        ];
    }

    public static function dayaSerap(string $jenis, ?float $override = null): float
    {
        $tabel = config('jejakwangi.engine.daya_serap_pohon');

        return (float) ($override ?? $tabel[$jenis] ?? $tabel['asumsi_rata2']);
    }

    public static function serapanPohon(array $pohon): float
    {
        $total = 0.0;
        foreach ($pohon as $p) {
            $total += max(0, (float) ($p['jumlah'] ?? 0)) * self::dayaSerap((string) ($p['jenis'] ?? ''), isset($p['kgPerTahun']) ? (float) $p['kgPerTahun'] : null) / 365;
        }

        return $total;
    }

    public static function emisiPerKm(string $jenis): float
    {
        $liter = config("jejakwangi.engine.liter_per_100km.$jenis");
        if ($liter === null) {
            return 0.0;
        }

        $bbm = config("jejakwangi.engine.bbm_kendaraan.$jenis", 'bensin');

        return ($liter / 100) * config("jejakwangi.engine.faktor_bbm.$bbm");
    }

    public static function emisiKendaraan(array $kendaraanPerHari, float $panjangKm): float
    {
        $total = 0.0;
        foreach ($kendaraanPerHari as $jenis => $n) {
            $total += max(0, (float) $n) * $panjangKm * self::emisiPerKm((string) $jenis);
        }

        return $total;
    }

    public static function kwhAcPerHari(array $toko): float
    {
        $kwh = 0.0;
        foreach ($toko as $t) {
            $kwh += (float) ($t['unitAC'] ?? 0) * (float) ($t['kWPerUnit'] ?? 0) * (float) ($t['jamOperasi'] ?? 0) * (float) ($t['faktorBeban'] ?? 0.7);
        }

        return $kwh;
    }

    public static function emisiAc(array $toko): float
    {
        return self::kwhAcPerHari($toko) * config('jejakwangi.engine.faktor_grid');
    }

    /** Neraca harian lengkap satu koridor (sama bentuk dengan hitungNeracaKoridor di FE, ditambah neraca bersih). */
    public static function hitung(KoridorModel $k): array
    {
        $in = self::masukan($k);
        $serapan = self::serapanPohon($in['pohon']);
        $kend = self::emisiKendaraan($in['kendaraanPerHari'], $in['panjangKm']);
        $ac = self::emisiAc($in['toko']);
        $totalEmisi = $kend + $ac;
        $defisit = max(0.0, $totalEmisi - $serapan);
        $mahoni = self::dayaSerap('mahoni') / 365;

        return [
            'koridor_id' => $k->id,
            'nama' => $k->short_name,
            'panjangKm' => $in['panjangKm'],
            'serapanPohon' => round($serapan, 2),
            'o2SetaraKgPerHari' => round($serapan * self::RASIO_O2_PER_CO2, 2),
            'emisiKendaraan' => round($kend, 2),
            'emisiAC' => round($ac, 2),
            'totalEmisi' => round($totalEmisi, 2),
            'rasioSerapanPersen' => $totalEmisi > 0 ? round($serapan / $totalEmisi * 100, 1) : null,
            'defisit' => round($defisit, 2),
            'pohonTambahanUntukNetral' => $mahoni > 0 ? (int) ceil($defisit / $mahoni) : null,
            'sumberData' => $in['sumberData'],
        ];
    }
}

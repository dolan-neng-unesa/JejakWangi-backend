<?php

namespace App\Services\Engine;

use App\Models\HourlyMetric;
use App\Models\KoridorModel;
use Illuminate\Support\Carbon;

/** Agregasi Database per jam → ringkasan harian/periode (dipakai rapor, baseline, ekonomi hijau, dashboard). */
class Neraca
{
    /** Total periode untuk satu koridor (atau semua bila null). */
    public static function ringkas(?int $koridorId, Carbon $dari, Carbon $sampai): array
    {
        $q = HourlyMetric::where('hour_at', '>=', $dari->copy()->utc())->where('hour_at', '<', $sampai->copy()->utc())
            ->when($koridorId, fn ($q) => $q->where('koridor_id', $koridorId));
        $r = (clone $q)->selectRaw('COUNT(*) as jam, COUNT(DISTINCT koridor_id) as koridor,
            COALESCE(SUM(serapan_kg),0) as serapan, COALESCE(SUM(emisi_ac_kg),0) as ac, COALESCE(SUM(emisi_kendaraan_kg),0) as kendaraan,
            COALESCE(SUM(emisi_terhindar_kg),0) as terhindar, COALESCE(SUM(neraca_kg),0) as neraca,
            COALESCE(SUM(solar_kwh),0) as surya, COALESCE(SUM(plastik_terhindar),0) as plastik, COALESCE(SUM(sisa_pangan_kg),0) as pangan,
            COALESCE(SUM(checkin_jumlah),0) as checkin, AVG(ispu) as ispu_rata, MAX(ispu) as ispu_maks, AVG(selisih_suhu) as selisih_rata,
            SUM(CASE WHEN ispu > ? THEN 1 ELSE 0 END) as jam_tidak_sehat, SUM(CASE WHEN hari_festival = 1 THEN 1 ELSE 0 END) as jam_festival',
            [config('jejakwangi.engine.ispu_sehat_maks')])->first();

        $emisi = (float) $r->ac + (float) $r->kendaraan;
        $masuk = (float) $r->serapan + (float) $r->terhindar;

        return [
            'jam_tercatat' => (int) $r->jam,
            'serapan_kg' => round((float) $r->serapan, 2),
            'emisi_ac_kg' => round((float) $r->ac, 2),
            'emisi_kendaraan_kg' => round((float) $r->kendaraan, 2),
            'emisi_total_kg' => round($emisi, 2),
            'terhindar_kg' => round((float) $r->terhindar, 2),
            'neraca_kg' => round((float) $r->neraca, 2),
            'rasio_persen' => $emisi > 0 ? round($masuk / $emisi * 100, 1) : null,
            'surya_kwh' => round((float) $r->surya, 2),
            'plastik_terhindar' => (int) $r->plastik,
            'sisa_pangan_kg' => round((float) $r->pangan, 2),
            'checkin' => (int) $r->checkin,
            'ispu_rata' => $r->ispu_rata !== null ? (int) round($r->ispu_rata) : null,
            'ispu_maks' => $r->ispu_maks !== null ? (int) $r->ispu_maks : null,
            'selisih_suhu_rata' => $r->selisih_rata !== null ? round((float) $r->selisih_rata, 2) : null,
            'jam_tidak_sehat' => (int) $r->jam_tidak_sehat,
            'jam_festival' => (int) $r->jam_festival,
        ];
    }

    /** Emisi bersih per hari (kg) = emisi − serapan − terhindar. Positif = koridor masih mengemisi. */
    public static function emisiBersihPerHari(KoridorModel $k, Carbon $dari, Carbon $sampai): array
    {
        $hari = max(1, $dari->diffInDays($sampai));
        $r = self::ringkas($k->id, $dari, $sampai);
        if ($r['jam_tercatat'] >= 24) {
            // Rata-rata per hari dari jam yang tercatat (jam < 24 per hari tetap dinormalkan ke 24 jam).
            $hariEfektif = max(1, $r['jam_tercatat'] / 24);

            return ['nilai' => round(-$r['neraca_kg'] / $hariEfektif, 3), 'sumber' => 'database_per_jam', 'hari' => round($hariEfektif, 1)];
        }
        $n = CarbonBalance::hitung($k);

        return ['nilai' => round($n['totalEmisi'] - $n['serapanPohon'], 3), 'sumber' => 'survei_harian', 'hari' => $hari];
    }

    public static function grade(?float $rasio): string
    {
        return match (true) {
            $rasio === null => '-',
            $rasio >= 100 => 'A',
            $rasio >= 75 => 'B',
            $rasio >= 50 => 'C',
            $rasio >= 25 => 'D',
            default => 'E',
        };
    }
}

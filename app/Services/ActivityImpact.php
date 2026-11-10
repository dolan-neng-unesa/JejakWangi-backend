<?php

namespace App\Services;

/**
 * Perhitungan dampak aktivitas jalan kaki (emisi terhindar, hemat BBM, kalori, poin).
 * Rumus identik dengan jejak-wangi-app/src/lib/journey.js — server yang jadi sumber kebenaran,
 * sehingga angka dari klien tidak dipercaya begitu saja.
 */
class ActivityImpact
{
    /** Orang yang sudah jalan/bersepeda dibandingkan dengan motor (kendaraan paling umum). */
    public static function pembanding(?string $transport): string
    {
        return $transport === 'mobil' ? 'mobil' : 'motor';
    }

    /** @return array{co2_kg: float, liter: float, rupiah: int, kkal: int, points: int, pembanding: string} */
    public static function hitung(int $distanceM, ?string $transport): array
    {
        $km = max(0, $distanceM) / 1000;
        $ref = self::pembanding($transport);
        $liter = (config("jejakwangi.liter_per_100km.$ref") / 100) * $km;

        return [
            'pembanding' => $ref,
            'liter' => $liter,
            'co2_kg' => round($liter * config('jejakwangi.faktor_emisi_bensin'), 3),
            'rupiah' => (int) round($liter * config('jejakwangi.harga_bbm_per_liter')),
            'kkal' => (int) round($km * config('jejakwangi.kkal_per_km')),
            'points' => (int) round($km * config('jejakwangi.poin_per_km')),
        ];
    }
}

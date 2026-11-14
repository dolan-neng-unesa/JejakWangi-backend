<?php

namespace App\Services;

/**
 * ISPU (Permen LHK P.14/MENLHK/SETJEN/KUM.1/7/2020) — sisi server.
 * Rumus identik dengan jejak-wangi-app/src/lib/ispu.js (semua konsentrasi dalam µg/m³).
 */
class Ispu
{
    /** Batas atas konsentrasi untuk indeks 50, 100, 200, 300, 500. */
    public const BREAKPOINTS = [
        'pm25' => [15.5, 55.4, 150.4, 250.4, 500],
        'pm10' => [50, 150, 350, 420, 500],
        'so2' => [52, 180, 400, 800, 1200],
        'co' => [4000, 8000, 15000, 30000, 45000],
        'o3' => [120, 235, 400, 800, 1000],
        'no2' => [80, 200, 1130, 2260, 3000],
    ];

    public const LABEL = ['pm25' => 'PM2.5', 'pm10' => 'PM10', 'so2' => 'SO2', 'co' => 'CO', 'o3' => 'O3', 'no2' => 'NO2'];

    private const INDEX_BOUNDS = [0, 50, 100, 200, 300, 500];

    public static function subIndeks(string $polutan, ?float $konsentrasi): ?int
    {
        $bp = self::BREAKPOINTS[$polutan] ?? null;
        if ($bp === null || $konsentrasi === null) {
            return null;
        }
        $x = max(0.0, $konsentrasi);
        if ($x >= end($bp)) {
            return 500;
        }
        $lowerX = 0.0;
        foreach ($bp as $i => $upper) {
            if ($x <= $upper) {
                $lowerI = self::INDEX_BOUNDS[$i];
                $upperI = self::INDEX_BOUNDS[$i + 1];

                return (int) round($lowerI + (($x - $lowerX) / ($upper - $lowerX)) * ($upperI - $lowerI));
            }
            $lowerX = $upper;
        }

        return 500;
    }

    public static function kategori(int $indeks): string
    {
        return match (true) {
            $indeks <= 50 => 'Baik',
            $indeks <= 100 => 'Sedang',
            $indeks <= 200 => 'Tidak Sehat',
            $indeks <= 300 => 'Sangat Tidak Sehat',
            default => 'Berbahaya',
        };
    }

    /**
     * @param array<string, float|int|null> $nilai bacaan polutan {pm25, pm10, so2, co, o3, no2}
     * @return array{value: int, dominan: string, kategori: string}|null null bila tak ada polutan
     */
    public static function hitung(array $nilai): ?array
    {
        $dominan = null;
        $maks = -1;
        foreach (array_keys(self::BREAKPOINTS) as $key) {
            $sub = self::subIndeks($key, isset($nilai[$key]) ? (float) $nilai[$key] : null);
            if ($sub !== null && $sub > $maks) {
                $maks = $sub;
                $dominan = $key;
            }
        }

        return $dominan === null ? null : [
            'value' => $maks,
            'dominan' => self::LABEL[$dominan],
            'kategori' => self::kategori($maks),
        ];
    }
}

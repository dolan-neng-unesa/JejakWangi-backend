<?php

namespace App\Services;

use App\Models\KoridorModel;
use App\Models\SensorReadingModel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Bacaan kualitas udara & suhu dari Open-Meteo sebagai pengganti / cadangan sensor ESP32.
 * Konsentrasi dalam µg/m³ — sama dengan satuan di App\Services\Ispu, jadi langsung bisa dihitung ISPU.
 *
 * Catatan: ini estimasi model (CAMS) per grid, bukan pengukuran di koridor.
 */
class OpenMeteoAir
{
    public const SUMBER = 'open-meteo';

    private const POLUTAN_AIR = [
        'pm2_5' => SensorReadingModel::TYPE_PM25,
        'pm10' => SensorReadingModel::TYPE_PM10,
        'sulphur_dioxide' => SensorReadingModel::TYPE_SO2,
        'carbon_monoxide' => SensorReadingModel::TYPE_CO,
        'ozone' => SensorReadingModel::TYPE_O3,
        'nitrogen_dioxide' => SensorReadingModel::TYPE_NO2,
    ];

    private const POLUTAN_KEYS = [
        SensorReadingModel::TYPE_PM25, SensorReadingModel::TYPE_PM10, SensorReadingModel::TYPE_SO2,
        SensorReadingModel::TYPE_CO, SensorReadingModel::TYPE_O3, SensorReadingModel::TYPE_NO2,
    ];

    public static function mode(): string
    {
        $m = (string) config('openmeteo.mode', 'off');

        return in_array($m, ['auto', 'openmeteo'], true) ? $m : 'off';
    }

    public static function aktif(): bool
    {
        return self::mode() !== 'off';
    }

    /** Titik tengah jalur koridor (path = [[lat, lng], ...]). */
    public static function titik(KoridorModel $k): ?array
    {
        $path = array_values(array_filter((array) $k->path, fn ($p) => is_array($p) && count($p) >= 2 && is_numeric($p[0]) && is_numeric($p[1])));
        if ($path === []) {
            return null;
        }
        $lat = array_sum(array_map(fn ($p) => (float) $p[0], $path)) / count($path);
        $lng = array_sum(array_map(fn ($p) => (float) $p[1], $path)) / count($path);

        return ['lat' => round($lat, 2), 'lng' => round($lng, 2)]; // ~1 km: koridor berdekatan berbagi satu cache
    }

    /** Koridor untuk kartu ISPU home saat tidak ada wilayah ESP32 yang aktif. */
    public static function koridorBawaan(): ?int
    {
        $id = config('openmeteo.default_koridor_id');
        if ($id !== null && $id !== '' && KoridorModel::whereKey((int) $id)->exists()) {
            return (int) $id;
        }

        return KoridorModel::orderBy('sort_order')->orderBy('id')->value('id');
    }

    /**
     * @return array{values: array<string,float>, at: Carbon}|null  values: pm25, pm10, so2, co, o3, no2, temperature
     */
    public static function bacaan(KoridorModel $k): ?array
    {
        if (! self::aktif() || ! ($t = self::titik($k))) {
            return null;
        }
        $ttl = max(1, (int) config('openmeteo.cache_minutes', 10));
        $key = sprintf('openmeteo:%s:%s', $t['lat'], $t['lng']);

        $hasil = Cache::get($key);
        if ($hasil === null) {
            $hasil = self::ambil($t['lat'], $t['lng']);
            // Gagal pun disimpan sebentar supaya Open-Meteo tidak dipanggil ulang di setiap request.
            Cache::put($key, $hasil ?? ['gagal' => true], $hasil ? now()->addMinutes($ttl) : now()->addMinute());
        }
        if (! is_array($hasil) || isset($hasil['gagal'])) {
            return null;
        }

        return ['values' => $hasil['values'], 'at' => Carbon::parse($hasil['at'])];
    }

    /**
     * Gabungkan bacaan sensor yang masih segar dengan Open-Meteo sesuai mode.
     *  - openmeteo: nilai Open-Meteo menimpa sensor.
     *  - auto: kelompok polutan (satu kesatuan, supaya ISPU tidak dicampur dua sumber) dan suhu
     *          diisi Open-Meteo hanya jika sensor tidak punya bacaan segar untuknya.
     *
     * @param array<string,float> $sensorSegar
     * @param array<string,float> $om
     * @return array{nilai: array<string,float>, dariOm: string[]}
     */
    public static function isi(array $sensorSegar, array $om): array
    {
        $nilai = $sensorSegar;
        $dariOm = [];

        if (self::mode() === 'openmeteo') {
            foreach ($om as $tipe => $v) {
                $nilai[$tipe] = $v;
                $dariOm[] = $tipe;
            }

            return ['nilai' => $nilai, 'dariOm' => $dariOm];
        }

        $adaPolutan = array_intersect(array_keys($sensorSegar), self::POLUTAN_KEYS) !== [];
        foreach ($om as $tipe => $v) {
            $polutan = in_array($tipe, self::POLUTAN_KEYS, true);
            $perluIsi = $polutan ? ! $adaPolutan : ! isset($sensorSegar[$tipe]);
            if ($perluIsi) {
                $nilai[$tipe] = $v;
                $dariOm[] = $tipe;
            }
        }

        return ['nilai' => $nilai, 'dariOm' => $dariOm];
    }

    /**
     * Panggilan langsung (tanpa cache, tanpa melihat OPENMETEO_MODE) — dipakai penyimpanan Database per jam (engine:hourly).
     *
     * @return array{values: array<string,float>, at: string}|null
     */
    public static function ambil(float $lat, float $lng): ?array
    {
        $timeout = max(1, (int) config('openmeteo.timeout_sec', 6));
        $values = [];
        $at = null;

        try {
            $air = Http::timeout($timeout)->acceptJson()->get(config('openmeteo.air_url'), [
                'latitude' => $lat,
                'longitude' => $lng,
                'current' => implode(',', array_keys(self::POLUTAN_AIR)),
                'timezone' => 'GMT',
            ]);
            if ($air->successful()) {
                $cur = (array) $air->json('current', []);
                foreach (self::POLUTAN_AIR as $src => $dst) {
                    if (isset($cur[$src]) && is_numeric($cur[$src])) {
                        $values[$dst] = (float) $cur[$src];
                    }
                }
                $at = $cur['time'] ?? null;
            }
        } catch (Throwable) {
            // dibiarkan: jatuh ke sensor / template
        }

        try {
            $w = Http::timeout($timeout)->acceptJson()->get(config('openmeteo.weather_url'), [
                'latitude' => $lat,
                'longitude' => $lng,
                'current' => 'temperature_2m',
                'timezone' => 'GMT',
            ]);
            if ($w->successful() && is_numeric($w->json('current.temperature_2m'))) {
                $values[SensorReadingModel::TYPE_TEMPERATURE] = (float) $w->json('current.temperature_2m');
                $at ??= $w->json('current.time');
            }
        } catch (Throwable) {
        }

        if ($values === []) {
            return null;
        }

        return ['values' => $values, 'at' => Carbon::parse($at ?? now(), 'UTC')->toIso8601String()];
    }
}

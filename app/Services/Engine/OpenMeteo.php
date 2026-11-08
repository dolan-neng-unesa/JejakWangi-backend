<?php

namespace App\Services\Engine;

use App\Models\KoridorModel;
use App\Models\SensorDeviceModel;
use App\Models\SensorReadingModel;
use App\Services\OpenMeteoAir;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Sumber data "API Open-Meteo": polutan kawasan (PM10, PM2.5) → ISPU kawasan, dan suhu kawasan → selisih suhu.
 * Hasil disimpan sebagai bacaan sensor dari perangkat virtual `open-meteo` (kunci API tidak diperlukan).
 * Bila API tidak terjangkau, `siapkanPerangkat()` + `seed()` mengisi data pengganti yang ditandai jelas.
 */
class OpenMeteo
{
    public const DEVICE = 'open-meteo';
    public const DEVICE_SEED = 'seed-kawasan';

    /** Ambil data terkini untuk titik tengah tiap koridor. @return int jumlah baris tersimpan */
    public function tarik(?Carbon $at = null): int
    {
        $at = ($at ?? now())->copy()->startOfHour();
        $rows = [];

        foreach (KoridorModel::orderBy('id')->get() as $k) {
            [$lat, $lng] = $this->titik($k);
            if ($lat === null) {
                continue;
            }
            // Satu klien untuk seluruh sistem: OpenMeteoAir (URL/timeout di config/openmeteo.php).
            $hasil = OpenMeteoAir::ambil((float) $lat, (float) $lng);
            if (! $hasil) {
                Log::warning('Open-Meteo gagal', ['koridor' => $k->id]);

                continue;
            }
            foreach ($hasil['values'] as $type => $val) {
                // Suhu model = suhu KAWASAN (area terbuka) → pembanding sensor kanopi untuk selisih suhu.
                $tipeSimpan = $type === SensorReadingModel::TYPE_TEMPERATURE ? SensorReadingModel::TYPE_TEMPERATURE_OPEN : $type;
                $rows[] = ['device_code' => self::DEVICE, 'koridor_id' => (string) $k->id, 'sensor_type' => $tipeSimpan, 'value' => (float) $val, 'recorded_at' => $at];
            }
        }
        if ($rows) {
            SensorReadingModel::insert($rows);
        }

        return count($rows);
    }

    /**
     * Data pengganti (seder) saat data nyata belum ada — pola harian realistis: PM naik saat jam sibuk, suhu puncak siang.
     * Ditandai device_code `seed-kawasan` sehingga bisa dibedakan dari data nyata (lihat `sumber` di hourly_metrics).
     */
    public function seed(Carbon $jam, KoridorModel $k, int $variasi = 0): int
    {
        $h = ($jam->copy()->setTimezone(config('jejakwangi.timezone')))->hour;
        $sibuk = in_array($h, [7, 8, 9, 16, 17, 18], true) ? 1 : 0;
        $dasar = 12 + ($k->id % 4) * 4 - ($k->shade_score ?? 50) / 25;
        $pm25 = round(max(4, $dasar + $sibuk * 10 + (($variasi * 7 + $h * 3) % 6)), 1);
        $pm10 = round($pm25 * 1.8 + 4, 1);
        $suhuTerbuka = round(25 + 7 * sin(max(0, $h - 6) / 12 * M_PI) + ($variasi % 3) * 0.2, 1);
        $rows = [];
        foreach ([
            SensorReadingModel::TYPE_PM25 => $pm25,
            SensorReadingModel::TYPE_PM10 => $pm10,
            SensorReadingModel::TYPE_TEMPERATURE_OPEN => $suhuTerbuka,
        ] as $type => $val) {
            $rows[] = ['device_code' => self::DEVICE_SEED, 'koridor_id' => (string) $k->id, 'sensor_type' => $type, 'value' => $val, 'recorded_at' => $jam->copy()->addMinutes(10)];
        }
        SensorReadingModel::insert($rows);

        return count($rows);
    }

    private function titik(KoridorModel $k): array
    {
        $path = (array) $k->path;
        if (! $path) {
            return [null, null];
        }
        $lat = array_sum(array_column($path, 0)) / count($path);
        $lng = array_sum(array_column($path, 1)) / count($path);

        return [round($lat, 4), round($lng, 4)];
    }

    /** Daftarkan perangkat virtual agar muncul di Sensor IoT (idempoten). */
    public static function siapkanPerangkat(): void
    {
        foreach ([self::DEVICE => 'Open-Meteo (API kualitas udara & cuaca)', self::DEVICE_SEED => 'Data pengganti kawasan (seder)'] as $kode => $nama) {
            SensorDeviceModel::firstOrCreate(['device_code' => $kode], [
                'key_hash' => SensorDeviceModel::hashKey(bin2hex(random_bytes(16))), // tak pernah dipakai: perangkat server-side
                'koridor_id' => null,
                'is_active' => true,
                'name' => $nama,
                'last_seen_at' => now(),
            ]);
        }
    }
}

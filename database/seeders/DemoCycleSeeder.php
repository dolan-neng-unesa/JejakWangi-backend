<?php

namespace Database\Seeders;

use App\Models\FestivalModel;
use App\Models\KoridorModel;
use App\Models\QrPoint;
use App\Models\SensorReadingModel;
use App\Models\UserModel;
use App\Models\WasteWeighingModel;
use App\Services\Engine\HourlyAggregator;
use App\Services\Engine\OpenMeteo;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Data SEDER untuk menguji satu siklus penuh saat data nyata belum ada (arahan mentor).
 * Semua perangkat seder berkode `seed-*` sehingga terlihat jelas bedanya dari sensor nyata
 * (halaman Sensor IoT & status sumber data menandainya "seder"). Aman dijalankan berulang.
 *
 *   php artisan db:seed --class=DemoCycleSeeder
 */
class DemoCycleSeeder extends Seeder
{
    public function run(int $jam = 48): void
    {
        if (KoridorModel::count() === 0) {
            $this->call([GreenSpaceSeeder::class, KoridorSeeder::class]);
        }
        OpenMeteo::siapkanPerangkat();
        $meteo = app(OpenMeteo::class);
        $koridors = KoridorModel::orderBy('id')->get();

        // Festival hari ini (Kalender Banyuwangi Festival → "hari festival").
        FestivalModel::firstOrCreate(['name' => 'Festival Kuliner Sritanjung (seder)', 'event_date' => now(config('jejakwangi.timezone'))->toDateString()], [
            'location_type' => 'koridor', 'location_name' => 'Taman Sritanjung', 'category' => 'kuliner', 'start_time' => '16:00', 'end_time' => '21:00', 'is_active' => true,
        ]);

        // Titik QR: kantong parkir, lapak, tujuan kedua (koordinat dari jalur koridor).
        $titik = function (string $kode, string $nama, string $tipe, int $kid, int $idx) {
            $k = KoridorModel::find($kid);
            $path = (array) ($k?->path ?? []);
            $p = $path[min($idx, max(0, count($path) - 1))] ?? [-8.2192, 114.3691];
            QrPoint::updateOrCreate(['code' => $kode], ['name' => $nama, 'type' => $tipe, 'koridor_id' => $kid, 'lat' => $p[0], 'lng' => $p[1], 'is_active' => true]);
        };
        $titik('PRK-SRI', 'Kantong parkir Taman Sritanjung', 'parkir', 1, 0);
        $titik('PRK-BLB', 'Kantong parkir Taman Blambangan', 'parkir', 4, 0);
        $titik('PRK-PSR', 'Kantong parkir Pasar Banyuwangi', 'parkir', 7, 0);
        $titik('LPK-PSR-01', 'Lapak Bu Sari (Pasar)', 'lapak', 1, 0);
        $titik('LPK-PSR-02', 'Lapak Pak Wayan (Pasar)', 'lapak', 1, 1);
        $titik('TJN-SRI', 'Taman Sritanjung (tujuan)', 'tujuan', 1, 3);

        // Sensor seder per jam.
        $awal = Carbon::now()->utc()->startOfHour()->subHours($jam);
        $solarKor = [1, 4, 7];
        $rows = [];
        for ($i = 0; $i < $jam; $i++) {
            $t = $awal->copy()->addHours($i);
            $h = $t->copy()->setTimezone(config('jejakwangi.timezone'))->hour;
            foreach ($koridors as $k) {
                $teduh = ($k->shade_score ?? 50) / 100;
                $kanopi = round(24.5 + 6 * sin(max(0, $h - 6) / 12 * M_PI) - $teduh * 2.5 + ($i % 3) * 0.1, 1);
                $rows[] = ['device_code' => 'seed-suhu', 'koridor_id' => (string) $k->id, 'sensor_type' => 'temperature', 'value' => $kanopi, 'recorded_at' => $t->copy()->addMinutes(12)];
                $meteo->seed($t, $k, $i);
                if (in_array($k->id, $solarKor, true)) {
                    $w = ($h >= 6 && $h <= 17) ? round(95 * sin(($h - 6 + 0.5) / 12 * M_PI), 1) : 0;
                    $rows[] = ['device_code' => 'seed-solar', 'koridor_id' => (string) $k->id, 'sensor_type' => 'solar_w', 'value' => $w, 'recorded_at' => $t->copy()->addMinutes(20)];
                }
                if ($k->id === 7) { // kamera YOLO koridor pasar
                    $ramai = in_array($h, [6, 7, 8, 16, 17], true) ? 1.0 : (($h >= 9 && $h <= 15) ? 0.5 : 0.15);
                    $rows[] = ['device_code' => 'seed-yolo', 'koridor_id' => '7', 'sensor_type' => 'vehicle_motor', 'value' => (int) round(260 * $ramai), 'recorded_at' => $t->copy()->addMinutes(30)];
                    $rows[] = ['device_code' => 'seed-yolo', 'koridor_id' => '7', 'sensor_type' => 'vehicle_car', 'value' => (int) round(40 * $ramai), 'recorded_at' => $t->copy()->addMinutes(30)];
                }
            }
        }
        foreach (array_chunk($rows, 500) as $c) {
            SensorReadingModel::insert($c);
        }

        // Timbang sisa pangan & sampah pasar (sudah dikonfirmasi) → "Kg".
        $warga = UserModel::firstOrCreate(['email' => 'warga@jejakwangi.id'], ['name' => 'Warga Demo', 'password' => 'jejakwangi123']);
        foreach ([['pangan', 'sayur_buah', 12.5, 'pangan_kg'], ['pangan', 'nasi_lauk', 6.0, 'pangan_kg'], ['sampah', 'botol_plastik', 8.0, 'anorganik_kg'], ['sampah', 'sisa_sayur', 30.0, 'organik_kg']] as $n => [$kind, $jenis, $kg, $kol]) {
            WasteWeighingModel::firstOrCreate(['code' => 'SDR-'.$kind.$n], [
                'kind' => $kind, 'station_code' => 'BSS-003', 'station_name' => 'Pasar Banyuwangi', 'depositor_type' => 'pengguna', 'depositor_name' => 'Seder',
                'user_id' => $warga->id, 'items' => [['jenis' => $jenis, 'kg' => $kg]], 'total_kg' => $kg, $kol => $kg,
                'status' => 'terkonfirmasi', 'weighed_at' => now()->subHours(3 + $n), 'confirmed_at' => now()->subHours(2 + $n),
            ]);
        }

        app(HourlyAggregator::class)->rentang($awal, Carbon::now()->utc()->startOfHour()->addHour());
    }
}

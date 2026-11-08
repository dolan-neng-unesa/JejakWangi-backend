<?php

namespace App\Console\Commands;

use App\Models\AdminNotificationModel;
use App\Models\AppSettingModel;
use App\Models\KoridorModel;
use App\Models\SensorDeviceModel;
use App\Models\SensorReadingModel;
use Illuminate\Console\Command;

/**
 * Dijadwalkan tiap 5 menit (routes/console.php). Mencatat ke notifikasi admin:
 *  - sensor aktif yang berhenti mengirim data (offline),
 *  - AQI > 100 (Tidak Sehat) pada bacaan terbaru.
 * Peringatan yang sama tidak diulang dalam jendela waktu tertentu.
 */
class MonitorSystem extends Command
{
    protected $signature = 'jejakwangi:monitor';

    protected $description = 'Cek sensor offline & AQI tinggi, lalu catat ke notifikasi admin.';

    public function handle(): int
    {
        $n = $this->checkOffline() + $this->checkAirQuality();
        $this->info("{$n} peringatan baru dicatat.");

        return self::SUCCESS;
    }

    private function recentlyNotified(string $category, string $title, int $hours): bool
    {
        return AdminNotificationModel::where('category', $category)->where('title', $title)
            ->where('created_at', '>=', now()->subHours($hours))->exists();
    }

    private function checkOffline(): int
    {
        $minutes = max(1, (int) AppSettingModel::get('sensor.offline_minutes', 5));
        $created = 0;

        $devices = SensorDeviceModel::where('is_active', true)->where('is_maintenance', false)->whereNotIn('device_code', [\App\Services\Engine\OpenMeteo::DEVICE, \App\Services\Engine\OpenMeteo::DEVICE_SEED])->whereNotNull('last_seen_at')->get();
        foreach ($devices as $d) {
            if ($d->last_seen_at->gt(now()->subMinutes($minutes))) {
                continue;
            }
            $title = "Sensor IoT {$d->device_code} Offline";
            if ($this->recentlyNotified('sensor', $title, 6)) {
                continue;
            }
            AdminNotificationModel::notify('sensor', $title, 'Tidak ada data masuk sejak ' . $d->last_seen_at->format('d/m H:i') . '. Periksa daya dan koneksi perangkat.');
            $created++;
        }

        return $created;
    }

    private function checkAirQuality(): int
    {
        $created = 0;
        $rows = SensorReadingModel::where('sensor_type', SensorReadingModel::TYPE_AIR_QUALITY)
            ->where('recorded_at', '>=', now()->subMinutes(10))
            ->orderByDesc('recorded_at')->get()->unique('koridor_id');

        foreach ($rows as $r) {
            if ($r->value <= 100) {
                continue;
            }
            $name = $r->koridor_id ? (KoridorModel::find($r->koridor_id)?->formal_name ?? "Koridor {$r->koridor_id}") : 'Semua koridor';
            $title = "Peringatan Kualitas Udara {$name}";
            if ($this->recentlyNotified('lingkungan', $title, 3)) {
                continue;
            }
            AdminNotificationModel::notify('lingkungan', $title, 'AQI mencapai ' . round($r->value) . ' (Tidak Sehat). Imbau kelompok sensitif membatasi aktivitas luar ruang.');
            $created++;
        }

        return $created;
    }
}

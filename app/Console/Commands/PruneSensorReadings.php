<?php

namespace App\Console\Commands;

use App\Models\AppSettingModel;
use App\Models\SensorReadingModel;
use Illuminate\Console\Command;

class PruneSensorReadings extends Command
{
    protected $signature = 'sensors:prune {--days= : simpan bacaan sekian hari terakhir (default: pengaturan sensor.retention_days)}';

    protected $description = 'Hapus bacaan sensor yang lebih lama dari N hari.';

    public function handle(): int
    {
        $days = max(1, (int) ($this->option('days') ?: AppSettingModel::get('sensor.retention_days', 30)));
        $total = 0;

        // Hapus per potongan supaya tidak mengunci tabel lama di SQLite/MySQL.
        do {
            $deleted = SensorReadingModel::where('recorded_at', '<', now()->subDays($days))->limit(5000)->delete();
            $total += $deleted;
        } while ($deleted === 5000);

        $this->info("{$total} bacaan lebih lama dari {$days} hari dihapus.");

        return self::SUCCESS;
    }
}

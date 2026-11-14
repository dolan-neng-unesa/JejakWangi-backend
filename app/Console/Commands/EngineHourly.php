<?php

namespace App\Console\Commands;

use App\Services\Engine\HourlyAggregator;
use App\Services\Engine\OpenMeteo;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Dijadwalkan tiap jam: tarik Open-Meteo lalu tulis baris Database per jam untuk jam yang baru lewat.
 *   php artisan engine:hourly                      jam lalu (+ tarik Open-Meteo)
 *   php artisan engine:hourly --dari=2026-11-10 --sampai=2026-11-14   isi ulang rentang (tanpa tarik API)
 */
class EngineHourly extends Command
{
    protected $signature = 'engine:hourly {--dari= : tanggal awal (YYYY-MM-DD, zona Jakarta)} {--sampai= : tanggal akhir inklusif} {--tanpa-api : lewati Open-Meteo}';

    protected $description = 'Hitung Database per jam (neraca karbon, ISPU, selisih suhu) dari semua sumber data.';

    public function handle(HourlyAggregator $agg, OpenMeteo $meteo): int
    {
        $tz = config('jejakwangi.timezone');
        if ($dari = $this->option('dari')) {
            $d = Carbon::parse($dari, $tz)->startOfDay()->utc();
            $s = Carbon::parse($this->option('sampai') ?: $dari, $tz)->endOfDay()->utc();
            $n = $agg->rentang($d, $s);
            $this->info("{$n} baris per-jam dihitung.");

            return self::SUCCESS;
        }

        if (! $this->option('tanpa-api')) {
            OpenMeteo::siapkanPerangkat();
            $this->info($meteo->tarik(now()->subHour()).' bacaan Open-Meteo disimpan.');
            \App\Models\SensorDeviceModel::whereIn('device_code', [OpenMeteo::DEVICE])->update(['last_seen_at' => now()]);
        }
        $n = $agg->jalankan(now()->subHour())->count();
        $this->info("{$n} baris per-jam dihitung.");

        return self::SUCCESS;
    }
}

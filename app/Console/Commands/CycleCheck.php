<?php

namespace App\Console\Commands;

use App\Models\HourlyMetric;
use App\Models\QrPoint;
use App\Services\Engine\Baseline;
use App\Services\Engine\GreenEconomy;
use App\Services\Engine\Neraca;
use Database\Seeders\DemoCycleSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Cek satu siklus data (arahan mentor): isi data seder bila belum ada, lalu tampilkan hasil tiap tahap flowchart.
 * Pengujian alur API lengkap (QR → check-in → usulan → keputusan) ada di tests/Feature/FullCycleTest.php:
 *   php artisan jejakwangi:cycle-check            # seed (bila kosong) + ringkasan
 *   php artisan jejakwangi:cycle-check --seed     # paksa isi ulang seder 48 jam
 *   php artisan test --filter=FullCycleTest       # uji alur penuh
 */
class CycleCheck extends Command
{
    protected $signature = 'jejakwangi:cycle-check {--seed : isi ulang data seder 48 jam}';

    protected $description = 'Seed data seder dan ringkas tiap tahap siklus: sumber → per jam → neraca → rapor → ekonomi.';

    public function handle(): int
    {
        if ($this->option('seed') || HourlyMetric::count() === 0) {
            (new DemoCycleSeeder())->run();
            $this->info('Data seder terisi (perangkat seed-*).');
        }
        $this->line('1. Database per jam : '.HourlyMetric::count().' baris, '.HourlyMetric::distinct('koridor_id')->count('koridor_id').' koridor');
        $this->line('2. Titik QR         : '.QrPoint::count().' titik (parkir/lapak/tujuan)');
        $r = Neraca::ringkas(null, Carbon::now()->subDays(7), Carbon::now());
        $this->line(sprintf('3. Neraca 7 hari    : serapan %s kg, emisi %s kg, terhindar %s kg, neraca %s kg', $r['serapan_kg'], $r['emisi_total_kg'], $r['terhindar_kg'], $r['neraca_kg']));
        $this->line(sprintf('   surya %s kWh · ISPU rata-rata %s · selisih suhu %s °C', $r['surya_kwh'], $r['ispu_rata'] ?? '-', $r['selisih_suhu_rata'] ?? '-'));
        $this->table(['Prioritas', 'Koridor', 'Rasio %', 'Grade', 'Sumber'], collect(Baseline::rapor(7)['koridor'])->map(fn ($k) => [$k['prioritas'], $k['koridor'], $k['rasio_persen'] ?? '-', $k['grade'], $k['sumber']])->all());
        $e = GreenEconomy::hitung(null, Carbon::now()->subDays(30), Carbon::now());
        $this->line('4. Ekonomi hijau 30 hari: Rp '.number_format($e['total_rupiah'], 0, ',', '.'));
        $this->info('Lanjut: POST /api/admin/asisten/laporan → setujui/tolak usulan (lihat FullCycleTest).');

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Models\SensorDeviceModel;
use Illuminate\Console\Command;

class RegisterDevice extends Command
{
    protected $signature = 'devices:register {device_code : mis. ESP32-KOR1-DIORAMA} {--koridor= : id koridor tempat perangkat dipasang} {--disable : nonaktifkan perangkat}';

    protected $description = 'Daftarkan ESP32 (atau putar ulang kunci API-nya). Kunci hanya ditampilkan sekali.';

    public function handle(): int
    {
        $code = $this->argument('device_code');

        if ($this->option('disable')) {
            $n = SensorDeviceModel::where('device_code', $code)->update(['is_active' => false]);
            $this->info($n ? "Perangkat {$code} dinonaktifkan." : "Perangkat {$code} tidak ditemukan.");

            return $n ? self::SUCCESS : self::FAILURE;
        }

        $plain = SensorDeviceModel::newPlainKey();
        $existing = SensorDeviceModel::where('device_code', $code)->first();

        SensorDeviceModel::updateOrCreate(
            ['device_code' => $code],
            [
                'key_hash' => SensorDeviceModel::hashKey($plain),
                'koridor_id' => $this->option('koridor') ?? $existing?->koridor_id,
                'is_active' => true,
            ],
        );

        $this->info(($existing ? 'Kunci diputar ulang' : 'Perangkat terdaftar') . ": {$code}");
        $this->line("X-Device-Key: {$plain}");
        $this->warn('Simpan sekarang — kunci tidak bisa ditampilkan lagi.');

        return self::SUCCESS;
    }
}

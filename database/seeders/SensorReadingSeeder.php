<?php

namespace Database\Seeders;

use App\Models\SensorReadingModel;
use Illuminate\Database\Seeder;

// Bacaan contoh (2 jam lalu → tampil sebagai "data lama") supaya
// GET /api/sensors/snapshot tidak kosong sebelum ESP32 pertama kali mengirim.
class SensorReadingSeeder extends Seeder
{
    public function run(): void
    {
        SensorReadingModel::query()->delete(); // idempotent

        $values = [
            SensorReadingModel::TYPE_TEMPERATURE => 29.4,
            SensorReadingModel::TYPE_AIR_QUALITY => 42,
            SensorReadingModel::TYPE_PM10 => 82,
            SensorReadingModel::TYPE_SO2 => 21,
            SensorReadingModel::TYPE_CO => 2400,
            SensorReadingModel::TYPE_O3 => 140,
            SensorReadingModel::TYPE_NO2 => 46,
        ];

        $at = now()->subHours(2);
        SensorReadingModel::insert(array_map(fn ($type, $value) => [
            'device_code' => 'ESP32-KOR1-DIORAMA',
            'koridor_id' => '1',
            'sensor_type' => $type,
            'value' => $value,
            'recorded_at' => $at,
        ], array_keys($values), $values));
    }
}

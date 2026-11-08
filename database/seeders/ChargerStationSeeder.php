<?php

namespace Database\Seeders;

use App\Models\ChargerStationModel;
use Illuminate\Database\Seeder;

class ChargerStationSeeder extends Seeder
{
    public function run(): void
    {
        ChargerStationModel::query()->delete(); // idempoten

        // Koordinat perkiraan di sekitar Taman Sritanjung–Blambangan (belum hasil survei lapangan).
        $rows = [
            ['Charger Station 1', 'Taman Sritanjung, Banyuwangi', 1, -8.2186, 114.3697, 4.0, 80, 4, 3],
            ['Charger Station 2', 'Jalur pedestrian Jl. Ahmad Yani', 1, -8.2174, 114.3711, 2.0, 74, 4, 4],
            ['Charger Station 3', 'Taman Blambangan', 2, -8.2203, 114.3664, 4.0, 90, 4, 2],
            ['Charger Station 4', 'Kawasan Pantai Boom', 5, -8.2077, 114.3893, 2.0, 68, 2, 2],
        ];
        foreach ($rows as [$name, $loc, $kor, $lat, $lng, $kwp, $bat, $total, $avail]) {
            ChargerStationModel::create([
                'name' => $name, 'location' => $loc, 'koridor_id' => $kor, 'latitude' => $lat, 'longitude' => $lng,
                'solar_kwp' => $kwp, 'battery_percent' => $bat, 'ports_total' => $total, 'ports_available' => $avail,
                'opening_hours' => '24 jam', 'is_active' => true,
            ]);
        }
    }
}

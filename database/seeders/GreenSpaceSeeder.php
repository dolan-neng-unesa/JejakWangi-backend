<?php

namespace Database\Seeders;

use App\Models\GreenSpaceModel;
use Illuminate\Database\Seeder;

// Data disamakan dengan greenSpaces[] di jejak-wangi-app/src/data/mockGreenSpaces.js.
class GreenSpaceSeeder extends Seeder
{
    public function run(): void
    {
        GreenSpaceModel::query()->delete();

        GreenSpaceModel::insert([
            [
                'id' => 1,
                'name' => 'Taman Sritanjung',
                'location_type' => 'taman',
                'description' => 'Pusat aktivitas sosial aktif di jantung Kabupaten Banyuwangi.',
                'latitude' => -8.21944,
                'longitude' => 114.36972,
                'address' => 'Jl. Jaksa Agung Suprapto, Banyuwangi',
                'shade_score' => 62,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 2,
                'name' => 'Taman Blambangan',
                'location_type' => 'taman',
                'description' => 'Kawasan olahraga dan panggung festival pariwisata daerah.',
                'latitude' => -8.2175,
                'longitude' => 114.3675,
                'address' => 'Jl. Ahmad Yani, Banyuwangi',
                'shade_score' => 58,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
}

<?php

namespace Database\Seeders;

use App\Models\GeofenceModel;
use Illuminate\Database\Seeder;

// Data disamakan dengan geofences[] di jejak-wangi-app/src/data/mockContent.js
// supaya perilaku FE tidak berubah saat pindah dari mock ke API ini.
class GeofenceSeeder extends Seeder
{
    public function run(): void
    {
        GeofenceModel::query()->delete();

        GeofenceModel::insert([
            [
                'name' => 'Taman Blambangan',
                'latitude' => -8.2175,
                'longitude' => 114.3675,
                'radius_meter' => 60,
                'is_active' => true,
                'soundscape_id' => 3,
                'welcome_title' => 'Selamat datang di Taman Blambangan!',
                'welcome_desc' => 'Nikmati suasana hijau dan musik relaksasi untuk membantu fokusmu.',
                'koridor_id' => 4,
                'background_image' => 'images/geofencing/zone-bg-blambangan.png',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Kawasan Pantai Boom',
                'latitude' => -8.2298,
                'longitude' => 114.3822,
                'radius_meter' => 80,
                'is_active' => true,
                'soundscape_id' => 3,
                'welcome_title' => 'Selamat datang di Kawasan Pantai Boom!',
                'welcome_desc' => 'Angin laut sejuk dan suara ombak menemani langkahmu di sini.',
                'koridor_id' => 6,
                'background_image' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Alun-Alun Banyuwangi',
                'latitude' => -8.2145,
                'longitude' => 114.3691,
                'radius_meter' => 70,
                'is_active' => true,
                'soundscape_id' => 1,
                'welcome_title' => 'Selamat datang di Alun-Alun Banyuwangi!',
                'welcome_desc' => 'Dengarkan gending Using sambil bersantai di pusat kota.',
                'koridor_id' => null,
                'background_image' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
}

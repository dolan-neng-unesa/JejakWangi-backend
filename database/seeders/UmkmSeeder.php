<?php

namespace Database\Seeders;

use App\Models\UmkmModel;
use Illuminate\Database\Seeder;

// Data disamakan persis dengan src/data/mockContent.js di jejak-wangi-app,
// supaya tampilan tidak berubah saat React pindah dari data mock ke API ini.
class UmkmSeeder extends Seeder
{
    public function run(): void
    {
        UmkmModel::query()->delete(); // aman dijalankan berkali-kali (idempotent)

        UmkmModel::insert([
            [
                'business_name' => 'Warung Bu Sari',
                'latitude' => -8.219,
                'longitude' => 114.3702,
                'business_type' => 'Makanan',
                'address' => 'Sekitar Taman Sritanjung',
                'koridor_id' => '1',
                'distance_m' => 120,
                'rating' => 4.8,
                'image_url' => 'images/umkm/umkm-warungbusari.png',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'business_name' => 'Es Dawet Mbak Tini',
                'latitude' => -8.2178,
                'longitude' => 114.3688,
                'business_type' => 'Minuman Tradisional',
                'address' => 'Sekitar Taman Sritanjung',
                'koridor_id' => '1',
                'distance_m' => 200,
                'rating' => 4.6,
                'image_url' => 'images/umkm/umkm-esdawet.png',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'business_name' => 'Rujak Soto Bu Ida',
                'latitude' => -8.2172,
                'longitude' => 114.3668,
                'business_type' => 'Makanan',
                'address' => 'Sekitar Taman Blambangan',
                'koridor_id' => '4',
                'distance_m' => 150,
                'rating' => 4.7,
                'image_url' => 'images/umkm/umkm-warungbusari.png',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'business_name' => 'Batik Gajah Oling Corner',
                'latitude' => -8.2183,
                'longitude' => 114.3693,
                'business_type' => 'Kerajinan',
                'address' => 'Koridor 1',
                'koridor_id' => '1',
                'distance_m' => 90,
                'rating' => 4.5,
                'image_url' => null, // belum ada foto (file umkm-batik.png tidak pernah ada) — FE menampilkan placeholder
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
}

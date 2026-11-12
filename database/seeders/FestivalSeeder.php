<?php

namespace Database\Seeders;

use App\Models\FestivalModel;
use Illuminate\Database\Seeder;

// Data disamakan persis dengan src/data/mockContent.js (bfestEvents) di jejak-wangi-app.
class FestivalSeeder extends Seeder
{
    public function run(): void
    {
        FestivalModel::query()->delete(); // aman dijalankan berkali-kali (idempotent)

        FestivalModel::insert([
            [
                'name' => 'Banyuwangi Ethno Carnival',
                'location_type' => 'panggung',
                'location_name' => 'Taman Blambangan',
                'category' => 'budaya',
                'address' => 'Jl. Veteran – Taman Blambangan',
                'event_date' => '2026-09-12',
                'start_time' => '09:00',
                'end_time' => '22:00',
                'description' => 'Event kolosal menampilkan ratusan peraga busana kontemporer berbasis budaya adat yang ramah lingkungan.',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Festival Gandrung Sewu',
                'location_type' => 'panggung',
                'location_name' => 'Pantai Boom',
                'category' => 'seni',
                'address' => 'Pantai Boom',
                'event_date' => '2026-09-24',
                'start_time' => '19:00',
                'end_time' => '23:00',
                'description' => 'Seribu penari Gandrung tampil serentak di tepi pantai, dipadukan dengan tata cahaya dan musik tradisional Using.',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Festival Kuwung',
                'location_type' => 'panggung',
                'location_name' => 'Taman Blambangan',
                'category' => 'pariwisata',
                'address' => 'Taman Blambangan',
                'event_date' => '2026-10-03',
                'start_time' => '16:00',
                'end_time' => '21:00',
                'description' => 'Pameran kerajinan, kuliner, dan potensi wisata dari seluruh kecamatan di Banyuwangi.',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
}

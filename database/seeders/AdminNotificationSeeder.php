<?php

namespace Database\Seeders;

use App\Models\AdminNotificationModel;
use Illuminate\Database\Seeder;

// Contoh notifikasi yang cocok dengan data lain yang sudah di-seed
// (festival, sensor) — bukan sekadar teks acak, supaya konsisten kalau
// diperiksa silang dengan halaman Kalender BWI / Sensor IoT.
class AdminNotificationSeeder extends Seeder
{
    public function run(): void
    {
        AdminNotificationModel::query()->delete();

        $now = now();

        AdminNotificationModel::insert([
            [
                'category' => 'festival',
                'title' => 'Event Festival Baru Ditambahkan',
                'description' => 'Banyuwangi Ethno Carnival dijadwalkan 12 September 2026 di Taman Blambangan.',
                'is_read' => false,
                'created_at' => $now->copy()->subHours(3),
                'updated_at' => $now->copy()->subHours(3),
            ],
            [
                'category' => 'sensor',
                'title' => 'Sensor IoT Mengirim Data Pertama',
                'description' => 'Perangkat ESP32-KOR1-DIORAMA mulai mengirim pembacaan suhu & kualitas udara.',
                'is_read' => false,
                'created_at' => $now->copy()->subHours(6),
                'updated_at' => $now->copy()->subHours(6),
            ],
            [
                'category' => 'mood',
                'title' => 'Laporan Mood Mingguan Selesai',
                'description' => 'Rekap mood 7 hari terakhir sudah bisa dilihat di halaman Mood & Wellbeing.',
                'is_read' => true,
                'created_at' => $now->copy()->subDay(),
                'updated_at' => $now->copy()->subDay(),
            ],
            [
                'category' => 'pengguna',
                'title' => 'Akun Admin Default Dibuat',
                'description' => 'Akun admin@jejakwangi.id dibuat lewat AdminUserSeeder untuk kebutuhan login dashboard.',
                'is_read' => true,
                'created_at' => $now->copy()->subDays(2),
                'updated_at' => $now->copy()->subDays(2),
            ],
        ]);
    }
}

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Index tambahan untuk query yang benar-benar dipakai controller:
//  - sensor_readings (sensor_type, recorded_at): snapshot "semua koridor" & MAX(recorded_at)
//    per jenis tanpa filter koridor — index lama (koridor_id, sensor_type, recorded_at)
//    tidak bisa dipakai karena kolom pertamanya tidak difilter.
//  - sensor_readings (device_code, sensor_type, recorded_at): bacaan terakhir per perangkat
//    di halaman Sensor IoT admin.
//  - mood_logs (logged_at): ringkasan mood se-kota (tanpa green_space_id).
//  - festival_models / umkm_models: filter is_active + urutan tanggal / koridor.
//  - users (created_at), admin_notifications (created_at, is_read): daftar terurut & hitung badge.
return new class extends Migration
{
    /** [tabel => [nama_index => kolom[]]] */
    private function indexes(): array
    {
        return [
            'sensor_readings' => [
                'sensor_readings_type_time_idx' => ['sensor_type', 'recorded_at'],
                'sensor_readings_device_type_time_idx' => ['device_code', 'sensor_type', 'recorded_at'],
            ],
            'mood_logs' => ['mood_logs_logged_at_idx' => ['logged_at']],
            'festival_models' => ['festival_models_active_date_idx' => ['is_active', 'event_date']],
            'umkm_models' => ['umkm_models_active_koridor_idx' => ['is_active', 'koridor_id']],
            'users' => ['users_created_at_idx' => ['created_at']],
            'admin_notifications' => [
                'admin_notifications_created_at_idx' => ['created_at'],
                'admin_notifications_is_read_idx' => ['is_read'],
            ],
        ];
    }

    public function up(): void
    {
        foreach ($this->indexes() as $table => $indexes) {
            if (!Schema::hasTable($table)) {
                continue;
            }
            foreach ($indexes as $name => $columns) {
                if (!Schema::hasIndex($table, $name)) {
                    Schema::table($table, fn (Blueprint $t) => $t->index($columns, $name));
                }
            }
        }
    }

    public function down(): void
    {
        foreach ($this->indexes() as $table => $indexes) {
            if (!Schema::hasTable($table)) {
                continue;
            }
            foreach (array_keys($indexes) as $name) {
                if (Schema::hasIndex($table, $name)) {
                    Schema::table($table, fn (Blueprint $t) => $t->dropIndex($name));
                }
            }
        }
    }
};

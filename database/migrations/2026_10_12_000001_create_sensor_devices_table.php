<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Daftar perangkat ESP32 yang boleh mengirim data. Kunci API hanya disimpan
// dalam bentuk hash (sha256) — kunci asli ditampilkan sekali saat
// `php artisan devices:register`.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sensor_devices', function (Blueprint $table) {
            $table->id();
            $table->string('device_code', 100)->unique(); // contoh: ESP32-KOR1-DIORAMA
            $table->string('koridor_id', 50)->nullable();
            $table->char('key_hash', 64)->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sensor_devices');
    }
};

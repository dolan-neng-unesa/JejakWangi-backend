<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Satu baris = satu bacaan satu jenis sensor. ESP32 mengirim banyak jenis
// sekaligus dalam satu request; controller memecahnya jadi beberapa baris.
// Unit tidak disimpan (diturunkan dari sensor_type), dan tidak ada
// created_at/updated_at — recorded_at sudah cukup, tabel ini append-only.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sensor_readings', function (Blueprint $table) {
            $table->id();
            $table->string('device_code', 100);
            $table->string('koridor_id', 50)->nullable();
            $table->string('sensor_type', 30);
            $table->decimal('value', 10, 2);
            $table->timestamp('recorded_at');

            // Query utama: "bacaan terakhir jenis X di koridor Y" + tren harian + prune.
            $table->index(['koridor_id', 'sensor_type', 'recorded_at']);
            $table->index('recorded_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sensor_readings');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Menggantikan koleksi Firestore `koridor` — lihat catatan lengkap di
// migration create_green_spaces_table. Gambar (thumbnail/hero_image)
// disimpan sebagai path relatif ke public/images/koridor/ (lihat
// KoridorResource untuk cara jadi URL absolut), bukan lagi hasil bundel
// Vite seperti di mock data React.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('koridors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('green_space_id')->nullable()->constrained('green_spaces')->nullOnDelete();
            $table->string('short_name');
            $table->string('formal_name');
            $table->string('route_label')->nullable();
            $table->string('desc')->nullable();
            $table->string('thumbnail')->nullable(); // path relatif, mis. "images/koridor/thumb-sritanjung.png"
            $table->string('hero_image')->nullable();
            $table->string('hijau_level'); // 'paling_hijau' | 'agak_hijau' | 'setengah_hijau' | 'tidak_hijau'
            $table->unsignedTinyInteger('hijau_score'); // 1..5
            $table->unsignedInteger('umkm_count')->default(0);
            $table->string('status'); // 'pilot' | 'rencana'
            $table->unsignedInteger('distance_meter')->nullable();
            $table->unsignedInteger('estimate_minutes')->nullable();
            $table->unsignedTinyInteger('shade_score')->default(0);
            $table->string('condition_title')->nullable();
            $table->text('condition_desc')->nullable();
            // Snapshot sensor non-suhu/kualitas-udara (kelembaban, kebisingan, UV) —
            // dua sensor itu (suhu & kualitas udara) sudah punya jalur live tersendiri
            // lewat sensor_readings (lihat SensorReadingController & useSensorReading.js
            // di jejak-wangi-app), jadi tidak perlu diduplikasi di sini.
            $table->json('sensor_snapshot')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('koridors');
    }
};

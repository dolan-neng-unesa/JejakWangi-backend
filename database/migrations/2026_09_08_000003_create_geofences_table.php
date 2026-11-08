<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Menggantikan koleksi Firestore `geofences` (lihat geofenceService.js di
// jejak-wangi-app). Pendeteksian zona tetap dihitung client-side (Haversine,
// Batasan 4.1 proposal) — endpoint ini hanya menyuplai daftar zona aktif,
// bukan melakukan pendeteksian di server.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('geofences', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->unsignedInteger('radius_meter');
            $table->boolean('is_active')->default(true);
            $table->foreignId('soundscape_id')->nullable()->constrained('soundscapes')->nullOnDelete();
            $table->string('welcome_title')->nullable();
            $table->text('welcome_desc')->nullable();
            $table->unsignedBigInteger('koridor_id')->nullable();
            $table->string('background_image')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('geofences');
    }
};

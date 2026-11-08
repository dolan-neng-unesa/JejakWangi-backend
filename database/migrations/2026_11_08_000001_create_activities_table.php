<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Aktivitas jalan kaki hasil rekam GPS (Jejak Wangi), satu baris per perjalanan.
        Schema::create('activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            // ID dari perangkat → sinkronisasi ulang tidak menggandakan aktivitas (idempoten).
            $table->string('client_id', 64);
            $table->unsignedBigInteger('koridor_id')->nullable();
            $table->string('transport', 10)->nullable(); // moda saat tiba: motor|mobil|jalan|sepeda
            $table->unsignedInteger('distance_m');
            $table->unsignedInteger('duration_sec');
            $table->unsignedInteger('kkal')->default(0);
            $table->decimal('co2_kg', 8, 3)->default(0); // emisi terhindar (estimasi)
            $table->unsignedInteger('rupiah')->default(0);
            $table->unsignedInteger('points')->default(0);
            $table->boolean('is_simulated')->default(false); // mode simulasi tidak dihitung ke misi/leaderboard
            $table->json('path')->nullable();
            $table->timestamp('started_at');
            $table->timestamps();

            $table->unique(['user_id', 'client_id']);
            $table->index(['user_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activities');
    }
};

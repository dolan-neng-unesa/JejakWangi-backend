<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Menggantikan koleksi Firestore `green_space` (Taman Sritanjung, Taman
// Blambangan) yang selama ini kosong di project Firebase asli, sehingga
// gambar/detail koridor tidak pernah muncul (green_space & koridor
// dipindah bersama-sama karena satu form data yang sama, lihat
// getKoridorDetail di koridorService.js).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('green_spaces', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('location_type'); // 'taman' | dst.
            $table->text('description')->nullable();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->string('address')->nullable();
            $table->unsignedTinyInteger('shade_score')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('green_spaces');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Menambah kolom yang dibutuhkan halaman Kalender BWI Fest versi kalender
// (filter kategori Budaya/Pariwisata/Seni, alamat lengkap di kartu event).
// Migration terpisah (bukan mengedit migration lama) karena migration lama
// sudah dianggap "sudah jalan" di alur pengembangan tim.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('festival_models', function (Blueprint $table) {
            $table->string('category')->nullable()->after('location_name'); // 'budaya' | 'pariwisata' | 'seni'
            $table->string('address')->nullable()->after('location_name');
        });
    }

    public function down(): void
    {
        Schema::table('festival_models', function (Blueprint $table) {
            $table->dropColumn(['category', 'address']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// `sort_order`: urutan tampil di daftar "Pilih Koridor Hijau". Sengaja terpisah dari `id`
// karena id dipakai sebagai nomor wilayah ESP32 (`no` -> koridor_id), geofence, UMKM, dan
// riwayat aktivitas — jadi koridor baru boleh disisipkan di tengah daftar tanpa menomori ulang.
//
// `highlights`: konten opsional halaman detail koridor ("Kunjunganmu hari ini", saran Eco-Coach,
// "Dampak bersama kita"). Koridor tanpa highlights tetap memakai tampilan detail standar.
// Bentuk JSON: { kunjungan: [{icon, title, value, note, sdg}], eco_coach: "...",
//                dampak: {judul, periode, komunitas, co2_kg, kantong_plastik, kompos},
//                pesan: "..." }
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('koridors', function (Blueprint $table) {
            $table->unsignedSmallInteger('sort_order')->default(0)->after('status');
            $table->json('highlights')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('koridors', function (Blueprint $table) {
            $table->dropColumn(['sort_order', 'highlights']);
        });
    }
};

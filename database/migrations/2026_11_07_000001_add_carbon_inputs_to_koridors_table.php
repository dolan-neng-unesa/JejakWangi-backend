<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Masukan neraca karbon per koridor (jumlah pohon per jenis, hitungan kendaraan per hari,
// AC toko, dan label sumber data). jejak-wangi-app (lib/carbonBalance.js) menghitung ulang
// `oxygen_kg_day` & `co2_reduced_kg_day` dari kolom ini; kedua kolom lama tetap jadi cadangan.
// Bentuk JSON: { pohon: [{jenis, jumlah}], kendaraanPerHari: {motor, mobil, ...},
//                toko: [{nama, unitAC, kWPerUnit, jamOperasi, faktorBeban}], sumberData: {...} }
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('koridors', function (Blueprint $table) {
            $table->json('carbon_inputs')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('koridors', function (Blueprint $table) {
            $table->dropColumn('carbon_inputs');
        });
    }
};

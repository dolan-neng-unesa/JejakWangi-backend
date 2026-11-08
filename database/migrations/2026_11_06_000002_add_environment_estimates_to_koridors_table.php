<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Estimasi kontribusi lingkungan per koridor (kartu "Kondisi Koridor Terpilih" di
// halaman detail koridor jejak-wangi-app): oksigen yang dihasilkan & CO2 yang direduksi, kg/hari.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('koridors', function (Blueprint $table) {
            $table->decimal('oxygen_kg_day', 8, 1)->nullable();
            $table->decimal('co2_reduced_kg_day', 8, 1)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('koridors', function (Blueprint $table) {
            $table->dropColumn(['oxygen_kg_day', 'co2_reduced_kg_day']);
        });
    }
};

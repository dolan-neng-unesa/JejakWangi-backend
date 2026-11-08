<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Charger station HP bertenaga surya (titik biru di peta Smart Green Route).
        Schema::create('charger_stations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('location')->nullable();
            $table->unsignedBigInteger('koridor_id')->nullable();
            $table->decimal('latitude', 9, 6);
            $table->decimal('longitude', 9, 6);
            $table->decimal('solar_kwp', 5, 2)->default(0);
            $table->unsignedTinyInteger('battery_percent')->default(0);
            $table->unsignedTinyInteger('ports_total')->default(4);
            $table->unsignedTinyInteger('ports_available')->default(4);
            $table->string('opening_hours')->default('24 jam');
            $table->string('image_url')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('charger_stations');
    }
};

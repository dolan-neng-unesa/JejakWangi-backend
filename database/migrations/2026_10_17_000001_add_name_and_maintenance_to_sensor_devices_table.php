<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Dipakai halaman Sensor IoT di dashboard admin: nama tampilan + mode perawatan.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sensor_devices', function (Blueprint $table) {
            $table->string('name', 120)->nullable()->after('device_code');
            $table->boolean('is_maintenance')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('sensor_devices', function (Blueprint $table) {
            $table->dropColumn(['name', 'is_maintenance']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SVARGA Backend (Laravel) — tabel Festival (BWI Fest / Kalender Festival).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('festival_models', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // "Banyuwangi Ethno Carnival" dst.
            $table->string('location_type')->nullable(); // "panggung" dst.
            $table->string('location_name')->nullable(); // "Taman Blambangan" dst.
            $table->date('event_date');
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->text('description')->nullable();
            $table->string('image_url')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('festival_models');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('soundscapes', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('category'); // 'gamelan_using' | 'alam'
            $table->string('duration'); // format "mm:ss", sesuai src/data/mockContent.js
            $table->string('audio_url');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('soundscapes');
    }
};

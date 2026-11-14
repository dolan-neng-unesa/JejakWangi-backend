<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Log notifikasi sistem untuk dashboard admin (menggantikan
// notificationService.js sisi Prisma). Ditulis manual/lewat seeder untuk
// sekarang — observer otomatis (mis. saat sensor offline atau festival baru
// dibuat) adalah pekerjaan lanjutan, belum termasuk di iterasi ini.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_notifications', function (Blueprint $table) {
            $table->id();
            $table->string('category'); // 'lingkungan' | 'sensor' | 'festival' | 'pengguna' | 'mood'
            $table->string('title');
            $table->text('description')->nullable();
            $table->boolean('is_read')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_notifications');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Kupon kini bertingkat & bisa diklaim ulang tiap bulan → hapus batas "satu kupon per misi".
        Schema::table('vouchers', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'source']);
            $table->unsignedTinyInteger('tier')->nullable()->after('source');
            $table->index(['user_id', 'tier']);
        });

        // Cooldown klaim per pengguna per tingkat. available_at = kapan boleh klaim lagi.
        Schema::create('voucher_cooldowns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedTinyInteger('tier');
            $table->timestamp('available_at');
            $table->timestamps();
            $table->unique(['user_id', 'tier']);
        });

        // QR "pelanggan membawa tempat sendiri": diterbitkan kasir, sekali pakai, kedaluwarsa cepat.
        Schema::create('byo_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('token', 64)->unique();
            $table->unsignedBigInteger('umkm_id');
            $table->foreignId('issued_by')->constrained('users')->cascadeOnDelete();
            $table->timestamp('expires_at');
            $table->foreignId('used_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
            $table->index(['used_by', 'used_at']);
        });

        // Akun kasir = pengguna yang ditautkan ke satu UMKM (diatur admin).
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('umkm_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('umkm_id');
        });
        Schema::dropIfExists('byo_tokens');
        Schema::dropIfExists('voucher_cooldowns');
        Schema::table('vouchers', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'tier']);
            $table->dropColumn('tier');
            $table->unique(['user_id', 'source']);
        });
    }
};

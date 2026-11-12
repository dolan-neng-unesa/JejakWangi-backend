<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Login admin dashboard (jejak-wangi-admin) sebelumnya lewat Prisma/Supabase
// terpisah. Dipindah ke Laravel Sanctum (tabel `users` yang sama dengan
// jejak-wangi-app) supaya tidak ada lagi ketergantungan ke Supabase — cukup satu
// kolom untuk membedakan admin dari pengguna biasa.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false)->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_admin');
        });
    }
};

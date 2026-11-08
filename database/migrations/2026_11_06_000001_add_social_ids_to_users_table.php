<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Login sosial (Google & Facebook). Akun sosial disimpan di tabel `users` yang
// sama dengan akun email/kata sandi — satu kolom ID per penyedia. Kolom
// `password` tetap NOT NULL: akun sosial baru diberi kata sandi acak yang tak
// pernah diketahui siapa pun (lihat AuthController@social), jadi tidak perlu
// mengubah skema SQLite yang sudah berjalan.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('google_id')->nullable()->unique();
            $table->string('facebook_id')->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['google_id']);
            $table->dropUnique(['facebook_id']);
            $table->dropColumn(['google_id', 'facebook_id']);
        });
    }
};

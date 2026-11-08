<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Menggantikan koleksi Firestore `mood_logs` (lihat TODO lama di
// jejak-wangi-app/src/services/moodService.js soal Cloud Function agregat —
// dengan tabel relasional ini agregasi bisa dihitung langsung lewat query
// SQL biasa di MoodLogController@summary, tanpa perlu Cloud Function.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mood_logs', function (Blueprint $table) {
            $table->id();
            // Nullable: mendukung mode tamu (anonim) sesuai Batasan 4.1 proposal —
            // data mood tetap bisa dikirim tanpa login.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('anonymous_session_id')->nullable();
            $table->unsignedBigInteger('green_space_id');
            $table->unsignedTinyInteger('mood_score'); // 1..5, lihat MOODS di MoodTrackerPage.jsx (1=Sangat Buruk .. 5=Sangat Baik)
            $table->string('activity')->nullable(); // 'belajar' | 'bekerja' | 'olahraga' | 'lainnya'
            $table->text('note')->nullable();
            $table->timestamp('logged_at');
            $table->timestamps();

            $table->index(['green_space_id', 'logged_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mood_logs');
    }
};

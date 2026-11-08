<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Geometri koridor untuk peta (MapPage di jejak-wangi-app): array titik
// [[lat, lng], ...] berurutan dari titik awal ke titik akhir. Nullable karena
// belum semua koridor punya data jalur.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('koridors', function (Blueprint $table) {
            $table->json('path')->nullable()->after('estimate_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('koridors', function (Blueprint $table) {
            $table->dropColumn('path');
        });
    }
};

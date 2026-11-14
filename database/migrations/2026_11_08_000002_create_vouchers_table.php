<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vouchers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('code', 20)->unique();
            $table->string('title');
            $table->string('merchant');
            $table->string('description')->nullable();
            $table->unsignedInteger('min_spend')->default(0);
            $table->unsignedInteger('max_discount')->default(0);
            $table->string('source', 50); // id misi penghasil kupon
            $table->string('status', 12)->default('tersedia'); // tersedia|digunakan
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();

            // Satu kupon per misi per pengguna (cegah ganda saat sinkronisasi bersamaan).
            $table->unique(['user_id', 'source']);
        });

        // Poin tambahan di luar aktivitas (bonus misi, bonus tumbler).
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedInteger('bonus_points')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('bonus_points');
        });
        Schema::dropIfExists('vouchers');
    }
};

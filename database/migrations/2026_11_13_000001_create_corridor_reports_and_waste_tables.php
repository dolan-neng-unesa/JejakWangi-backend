<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Lapor Koridor (laporan warga → tindak lanjut DLH) + Sampah & Pangan (penimbangan, poin, pengolahan).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('corridor_reports', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->nullable()->unique(); // KRD-0012, diisi setelah id diketahui
            $table->unsignedBigInteger('koridor_id')->nullable();
            $table->string('location');
            $table->string('category', 30);
            $table->string('title');
            $table->text('description');
            $table->string('status', 20)->default('menunggu'); // menunggu | diproses | selesai | ditolak
            $table->string('assignee')->nullable();
            $table->text('admin_note')->nullable();
            $table->foreignId('reporter_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reporter_name')->nullable();
            $table->json('evidence')->nullable();        // bukti dari pelapor
            $table->json('action_evidence')->nullable(); // bukti tindakan DLH
            $table->json('history')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
            $table->index('koridor_id');
        });

        Schema::create('waste_weighings', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->nullable()->unique(); // TMB-00128
            $table->string('kind', 10)->default('sampah');     // sampah | pangan
            $table->string('station_code', 20);
            $table->string('station_name');
            $table->string('depositor_type', 10)->default('pengguna'); // pengguna | lapak
            $table->string('depositor_name');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('umkm_id')->nullable();
            $table->json('items');
            $table->decimal('total_kg', 9, 2)->default(0);
            $table->decimal('organik_kg', 9, 2)->default(0);
            $table->decimal('anorganik_kg', 9, 2)->default(0);
            $table->decimal('pangan_kg', 9, 2)->default(0);
            $table->unsignedInteger('points')->default(0);
            $table->string('status', 24)->default('draft'); // draft | menunggu_konfirmasi | terkonfirmasi | perlu_koreksi
            $table->text('note')->nullable();
            $table->text('correction_note')->nullable();
            $table->timestamp('weighed_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->foreignId('weighed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['kind', 'status', 'weighed_at']);
            $table->index(['user_id', 'status']);
        });

        Schema::create('waste_checkins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('station_code', 20);
            $table->timestamp('scanned_at');
            $table->timestamps();
            $table->index(['station_code', 'scanned_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('waste_checkins');
        Schema::dropIfExists('waste_weighings');
        Schema::dropIfExists('corridor_reports');
    }
};

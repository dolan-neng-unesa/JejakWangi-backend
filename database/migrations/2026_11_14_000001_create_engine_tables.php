<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Lapisan "Server Lokal" & "Tindak Lanjut" dari flowchart JEJAK WANGI:
//   hourly_metrics   → Database per jam (neraca karbon koridor, ISPU, selisih suhu, energi surya, kendaraan, plastik, sisa pangan)
//   qr_points        → titik QR (kantong parkir, lapak, tujuan kedua) milik aplikasi warga
//   qr_checkins      → check-in QR warga (bawa tas sendiri? / tujuan kedua / kecepatan wajar?)
//   ai_reports       → keluaran Asisten Laporan AI (3 temuan + 3 usulan)
//   proposals        → usulan yang menunggu keputusan pengelola ("Usulan disetujui?")
//   scenarios        → Simulator skenario
//   mitigation_targets → Baseline dan target mitigasi
//   follow_up_actions  → pemilahan sampah mandiri & jadwal penjemputan sampah
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hourly_metrics', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('koridor_id');
            $table->timestamp('hour_at'); // awal jam, UTC
            $table->decimal('solar_kwh', 9, 3)->nullable();
            $table->unsignedSmallInteger('ispu')->nullable();
            $table->string('ispu_kategori', 24)->nullable();
            $table->string('polutan_dominan', 8)->nullable();
            $table->decimal('pm25', 7, 2)->nullable();
            $table->decimal('pm10', 7, 2)->nullable();
            $table->decimal('suhu_kanopi', 5, 2)->nullable();
            $table->decimal('suhu_terbuka', 5, 2)->nullable();
            $table->decimal('selisih_suhu', 5, 2)->nullable();
            $table->unsignedInteger('kendaraan_motor')->default(0);
            $table->unsignedInteger('kendaraan_mobil')->default(0);
            $table->boolean('hari_festival')->default(false);
            $table->string('festival_nama')->nullable();
            $table->unsignedInteger('checkin_jumlah')->default(0);
            $table->unsignedInteger('plastik_terhindar')->default(0);
            $table->decimal('sisa_pangan_kg', 9, 2)->default(0);
            // Neraca karbon (kg CO2e dalam jam ini)
            $table->decimal('serapan_kg', 10, 4)->default(0);
            $table->decimal('emisi_ac_kg', 10, 4)->default(0);
            $table->decimal('emisi_kendaraan_kg', 10, 4)->default(0);
            $table->decimal('emisi_terhindar_kg', 10, 4)->default(0); // jalan kaki + surya + plastik
            $table->decimal('neraca_kg', 10, 4)->default(0);          // serapan + terhindar − emisi (positif = surplus)
            $table->json('sumber')->nullable();                        // asal tiap angka: sensor | api | survei | estimasi | seed
            $table->timestamps();
            $table->unique(['koridor_id', 'hour_at']);
            $table->index('hour_at');
        });

        Schema::create('qr_points', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();      // isi QR: jejakwangi:titik:<code>
            $table->string('name');
            $table->string('type', 12);                // parkir | lapak | tujuan
            $table->unsignedBigInteger('koridor_id')->nullable();
            $table->unsignedBigInteger('umkm_id')->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['type', 'koridor_id']);
        });

        Schema::create('qr_checkins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('koridor_id')->nullable();
            $table->unsignedBigInteger('start_point_id')->nullable();
            $table->unsignedBigInteger('end_point_id')->nullable();
            $table->boolean('tanpa_belanja')->default(false);
            $table->boolean('bawa_tas')->nullable();
            $table->string('status', 24)->default('menunggu_tujuan'); // menunggu_tujuan | dihitung | tidak_dihitung
            $table->string('alasan')->nullable();
            $table->unsignedInteger('jarak_m')->default(0);
            $table->unsignedInteger('durasi_detik')->default(0);
            $table->decimal('kecepatan_mps', 6, 2)->nullable();
            $table->decimal('co2_kg', 8, 3)->default(0);
            $table->unsignedSmallInteger('plastik_pcs')->default(0);
            $table->unsignedInteger('points')->default(0);
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'started_at']);
            $table->index(['status', 'started_at']);
        });

        Schema::create('ai_reports', function (Blueprint $table) {
            $table->id();
            $table->string('week_key', 10);            // 2026-W46
            $table->string('sumber', 12)->default('template'); // ai | template
            $table->text('teks');
            $table->json('temuan');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('week_key');
        });

        Schema::create('proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_report_id')->nullable()->constrained('ai_reports')->nullOnDelete();
            $table->unsignedBigInteger('parent_id')->nullable();        // usulan yang direvisi
            $table->string('dedupe_key', 80)->nullable()->index();
            $table->string('kategori', 20);                              // sampah | ac | kendaraan | pohon | suhu | udara | pangan
            $table->unsignedBigInteger('koridor_id')->nullable();
            $table->string('temuan');
            $table->string('usulan');
            $table->json('dampak')->nullable();                          // estimasi {co2_kg_per_hari, ...}
            $table->json('parameter')->nullable();                       // parameter skenario simulator
            $table->string('status', 12)->default('menunggu');           // menunggu | disetujui | ditolak
            $table->text('catatan')->nullable();
            $table->unsignedBigInteger('scenario_id')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });

        Schema::create('scenarios', function (Blueprint $table) {
            $table->id();
            $table->string('nama');
            $table->unsignedBigInteger('koridor_id');
            $table->unsignedBigInteger('proposal_id')->nullable();
            $table->json('parameter');
            $table->json('hasil');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('koridor_id');
        });

        Schema::create('mitigation_targets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('koridor_id');
            $table->decimal('baseline_kg_hari', 10, 3);   // emisi bersih (emisi − serapan − terhindar) per hari saat baseline
            $table->decimal('target_persen', 5, 1);       // penurunan emisi bersih yang dikejar
            $table->decimal('target_kg_hari', 10, 3);
            $table->date('baseline_dari')->nullable();
            $table->date('baseline_sampai')->nullable();
            $table->date('tenggat')->nullable();
            $table->unsignedBigInteger('proposal_id')->nullable();
            $table->foreignId('set_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('koridor_id');
        });

        Schema::create('follow_up_actions', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 24);                    // pemilahan_mandiri | jadwal_penjemputan
            $table->string('judul');
            $table->unsignedBigInteger('koridor_id')->nullable();
            $table->string('station_code', 20)->nullable();
            $table->date('tanggal')->nullable();
            $table->string('jam', 20)->nullable();
            $table->decimal('target_kg', 9, 2)->nullable();
            $table->string('status', 12)->default('terjadwal'); // terjadwal | berjalan | selesai | batal
            $table->unsignedBigInteger('proposal_id')->nullable();
            $table->string('petugas')->nullable();
            $table->text('catatan')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();
            $table->index(['kind', 'status', 'tanggal']);
        });

        Schema::create('reward_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('hadiah', 20);                  // voucher_lapak | bibit
            $table->unsignedInteger('poin');
            $table->string('kode', 20)->unique();
            $table->string('status', 12)->default('aktif'); // aktif | dipakai
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        foreach (['reward_redemptions', 'follow_up_actions', 'mitigation_targets', 'scenarios', 'proposals', 'ai_reports', 'qr_checkins', 'qr_points', 'hourly_metrics'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};

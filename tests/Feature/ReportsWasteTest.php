<?php

namespace Tests\Feature;

use App\Models\KoridorModel;
use App\Models\UserModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ReportsWasteTest extends TestCase
{
    use RefreshDatabase;

    private function as(UserModel $u)
    {
        // Guard Sanctum menyimpan pengguna dari request sebelumnya selama satu tes — lupakan agar token baru dipakai.
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$u->createToken('t')->plainTextToken);
    }

    private function koridor(): int
    {
        return KoridorModel::create(['short_name' => 'Koridor 2', 'formal_name' => 'Koridor 2', 'hijau_level' => 'agak_hijau', 'hijau_score' => 50, 'umkm_count' => 0, 'status' => 'pilot'])->id;
    }

    public function test_laporan_dibuat_diverifikasi_dan_selesai_wajib_bukti(): void
    {
        Storage::fake('public');
        $admin = UserModel::factory()->create(['is_admin' => true]);
        $warga = UserModel::factory()->create();

        $res = $this->as($warga)->postJson('/api/reports', [
            'koridor_id' => $this->koridor(), 'location' => 'Jl. Diponegoro', 'category' => 'sampah',
            'title' => 'Sampah menumpuk', 'description' => 'Menutup trotoar',
        ])->assertStatus(201)->assertJsonPath('data.status', 'menunggu');
        $id = $res->json('data.id');
        $this->assertSame(sprintf('KRD-%04d', $id), $res->json('data.code'));

        // Warga biasa tidak boleh membuka daftar admin.
        $this->as($warga)->getJson('/api/admin/reports')->assertStatus(403);

        $this->as($admin)->postJson("/api/admin/reports/$id", ['status' => 'selesai', 'note' => 'Sudah dibersihkan'])
            ->assertStatus(422)->assertJsonValidationErrors('action_evidence');

        $this->as($admin)->post("/api/admin/reports/$id", [
            'status' => 'selesai', 'assignee' => 'Tim Kebersihan', 'note' => 'Sudah dibersihkan',
            'action_evidence' => [UploadedFile::fake()->image('bukti.jpg')],
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.status', 'selesai');

        $this->as($admin)->getJson('/api/admin/reports')->assertOk()->assertJsonPath('selesai', 1)->assertJsonPath('total', 1);
        $this->as($warga)->postJson("/api/reports/$id/respond", ['action' => 'tinjau_ulang'])->assertOk()->assertJsonPath('data.status', 'menunggu');
    }

    public function test_penimbangan_poin_masuk_setelah_pengguna_konfirmasi_sekali_saja(): void
    {
        $admin = UserModel::factory()->create(['is_admin' => true]);
        $warga = UserModel::factory()->create();

        $this->as($warga)->postJson('/api/waste/checkin', ['station_code' => 'jejakwangi:timbang:BSS-001'])->assertStatus(201);
        $this->as($warga)->postJson('/api/waste/checkin', ['station_code' => 'XXX'])->assertStatus(404);

        $res = $this->as($admin)->postJson('/api/admin/waste', [
            'station_code' => 'BSS-001', 'depositor_type' => 'pengguna', 'user_id' => $warga->id,
            'items' => [['jenis' => 'sisa_sayur', 'kg' => 2], ['jenis' => 'botol_plastik', 'kg' => 1.5], ['jenis' => 'kertas', 'kg' => 1]],
        ])->assertStatus(201)->assertJsonPath('data.points', 90)->assertJsonPath('data.status', 'menunggu_konfirmasi');
        $id = $res->json('data.id');

        // Belum terkonfirmasi → belum dihitung & poin belum masuk.
        $this->as($admin)->getJson('/api/admin/waste/summary')->assertJsonPath('data.total_kg', 0);
        $this->assertSame(0, (int) UserModel::find($warga->id)->bonus_points);

        $this->as($warga)->postJson("/api/waste/$id/confirm")->assertOk();
        $this->as($warga)->postJson("/api/waste/$id/confirm")->assertStatus(422);
        $this->assertSame(90, (int) UserModel::find($warga->id)->bonus_points);

        $s = $this->as($admin)->getJson('/api/admin/waste/summary')->assertOk();
        $s->assertJsonPath('data.total_kg', 4.5)->assertJsonPath('data.organik_kg', 2)->assertJsonPath('data.anorganik_kg', 2.5)->assertJsonPath('data.menunggu_pengolahan_kg', 4.5);

        $this->as($admin)->postJson("/api/admin/waste/$id/process")->assertOk()->assertJsonPath('data.processed', true);
        $this->as($admin)->getJson('/api/admin/waste/summary')->assertJsonPath('data.organik_diolah_kg', 2)->assertJsonPath('data.menunggu_pengolahan_kg', 0);
    }

    public function test_minta_koreksi_lalu_petugas_kirim_ulang_dan_campur_pangan_ditolak(): void
    {
        $admin = UserModel::factory()->create(['is_admin' => true]);
        $warga = UserModel::factory()->create();
        $body = ['station_code' => 'BSS-002', 'depositor_type' => 'pengguna', 'user_id' => $warga->id, 'items' => [['jenis' => 'kaca', 'kg' => 3]]];

        $id = $this->as($admin)->postJson('/api/admin/waste', $body)->json('data.id');
        $this->as($warga)->postJson("/api/waste/$id/correction", [])->assertStatus(422);
        $this->as($warga)->postJson("/api/waste/$id/correction", ['note' => 'Beratnya 2 kg'])->assertOk()->assertJsonPath('data.status', 'perlu_koreksi');

        $body['items'] = [['jenis' => 'kaca', 'kg' => 2]];
        $this->as($admin)->putJson("/api/admin/waste/$id", $body)->assertOk()->assertJsonPath('data.status', 'menunggu_konfirmasi')->assertJsonPath('data.points', 40);

        $body['items'] = [['jenis' => 'kaca', 'kg' => 2], ['jenis' => 'nasi_lauk', 'kg' => 1]];
        $this->as($admin)->postJson('/api/admin/waste', $body)->assertStatus(422);
    }
}

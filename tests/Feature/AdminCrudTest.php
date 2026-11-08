<?php

namespace Tests\Feature;

use App\Models\FestivalModel;
use App\Models\GeofenceModel;
use App\Models\UserModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminCrudTest extends TestCase
{
    use RefreshDatabase;

    private UserModel $admin;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = UserModel::factory()->create(['is_admin' => true]);
        $this->token = $this->admin->createToken('t')->plainTextToken;
    }

    private function asAdmin()
    {
        return $this->withHeader('Authorization', "Bearer {$this->token}");
    }

    // --- Pengguna ---

    public function test_admin_bisa_membuat_pengguna_baru_termasuk_admin(): void
    {
        $this->asAdmin()->postJson('/api/admin/users', [
            'name' => 'Staff Baru', 'email' => 'staff@jejakwangi.test', 'password' => 'rahasia123', 'is_admin' => true,
        ])->assertStatus(201)->assertJsonPath('data.peran', 'Admin');

        $this->assertDatabaseHas('users', ['email' => 'staff@jejakwangi.test', 'is_admin' => true]);
    }

    public function test_admin_bisa_mengedit_dan_menonaktifkan_pengguna(): void
    {
        $user = UserModel::factory()->create(['is_active' => true]);

        $this->asAdmin()->putJson("/api/admin/users/{$user->id}", ['name' => 'Nama Baru', 'is_active' => false])
            ->assertStatus(200)->assertJsonPath('data.status', 'NonAktif');

        $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => 'Nama Baru', 'is_active' => false]);
    }

    public function test_admin_tidak_bisa_mencabut_hak_admin_atau_menonaktifkan_atau_menghapus_dirinya_sendiri(): void
    {
        $this->asAdmin()->putJson("/api/admin/users/{$this->admin->id}", ['is_admin' => false])->assertStatus(422);
        $this->asAdmin()->putJson("/api/admin/users/{$this->admin->id}", ['is_active' => false])->assertStatus(422);
        $this->asAdmin()->deleteJson("/api/admin/users/{$this->admin->id}")->assertStatus(422);
    }

    public function test_admin_bisa_menghapus_pengguna_lain(): void
    {
        $user = UserModel::factory()->create();

        $this->asAdmin()->deleteJson("/api/admin/users/{$user->id}")->assertStatus(200);
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }

    public function test_email_duplikat_ditolak_saat_membuat_pengguna(): void
    {
        UserModel::factory()->create(['email' => 'dipakai@jejakwangi.test']);

        $this->asAdmin()->postJson('/api/admin/users', ['name' => 'X', 'email' => 'dipakai@jejakwangi.test', 'password' => 'rahasia123'])
            ->assertStatus(422);
    }

    public function test_daftar_pengguna_mendukung_pencarian_filter_status_dan_paginasi(): void
    {
        UserModel::factory()->create(['name' => 'Budi Santoso', 'email' => 'budi@x.test', 'is_active' => true]);
        UserModel::factory()->create(['name' => 'Siti Aisyah', 'email' => 'siti@x.test', 'is_active' => false]);
        UserModel::factory()->count(6)->create(['is_active' => true]);

        $this->asAdmin()->getJson('/api/admin/users?q=budi')->assertJsonPath('filtered_total', 1);
        $this->asAdmin()->getJson('/api/admin/users?status=nonaktif')->assertJsonPath('filtered_total', 1);

        $page = $this->asAdmin()->getJson('/api/admin/users?per_page=3&page=2');
        $page->assertJsonPath('page', 2);
        $this->assertCount(3, $page->json('data'));
        $this->assertGreaterThan(1, $page->json('last_page'));
    }

    // --- Geofencing ---

    public function test_admin_bisa_edit_dan_hapus_zona(): void
    {
        $zone = GeofenceModel::create(['name' => 'Lama', 'latitude' => -8.2, 'longitude' => 114.3, 'radius_meter' => 50]);

        $this->asAdmin()->putJson("/api/geofences/{$zone->id}", ['name' => 'Baru', 'radius_meter' => 120])
            ->assertStatus(200)->assertJsonPath('data.name', 'Baru');
        $this->assertDatabaseHas('geofences', ['id' => $zone->id, 'radius_meter' => 120]);

        $this->asAdmin()->deleteJson("/api/geofences/{$zone->id}")->assertStatus(200);
        $this->assertDatabaseMissing('geofences', ['id' => $zone->id]);
    }

    // --- Festival ---

    public function test_admin_bisa_crud_festival(): void
    {
        $created = $this->asAdmin()->postJson('/api/festivals', [
            'name' => 'Festival Uji', 'location_name' => 'Taman Blambangan', 'category' => 'seni',
            'event_date' => '2026-12-01', 'start_time' => '09:00', 'end_time' => '17:00',
        ])->assertStatus(201)->assertJsonPath('data.category', 'seni');

        $id = $created->json('data.id');

        $this->asAdmin()->putJson("/api/festivals/{$id}", ['name' => 'Festival Uji (Revisi)'])
            ->assertStatus(200)->assertJsonPath('data.bfest_name', 'Festival Uji (Revisi)');

        $this->asAdmin()->deleteJson("/api/festivals/{$id}")->assertStatus(200);
        $this->assertDatabaseMissing('festival_models', ['id' => $id]);
    }

    public function test_kategori_festival_di_luar_daftar_ditolak(): void
    {
        $this->asAdmin()->postJson('/api/festivals', [
            'name' => 'X', 'location_name' => 'Y', 'category' => 'olahraga', 'event_date' => '2026-12-01',
        ])->assertStatus(422);
    }

    public function test_admin_all_menampilkan_festival_nonaktif(): void
    {
        FestivalModel::create(['name' => 'Aktif', 'location_name' => 'A', 'event_date' => '2026-12-01', 'is_active' => true]);
        FestivalModel::create(['name' => 'Nonaktif', 'location_name' => 'B', 'event_date' => '2026-12-02', 'is_active' => false]);

        $this->getJson('/api/festivals')->assertJsonCount(1, 'data');
        $this->getJson('/api/festivals?all=1')->assertJsonCount(2, 'data');
    }

    // --- Proteksi ---

    public function test_user_biasa_dan_tamu_ditolak_di_semua_mutasi(): void
    {
        $biasa = UserModel::factory()->create(['is_admin' => false]);
        $biasaToken = $biasa->createToken('t')->plainTextToken;

        $this->postJson('/api/festivals', ['name' => 'X'])->assertStatus(401);
        $this->withHeader('Authorization', "Bearer {$biasaToken}")->postJson('/api/festivals', ['name' => 'X'])->assertStatus(403);
        $this->withHeader('Authorization', "Bearer {$biasaToken}")->deleteJson('/api/geofences/1')->assertStatus(403);
        $this->withHeader('Authorization', "Bearer {$biasaToken}")->postJson('/api/admin/users', [])->assertStatus(403);
    }
}

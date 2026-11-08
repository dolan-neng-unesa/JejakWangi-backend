<?php

namespace Tests\Feature;

use App\Models\AdminNotificationModel;
use App\Models\GeofenceModel;
use App\Models\MoodLogModel;
use App\Models\SensorReadingModel;
use App\Models\SoundscapeModel;
use App\Models\UserModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminApiTest extends TestCase
{
    use RefreshDatabase;

    private function adminToken(): string
    {
        $admin = UserModel::factory()->create(['is_admin' => true]);

        return $admin->createToken('test')->plainTextToken;
    }

    private function nonAdminToken(): string
    {
        $user = UserModel::factory()->create(['is_admin' => false]);

        return $user->createToken('test')->plainTextToken;
    }

    // --- Proteksi middleware admin ---

    public function test_endpoint_admin_menolak_tanpa_token(): void
    {
        $this->getJson('/api/admin/users')->assertStatus(401);
    }

    public function test_endpoint_admin_menolak_user_biasa(): void
    {
        $token = $this->nonAdminToken();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/admin/users')
            ->assertStatus(403);
    }

    // --- AdminUserController ---

    public function test_admin_bisa_lihat_daftar_pengguna(): void
    {
        UserModel::factory()->count(3)->create();
        $token = $this->adminToken(); // +1 admin

        $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/admin/users');

        $response->assertStatus(200)->assertJsonPath('total', 4);
        $this->assertCount(4, $response->json('data'));
    }

    public function test_activity_monthly_mengembalikan_jumlah_bulan_yang_diminta(): void
    {
        $token = $this->adminToken();
        UserModel::factory()->create(['created_at' => now()]);
        MoodLogModel::factory()->create(['logged_at' => now()]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/admin/activity-monthly?months=3');

        $response->assertStatus(200);
        $this->assertCount(3, $response->json('data'));
        $this->assertGreaterThanOrEqual(1, collect($response->json('data'))->last()['aktif']);
    }

    // --- AdminNotificationController ---

    public function test_admin_bisa_lihat_notifikasi_dan_hitung_belum_dibaca(): void
    {
        $token = $this->adminToken();
        AdminNotificationModel::create(['category' => 'sensor', 'title' => 'A', 'is_read' => false]);
        AdminNotificationModel::create(['category' => 'festival', 'title' => 'B', 'is_read' => true]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/admin/notifications');

        $response->assertStatus(200)->assertJsonPath('total', 2)->assertJsonPath('belum_dibaca', 1);
    }

    public function test_mark_all_read_menandai_semua_notifikasi_terbaca(): void
    {
        $token = $this->adminToken();
        AdminNotificationModel::create(['category' => 'sensor', 'title' => 'A', 'is_read' => false]);
        AdminNotificationModel::create(['category' => 'festival', 'title' => 'B', 'is_read' => false]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/admin/notifications/mark-read')
            ->assertStatus(200);

        $this->assertDatabaseMissing('admin_notifications', ['is_read' => false]);
    }

    // --- GeofenceController@store ---

    public function test_admin_bisa_membuat_zona_geofencing_baru(): void
    {
        $token = $this->adminToken();

        $response = $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/geofences', [
            'name' => 'Zona Baru Test',
            'latitude' => -8.21,
            'longitude' => 114.36,
            'radius_meter' => 50,
        ]);

        $response->assertStatus(201)->assertJsonPath('data.name', 'Zona Baru Test');
        $this->assertDatabaseHas('geofences', ['name' => 'Zona Baru Test']);
    }

    public function test_user_biasa_tidak_bisa_membuat_zona(): void
    {
        $token = $this->nonAdminToken();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/geofences', ['name' => 'Test', 'latitude' => -8.21, 'longitude' => 114.36, 'radius_meter' => 50])
            ->assertStatus(403);
    }

    public function test_geofences_index_dengan_all_menampilkan_zona_nonaktif_juga(): void
    {
        GeofenceModel::create(['name' => 'Aktif', 'latitude' => -8.2, 'longitude' => 114.3, 'radius_meter' => 50, 'is_active' => true]);
        GeofenceModel::create(['name' => 'Nonaktif', 'latitude' => -8.2, 'longitude' => 114.3, 'radius_meter' => 50, 'is_active' => false]);

        $this->getJson('/api/geofences')->assertJsonCount(1, 'data');
        $this->getJson('/api/geofences?all=1')->assertJsonCount(2, 'data');
    }

    // --- SensorReadingController@history ---

    public function test_sensor_history_mengembalikan_tren_harian(): void
    {
        SensorReadingModel::create([
            'device_code' => 'ESP32-TEST', 'sensor_type' => 'temperature', 'value' => 30,
            'unit' => '°C', 'recorded_at' => now(),
        ]);

        $response = $this->getJson('/api/sensors/history?sensor_type=temperature&days=7');

        $response->assertStatus(200);
        $this->assertCount(7, $response->json('daily'));
        $this->assertEquals(30, collect($response->json('daily'))->last()['average_value']);
    }
}

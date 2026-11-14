<?php

namespace Tests\Feature;

use App\Models\GreenSpaceModel;
use App\Models\KoridorModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KoridorApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_daftar_koridor_bisa_diambil(): void
    {
        KoridorModel::create([
            'short_name' => 'RTH Sritanjung', 'formal_name' => 'Koridor 1: Sritanjung',
            'thumbnail' => 'images/koridor/thumb-sritanjung.png',
            'hijau_level' => 'paling_hijau', 'hijau_score' => 5, 'status' => 'pilot',
            'shade_score' => 82,
        ]);

        $response = $this->getJson('/api/koridors');

        $response->assertStatus(200)->assertJsonCount(1, 'data');
    }

    public function test_detail_koridor_mengembalikan_url_gambar_absolut_dan_sensor_snapshot(): void
    {
        $koridor = KoridorModel::create([
            'short_name' => 'RTH Sritanjung', 'formal_name' => 'Koridor 1: Sritanjung',
            'thumbnail' => 'images/koridor/thumb-sritanjung.png',
            'hero_image' => 'images/koridor/hero-koridor1.png',
            'hijau_level' => 'paling_hijau', 'hijau_score' => 5, 'status' => 'pilot',
            'shade_score' => 82,
            'sensor_snapshot' => ['pm25' => 12, 'suhu' => 26.4, 'sensor_status' => 'online'],
        ]);

        $response = $this->getJson("/api/koridors/{$koridor->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.sensor.pm25', 12)
            ->assertJsonPath('data.sensor.suhu', 26.4);

        // Gambar harus jadi URL absolut (http://...), bukan path relatif mentah,
        // supaya <img src> di FE langsung bisa dipakai tanpa perlu digabung manual.
        $this->assertStringStartsWith('http', $response->json('data.thumbnail'));
    }

    public function test_koridor_mengembalikan_path_untuk_peta(): void
    {
        $koridor = KoridorModel::create([
            'short_name' => 'RTH Sritanjung', 'formal_name' => 'Koridor 1: Sritanjung',
            'hijau_level' => 'paling_hijau', 'hijau_score' => 5, 'status' => 'pilot',
            'path' => [[-8.21944, 114.36972], [-8.2175, 114.3675]],
        ]);

        $this->getJson("/api/koridors/{$koridor->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.path.0.0', -8.21944)
            ->assertJsonCount(2, 'data.path');
    }

    public function test_koridor_tanpa_path_mengembalikan_null(): void
    {
        $koridor = KoridorModel::create([
            'short_name' => 'Kalilo', 'formal_name' => 'Koridor 2: Kalilo',
            'hijau_level' => 'agak_hijau', 'hijau_score' => 4, 'status' => 'rencana',
        ]);

        $this->getJson("/api/koridors/{$koridor->id}")->assertJsonPath('data.path', null);
    }

    public function test_daftar_koridor_diurutkan_berdasarkan_sort_order_bukan_id(): void
    {
        // Pasar Induk dibuat terakhir (id terbesar) tapi tampil di posisi 2 lewat sort_order.
        KoridorModel::create(['short_name' => 'RTH Sritanjung', 'formal_name' => 'Koridor 1', 'hijau_level' => 'paling_hijau', 'hijau_score' => 5, 'status' => 'pilot', 'sort_order' => 1]);
        KoridorModel::create(['short_name' => 'Corridor 1 Kalilo', 'formal_name' => 'Koridor 2', 'hijau_level' => 'agak_hijau', 'hijau_score' => 4, 'status' => 'rencana', 'sort_order' => 3]);
        KoridorModel::create(['short_name' => 'Corridor Pasar', 'formal_name' => 'Koridor Pasar Induk', 'hijau_level' => 'agak_hijau', 'hijau_score' => 4, 'status' => 'rencana', 'sort_order' => 2]);

        $this->getJson('/api/koridors')
            ->assertStatus(200)
            ->assertJsonPath('data.0.short_name', 'RTH Sritanjung')
            ->assertJsonPath('data.1.short_name', 'Corridor Pasar')
            ->assertJsonPath('data.2.short_name', 'Corridor 1 Kalilo');
    }

    public function test_koridor_mengembalikan_highlights_untuk_halaman_detail(): void
    {
        $koridor = KoridorModel::create([
            'short_name' => 'Corridor Pasar', 'formal_name' => 'Koridor Pasar Induk',
            'hijau_level' => 'agak_hijau', 'hijau_score' => 4, 'status' => 'rencana',
            'highlights' => [
                'kunjungan' => [['icon' => 'footprints', 'title' => 'Parkir sekali, jalan 450 m', 'value' => 'Hemat 120 g CO₂', 'note' => 'Setara ±Rp500', 'sdg' => 'SDG 13']],
                'eco_coach' => 'Besok coba datang sebelum pukul 07.00.',
                'dampak' => ['co2_kg' => 85, 'kantong_plastik' => 1240],
            ],
        ]);

        $this->getJson("/api/koridors/{$koridor->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.highlights.kunjungan.0.sdg', 'SDG 13')
            ->assertJsonPath('data.highlights.dampak.kantong_plastik', 1240);
    }

    public function test_koridor_tanpa_highlights_mengembalikan_null(): void
    {
        $koridor = KoridorModel::create([
            'short_name' => 'Kalilo', 'formal_name' => 'Koridor 2: Kalilo',
            'hijau_level' => 'agak_hijau', 'hijau_score' => 4, 'status' => 'rencana',
        ]);

        $this->getJson("/api/koridors/{$koridor->id}")->assertJsonPath('data.highlights', null);
    }

    public function test_koridor_yang_tidak_ada_mengembalikan_404(): void
    {
        $this->getJson('/api/koridors/999')->assertStatus(404);
    }

    public function test_daftar_green_space_bisa_diambil(): void
    {
        GreenSpaceModel::create([
            'name' => 'Taman Sritanjung', 'location_type' => 'taman',
            'latitude' => -8.21944, 'longitude' => 114.36972, 'shade_score' => 62,
        ]);

        $this->getJson('/api/green-spaces')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Taman Sritanjung');
    }
}

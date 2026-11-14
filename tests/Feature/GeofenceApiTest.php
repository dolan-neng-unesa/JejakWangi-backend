<?php

namespace Tests\Feature;

use App\Models\GeofenceModel;
use App\Models\SoundscapeModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GeofenceApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_hanya_zona_aktif_yang_dikembalikan(): void
    {
        $soundscape = SoundscapeModel::create([
            'title' => 'Alam yang Tenang', 'category' => 'alam', 'duration' => '04:20',
            'audio_url' => '/audio/alam.mp3', 'is_active' => true,
        ]);

        GeofenceModel::create([
            'name' => 'Zona Aktif', 'latitude' => -8.21, 'longitude' => 114.36,
            'radius_meter' => 50, 'is_active' => true, 'soundscape_id' => $soundscape->id,
        ]);
        GeofenceModel::create([
            'name' => 'Zona Nonaktif', 'latitude' => -8.22, 'longitude' => 114.37,
            'radius_meter' => 50, 'is_active' => false,
        ]);

        $response = $this->getJson('/api/geofences');

        $response->assertStatus(200)->assertJsonCount(1, 'data');
        $this->assertEquals('Zona Aktif', $response->json('data.0.name'));
    }

    public function test_background_image_dikembalikan_sebagai_url_absolut(): void
    {
        GeofenceModel::create([
            'name' => 'Taman Blambangan', 'latitude' => -8.2175, 'longitude' => 114.3675,
            'radius_meter' => 60, 'is_active' => true,
            'background_image' => 'images/geofencing/zone-bg-blambangan.png',
        ]);

        $response = $this->getJson('/api/geofences');

        $this->assertStringStartsWith('http', $response->json('data.0.background_image'));
    }

    public function test_soundscape_bisa_diambil_by_id(): void
    {
        $soundscape = SoundscapeModel::create([
            'title' => 'Gending Using Sore Hari', 'category' => 'gamelan_using', 'duration' => '05:10',
            'audio_url' => '/audio/gending.mp3', 'is_active' => true,
        ]);

        $this->getJson("/api/soundscapes/{$soundscape->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.title', 'Gending Using Sore Hari');
    }

    public function test_soundscape_yang_tidak_ada_mengembalikan_404(): void
    {
        $this->getJson('/api/soundscapes/999')->assertStatus(404);
    }
}

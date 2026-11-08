<?php

namespace Tests\Feature;

use Tests\TestCase;

// Pengganti ExampleTest bawaan skeleton Laravel, yang meminta `/` (route web) padahal
// aplikasi ini hanya API (bootstrap/app.php tidak memuat routes/web.php) sehingga selalu gagal.
// Health check `/up` dipakai load balancer/monitoring; `/api/*` yang tak dikenal harus 404 JSON.
class ExampleTest extends TestCase
{
    public function test_health_check_merespons_ok(): void
    {
        $this->get('/up')->assertStatus(200);
    }

    public function test_endpoint_api_tak_dikenal_membalas_404_json(): void
    {
        $this->getJson('/api/tidak-ada')
            ->assertStatus(404)
            ->assertJsonPath('message', 'Data tidak ditemukan.');
    }
}

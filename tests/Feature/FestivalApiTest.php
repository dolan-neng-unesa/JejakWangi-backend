<?php

namespace Tests\Feature;

use App\Models\FestivalModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FestivalApiTest extends TestCase
{
    use RefreshDatabase;

    private function makeFestival(array $overrides = []): FestivalModel
    {
        return FestivalModel::create(array_merge([
            'name' => 'Festival Contoh',
            'location_name' => 'Taman Blambangan',
            'category' => 'budaya',
            'address' => 'Jl. Contoh',
            'event_date' => '2026-09-12',
            'start_time' => '09:00',
            'end_time' => '17:00',
            'is_active' => true,
        ], $overrides));
    }

    public function test_filter_month_hanya_mengembalikan_event_di_bulan_itu(): void
    {
        $this->makeFestival(['event_date' => '2026-09-12']);
        $this->makeFestival(['name' => 'Beda Bulan', 'event_date' => '2026-10-03']);

        $response = $this->getJson('/api/festivals?month=2026-09');

        $response->assertStatus(200)->assertJsonCount(1, 'data');
        $this->assertEquals('Festival Contoh', $response->json('data.0.bfest_name'));
    }

    public function test_filter_category_bekerja(): void
    {
        $this->makeFestival(['category' => 'budaya']);
        $this->makeFestival(['name' => 'Festival Seni', 'category' => 'seni']);

        $response = $this->getJson('/api/festivals?category=seni');

        $response->assertStatus(200)->assertJsonCount(1, 'data');
        $this->assertEquals('seni', $response->json('data.0.category'));
    }

    public function test_month_dan_category_bisa_digabung(): void
    {
        $this->makeFestival(['category' => 'budaya', 'event_date' => '2026-09-12']);
        $this->makeFestival(['name' => 'Seni bulan sama', 'category' => 'seni', 'event_date' => '2026-09-20']);
        $this->makeFestival(['name' => 'Budaya bulan lain', 'category' => 'budaya', 'event_date' => '2026-10-01']);

        $response = $this->getJson('/api/festivals?month=2026-09&category=budaya');

        $response->assertStatus(200)->assertJsonCount(1, 'data');
        $this->assertEquals('Festival Contoh', $response->json('data.0.bfest_name'));
    }

    public function test_image_dikembalikan_sebagai_url_absolut_kalau_ada(): void
    {
        $this->makeFestival(['image_url' => 'images/koridor/thumb-sritanjung.png']);

        $response = $this->getJson('/api/festivals?month=2026-09');

        $this->assertStringStartsWith('http', $response->json('data.0.image'));
    }
}

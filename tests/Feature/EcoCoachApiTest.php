<?php

namespace Tests\Feature;

use App\Models\ActivityModel;
use App\Models\ByoTokenModel;
use App\Models\KoridorModel;
use App\Models\SensorReadingModel;
use App\Models\UserModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EcoCoachApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-12 10:00:00', 'Asia/Jakarta')); // Kamis
        config(['ecocoach.provider' => null]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function login(array $attrs = []): array
    {
        $u = UserModel::factory()->create($attrs);

        return [$u, ['Authorization' => 'Bearer '.$u->createToken('t')->plainTextToken]];
    }

    private function jalan(UserModel $u, int $m = 450, string $at = '2026-11-12 08:00:00', ?int $koridor = null): ActivityModel
    {
        return ActivityModel::create([
            'user_id' => $u->id, 'client_id' => uniqid('c'), 'koridor_id' => $koridor, 'transport' => 'motor',
            'distance_m' => $m, 'duration_sec' => 400, 'co2_kg' => 0.12, 'rupiah' => 500, 'points' => 10,
            'started_at' => Carbon::parse($at, 'Asia/Jakarta')->utc(), // simpan UTC seperti controller
        ]);
    }

    private function koridor(): KoridorModel
    {
        return KoridorModel::create([
            'short_name' => 'Koridor Uji', 'formal_name' => 'Koridor Uji Formal', 'hijau_level' => 'paling_hijau', 'hijau_score' => 5, 'status' => 'pilot', 'shade_score' => 64,
            'carbon_inputs' => ['pohon' => [['jenis' => 'mahoni', 'jumlah' => 18]], 'kendaraanPerHari' => ['motor' => 1200, 'mobil' => 150]],
        ]);
    }

    private function sensor(int $koridorId, string $type, float $value, ?Carbon $at = null): void
    {
        SensorReadingModel::create(['device_code' => 'd1', 'koridor_id' => (string) $koridorId, 'sensor_type' => $type, 'value' => $value, 'recorded_at' => $at ?? now()]);
    }

    public function test_ringkasan_wajib_login(): void
    {
        $this->postJson('/api/eco-coach', ['mode' => 'ringkasan'])->assertStatus(401);
    }

    public function test_ringkasan_memakai_angka_dari_database(): void
    {
        [$u, $h] = $this->login();
        $this->jalan($u, 450);
        ByoTokenModel::create(['token' => 't1', 'umkm_id' => 1, 'issued_by' => UserModel::factory()->create()->id, 'expires_at' => now()->addMinutes(5), 'used_by' => $u->id, 'used_at' => now()]);

        $r = $this->withHeaders($h)->postJson('/api/eco-coach', ['mode' => 'ringkasan'])->assertOk();

        $this->assertSame('template', $r->json('data.sumber'));
        $teks = $r->json('data.teks');
        $this->assertStringContainsString('450 m', $teks);
        $this->assertStringContainsString('120 g CO₂', $teks);
        $this->assertStringContainsString('Rp500', $teks);
        $this->assertStringContainsString('1 kemasan sekali pakai', $teks);
    }

    public function test_tamu_boleh_bertanya_dan_dapat_kondisi_koridor_dari_sensor(): void
    {
        $k = $this->koridor();
        $this->sensor($k->id, 'temperature', 29.54);
        $this->sensor($k->id, 'pm25', 12);

        $r = $this->postJson('/api/eco-coach', ['mode' => 'tanya', 'pertanyaan' => 'Udara sekarang bagaimana?', 'koridor_id' => $k->id])->assertOk();

        $this->assertStringContainsString('ISPU koridor saat ini 39 (Baik)', $r->json('data.teks'));
        $this->assertStringContainsString('suhu 29,5°C', $r->json('data.teks'));
        $this->assertStringContainsString('Koridor Uji', $r->json('data.teks'));
    }

    public function test_bacaan_sensor_basi_tidak_dipakai(): void
    {
        $k = $this->koridor();
        $this->sensor($k->id, 'temperature', 29.5, now()->subMinutes(30));

        $r = $this->postJson('/api/eco-coach', ['mode' => 'tanya', 'pertanyaan' => 'Suhu sekarang?', 'koridor_id' => $k->id])->assertOk();

        $this->assertStringContainsString('belum punya data', $r->json('data.teks'));
    }

    public function test_ai_dipakai_bila_angka_cocok_dan_dibuang_bila_dikarang(): void
    {
        config(['ecocoach.provider' => 'openai', 'ecocoach.openai_api_key' => 'x', 'ecocoach.base_url' => 'http://llm.test/v1', 'ecocoach.model' => 'm']);
        [$u, $h] = $this->login();
        $this->jalan($u, 450);

        Http::fake(['llm.test/*' => Http::sequence()
            ->push(['choices' => [['message' => ['content' => 'Kamu berjalan 450 m dan menghemat 120 g CO₂.']]]])
            ->push(['choices' => [['message' => ['content' => 'Kamu menghemat 9 kg CO₂, setara 40 pohon!']]]])]);

        $ok = $this->withHeaders($h)->postJson('/api/eco-coach', ['mode' => 'ringkasan'])->assertOk();
        $this->assertSame('ai', $ok->json('data.sumber'));

        $palsu = $this->withHeaders($h)->postJson('/api/eco-coach', ['mode' => 'ringkasan'])->assertOk();
        $this->assertSame('template', $palsu->json('data.sumber'));
        $this->assertStringContainsString('450 m', $palsu->json('data.teks'));
    }

    public function test_alasan_internal_tidak_bocor_ke_klien_saat_tidak_debug(): void
    {
        config(['ecocoach.provider' => 'openai', 'ecocoach.openai_api_key' => 'x', 'ecocoach.base_url' => 'http://llm.test/v1', 'app.debug' => false]);
        Http::fake(['llm.test/*' => Http::response('boom', 500)]);

        $r = $this->postJson('/api/eco-coach', ['mode' => 'tanya', 'pertanyaan' => 'Halo Eco-Coach'])->assertOk();

        $this->assertSame('template', $r->json('data.sumber'));
        $this->assertArrayNotHasKey('alasan', $r->json('data'));
    }

    public function test_validasi_input(): void
    {
        $this->postJson('/api/eco-coach', ['mode' => 'laporan'])->assertStatus(422);
        $this->postJson('/api/eco-coach', ['mode' => 'tanya'])->assertStatus(422);
        $this->postJson('/api/eco-coach', ['mode' => 'tanya', 'pertanyaan' => str_repeat('a', 301)])->assertStatus(422);
    }

    public function test_pengguna_nonaktif_diperlakukan_sebagai_tamu(): void
    {
        [, $h] = $this->login(['is_active' => false]);
        $this->withHeaders($h)->postJson('/api/eco-coach', ['mode' => 'ringkasan'])->assertStatus(401);
    }

    public function test_dibatasi_10_permintaan_per_menit(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/eco-coach', ['mode' => 'tanya', 'pertanyaan' => 'Halo Eco-Coach'])->assertOk();
        }
        $this->postJson('/api/eco-coach', ['mode' => 'tanya', 'pertanyaan' => 'Halo Eco-Coach'])->assertStatus(429);
    }

    public function test_laporan_hanya_untuk_admin(): void
    {
        $this->postJson('/api/admin/eco-coach/laporan')->assertStatus(401);
        [, $biasa] = $this->login(['is_admin' => false]);
        $this->withHeaders($biasa)->postJson('/api/admin/eco-coach/laporan')->assertStatus(403);
    }

    public function test_laporan_mingguan_menghitung_dari_database(): void
    {
        [, $admin] = $this->login(['is_admin' => true]);
        $a = UserModel::factory()->create();
        $b = UserModel::factory()->create();
        $this->jalan($a, 1000, '2026-11-10 06:15:00');   // Selasa minggu ini, 06.xx
        $this->jalan($b, 2000, '2026-11-11 06:45:00');   // Rabu, 06.xx
        $this->jalan($b, 500, '2026-11-11 15:00:00');
        $this->jalan($b, 9999, '2026-11-02 06:00:00');   // minggu lalu: tidak dihitung

        $r = $this->withHeaders($admin)->postJson('/api/admin/eco-coach/laporan')->assertOk();

        $teks = $r->json('data.teks');
        $this->assertSame('template', $r->json('data.sumber'));
        $this->assertStringContainsString('3 oleh 2 pejalan', $teks);
        $this->assertStringContainsString('3,5 km', $teks);
        $this->assertStringContainsString('06.00–07.00', $teks);
    }
}

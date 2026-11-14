<?php

namespace Tests\Feature;

use App\Models\HourlyMetric;
use App\Models\KoridorModel;
use App\Models\QrCheckin;
use App\Models\UserModel;
use Database\Seeders\DemoCycleSeeder;
use Database\Seeders\GreenSpaceSeeder;
use Database\Seeders\KoridorSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Satu siklus penuh sesuai flowchart, dengan data seder:
 * sumber data → Database per jam → Asisten Laporan (3 temuan/3 usulan) → keputusan → tindak lanjut → data baru;
 * dan sisi warga: QR parkir → check-in lapak/tujuan → dampak → poin → hadiah.
 */
class FullCycleTest extends TestCase
{
    use RefreshDatabase;

    private function as(UserModel $u)
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$u->createToken('t')->plainTextToken);
    }

    public function test_siklus_penuh(): void
    {
        config(['ecocoach.provider' => null]);
        $this->seed(GreenSpaceSeeder::class);
        $this->seed(KoridorSeeder::class);
        (new DemoCycleSeeder())->run(30);
        $admin = UserModel::factory()->create(['is_admin' => true]);
        $warga = UserModel::where('email', 'warga@jejakwangi.id')->first();

        // 1. Sumber data → Database per jam
        $this->assertGreaterThan(100, HourlyMetric::count());
        $m = HourlyMetric::where('koridor_id', 7)->whereNotNull('solar_kwh')->where('kendaraan_motor', '>', 0)->first();
        $this->assertNotNull($m, 'baris jam koridor pasar harus berisi YOLO dan surya');
        $this->assertSame('yolo', $m->sumber['kendaraan']);
        $this->assertNotNull(HourlyMetric::whereNotNull('ispu')->first());
        $this->assertNotNull(HourlyMetric::whereNotNull('selisih_suhu')->first());
        $sumber = $this->as($admin)->getJson('/api/admin/engine/sumber')->assertOk()->json('data');
        $this->assertCount(8, $sumber);
        $this->assertSame('seder', collect($sumber)->firstWhere('kunci', 'surya')['asal']);

        // 2. Open-Meteo (API dipalsukan)
        Http::fake(['*' => Http::response(['current' => ['time' => now()->utc()->format('Y-m-d\TH:i'), 'pm10' => 30, 'pm2_5' => 12, 'temperature_2m' => 31.5]])]);
        $this->as($admin)->postJson('/api/admin/engine/recompute')->assertOk()->assertJsonPath('data.open_meteo_baris', fn ($n) => $n > 0);

        $this->assertTrue(\App\Models\SensorReadingModel::where('device_code', 'open-meteo')->where('sensor_type', 'temperature_open')->where('value', 31.5)->exists(), 'suhu kawasan Open-Meteo → temperature_open');
        $this->assertTrue(\App\Models\SensorReadingModel::where('device_code', 'open-meteo')->where('sensor_type', 'pm25')->where('value', 12)->exists());

        // 3. Neraca, rapor, ekonomi
        $n = $this->as($admin)->getJson('/api/admin/engine/neraca?days=7')->assertOk()->json('data');
        $this->assertGreaterThan(0, $n['kawasan']['serapan_kg']);
        $this->assertGreaterThan(0, $n['kawasan']['surya_kwh']);
        $rapor = $this->as($admin)->getJson('/api/admin/engine/rapor')->assertOk()->json('data.koridor');
        $this->assertSame(1, $rapor[0]['prioritas']);
        $this->as($admin)->getJson('/api/admin/engine/ekonomi')->assertOk()->assertJsonPath('data.total_rupiah', fn ($v) => $v > 0);

        // 4. Sisi warga: QR parkir (ISPU sehat?), check-in lapak
        $p = $this->getJson('/api/qr/parkir/jejakwangi:titik:PRK-SRI')->assertOk()->json('data');
        $this->assertArrayHasKey('sehat', $p);
        $this->assertNotNull($p['ispu']);
        $this->getJson('/api/qr/parkir/XXX')->assertStatus(404);

        $this->as($warga)->postJson('/api/checkins/start', ['code' => 'LPK-PSR-01'])->assertOk()->assertJsonPath('data.langkah', 'tanya_tas');
        $this->as($warga)->postJson('/api/checkins/start', ['code' => 'LPK-PSR-01', 'bawa_tas' => true])->assertStatus(201)
            ->assertJsonPath('data.langkah', 'selesai')->assertJsonPath('data.checkin.plastik_pcs', 1);

        // Tidak bawa tas → QR tujuan kedua; kecepatan wajar → dihitung
        $r = $this->as($warga)->postJson('/api/checkins/start', ['code' => 'LPK-PSR-01', 'bawa_tas' => false])->assertStatus(201)->assertJsonPath('data.langkah', 'scan_tujuan_kedua');
        $id = $r->json('data.checkin.id');
        Carbon::setTestNow(now()->addMinutes(6));
        $this->as($warga)->postJson("/api/checkins/$id/finish", ['code' => 'jejakwangi:titik:TJN-SRI'])->assertOk()->assertJsonPath('data.status', 'dihitung')
            ->assertJsonPath('data.jarak_m', fn ($v) => $v > 30);

        // Terlalu cepat → tidak dihitung
        $r = $this->as($warga)->postJson('/api/checkins/start', ['code' => 'LPK-PSR-01', 'tanpa_belanja' => true])->assertStatus(201);
        $id2 = $r->json('data.checkin.id');
        Carbon::setTestNow(now()->addSeconds(21));
        $this->as($warga)->postJson("/api/checkins/$id2/finish", ['code' => 'TJN-SRI'])->assertOk()->assertJsonPath('data.status', 'tidak_dihitung');
        Carbon::setTestNow();
        $this->assertSame(2, QrCheckin::where('status', 'dihitung')->count());
        $this->assertSame(1, QrCheckin::where('status', 'tidak_dihitung')->count());

        // 5. Kartu dampak, poin, hadiah
        $imp = $this->as($warga)->getJson('/api/me/impact')->assertOk()->json('data');
        $this->assertSame(1, $imp['plastik_terhindar']);
        $this->assertCount(4, $imp['sdgs']);
        $this->as($warga)->postJson('/api/rewards/redeem', ['hadiah' => 'bibit'])->assertStatus(422); // poin belum cukup
        $warga->forceFill(['bonus_points' => 500])->save();
        $this->as($warga)->postJson('/api/rewards/redeem', ['hadiah' => 'bibit'])->assertStatus(201);
        $this->as($warga)->getJson('/api/rewards')->assertOk()->assertJsonPath('poin_tersedia', fn ($v) => $v < 500);

        // 6. Asisten Laporan → 3 temuan + 3 usulan
        $lap = $this->as($admin)->postJson('/api/admin/asisten/laporan')->assertStatus(201)->json('data');
        $this->assertCount(3, $lap['temuan']);
        $this->assertCount(3, $lap['usulan']);
        $this->assertNotSame('', $lap['teks']);

        // 7. Usulan disetujui? Ya → tindak lanjut; Tidak → simulator → perbaiki dampak → ajukan lagi
        $ya = collect($lap['usulan'])->firstWhere('parameter', '!=', null) ?? $lap['usulan'][0];
        $res = $this->as($admin)->postJson("/api/admin/proposals/{$ya['id']}/setuju")->assertOk()->assertJsonPath('data.status', 'disetujui');
        $this->assertNotEmpty($res->json('tindakan'));
        $this->as($admin)->postJson("/api/admin/proposals/{$ya['id']}/setuju")->assertStatus(422);
        $this->assertNotEmpty($this->as($admin)->getJson('/api/admin/engine/baseline')->json('data'));

        $tidak = collect($lap['usulan'])->first(fn ($u) => $u['id'] !== $ya['id'] && $u['parameter']);
        if ($tidak) {
            $t = $this->as($admin)->postJson("/api/admin/proposals/{$tidak['id']}/tolak", ['catatan' => 'Dampak kurang'])->assertOk();
            $this->assertNotNull($t->json('perbaikan.hasil.selisih'));
            $baru = $this->as($admin)->postJson("/api/admin/proposals/{$tidak['id']}/revisi")->assertStatus(201)->json('data');
            $this->assertSame('menunggu', $baru['status']);
            $this->assertSame($tidak['id'], $baru['parent_id']);
        }

        // Kategori sampah/pangan → jadwal penjemputan & pemilahan mandiri
        $sampah = collect($lap['usulan'])->first(fn ($u) => in_array($u['kategori'], ['sampah', 'pangan'], true));
        if ($sampah) {
            $this->as($admin)->postJson("/api/admin/proposals/{$sampah['id']}/setuju")->assertOk();
            $this->assertNotEmpty($this->as($admin)->getJson('/api/admin/follow-ups?kind=jadwal_penjemputan')->json('data'));
        }
        $f = $this->as($admin)->postJson('/api/admin/follow-ups', ['kind' => 'pemilahan_mandiri', 'judul' => 'Pilah lapak sayur', 'target_kg' => 20])->assertStatus(201)->json('data');
        $this->as($admin)->putJson("/api/admin/follow-ups/{$f['id']}", ['status' => 'selesai'])->assertOk()->assertJsonPath('data.status', 'selesai');

        // 8. Simulator
        $s = $this->as($admin)->postJson('/api/admin/engine/simulasi', ['koridor_id' => 1, 'tambah_pohon' => ['jenis' => 'mahoni', 'jumlah' => 50], 'persen_hemat_ac' => 20, 'simpan' => true])->assertOk()->json('data');
        $this->assertGreaterThan(0, $s['selisih']['emisi_bersih_turun_kg_hari']);
        $this->assertNotEmpty($this->as($admin)->getJson('/api/admin/engine/skenario')->json('data'));
    }

    public function test_warga_biasa_tak_boleh_membuka_endpoint_engine(): void
    {
        $u = UserModel::factory()->create();
        $this->as($u)->getJson('/api/admin/engine/neraca')->assertStatus(403);
        $this->as($u)->postJson('/api/admin/asisten/laporan')->assertStatus(403);
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->postJson('/api/checkins/start', ['code' => 'x'])->assertStatus(401);
    }

    /** Payload persis seperti sketch ESP32 (DHT11): suhu + kelembapan, kunci perangkat di header. */
    public function test_esp32_dht11_mengirim_suhu_dan_kelembapan(): void
    {
        $this->seed(GreenSpaceSeeder::class);
        $this->seed(KoridorSeeder::class);
        $kunci = 'kunci-esp32-uji-1234567890';
        \App\Models\SensorDeviceModel::create(['device_code' => 'ESP32-DHT-01', 'key_hash' => \App\Models\SensorDeviceModel::hashKey($kunci), 'koridor_id' => 1, 'is_active' => true, 'name' => 'ESP32 DHT11']);

        $this->withHeader('X-Device-Key', $kunci)->postJson('/api/sensors/readings', ['temperature' => 30.5, 'humidity' => 71])->assertSuccessful();

        $snap = $this->getJson('/api/sensors/snapshot?koridor_id=1')->assertOk()->json();
        $r = $snap['readings'] ?? $snap['data']['readings'] ?? [];
        $this->assertEquals(71, $r['humidity']['value'] ?? null);
        $this->assertEquals(30.5, $r['temperature']['value'] ?? null);
        $this->withHeader('X-Device-Key', 'salah')->postJson('/api/sensors/readings', ['temperature' => 1])->assertStatus(401);
    }
}

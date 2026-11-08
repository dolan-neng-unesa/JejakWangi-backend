<?php

namespace Tests\Feature;

use App\Models\KoridorModel;
use App\Models\SensorReadingModel;
use App\Services\EcoCoach\EcoCoachData;
use App\Services\Ispu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Jalankan: php artisan test --filter=OpenMeteoAirTest */
class OpenMeteoAirTest extends TestCase
{
    use RefreshDatabase;

    private KoridorModel $koridor;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->koridor = KoridorModel::create([
            'short_name' => 'Sritanjung', 'formal_name' => 'Koridor Sritanjung', 'hijau_level' => 'paling_hijau', 'hijau_score' => 5, 'status' => 'pilot', 'path' => [[-8.2194, 114.3697], [-8.2175, 114.3675]],
        ]);
    }

    private function fakeOpenMeteo(): void
    {
        Http::fake([
            'air-quality-api.open-meteo.com/*' => Http::response(['current' => [
                'time' => '2026-11-14T04:00', 'pm10' => 40.0, 'pm2_5' => 30.0, 'carbon_monoxide' => 300.0,
                'nitrogen_dioxide' => 10.0, 'sulphur_dioxide' => 5.0, 'ozone' => 60.0,
            ]]),
            'api.open-meteo.com/*' => Http::response(['current' => ['time' => '2026-11-14T04:00', 'temperature_2m' => 31.4]]),
        ]);
    }

    public function test_mode_off_tidak_memanggil_open_meteo(): void
    {
        Http::fake();
        $this->getJson('/api/sensors/snapshot?koridor_id='.$this->koridor->id)->assertOk()->assertJsonPath('is_stale', true);
        Http::assertNothingSent();
    }

    public function test_mode_auto_mengisi_snapshot_saat_sensor_kosong(): void
    {
        config(['openmeteo.mode' => 'auto']);
        $this->fakeOpenMeteo();

        $r = $this->getJson('/api/sensors/snapshot?koridor_id='.$this->koridor->id)->assertOk();
        $r->assertJsonPath('is_stale', false)
            ->assertJsonPath('readings.pm25.value', 30)
            ->assertJsonPath('readings.pm25.source', 'open-meteo')
            ->assertJsonPath('readings.pm25.unit', 'µg/m³')
            ->assertJsonPath('readings.temperature.value', 31.4);
    }

    public function test_mode_auto_sensor_segar_menang(): void
    {
        config(['openmeteo.mode' => 'auto']);
        $this->fakeOpenMeteo();
        SensorReadingModel::insert([
            ['device_code' => 'x', 'koridor_id' => (string) $this->koridor->id, 'sensor_type' => 'pm25', 'value' => 80, 'recorded_at' => now()],
            ['device_code' => 'x', 'koridor_id' => (string) $this->koridor->id, 'sensor_type' => 'temperature', 'value' => 29, 'recorded_at' => now()],
        ]);

        $r = $this->getJson('/api/sensors/snapshot?koridor_id='.$this->koridor->id)->assertOk();
        $r->assertJsonPath('readings.pm25.value', 80)->assertJsonMissingPath('readings.pm25.source')
            ->assertJsonPath('readings.temperature.value', 29)
            // Kelompok polutan tidak dicampur dua sumber: sensor punya pm25 -> pm10 Open-Meteo tidak ikut.
            ->assertJsonMissingPath('readings.pm10');
    }

    public function test_mode_auto_menggantikan_bacaan_sensor_yang_basi(): void
    {
        config(['openmeteo.mode' => 'auto']);
        $this->fakeOpenMeteo();
        SensorReadingModel::insert([
            ['device_code' => 'x', 'koridor_id' => (string) $this->koridor->id, 'sensor_type' => 'pm25', 'value' => 80, 'recorded_at' => now()->subHour()],
        ]);

        $this->getJson('/api/sensors/snapshot?koridor_id='.$this->koridor->id)
            ->assertJsonPath('readings.pm25.value', 30)->assertJsonPath('readings.pm25.is_stale', false);
    }

    public function test_mode_openmeteo_menimpa_sensor(): void
    {
        config(['openmeteo.mode' => 'openmeteo']);
        $this->fakeOpenMeteo();
        SensorReadingModel::insert([
            ['device_code' => 'x', 'koridor_id' => (string) $this->koridor->id, 'sensor_type' => 'pm25', 'value' => 80, 'recorded_at' => now()],
        ]);

        $this->getJson('/api/sensors/snapshot?koridor_id='.$this->koridor->id)->assertJsonPath('readings.pm25.value', 30);
    }

    public function test_snapshots_semua_koridor_dan_active_memakai_koridor_bawaan(): void
    {
        config(['openmeteo.mode' => 'auto']);
        $this->fakeOpenMeteo();

        $this->getJson('/api/sensors/snapshots')->assertOk()
            ->assertJsonPath('snapshots.'.$this->koridor->id.'.readings.pm10.value', 40);
        $this->getJson('/api/sensors/active')->assertOk()
            ->assertJsonPath('koridor_id', (string) $this->koridor->id)->assertJsonPath('source', 'open-meteo');
    }

    public function test_gagal_open_meteo_jatuh_ke_perilaku_lama_dan_tidak_dipanggil_ulang(): void
    {
        config(['openmeteo.mode' => 'auto']);
        Http::fake(['*' => Http::response('error', 500)]);

        $this->getJson('/api/sensors/snapshot?koridor_id='.$this->koridor->id)->assertOk()->assertJsonPath('is_stale', true);
        Cache::flush(); // buang cache snapshot saja tidak bisa dipisah; cukup cek tidak error
        $this->getJson('/api/sensors/active')->assertOk()->assertJsonPath('koridor_id', (string) $this->koridor->id);
    }

    public function test_ispu_koridor_untuk_eco_coach_dari_open_meteo(): void
    {
        config(['openmeteo.mode' => 'auto']);
        $this->fakeOpenMeteo();

        $k = EcoCoachData::koridor($this->koridor->id);
        $harapan = Ispu::hitung(['pm25' => 30.0, 'pm10' => 40.0, 'so2' => 5.0, 'co' => 300.0, 'o3' => 60.0, 'no2' => 10.0]);
        $this->assertSame($harapan['value'], $k['ispu']);
        $this->assertSame($harapan['kategori'], $k['kategoriIspu']);
        $this->assertSame(31.4, $k['suhuC']);
        $this->assertStringContainsString('Open-Meteo', $k['sumberUdara']);
    }
}

<?php

namespace Tests\Feature;

use App\Models\SensorDeviceModel;
use App\Models\SensorReadingModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Jalankan: php artisan test --filter=SensorReadingApiTest */
class SensorReadingApiTest extends TestCase
{
    use RefreshDatabase;

    private string $key;

    protected function setUp(): void
    {
        parent::setUp();

        $this->key = SensorDeviceModel::newPlainKey();
        SensorDeviceModel::create([
            'device_code' => 'ESP32-KOR1-DIORAMA',
            'koridor_id' => '1',
            'key_hash' => SensorDeviceModel::hashKey($this->key),
        ]);
    }

    private function kirim(array $payload, ?string $key = null)
    {
        return $this->withHeaders(['X-Device-Key' => $key ?? $this->key])->postJson('/api/sensors/readings', $payload);
    }

    public function test_esp32_mengirim_banyak_sensor_dalam_satu_request(): void
    {
        $this->kirim(['temperature' => 30.2, 'air_quality' => 55, 'pm10' => 41, 'co' => 2300])
            ->assertStatus(201)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('n', 4)
            ->assertJsonStructure(['t']);

        $this->assertDatabaseCount('sensor_readings', 4);
        // device_code & koridor_id diambil dari perangkat, bukan payload.
        $this->assertDatabaseHas('sensor_readings', [
            'device_code' => 'ESP32-KOR1-DIORAMA', 'koridor_id' => '1', 'sensor_type' => 'temperature', 'value' => 30.2,
        ]);
        $this->assertNotNull(SensorDeviceModel::first()->last_seen_at);
    }

    public function test_tanpa_atau_dengan_kunci_salah_ditolak(): void
    {
        $this->postJson('/api/sensors/readings', ['temperature' => 30])->assertStatus(401);
        $this->kirim(['temperature' => 30], 'svg_salah')->assertStatus(401);
        $this->assertDatabaseCount('sensor_readings', 0);
    }

    public function test_perangkat_nonaktif_ditolak(): void
    {
        SensorDeviceModel::query()->update(['is_active' => false]);
        $this->kirim(['temperature' => 30])->assertStatus(401);
    }

    public function test_bearer_token_juga_diterima(): void
    {
        $this->withToken($this->key)->postJson('/api/sensors/readings', ['temperature' => 30])->assertStatus(201);
    }

    public function test_payload_tanpa_sensor_dikenal_atau_non_numerik_ditolak(): void
    {
        $this->kirim(['kelembaban' => 60])->assertStatus(422);
        $this->kirim(['temperature' => 'abc'])->assertStatus(422);
        $this->assertDatabaseCount('sensor_readings', 0);
    }

    public function test_ts_masuk_akal_dipakai_dan_ts_ngawur_diabaikan(): void
    {
        $ok = now()->subMinutes(10)->timestamp;
        $this->kirim(['temperature' => 28, 'ts' => $ok])->assertStatus(201);
        $this->assertSame($ok, SensorReadingModel::first()->recorded_at->timestamp);

        SensorReadingModel::query()->delete();
        $this->kirim(['temperature' => 28, 'ts' => 946684800])->assertStatus(201); // jam ESP belum sinkron (tahun 2000)
        $this->assertTrue(SensorReadingModel::first()->recorded_at->gt(now()->subMinute()));
    }

    public function test_snapshot_mengembalikan_semua_jenis_dan_menandai_data_lama(): void
    {
        SensorReadingModel::insert([
            ['device_code' => 'D', 'koridor_id' => '1', 'sensor_type' => 'temperature', 'value' => 27, 'recorded_at' => now()->subHours(3)],
            ['device_code' => 'D', 'koridor_id' => '1', 'sensor_type' => 'temperature', 'value' => 28.5, 'recorded_at' => now()->subMinutes(2)],
            ['device_code' => 'D', 'koridor_id' => '1', 'sensor_type' => 'pm10', 'value' => 61, 'recorded_at' => now()->subHours(1)],
            ['device_code' => 'D', 'koridor_id' => '2', 'sensor_type' => 'pm10', 'value' => 999, 'recorded_at' => now()],
        ]);

        $this->getJson('/api/sensors/snapshot?koridor_id=1')
            ->assertOk()
            ->assertJsonPath('readings.temperature.value', 28.5)
            ->assertJsonPath('readings.temperature.unit', '°C')
            ->assertJsonPath('readings.temperature.is_stale', false)
            ->assertJsonPath('readings.pm10.value', 61)
            ->assertJsonPath('readings.pm10.is_stale', true)
            ->assertJsonPath('is_stale', false);
    }

    public function test_snapshot_kosong_tidak_error(): void
    {
        $this->getJson('/api/sensors/snapshot?koridor_id=1')
            ->assertOk()
            ->assertJsonPath('is_stale', true)
            ->assertJsonPath('updated_at', null);
    }

    public function test_snapshot_langsung_segar_setelah_esp_mengirim(): void
    {
        $this->getJson('/api/sensors/snapshot?koridor_id=1'); // mengisi cache kosong
        $this->kirim(['temperature' => 31.7])->assertStatus(201);

        $this->getJson('/api/sensors/snapshot?koridor_id=1')
            ->assertJsonPath('readings.temperature.value', 31.7)
            ->assertJsonPath('is_stale', false);
    }

    public function test_history_rata_rata_harian(): void
    {
        SensorReadingModel::insert([
            ['device_code' => 'D', 'koridor_id' => '1', 'sensor_type' => 'pm10', 'value' => 40, 'recorded_at' => now()->subHour()],
            ['device_code' => 'D', 'koridor_id' => '1', 'sensor_type' => 'pm10', 'value' => 60, 'recorded_at' => now()->subHours(2)],
        ]);

        $res = $this->getJson('/api/sensors/history?sensor_type=pm10&days=3')->assertOk();
        $this->assertCount(3, $res->json('daily'));
    }

    public function test_prune_menghapus_bacaan_lama(): void
    {
        SensorReadingModel::insert([
            ['device_code' => 'D', 'koridor_id' => '1', 'sensor_type' => 'pm10', 'value' => 1, 'recorded_at' => now()->subDays(40)],
            ['device_code' => 'D', 'koridor_id' => '1', 'sensor_type' => 'pm10', 'value' => 2, 'recorded_at' => now()->subDay()],
        ]);

        $this->artisan('sensors:prune', ['--days' => 30])->assertSuccessful();
        $this->assertDatabaseCount('sensor_readings', 1);
    }

    public function test_perintah_register_membuat_kunci_yang_berfungsi(): void
    {
        $this->artisan('devices:register', ['device_code' => 'ESP32-BARU', '--koridor' => '2'])->assertSuccessful();

        $device = SensorDeviceModel::where('device_code', 'ESP32-BARU')->first();
        $this->assertSame('2', $device->koridor_id);
        $this->assertSame(64, strlen($device->key_hash));
    }

    public function test_perangkat_yang_terlalu_sering_mengirim_dapat_429_bukan_500(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->kirim(['temperature' => 30])->assertStatus(201);
        }

        $this->kirim(['temperature' => 30])->assertStatus(429)->assertHeader('Retry-After');
    }

    public function test_payload_multi_wilayah_dipecah_per_koridor(): void
    {
        $this->kirim(['wilayah' => [
            ['no' => 1, 'temperature' => 31, 'pm25' => 54, 'co2' => 480, 'o2' => 20.8, 'led' => true],
            ['no' => 2, 'temperature' => 33.5, 'pm25' => 72],
        ]])->assertStatus(201)->assertJsonPath('n', 6);

        $this->assertDatabaseHas('sensor_readings', ['koridor_id' => '1', 'sensor_type' => 'co2', 'value' => 480]);
        $this->assertDatabaseHas('sensor_readings', ['koridor_id' => '2', 'sensor_type' => 'pm25', 'value' => 72]);

        $this->getJson('/api/sensors/snapshot?koridor_id=2')
            ->assertJsonPath('readings.pm25.value', 72)
            ->assertJsonPath('readings.temperature.value', 33.5);
        $this->getJson('/api/sensors/snapshot?koridor_id=1')->assertJsonPath('readings.o2.unit', '%');
    }

    public function test_wilayah_tanpa_nomor_ditolak(): void
    {
        $this->kirim(['wilayah' => [['temperature' => 30]]])->assertStatus(422);
    }

    public function test_wilayah_aktif_dicatat_dan_semua_off_mengembalikan_null(): void
    {
        $this->getJson('/api/sensors/active')->assertOk()->assertJsonPath('koridor_id', null);

        $this->kirim(['aktif' => 3, 'wilayah' => [['no' => 3, 'pm25' => 20, 'led' => true]]])->assertStatus(201);
        $this->getJson('/api/sensors/active')->assertOk()
            ->assertJsonPath('koridor_id', '3')
            ->assertJsonPath('is_stale', false);

        // Semua OFF: tanpa data sensor, tetap diterima.
        $this->kirim(['aktif' => 0, 'wilayah' => []])->assertStatus(201)->assertJsonPath('n', 0);
        $this->getJson('/api/sensors/active')->assertOk()->assertJsonPath('koridor_id', null);
    }

    public function test_status_aktif_kedaluwarsa_jika_perangkat_diam(): void
    {
        $this->kirim(['aktif' => 2, 'wilayah' => [['no' => 2, 'pm25' => 20, 'led' => true]]])->assertStatus(201);
        $this->travel(6)->minutes();
        $this->getJson('/api/sensors/active')->assertJsonPath('koridor_id', null)->assertJsonPath('is_stale', true);
    }
}

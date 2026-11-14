<?php

namespace Tests\Feature;

use App\Models\FestivalModel;
use App\Models\MoodLogModel;
use App\Models\UserModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/** Autentikasi, otorisasi, dan batasan input — jalankan: php artisan test --filter=SecurityHardeningTest */
class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    private function bearer(string $token): array
    {
        return ['Authorization' => "Bearer {$token}"];
    }

    private function admin(): array
    {
        $admin = UserModel::factory()->create(['is_admin' => true]);

        return [$admin, $admin->createToken('t')->plainTextToken];
    }

    // --- Login ---

    public function test_akun_nonaktif_tidak_bisa_login(): void
    {
        UserModel::factory()->create(['email' => 'off@jejakwangi.test', 'password' => Hash::make('sandiaman'), 'is_active' => false]);

        $this->postJson('/api/auth/login', ['email' => 'off@jejakwangi.test', 'password' => 'sandiaman'])
            ->assertStatus(403);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_token_milik_akun_nonaktif_ditolak_di_endpoint_terlindungi(): void
    {
        $user = UserModel::factory()->create();
        $token = $user->createToken('t')->plainTextToken;
        $user->update(['is_active' => false]);

        $this->withHeaders($this->bearer($token))->getJson('/api/auth/me')->assertStatus(403);
    }

    public function test_admin_nonaktif_ditolak_di_endpoint_admin(): void
    {
        [$admin, $token] = $this->admin();
        $admin->update(['is_active' => false]);

        $this->withHeaders($this->bearer($token))->getJson('/api/admin/users')->assertStatus(403);
    }

    public function test_login_tidak_peka_huruf_besar_dan_spasi_pada_email(): void
    {
        UserModel::factory()->create(['email' => 'levina@jejakwangi.test', 'password' => Hash::make('sandiaman')]);

        $this->postJson('/api/auth/login', ['email' => '  Levina@JEJAKWANGI.test ', 'password' => 'sandiaman'])
            ->assertStatus(200)->assertJsonStructure(['user', 'token', 'expires_at']);
    }

    public function test_login_email_tak_terdaftar_dan_sandi_salah_membalas_sama(): void
    {
        UserModel::factory()->create(['email' => 'ada@jejakwangi.test', 'password' => Hash::make('sandiaman')]);

        $a = $this->postJson('/api/auth/login', ['email' => 'ada@jejakwangi.test', 'password' => 'salah-sekali']);
        $b = $this->postJson('/api/auth/login', ['email' => 'tidakada@jejakwangi.test', 'password' => 'salah-sekali']);

        $a->assertStatus(401);
        $b->assertStatus(401);
        $this->assertSame($a->json('message'), $b->json('message')); // tidak membocorkan email terdaftar
    }

    public function test_login_dibatasi_setelah_lima_percobaan_gagal(): void
    {
        UserModel::factory()->create(['email' => 'brute@jejakwangi.test', 'password' => Hash::make('sandiaman')]);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/login', ['email' => 'brute@jejakwangi.test', 'password' => "salah-{$i}"])->assertStatus(401);
        }

        $this->postJson('/api/auth/login', ['email' => 'brute@jejakwangi.test', 'password' => 'sandiaman'])
            ->assertStatus(429)
            ->assertHeader('Retry-After');
    }

    public function test_token_kedaluwarsa_sesuai_konfigurasi(): void
    {
        config(['sanctum.expiration' => 10]);
        UserModel::factory()->create(['email' => 'exp@jejakwangi.test', 'password' => Hash::make('sandiaman')]);

        $token = $this->postJson('/api/auth/login', ['email' => 'exp@jejakwangi.test', 'password' => 'sandiaman'])
            ->assertStatus(200)->json('token');

        $this->withHeaders($this->bearer($token))->getJson('/api/auth/me')->assertStatus(200);

        $this->travel(11)->minutes();
        // Guard Sanctum meng-cache pengguna per proses test; di produksi tiap request mulai bersih.
        $this->app['auth']->forgetGuards();

        $this->withHeaders($this->bearer($token))->getJson('/api/auth/me')->assertStatus(401);
    }

    public function test_jumlah_token_aktif_per_akun_dibatasi_lima(): void
    {
        $user = UserModel::factory()->create(['email' => 'multi@jejakwangi.test', 'password' => Hash::make('sandiaman')]);
        for ($i = 0; $i < 5; $i++) {
            $user->createToken("lama-{$i}");
        }

        $this->postJson('/api/auth/login', ['email' => 'multi@jejakwangi.test', 'password' => 'sandiaman'])->assertStatus(200);

        $this->assertSame(5, $user->tokens()->count());
        $this->assertFalse($user->tokens()->where('name', 'lama-0')->exists()); // yang tertua dibuang
    }

    public function test_sandi_kurang_dari_delapan_karakter_ditolak_saat_daftar(): void
    {
        $this->postJson('/api/auth/register', ['name' => 'X', 'email' => 'x@jejakwangi.test', 'password' => '1234567'])
            ->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_email_didaftarkan_dalam_huruf_kecil_dan_duplikat_beda_kapital_ditolak(): void
    {
        $this->postJson('/api/auth/register', ['name' => 'A', 'email' => 'Campur@JejakWangi.Test', 'password' => 'rahasia123'])
            ->assertStatus(201)->assertJsonPath('user.email', 'campur@jejakwangi.test');

        $this->postJson('/api/auth/register', ['name' => 'B', 'email' => 'CAMPUR@jejakwangi.test', 'password' => 'rahasia123'])
            ->assertStatus(422);
    }

    // --- Pencabutan token oleh admin ---

    public function test_menonaktifkan_pengguna_mencabut_semua_tokennya(): void
    {
        [, $adminToken] = $this->admin();
        $target = UserModel::factory()->create();
        $target->createToken('a');
        $target->createToken('b');

        $this->withHeaders($this->bearer($adminToken))
            ->putJson("/api/admin/users/{$target->id}", ['is_active' => false])
            ->assertStatus(200);

        $this->assertSame(0, $target->tokens()->count());
    }

    public function test_mengganti_sandi_pengguna_mencabut_tokennya(): void
    {
        [, $adminToken] = $this->admin();
        $target = UserModel::factory()->create();
        $target->createToken('a');

        $this->withHeaders($this->bearer($adminToken))
            ->putJson("/api/admin/users/{$target->id}", ['password' => 'sandi-baru-123'])
            ->assertStatus(200);

        $this->assertSame(0, $target->tokens()->count());
    }

    public function test_menghapus_pengguna_menghapus_tokennya(): void
    {
        [, $adminToken] = $this->admin();
        $target = UserModel::factory()->create();
        $target->createToken('a');

        $this->withHeaders($this->bearer($adminToken))->deleteJson("/api/admin/users/{$target->id}")->assertStatus(200);

        $this->assertSame(0, PersonalAccessToken::where('tokenable_id', $target->id)->count());
    }

    public function test_admin_mengganti_sandi_sendiri_tidak_mencabut_token_yang_sedang_dipakai(): void
    {
        [$admin, $token] = $this->admin();
        $admin->createToken('perangkat-lain');

        $this->withHeaders($this->bearer($token))
            ->putJson("/api/admin/users/{$admin->id}", ['password' => 'sandi-baru-123'])
            ->assertStatus(200);

        $this->assertSame(1, $admin->tokens()->count()); // hanya token yang dipakai yang tersisa
    }

    // --- Batas input publik ---

    public function test_id_non_numerik_membalas_404_bukan_500(): void
    {
        $this->getJson('/api/festivals/abc')->assertStatus(404);
        $this->getJson('/api/umkm/abc')->assertStatus(404);
        $this->getJson('/api/koridors/abc')->assertStatus(404);
    }

    public function test_per_page_dijepit_ke_rentang_wajar(): void
    {
        foreach (range(1, 3) as $i) {
            FestivalModel::create(['name' => "Event {$i}", 'location_name' => 'Taman', 'event_date' => now()->addDays($i)->toDateString()]);
        }

        $this->getJson('/api/festivals?per_page=0')->assertStatus(200)->assertJsonPath('meta.per_page', 1);
        $this->getJson('/api/festivals?per_page=-5')->assertStatus(200)->assertJsonPath('meta.per_page', 1);
        $this->getJson('/api/festivals?per_page=1000000')->assertStatus(200)->assertJsonPath('meta.per_page', 100);
        $this->getJson('/api/umkm?per_page=1000000')->assertStatus(200)->assertJsonPath('meta.per_page', 100);
    }

    public function test_filter_festival_yang_tidak_valid_membalas_422_bukan_500(): void
    {
        $this->getJson('/api/festivals?month=abc')->assertStatus(422)->assertJsonValidationErrors('month');
        $this->getJson('/api/festivals?category=ngawur')->assertStatus(422)->assertJsonValidationErrors('category');
        $this->getJson('/api/festivals?month=2026-10&category=seni')->assertStatus(200);
    }

    public function test_activity_monthly_membatasi_jumlah_bulan(): void
    {
        [, $token] = $this->admin();

        $this->withHeaders($this->bearer($token))->getJson('/api/admin/activity-monthly?months=100000')
            ->assertStatus(200)->assertJsonCount(24, 'data');
        $this->withHeaders($this->bearer($token))->getJson('/api/admin/activity-monthly?months=0')
            ->assertStatus(200)->assertJsonCount(1, 'data');
    }

    public function test_activity_monthly_menghitung_pengguna_dan_mood_bulan_ini(): void
    {
        [, $token] = $this->admin(); // 1 pengguna bulan ini
        UserModel::factory()->count(2)->create();
        MoodLogModel::create(['green_space_id' => 1, 'mood_score' => 4, 'logged_at' => now()]);

        $row = collect($this->withHeaders($this->bearer($token))->getJson('/api/admin/activity-monthly?months=3')->json('data'))->last();

        $this->assertSame(3, $row['aktif']);
        $this->assertSame(1, $row['catatan_mood']);
    }

    public function test_pencarian_pengguna_tidak_menganggap_persen_sebagai_wildcard(): void
    {
        [, $token] = $this->admin();
        UserModel::factory()->create(['name' => 'Diskon 100% Asli', 'email' => 'diskon@jejakwangi.test']);
        UserModel::factory()->create(['name' => 'Orang Biasa', 'email' => 'biasa@jejakwangi.test']);

        $res = $this->withHeaders($this->bearer($token))->getJson('/api/admin/users?q=' . urlencode('%'))->assertStatus(200);

        $this->assertSame(1, $res->json('filtered_total'));
        $this->assertSame('Diskon 100% Asli', $res->json('data.0.nama'));
    }

    public function test_ringkasan_pengguna_dihitung_benar(): void
    {
        [, $token] = $this->admin();
        UserModel::factory()->count(2)->create(['is_active' => false]);

        $res = $this->withHeaders($this->bearer($token))->getJson('/api/admin/users')->assertStatus(200);

        $this->assertSame(3, $res->json('total'));
        $this->assertSame(1, $res->json('aktif'));
        $this->assertSame(2, $res->json('nonaktif'));
        $this->assertSame(3, $res->json('baru_7_hari'));
    }

    // --- Mood: kontributor unik ---

    public function test_kontributor_unik_menggabungkan_akun_sesi_tamu_dan_tanpa_identitas(): void
    {
        $user = UserModel::factory()->create();
        $log = fn (array $extra) => MoodLogModel::create($extra + ['green_space_id' => 1, 'mood_score' => 3, 'logged_at' => now()]);

        $log(['user_id' => $user->id]);                         // akun A
        $log(['user_id' => $user->id]);                         // akun A lagi → tetap 1
        $log(['anonymous_session_id' => 'sesi-1']);              // tamu 1
        $log(['anonymous_session_id' => 'sesi-1']);              // tamu 1 lagi → tetap 1
        $log([]);                                               // tanpa identitas → 1
        $log(['anonymous_session_id' => '']);                    // string kosong = tanpa identitas → 1
        $log(['user_id' => $user->id, 'anonymous_session_id' => 'sesi-1']); // akun menang, bukan sesi

        $this->getJson('/api/mood-logs/summary?green_space_id=1')
            ->assertStatus(200)
            ->assertJsonPath('total_entries', 7)
            ->assertJsonPath('unique_contributors', 4); // 1 akun + 1 sesi + 2 tanpa identitas
    }

    // --- Sensor: snapshot massal ---

    public function test_snapshots_mengembalikan_semua_koridor_dalam_satu_request(): void
    {
        $now = now();
        foreach ([['1', 'temperature', 30.5], ['1', 'pm25', 12], ['2', 'temperature', 28.1]] as [$k, $type, $v]) {
            \App\Models\SensorReadingModel::insert([
                'device_code' => 'D', 'koridor_id' => $k, 'sensor_type' => $type, 'value' => $v, 'recorded_at' => $now,
            ]);
        }

        $res = $this->getJson('/api/sensors/snapshots')->assertStatus(200);

        $this->assertEqualsCanonicalizing(['1', '2'], array_keys($res->json('snapshots')));
        $this->assertSame(30.5, $res->json('snapshots.1.readings.temperature.value'));
        $this->assertSame(12.0, (float) $res->json('snapshots.1.readings.pm25.value'));
        $this->assertSame(28.1, $res->json('snapshots.2.readings.temperature.value'));
        $this->assertFalse($res->json('snapshots.1.is_stale'));
    }

    public function test_snapshots_kosong_bila_belum_ada_bacaan(): void
    {
        $this->getJson('/api/sensors/snapshots')->assertStatus(200)->assertExactJson(['snapshots' => []]);
    }

    public function test_snapshot_menolak_koridor_id_berbentuk_aneh(): void
    {
        $this->getJson('/api/sensors/snapshot?koridor_id=' . urlencode('1;DROP TABLE x'))->assertStatus(422);
        $this->getJson('/api/sensors/snapshot?koridor_id=2')->assertStatus(200);
    }

    // --- HTTP caching & CORS ---

    public function test_data_referensi_membawa_cache_control_dan_etag_serta_mendukung_304(): void
    {
        $first = $this->getJson('/api/koridors')->assertStatus(200);
        $first->assertHeader('ETag');
        $this->assertStringContainsString('max-age=60', $first->headers->get('Cache-Control'));

        $this->withHeaders(['If-None-Match' => $first->headers->get('ETag')])
            ->getJson('/api/koridors')->assertStatus(304);
    }

    public function test_preflight_boleh_disimpan_browser(): void
    {
        $this->call('OPTIONS', '/api/auth/login', [], [], [], [
            'HTTP_ORIGIN' => 'http://localhost:5173',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'content-type,authorization',
        ])->assertHeader('Access-Control-Max-Age', '600')
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173');
    }
}

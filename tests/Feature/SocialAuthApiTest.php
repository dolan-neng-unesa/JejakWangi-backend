<?php

namespace Tests\Feature;

use App\Models\AdminNotificationModel;
use App\Models\UserModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SocialAuthApiTest extends TestCase
{
    use RefreshDatabase;

    private const GOOGLE_CLIENT = 'google-client-123.apps.googleusercontent.com';
    private const FB_APP = '555000111';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.google.client_id' => self::GOOGLE_CLIENT,
            'services.facebook.app_id' => self::FB_APP,
            'services.facebook.app_secret' => 'fb-secret',
        ]);
    }

    /** Http::fake() menumpuk stub (yang pertama cocok menang) — mulai dari Factory bersih tiap kali. */
    private function freshHttp(): void
    {
        app()->forgetInstance(HttpFactory::class);
        Http::clearResolvedInstance(HttpFactory::class);
    }

    private function fakeGoogle(array $overrides = [], int $status = 200): void
    {
        $this->freshHttp();
        Http::fake([
            'oauth2.googleapis.com/tokeninfo*' => Http::response(array_merge([
                'iss' => 'https://accounts.google.com',
                'aud' => self::GOOGLE_CLIENT,
                'sub' => 'g-1001',
                'email' => 'Warga@Gmail.com',
                'email_verified' => 'true',
                'name' => 'Warga Banyuwangi',
                'exp' => (string) (time() + 3600),
            ], $overrides), $status),
        ]);
    }

    private function fakeFacebook(array $debug = [], array $profile = [], int $profileStatus = 200): void
    {
        $this->freshHttp();
        Http::fake([
            'graph.facebook.com/*/debug_token*' => Http::response(['data' => array_merge([
                'is_valid' => true, 'app_id' => self::FB_APP, 'user_id' => 'fb-2002',
            ], $debug)]),
            'graph.facebook.com/*/me*' => Http::response(array_merge([
                'id' => 'fb-2002', 'name' => 'Pejalan FB', 'email' => 'pejalan@fb.test',
            ], $profile), $profileStatus),
        ]);
    }

    private function google(string $token = 'id-token'): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/auth/social', ['provider' => 'google', 'token' => $token]);
    }

    private function facebook(string $token = 'fb-token'): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/auth/social', ['provider' => 'facebook', 'token' => $token]);
    }

    public function test_google_pengguna_baru_dibuat_dan_menerima_token(): void
    {
        $this->fakeGoogle();

        $this->google()
            ->assertStatus(201)
            ->assertJsonPath('user.email', 'warga@gmail.com')
            ->assertJsonPath('user.name', 'Warga Banyuwangi')
            ->assertJsonPath('user.is_admin', false)
            ->assertJsonStructure(['user' => ['id', 'name', 'email'], 'token', 'expires_at']);

        $user = UserModel::where('email', 'warga@gmail.com')->firstOrFail();
        $this->assertSame('g-1001', $user->google_id);
        $this->assertNotNull($user->email_verified_at);
        $this->assertFalse($user->is_admin);
        $this->assertSame(1, AdminNotificationModel::count());
    }

    public function test_token_yang_diterbitkan_benar_bisa_dipakai_ke_me(): void
    {
        $this->fakeGoogle();
        $token = $this->google()->json('token');

        $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/auth/me')
            ->assertOk()->assertJsonPath('data.email', 'warga@gmail.com');
    }

    public function test_google_login_kedua_memakai_akun_yang_sama(): void
    {
        $this->fakeGoogle();
        $this->google()->assertStatus(201);
        $this->google()->assertStatus(200);

        $this->assertSame(1, UserModel::count());
        $this->assertSame(1, AdminNotificationModel::count());
    }

    public function test_google_menautkan_akun_email_yang_sudah_ada_tanpa_membuat_duplikat(): void
    {
        $existing = UserModel::factory()->create(['email' => 'warga@gmail.com', 'is_admin' => false]);
        $this->fakeGoogle();

        $this->google()->assertStatus(200)->assertJsonPath('user.id', $existing->id);

        $this->assertSame(1, UserModel::count());
        $this->assertSame('g-1001', $existing->fresh()->google_id);
    }

    public function test_menautkan_akun_admin_tidak_mengubah_status_admin_dan_tidak_memberi_admin_ke_akun_baru(): void
    {
        UserModel::factory()->create(['email' => 'warga@gmail.com', 'is_admin' => true]);
        $this->fakeGoogle();
        $this->google()->assertOk()->assertJsonPath('user.is_admin', true); // admin tetap admin

        $this->fakeGoogle(['sub' => 'g-9', 'email' => 'baru@gmail.com']);
        $this->google()->assertStatus(201)->assertJsonPath('user.is_admin', false);
    }

    public function test_akun_yang_sudah_tertaut_ke_google_lain_ditolak(): void
    {
        $u = UserModel::factory()->create(['email' => 'warga@gmail.com']);
        $u->forceFill(['google_id' => 'g-lain'])->save();
        $this->fakeGoogle(['sub' => 'g-1001']);

        $this->google()->assertStatus(409);
    }

    public function test_google_audience_salah_ditolak_walau_token_valid_untuk_aplikasi_lain(): void
    {
        $this->fakeGoogle(['aud' => 'aplikasi-lain.apps.googleusercontent.com']);

        $this->google()->assertStatus(401);
        $this->assertSame(0, UserModel::count());
    }

    public function test_google_issuer_salah_atau_kedaluwarsa_ditolak(): void
    {
        $this->fakeGoogle(['iss' => 'https://evil.example.com']);
        $this->google()->assertStatus(401);

        $this->fakeGoogle(['exp' => (string) (time() - 10)]);
        $this->google()->assertStatus(401);
    }

    public function test_google_email_belum_terverifikasi_ditolak(): void
    {
        $this->fakeGoogle(['email_verified' => 'false']);

        $this->google()->assertStatus(422);
        $this->assertSame(0, UserModel::count());
    }

    public function test_google_menolak_token_dari_penyedia_dan_melaporkan_gangguan_penyedia(): void
    {
        $this->fakeGoogle(['error_description' => 'Invalid Value'], 400);
        $this->google()->assertStatus(401);

        $this->fakeGoogle([], 503);
        $this->google()->assertStatus(502);
    }

    public function test_akun_nonaktif_ditolak_baik_yang_sudah_tertaut_maupun_via_email(): void
    {
        $u = UserModel::factory()->create(['email' => 'warga@gmail.com', 'is_active' => false]);
        $this->fakeGoogle();
        $this->google()->assertStatus(403); // via email

        $u->forceFill(['google_id' => 'g-1001'])->save();
        $this->google()->assertStatus(403); // via ID penyedia
        $this->assertSame(0, $u->tokens()->count());
    }

    public function test_facebook_pengguna_baru_dibuat(): void
    {
        $this->fakeFacebook();

        $this->facebook()
            ->assertStatus(201)
            ->assertJsonPath('user.email', 'pejalan@fb.test')
            ->assertJsonStructure(['token']);

        $this->assertSame('fb-2002', UserModel::where('email', 'pejalan@fb.test')->value('facebook_id'));
    }

    public function test_facebook_token_milik_aplikasi_lain_atau_tidak_valid_ditolak(): void
    {
        $this->fakeFacebook(['app_id' => '999']);
        $this->facebook()->assertStatus(401);

        $this->fakeFacebook(['is_valid' => false]);
        $this->facebook()->assertStatus(401);

        $this->fakeFacebook(profile: ['id' => 'fb-orang-lain']); // profil tidak cocok dengan pemilik token
        $this->facebook()->assertStatus(401);

        $this->assertSame(0, UserModel::count());
    }

    public function test_facebook_tanpa_email_ditolak_dengan_pesan_jelas(): void
    {
        $this->fakeFacebook(profile: ['email' => null]);

        $this->facebook()->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'email'));
        $this->assertSame(0, UserModel::count());
    }

    public function test_penyedia_yang_belum_dikonfigurasi_menjawab_503_tanpa_menghubungi_penyedia(): void
    {
        config(['services.google.client_id' => null]);
        $this->freshHttp();
        Http::fake();

        $this->google()->assertStatus(503);
        Http::assertNothingSent();
    }

    public function test_validasi_input(): void
    {
        $this->postJson('/api/auth/social', ['provider' => 'twitter', 'token' => 'x'])->assertStatus(422);
        $this->postJson('/api/auth/social', ['provider' => 'google'])->assertStatus(422);
        $this->postJson('/api/auth/social', [])->assertStatus(422);
    }

    public function test_akun_sosial_tidak_bisa_login_dengan_kata_sandi_tebakan(): void
    {
        $this->fakeGoogle();
        $this->google()->assertStatus(201);

        foreach (['', 'password', '12345678', 'rahasia123'] as $guess) {
            $this->postJson('/api/auth/login', ['email' => 'warga@gmail.com', 'password' => $guess])
                ->assertStatus(in_array($guess, [''], true) ? 422 : 401);
        }
    }

    public function test_endpoint_providers_hanya_menampilkan_yang_aktif_dan_tidak_membocorkan_rahasia(): void
    {
        $res = $this->getJson('/api/auth/providers')->assertOk();
        $res->assertJsonPath('google.client_id', self::GOOGLE_CLIENT)
            ->assertJsonPath('facebook.app_id', self::FB_APP);
        $this->assertStringNotContainsString('fb-secret', $res->getContent());

        config(['services.facebook.app_secret' => null]);
        $this->getJson('/api/auth/providers')->assertJsonPath('facebook', null);

        config(['services.google.client_id' => '']);
        $this->getJson('/api/auth/providers')->assertJsonPath('google', null);
    }

    public function test_login_sosial_dibatasi_laju_per_ip(): void
    {
        $this->freshHttp();
        Http::fake();
        for ($i = 0; $i < 20; $i++) {
            $this->google("t{$i}")->assertStatus(401);
        }
        $this->google('terlalu-banyak')->assertStatus(429);
    }
}

<?php

namespace Tests\Feature;

use App\Models\UserModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_pengguna_baru_dapat_mendaftar_dan_menerima_token(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Radhiyyan',
            'email' => 'radhi@jejakwangi.test',
            'password' => 'rahasia123',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('user.email', 'radhi@jejakwangi.test')
            ->assertJsonStructure(['user' => ['id', 'name', 'email'], 'token']);

        $this->assertDatabaseHas('users', ['email' => 'radhi@jejakwangi.test']);
    }

    public function test_email_yang_sudah_terdaftar_ditolak(): void
    {
        UserModel::factory()->create(['email' => 'dipakai@jejakwangi.test']);

        $response = $this->postJson('/api/auth/register', [
            'name' => 'Lainnya',
            'email' => 'dipakai@jejakwangi.test',
            'password' => 'rahasia123',
        ]);

        $response->assertStatus(422);
    }

    public function test_login_dengan_kredensial_benar_menerima_token(): void
    {
        UserModel::factory()->create([
            'email' => 'levina@jejakwangi.test',
            'password' => Hash::make('sandiaman'),
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'levina@jejakwangi.test',
            'password' => 'sandiaman',
        ]);

        $response->assertStatus(200)->assertJsonStructure(['user', 'token']);
    }

    public function test_registrasi_publik_tidak_bisa_menjadikan_diri_admin(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Bukan Admin',
            'email' => 'bukan-admin@jejakwangi.test',
            'password' => 'rahasia123',
            'is_admin' => true, // seharusnya diabaikan
        ]);

        $response->assertStatus(201)->assertJsonPath('user.is_admin', false);
    }

    public function test_user_dengan_is_admin_true_bisa_login_dan_field_is_admin_terlihat(): void
    {
        UserModel::factory()->create([
            'email' => 'admin-test@jejakwangi.test',
            'password' => Hash::make('adminpass'),
            'is_admin' => true,
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'admin-test@jejakwangi.test',
            'password' => 'adminpass',
        ]);

        $response->assertStatus(200)->assertJsonPath('user.is_admin', true);
    }

    public function test_login_dengan_password_salah_ditolak(): void
    {
        UserModel::factory()->create([
            'email' => 'levina@jejakwangi.test',
            'password' => Hash::make('sandiaman'),
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'levina@jejakwangi.test',
            'password' => 'salah',
        ]);

        $response->assertStatus(401);
    }

    public function test_endpoint_me_butuh_token_yang_valid(): void
    {
        $this->getJson('/api/auth/me')->assertStatus(401);

        $user = UserModel::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/auth/me')
            ->assertStatus(200)
            ->assertJsonPath('data.email', $user->email);
    }

    public function test_logout_mencabut_token_yang_sedang_dipakai(): void
    {
        $user = UserModel::factory()->create();
        $token = $user->createToken('test')->plainTextToken;
        $tokenId = explode('|', $token)[0];

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/auth/logout')
            ->assertStatus(200);

        // Baris token harus sudah hilang dari personal_access_tokens setelah logout
        // (memverifikasi lewat DB, bukan request kedua, karena guard 'sanctum'
        // di-cache per-instance oleh AuthManager selama satu proses test PHP —
        // di request HTTP sungguhan/production ini bukan masalah, tiap request
        // adalah proses baru).
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $tokenId]);
    }
}

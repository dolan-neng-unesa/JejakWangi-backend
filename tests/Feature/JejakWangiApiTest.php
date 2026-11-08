<?php

namespace Tests\Feature;

use App\Models\ActivityModel;
use App\Models\ByoTokenModel;
use App\Models\ChargerStationModel;
use App\Models\UmkmModel;
use App\Models\UserModel;
use App\Models\VoucherCooldownModel;
use App\Models\VoucherModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class JejakWangiApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-15 10:00:00', 'Asia/Jakarta'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function login(array $attrs = []): array
    {
        $user = UserModel::factory()->create($attrs);

        return [$user, ['Authorization' => 'Bearer '.$user->createToken('t')->plainTextToken]];
    }

    /** Aktivitas langsung ke DB (jarak dalam meter) pada waktu tertentu. */
    private function walk(UserModel $u, int $meters, ?string $at = null, bool $sim = false): ActivityModel
    {
        return ActivityModel::create([
            'user_id' => $u->id, 'client_id' => uniqid('c'), 'distance_m' => $meters, 'duration_sec' => max(1, intdiv($meters, 1)),
            'points' => (int) round($meters / 1000 * 30), 'is_simulated' => $sim,
            'started_at' => $at ? Carbon::parse($at) : now(),
        ]);
    }

    private function aktivitas(array $o = []): array
    {
        return array_merge([
            'client_id' => 'c'.uniqid(), 'koridor_id' => 1, 'transport' => 'mobil',
            'distance_m' => 5200, 'duration_sec' => 1938, 'started_at' => '2026-11-15T07:00:00+07:00',
        ], $o);
    }

    /** Guard Sanctum menyimpan user request sebelumnya dalam satu tes. */
    private function as(array $h): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders($h);
    }

    // ---------------------------------------------------------------- dasar & aktivitas

    public function test_endpoint_butuh_login(): void
    {
        $this->postJson('/api/activities', $this->aktivitas())->assertStatus(401);
        foreach (['/api/vouchers', '/api/vouchers/tiers', '/api/me/summary'] as $u) {
            $this->getJson($u)->assertStatus(401);
        }
        $this->postJson('/api/vouchers/claim', ['tier' => 1])->assertStatus(401);
        $this->postJson('/api/byo/confirm', ['token' => 'x'])->assertStatus(401);
        $this->postJson('/api/byo/token')->assertStatus(401);
    }

    public function test_dampak_dihitung_ulang_di_server(): void
    {
        [, $h] = $this->login();
        $res = $this->postJson('/api/activities', $this->aktivitas(['co2_kg' => 999, 'points' => 99999]), $h)->assertCreated();
        $res->assertJsonPath('data.points', 156);
        $this->assertEqualsWithDelta(5.2 * 0.1179 * 2.31, $res->json('data.co2_kg'), 0.001);
        $res->assertJsonPath('data.rupiah', (int) round(5.2 * 0.1179 * 10000));
    }

    public function test_pembanding_motor_untuk_pejalan_kaki(): void
    {
        [, $h] = $this->login();
        $res = $this->postJson('/api/activities', $this->aktivitas(['transport' => 'jalan']), $h)->assertCreated();
        $this->assertEqualsWithDelta(5.2 * 0.0266 * 2.31, $res->json('data.co2_kg'), 0.001);
    }

    public function test_menolak_kecepatan_tidak_wajar(): void
    {
        [, $h] = $this->login();
        $this->postJson('/api/activities', $this->aktivitas(['distance_m' => 20000, 'duration_sec' => 600]), $h)->assertStatus(422);
    }

    public function test_sinkronisasi_ulang_idempoten(): void
    {
        [, $h] = $this->login();
        $body = $this->aktivitas(['client_id' => 'sama']);
        $this->postJson('/api/activities', $body, $h)->assertCreated();
        $this->postJson('/api/activities', $body, $h)->assertOk();
        $this->assertDatabaseCount('activities', 1);
    }

    public function test_aktivitas_simulasi_tidak_dihitung(): void
    {
        [$u, $h] = $this->login();
        $this->postJson('/api/activities', $this->aktivitas(['distance_m' => 6000, 'duration_sec' => 4000, 'is_simulated' => true]), $h)
            ->assertCreated()->assertJsonPath('data.points', 0);
        $this->getJson('/api/me/summary', $h)->assertJsonPath('data.total_km', 0)->assertJsonPath('data.bulan_km', 0);
        $this->assertSame([], $this->getJson('/api/leaderboard')->json('data'));
    }

    // ---------------------------------------------------------------- PROBLEM 1: kupon bertingkat

    public function test_konfigurasi_tingkat_sesuai_ketentuan(): void
    {
        $t = config('jejakwangi.tiers');
        $this->assertSame([1, 3, 5], array_column($t, 'target_km'));
        $this->assertSame([7, 7, 14], array_column($t, 'berlaku_hari'));
        $this->assertSame(['Air Mineral 1 pcs', 'Air Isotonik 1 pcs', 'Air Isotonik 1 pcs + Jajan 1 pcs'], array_column($t, 'hadiah'));
        $this->assertSame(30, config('jejakwangi.kupon_cooldown_hari'));
    }

    public function test_status_tingkat_mengikuti_progres_bulan_ini(): void
    {
        [$u, $h] = $this->login();
        $res = $this->getJson('/api/vouchers/tiers', $h)->assertOk();
        $this->assertSame(['terkunci', 'terkunci', 'terkunci'], array_column($res->json('data'), 'status'));

        $this->walk($u, 1200);
        $res = $this->getJson('/api/vouchers/tiers', $h);
        $this->assertSame(['bisa_diklaim', 'terkunci', 'terkunci'], array_column($res->json('data'), 'status'));
        $res->assertJsonPath('data.1.progress_km', 1.2)->assertJsonPath('bulan_km', 1.2);

        $this->walk($u, 4000); // kumulatif 5,2 km
        $this->assertSame(['bisa_diklaim', 'bisa_diklaim', 'bisa_diklaim'], array_column($this->getJson('/api/vouchers/tiers', $h)->json('data'), 'status'));
    }

    public function test_klaim_berhasil_membuat_kupon_dan_cooldown_30_hari(): void
    {
        [$u, $h] = $this->login();
        $this->walk($u, 3100);

        $r = $this->postJson('/api/vouchers/claim', ['tier' => 2], $h)->assertCreated();
        $r->assertJsonPath('data.title', 'Pelari Niat')->assertJsonPath('data.description', 'Air Isotonik 1 pcs')->assertJsonPath('data.status', 'tersedia')->assertJsonPath('data.tier', 2);
        $this->assertTrue(Carbon::parse($r->json('data.expires_at'))->equalTo(now()->addDays(7)));
        $this->assertTrue(VoucherCooldownModel::where('user_id', $u->id)->where('tier', 2)->first()->available_at->equalTo(now()->addDays(30)));

        // tingkat lain tidak ikut cooldown
        $st = $this->getJson('/api/vouchers/tiers', $h)->json('data');
        $this->assertSame(['bisa_diklaim', 'cooldown', 'terkunci'], array_column($st, 'status'));
        $this->assertSame(30, $st[1]['sisa_hari_cooldown']);
    }

    public function test_masa_pakai_tingkat_3_adalah_14_hari(): void
    {
        [$u, $h] = $this->login();
        $this->walk($u, 5000);
        $r = $this->postJson('/api/vouchers/claim', ['tier' => 3], $h)->assertCreated();
        $this->assertTrue(Carbon::parse($r->json('data.expires_at'))->equalTo(now()->addDays(14)));
        $this->assertSame('Air Isotonik 1 pcs + Jajan 1 pcs', $r->json('data.description'));
    }

    public function test_tidak_bisa_klaim_saat_cooldown_atau_progres_kurang_atau_tingkat_salah(): void
    {
        [$u, $h] = $this->login();
        $this->postJson('/api/vouchers/claim', ['tier' => 1], $h)->assertStatus(422); // belum 1 km
        $this->walk($u, 1000);
        $this->postJson('/api/vouchers/claim', ['tier' => 1], $h)->assertCreated(); // pas 1 km
        $this->postJson('/api/vouchers/claim', ['tier' => 1], $h)->assertStatus(422)->assertJsonPath('message', 'Kupon Pelari Mager masih cooldown sampai 15 Desember 2026.'); // 15 Nov + 30 hari = 15 Des
        $this->postJson('/api/vouchers/claim', ['tier' => 9], $h)->assertStatus(422);
        $this->postJson('/api/vouchers/claim', [], $h)->assertStatus(422);
        $this->assertSame(1, VoucherModel::count());
    }

    public function test_setelah_30_hari_bisa_klaim_lagi_bila_progres_bulan_baru_cukup(): void
    {
        [$u, $h] = $this->login();
        $this->walk($u, 1500);
        $this->postJson('/api/vouchers/claim', ['tier' => 1], $h)->assertCreated();

        Carbon::setTestNow(now()->addDays(31)); // 16 Des: bulan baru, cooldown lewat
        $this->postJson('/api/vouchers/claim', ['tier' => 1], $h)->assertStatus(422); // progres Desember masih 0
        $this->walk($u, 1000);
        $this->postJson('/api/vouchers/claim', ['tier' => 1], $h)->assertCreated();
        $this->assertSame(2, VoucherModel::where('user_id', $u->id)->count());
    }

    public function test_progres_hanya_bulan_berjalan_zona_jakarta_dan_bukan_simulasi(): void
    {
        [$u, $h] = $this->login();
        $this->walk($u, 4000, '2026-10-31T16:30:00Z');       // 31 Okt 23:30 WIB → bulan lalu
        $this->walk($u, 500, '2026-10-31T17:30:00Z');        // 1 Nov 00:30 WIB → bulan ini
        $this->walk($u, 9000, null, true);                   // simulasi
        $this->getJson('/api/me/summary', $h)->assertJsonPath('data.bulan_km', 0.5);
    }

    public function test_klaim_bersamaan_hanya_satu_yang_lolos(): void
    {
        [$u, $h] = $this->login();
        $this->walk($u, 2000);
        $lock = \Illuminate\Support\Facades\Cache::lock("klaim:{$u->id}:1", 10);
        $this->assertTrue($lock->get());
        $this->postJson('/api/vouchers/claim', ['tier' => 1], $h)->assertStatus(429);
        $lock->release();
        $this->postJson('/api/vouchers/claim', ['tier' => 1], $h)->assertCreated();
    }

    public function test_redeem_kupon(): void
    {
        [$u, $h] = $this->login();
        $this->walk($u, 1000);
        $id = $this->postJson('/api/vouchers/claim', ['tier' => 1], $h)->json('data.id');
        $this->postJson("/api/vouchers/$id/redeem", [], $h)->assertOk()->assertJsonPath('data.status', 'digunakan');
        $this->postJson("/api/vouchers/$id/redeem", [], $h)->assertStatus(422);
    }

    public function test_kupon_kedaluwarsa_tidak_bisa_dipakai(): void
    {
        [$u, $h] = $this->login();
        $v = VoucherModel::create(['user_id' => $u->id, 'code' => 'JW-OLD', 'title' => 'x', 'merchant' => 'y', 'source' => 's', 'expires_at' => now()->subDay()]);
        $this->getJson('/api/vouchers', $h)->assertJsonPath('data.0.status', 'kedaluwarsa');
        $this->postJson("/api/vouchers/{$v->id}/redeem", [], $h)->assertStatus(422);
    }

    public function test_tidak_bisa_memakai_kupon_orang_lain(): void
    {
        [$a] = $this->login();
        [, $hb] = $this->login();
        $v = VoucherModel::create(['user_id' => $a->id, 'code' => 'JW-A', 'title' => 'x', 'merchant' => 'y', 'source' => 's', 'expires_at' => now()->addDay()]);
        $this->postJson("/api/vouchers/{$v->id}/redeem", [], $hb)->assertStatus(404);
    }

    // ---------------------------------------------------------------- PROBLEM 2: leaderboard bulanan + badge

    public function test_leaderboard_urut_jarak_menyamarkan_nama_dan_memberi_badge(): void
    {
        [$a, $ha] = $this->login(['name' => 'Levi Kusuma']);
        [$b] = $this->login(['name' => 'Sari']);
        $this->walk($b, 2000);
        $this->walk($a, 4000);

        $res = $this->getJson('/api/leaderboard', $ha)->assertOk();
        $res->assertJsonPath('data.0.name', 'Levi K.')->assertJsonPath('data.0.rank', 1)->assertJsonPath('data.0.km', 4)
            ->assertJsonPath('data.0.badge.kode', 'top-1')->assertJsonPath('data.0.is_me', true)
            ->assertJsonPath('data.1.name', 'Sari')->assertJsonPath('data.1.badge.kode', 'top-3')->assertJsonPath('data.1.is_me', false);
        $this->assertArrayNotHasKey('user_id', $res->json('data.0'));
        $this->assertArrayNotHasKey('uid', $res->json('data.0'));
    }

    public function test_badge_per_peringkat(): void
    {
        $badges = fn (int $r) => \App\Services\MonthlyRanking::badgeFor($r)['kode'] ?? null;
        $this->assertSame('top-1', $badges(1));
        $this->assertSame('top-3', $badges(2));
        $this->assertSame('top-3', $badges(3));
        $this->assertSame('top-5', $badges(4));
        $this->assertSame('top-5', $badges(5));
        $this->assertSame('top-10', $badges(6));
        $this->assertSame('top-10', $badges(10));
        $this->assertNull($badges(11));
    }

    public function test_leaderboard_maksimal_100_dan_pengguna_di_luar_daftar_melihat_posisi_sendiri(): void
    {
        for ($i = 1; $i <= 101; $i++) {
            $u = UserModel::factory()->create(['name' => "User $i"]);
            $this->walk($u, 20000 - $i * 100);
        }
        [$me, $h] = $this->login(['name' => 'Aku Pelan']);
        $this->walk($me, 100);

        $res = $this->getJson('/api/leaderboard?limit=500', $h)->assertOk();
        $this->assertCount(100, $res->json('data'));
        $res->assertJsonPath('data.99.rank', 100)->assertJsonPath('me.rank', 102)->assertJsonPath('me.badge', null);
        $res->assertJsonPath('data.0.badge.kode', 'top-1')->assertJsonPath('data.10.badge', null);
    }

    public function test_leaderboard_reset_awal_bulan_dan_badge_ikut_reset(): void
    {
        [$a, $h] = $this->login();
        $this->walk($a, 8000);
        $this->getJson('/api/me/summary', $h)->assertJsonPath('data.badge.kode', 'top-1');

        Carbon::setTestNow(Carbon::parse('2026-12-01 00:05:00', 'Asia/Jakarta')); // awal bulan berikutnya
        $this->assertSame([], $this->getJson('/api/leaderboard', $h)->json('data'));
        $this->getJson('/api/me/summary', $h)->assertJsonPath('data.bulan_km', 0)->assertJsonPath('data.badge', null)->assertJsonPath('data.rank_bulan', null);
        $this->walk($a, 1000);
        $this->getJson('/api/leaderboard', $h)->assertJsonPath('data.0.km', 1)->assertJsonPath('data.0.badge.kode', 'top-1');
    }

    public function test_jarak_seri_berbagi_peringkat(): void
    {
        [$a] = $this->login();
        [$b, $hb] = $this->login();
        $this->walk($a, 3000);
        $this->walk($b, 3000);
        $this->getJson('/api/me/summary', $hb)->assertJsonPath('data.rank_bulan', 1);
    }

    // ---------------------------------------------------------------- PROBLEM 3: QR tempat sendiri

    private function kasir(): array
    {
        $umkm = UmkmModel::create(['business_name' => 'Warung Bu Sari', 'business_type' => 'Makanan', 'address' => 'x', 'koridor_id' => '1', 'is_active' => true]);

        return $this->login(['umkm_id' => $umkm->id]);
    }

    private function token(array $hKasir): string
    {
        $r = $this->as($hKasir)->postJson('/api/byo/token')->assertCreated();

        return substr($r->json('data.qr_payload'), strlen('jejakwangi:byo:'));
    }

    /** Pelanggan dengan kupon tingkat 1 & 2 yang sedang cooldown. */
    private function pelangganCooldown(): array
    {
        [$u, $h] = $this->login();
        $this->walk($u, 3000);
        $this->as($h)->postJson('/api/vouchers/claim', ['tier' => 1])->assertCreated();
        $this->as($h)->postJson('/api/vouchers/claim', ['tier' => 2])->assertCreated();

        return [$u, $h];
    }

    public function test_hanya_kasir_yang_bisa_menerbitkan_qr(): void
    {
        [, $h] = $this->login();
        $this->postJson('/api/byo/token', [], $h)->assertStatus(403);
        [, $hk] = $this->kasir();
        $r = $this->as($hk)->postJson('/api/byo/token')->assertCreated();
        $this->assertStringStartsWith('jejakwangi:byo:', $r->json('data.qr_payload'));
        $this->assertTrue(Carbon::parse($r->json('data.expires_at'))->equalTo(now()->addMinutes(5)));
    }

    public function test_qr_memangkas_cooldown_1_hari_untuk_kupon_yang_sedang_cooldown(): void
    {
        [, $hk] = $this->kasir();
        [$u, $hu] = $this->pelangganCooldown();
        $tok = $this->token($hk);

        $r = $this->as($hu)->postJson('/api/byo/confirm', ['token' => $tok])->assertOk()->assertJsonPath('applied', true)->assertJsonPath('data.dikurangi_hari', 1);
        $this->assertCount(2, $r->json('data.cooldowns'));
        foreach (VoucherCooldownModel::where('user_id', $u->id)->get() as $c) {
            $this->assertTrue($c->available_at->equalTo(now()->addDays(29)), 'cooldown harus 29 hari');
        }
        $this->assertDatabaseHas('byo_tokens', ['token' => $tok, 'used_by' => $u->id]);
    }

    public function test_qr_sekali_pakai(): void
    {
        [, $hk] = $this->kasir();
        [, $hu] = $this->pelangganCooldown();
        [$v, $hv] = $this->pelangganCooldown();
        $tok = $this->token($hk);
        $this->as($hu)->postJson('/api/byo/confirm', ['token' => $tok])->assertOk();
        $this->as($hv)->postJson('/api/byo/confirm', ['token' => $tok])->assertStatus(422);
        foreach (VoucherCooldownModel::where('user_id', $v->id)->get() as $c) {
            $this->assertTrue($c->available_at->equalTo(now()->addDays(30))); // tidak ikut terpangkas
        }
    }

    public function test_qr_tidak_dihabiskan_bila_tidak_ada_kupon_cooldown(): void
    {
        [, $hk] = $this->kasir();
        [, $hu] = $this->login();
        $tok = $this->token($hk);
        $this->as($hu)->postJson('/api/byo/confirm', ['token' => $tok])->assertOk()->assertJsonPath('applied', false);
        $this->assertDatabaseHas('byo_tokens', ['token' => $tok, 'used_at' => null]);
    }

    public function test_qr_kedaluwarsa_tidak_dikenal_dan_milik_sendiri_ditolak(): void
    {
        [, $hk] = $this->kasir();
        [, $hu] = $this->pelangganCooldown();
        $tok = $this->token($hk);

        $this->as($hu)->postJson('/api/byo/confirm', ['token' => 'ngawur'])->assertStatus(404);
        $this->as($hk)->postJson('/api/byo/confirm', ['token' => $tok])->assertStatus(422); // kasir scan QR sendiri

        Carbon::setTestNow(now()->addMinutes(6));
        $this->as($hu)->postJson('/api/byo/confirm', ['token' => $tok])->assertStatus(422);
    }

    public function test_batas_konfirmasi_per_hari(): void
    {
        [, $hk] = $this->kasir();
        [, $hu] = $this->pelangganCooldown();
        for ($i = 0; $i < 3; $i++) {
            $tok = $this->token($hk); // token dibuat dulu: as() mengganti header default
            $this->as($hu)->postJson('/api/byo/confirm', ['token' => $tok])->assertOk();
        }
        $tok = $this->token($hk);
        $this->as($hu)->postJson('/api/byo/confirm', ['token' => $tok])->assertStatus(429);
        $this->assertSame(3, ByoTokenModel::whereNotNull('used_at')->count());
    }

    public function test_pemangkasan_cukup_membuat_kupon_bisa_diklaim_lagi(): void
    {
        [, $hk] = $this->kasir();
        [$u, $hu] = $this->login();
        $this->walk($u, 1000);
        $this->as($hu)->postJson('/api/vouchers/claim', ['tier' => 1])->assertCreated();
        VoucherCooldownModel::where('user_id', $u->id)->update(['available_at' => now()->addHours(10)]); // tinggal <1 hari
        $tok = $this->token($hk);
        $this->as($hu)->postJson('/api/byo/confirm', ['token' => $tok])->assertOk()->assertJsonPath('data.cooldowns.0.sudah_bisa_diklaim', true);
        $this->as($hu)->postJson('/api/vouchers/claim', ['tier' => 1])->assertCreated();
    }

    public function test_admin_menjadikan_akun_kasir_dan_registrasi_publik_tidak_bisa(): void
    {
        $umkm = UmkmModel::create(['business_name' => 'W', 'business_type' => 'Makanan', 'address' => 'x', 'koridor_id' => '1', 'is_active' => true]);
        $target = UserModel::factory()->create();
        [, $ha] = $this->login(['is_admin' => true]);
        $this->putJson("/api/admin/users/{$target->id}", ['umkm_id' => $umkm->id], $ha)->assertOk()->assertJsonPath('data.umkm_id', $umkm->id);
        $this->assertSame($umkm->id, $target->fresh()->umkm_id);

        $this->app['auth']->forgetGuards();
        $r = $this->postJson('/api/auth/register', ['name' => 'Nakal', 'email' => 'nakal@example.com', 'password' => 'rahasia123', 'password_confirmation' => 'rahasia123', 'umkm_id' => $umkm->id]);
        $this->assertNull(UserModel::where('email', 'nakal@example.com')->first()?->umkm_id);
        $this->assertContains($r->getStatusCode(), [200, 201]);
    }

    // ---------------------------------------------------------------- charger & admin

    public function test_charger_publik(): void
    {
        ChargerStationModel::create(['name' => 'C1', 'latitude' => -8.2, 'longitude' => 114.3, 'ports_total' => 4, 'ports_available' => 0]);
        $this->getJson('/api/chargers')->assertOk()->assertJsonPath('data.0.is_available', false);
        $this->getJson('/api/chargers/1')->assertOk()->assertJsonPath('data.name', 'C1');
        $this->getJson('/api/chargers/99')->assertStatus(404);
    }

    public function test_ringkasan_admin_hanya_untuk_admin(): void
    {
        [, $h] = $this->login(['is_admin' => false]);
        $this->getJson('/api/admin/jejak-wangi/summary', $h)->assertStatus(403);
        [, $ha] = $this->login(['is_admin' => true]);
        $this->as($ha)->getJson('/api/admin/jejak-wangi/summary')->assertOk()->assertJsonStructure(['data' => ['total_aktivitas', 'total_km', 'kupon', 'byo_terkonfirmasi', 'kupon_per_tingkat']]);
    }
}

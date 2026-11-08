<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\SocialAuthException;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\AdminNotificationModel;
use App\Models\UserModel;
use App\Services\SocialIdentityVerifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Autentikasi berbasis token (Laravel Sanctum "personal access token"),
 * cocok untuk PWA/mobile (bukan cookie/CSRF seperti SPA-mode Sanctum).
 * Dipakai jejak-wangi-app (lihat src/services/authService.js): email/kata sandi
 * serta login sosial Google & Facebook (lihat social()).
 *
 * Login tamu (tanpa akun) tetap didukung penuh di FE: endpoint di sini
 * hanya dipakai kalau pengguna memilih login/daftar untuk menyimpan
 * riwayat Mood Tracker lintas perangkat (lihat proposal, bagian 7.3 UX).
 *
 * Catatan keamanan:
 *  - Kata sandi diperiksa langsung dengan Hash::check, BUKAN Auth::attempt:
 *    guard default ('web') berbasis session, sedangkan route API tidak
 *    menjalankan middleware session — attempt() akan menulis ke store session
 *    yang tak pernah dipakai dan menambah beban DB di tiap login.
 *  - Hash::check selalu dijalankan (hash tiruan bila email tak ada) supaya
 *    waktu respons tidak membocorkan email mana yang terdaftar.
 *  - Akun nonaktif (is_active=false) ditolak saat login; token lamanya dicabut
 *    oleh AdminUserController saat akun dinonaktifkan.
 *  - Jumlah token aktif per pengguna dibatasi (MAX_TOKENS), token lama dibuang.
 *  - Rate limit: lihat RateLimiter 'auth-login' / 'auth-register' di AppServiceProvider.
 */
class AuthController extends Controller
{
    /** Perangkat/sesi aktif maksimum per akun; token tertua dicabut otomatis. */
    private const MAX_TOKENS = 5;

    private static ?string $dummyHash = null;

    public function register(Request $request)
    {
        $request->merge(['email' => $this->normalizeEmail($request->input('email'))]);

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'max:128'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Data pendaftaran tidak valid', 'errors' => $validator->errors()], 422);
        }

        $user = UserModel::create([
            'name' => trim((string) $request->input('name')),
            'email' => $request->input('email'),
            'password' => $request->input('password'), // cast 'hashed' di UserModel
        ]);

        AdminNotificationModel::notify('pengguna', 'Pendaftaran Pengguna Baru', "{$user->name} ({$user->email}) mendaftar lewat aplikasi.");

        return response()->json($this->tokenPayload($user), 201);
    }

    public function login(Request $request)
    {
        $request->merge(['email' => $this->normalizeEmail($request->input('email'))]);

        $validator = Validator::make($request->all(), [
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'max:128'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Email atau kata sandi tidak valid', 'errors' => $validator->errors()], 422);
        }

        $user = UserModel::whereRaw('LOWER(email) = ?', [$request->input('email')])->first();

        // Selalu hitung hash (tiruan bila akun tidak ada) → waktu respons seragam.
        $passwordOk = Hash::check($request->input('password'), $user?->password ?? $this->dummyHash());

        if (!$user || !$passwordOk) {
            return response()->json(['message' => 'Email atau kata sandi salah.'], 401);
        }

        if (!$user->is_active) {
            return response()->json(['message' => 'Akun ini dinonaktifkan. Hubungi administrator.'], 403);
        }

        // Naikkan biaya hash otomatis kalau BCRYPT_ROUNDS diperbesar.
        if (Hash::needsRehash($user->password)) {
            $user->forceFill(['password' => $request->input('password')])->save();
        }

        return response()->json($this->tokenPayload($user));
    }

    /**
     * Login/daftar lewat Google atau Facebook. FE mengirim kredensial dari SDK penyedia
     * (Google: ID token, Facebook: access token); identitas diverifikasi ke penyedia
     * oleh SocialIdentityVerifier, lalu akun dicari/dibuat dan token Sanctum diterbitkan
     * — respons sama persis dengan login email.
     *
     * Urutan pencarian akun: (1) ID penyedia, (2) email yang SUDAH diverifikasi penyedia
     * → ditautkan ke akun email yang ada, (3) buat akun baru. Akun baru diberi kata sandi
     * acak yang tak diketahui siapa pun (kolom password NOT NULL), dan TIDAK pernah admin.
     */
    public function social(Request $request, SocialIdentityVerifier $verifier)
    {
        $validator = Validator::make($request->all(), [
            'provider' => ['required', 'string', 'in:' . implode(',', SocialIdentityVerifier::PROVIDERS)],
            'token' => ['required', 'string', 'max:4096'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Data login sosial tidak valid', 'errors' => $validator->errors()], 422);
        }

        $provider = $request->input('provider');

        try {
            $identity = $verifier->verify($provider, $request->input('token'));
        } catch (SocialAuthException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }

        $column = $provider . '_id';
        $email = Str::lower(trim((string) $identity['email']));
        $created = false;

        $user = UserModel::where($column, $identity['id'])->first();

        if (!$user) {
            $user = UserModel::whereRaw('LOWER(email) = ?', [$email])->first();

            if ($user) {
                if (!$user->is_active) {
                    return response()->json(['message' => 'Akun ini dinonaktifkan. Hubungi administrator.'], 403);
                }
                if ($user->{$column} !== null && $user->{$column} !== $identity['id']) {
                    return response()->json(['message' => 'Email ini sudah tertaut ke akun ' . ucfirst($provider) . ' lain.'], 409);
                }
                // Tautkan akun email yang sudah ada ke penyedia ini (email sudah diverifikasi penyedia).
                $user->forceFill([$column => $identity['id'], 'email_verified_at' => $user->email_verified_at ?? now()])->save();
            } else {
                $user = UserModel::create([
                    'name' => trim($identity['name']) !== '' ? trim($identity['name']) : strstr($email, '@', true),
                    'email' => $email,
                    'password' => Str::random(48), // cast 'hashed'; tak pernah dipakai untuk login
                ]);
                $user->forceFill([$column => $identity['id'], 'email_verified_at' => now()])->save();
                $user->refresh(); // muat default DB (is_active=true) — model hasil create() belum memilikinya
                $created = true;

                AdminNotificationModel::notify('pengguna', 'Pendaftaran Pengguna Baru', "{$user->name} ({$user->email}) mendaftar lewat " . ucfirst($provider) . '.');
            }
        }

        if (!$user->is_active) {
            return response()->json(['message' => 'Akun ini dinonaktifkan. Hubungi administrator.'], 403);
        }

        return response()->json($this->tokenPayload($user), $created ? 201 : 200);
    }

    /**
     * Penyedia login sosial yang aktif + ID publik aplikasinya, supaya FE tidak perlu
     * menyalin konfigurasi (satu sumber: .env backend). Rahasia (app_secret) TIDAK pernah dikirim.
     */
    public function providers(SocialIdentityVerifier $verifier)
    {
        return response()->json([
            'google' => $verifier->isConfigured('google') ? ['client_id' => config('services.google.client_id')] : null,
            'facebook' => $verifier->isConfigured('facebook') ? ['app_id' => config('services.facebook.app_id'), 'version' => config('services.facebook.graph_version')] : null,
        ]);
    }

    public function logout(Request $request)
    {
        $token = $request->user()->currentAccessToken();
        // TransientToken (mis. Sanctum::actingAs di test) tidak punya baris DB.
        if ($token && method_exists($token, 'delete')) {
            $token->delete();
        }

        return response()->json(['message' => 'Berhasil keluar.']);
    }

    public function me(Request $request)
    {
        return new UserResource($request->user());
    }

    /** Terbitkan token baru, buang token lama di atas batas, dan bentuk response standar. */
    private function tokenPayload(UserModel $user): array
    {
        $minutes = (int) config('sanctum.expiration');
        $expiresAt = $minutes > 0 ? now()->addMinutes($minutes) : null;
        $newToken = $user->createToken('jejak-wangi-app', ['*'], $expiresAt);

        $stale = $user->tokens()->orderByDesc('id')->pluck('id')->slice(self::MAX_TOKENS);
        if ($stale->isNotEmpty()) {
            $user->tokens()->whereIn('id', $stale->all())->delete();
        }

        return [
            'user' => new UserResource($user),
            'token' => $newToken->plainTextToken,
            'expires_at' => $expiresAt?->toIso8601String(),
        ];
    }

    private function normalizeEmail(mixed $email): ?string
    {
        return is_string($email) ? Str::lower(trim($email)) : null;
    }

    private function dummyHash(): string
    {
        return self::$dummyHash ??= Hash::make(Str::random(32));
    }
}

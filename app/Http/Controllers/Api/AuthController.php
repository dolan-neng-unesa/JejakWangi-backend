<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\AdminNotificationModel;
use App\Models\UserModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Autentikasi berbasis token (Laravel Sanctum "personal access token"),
 * cocok untuk PWA/mobile (bukan cookie/CSRF seperti SPA-mode Sanctum).
 * Menggantikan Firebase Auth di svarga-app — lihat urutan fallback yang
 * sama seperti fitur lain (Laravel API -> Firebase -> mock) di
 * src/services/authService.js.
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
        $newToken = $user->createToken('svarga-app', ['*'], $expiresAt);

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

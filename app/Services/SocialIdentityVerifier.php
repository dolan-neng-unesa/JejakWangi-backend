<?php

namespace App\Services;

use App\Exceptions\SocialAuthException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Memverifikasi kredensial yang didapat FE dari Google/Facebook langsung ke
 * penyedia-nya, lalu mengembalikan identitas terverifikasi. Server TIDAK pernah
 * memercayai data profil kiriman klien — hanya token-nya.
 *
 *  - Google: FE mengirim ID token (JWT dari Google Identity Services). Tanda
 *    tangan & masa berlaku diperiksa Google lewat endpoint tokeninfo; di sini
 *    kita pastikan token DITERBITKAN UNTUK aplikasi kita (aud), email terverifikasi.
 *  - Facebook: FE mengirim access token. debug_token memastikan token valid dan
 *    milik aplikasi kita (app_id), baru profil diambil dari Graph API.
 *
 * Tanpa pemeriksaan audience/app_id, token yang diterbitkan untuk aplikasi LAIN
 * bisa dipakai untuk masuk sebagai siapa pun — jangan dihapus.
 *
 * @phpstan-type Identity array{id: string, email: ?string, name: string}
 */
class SocialIdentityVerifier
{
    public const PROVIDERS = ['google', 'facebook'];

    public function isConfigured(string $provider): bool
    {
        return match ($provider) {
            'google' => filled(config('services.google.client_id')),
            'facebook' => filled(config('services.facebook.app_id')) && filled(config('services.facebook.app_secret')),
            default => false,
        };
    }

    /** @return Identity */
    public function verify(string $provider, string $token): array
    {
        if (!$this->isConfigured($provider)) {
            throw new SocialAuthException('Login ' . ucfirst($provider) . ' belum dikonfigurasi di server.', 503);
        }

        return match ($provider) {
            'google' => $this->google($token),
            'facebook' => $this->facebook($token),
        };
    }

    /** @return Identity */
    private function google(string $idToken): array
    {
        $data = $this->get('https://oauth2.googleapis.com/tokeninfo', ['id_token' => $idToken], 'Google');

        $issuerOk = in_array($data['iss'] ?? null, ['accounts.google.com', 'https://accounts.google.com'], true);
        $audienceOk = hash_equals((string) config('services.google.client_id'), (string) ($data['aud'] ?? ''));
        $notExpired = (int) ($data['exp'] ?? 0) > time();

        if (!$issuerOk || !$audienceOk || !$notExpired || empty($data['sub'])) {
            throw new SocialAuthException('Token Google tidak valid.');
        }

        if (!filter_var($data['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN) || empty($data['email'])) {
            throw new SocialAuthException('Email akun Google belum terverifikasi.', 422);
        }

        return [
            'id' => (string) $data['sub'],
            'email' => (string) $data['email'],
            'name' => (string) ($data['name'] ?? strstr((string) $data['email'], '@', true)),
        ];
    }

    /** @return Identity */
    private function facebook(string $accessToken): array
    {
        $appId = (string) config('services.facebook.app_id');
        $version = config('services.facebook.graph_version', 'v21.0');
        $graph = "https://graph.facebook.com/{$version}";

        $debug = $this->get("{$graph}/debug_token", [
            'input_token' => $accessToken,
            'access_token' => $appId . '|' . config('services.facebook.app_secret'),
        ], 'Facebook')['data'] ?? [];

        if (empty($debug['is_valid']) || !hash_equals($appId, (string) ($debug['app_id'] ?? '')) || empty($debug['user_id'])) {
            throw new SocialAuthException('Token Facebook tidak valid.');
        }

        $profile = $this->get("{$graph}/me", [
            'fields' => 'id,name,email',
            'access_token' => $accessToken,
        ], 'Facebook');

        if (empty($profile['id']) || (string) $profile['id'] !== (string) $debug['user_id']) {
            throw new SocialAuthException('Token Facebook tidak valid.');
        }

        if (empty($profile['email'])) {
            throw new SocialAuthException('Akun Facebook ini tidak membagikan email. Izinkan akses email atau daftar dengan email.', 422);
        }

        return [
            'id' => (string) $profile['id'],
            'email' => (string) $profile['email'],
            'name' => (string) ($profile['name'] ?? strstr((string) $profile['email'], '@', true)),
        ];
    }

    /** GET JSON ke penyedia; 4xx dari penyedia = kredensial ditolak, jaringan/5xx = layanan tak terjangkau. */
    private function get(string $url, array $query, string $label): array
    {
        try {
            $response = $this->http()->get($url, $query);
        } catch (ConnectionException) {
            throw new SocialAuthException("Tidak dapat menghubungi {$label}. Coba lagi sebentar lagi.", 502);
        }

        if ($response->serverError()) {
            throw new SocialAuthException("{$label} sedang bermasalah. Coba lagi sebentar lagi.", 502);
        }
        if ($response->failed()) {
            throw new SocialAuthException("Token {$label} tidak valid atau sudah kedaluwarsa.");
        }

        return $response->json() ?? [];
    }

    private function http(): PendingRequest
    {
        return Http::acceptJson()->timeout(8)->connectTimeout(5);
    }
}

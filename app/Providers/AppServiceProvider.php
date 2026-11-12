<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Dibuat eksplisit (bukan auto-wiring) karena konstruktornya menerima array konfigurasi & Closure opsional.
        $this->app->bind(\App\Services\EcoCoach\EcoCoach::class, fn () => new \App\Services\EcoCoach\EcoCoach(config('ecocoach')));
    }

    public function boot(): void
    {
        // Semua parameter {id} di routes/api.php wajib numerik. Tanpa ini,
        // /api/festivals/abc masuk ke controller ber-type-hint `int $id` dan
        // berakhir sebagai TypeError → 500 alih-alih 404 yang benar.
        Route::pattern('id', '[0-9]+');

        // ESP32 normalnya kirim tiap 30–60 detik; 20/menit per perangkat sudah longgar
        // dan melindungi DB dari firmware yang loop tanpa delay.
        RateLimiter::for('sensor-ingest', fn (Request $request) => Limit::perMinute(20)
            ->by($request->attributes->get('device')?->id ?? $request->ip()));

        // Anti brute-force: 5 percobaan/menit per (email + IP), plus plafon 30/menit per IP
        // supaya penyerang tidak bisa menyapu banyak email dari satu alamat.
        RateLimiter::for('auth-login', fn (Request $request) => [
            Limit::perMinute(5)->by(strtolower((string) $request->input('email')) . '|' . $request->ip()),
            Limit::perMinute(30)->by($request->ip()),
        ]);

        // Login sosial memanggil API penyedia di tiap request → batasi per IP agar tidak bisa dipakai
        // membanjiri Google/Facebook lewat server kita.
        RateLimiter::for('auth-social', fn (Request $request) => Limit::perMinute(20)->by($request->ip()));

        // Pendaftaran juga memicu notifikasi admin — batasi supaya tidak bisa membanjiri tabelnya.
        RateLimiter::for('auth-register', fn (Request $request) => Limit::perHour(20)->by($request->ip()));

        // Mood tamu tanpa auth. Banyak pengunjung bisa berbagi satu IP (WiFi acara/NAT seluler),
        // jadi batasnya sengaja longgar — cukup untuk menahan skrip spam, bukan pemakaian wajar.
        // Eco-Coach memanggil LLM berbayar di tiap request: 10/menit per pengguna (atau per IP untuk tamu).
        RateLimiter::for('eco-coach', fn (Request $request) => Limit::perMinute(10)
            ->by($request->user('sanctum')?->id ?? $request->ip()));

        RateLimiter::for('mood-store', fn (Request $request) => Limit::perMinute(60)->by($request->ip()));
    }
}

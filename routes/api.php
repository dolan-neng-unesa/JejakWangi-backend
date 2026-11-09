<?php

use App\Http\Controllers\Api\AdminJejakWangiController;
use App\Http\Controllers\Api\AdminNotificationController;
use App\Http\Controllers\Api\ByoController;
use App\Http\Controllers\Api\ChargerStationController;
use App\Http\Controllers\Api\JejakWangiController;
use App\Http\Controllers\Api\AdminSensorDeviceController;
use App\Http\Controllers\Api\AdminSettingsController;
use App\Http\Controllers\Api\AdminUserController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\FestivalController;
use App\Http\Controllers\Api\GeofenceController;
use App\Http\Controllers\Api\GreenSpaceController;
use App\Http\Controllers\Api\KoridorController;
use App\Http\Controllers\Api\MoodLogController;
use App\Http\Controllers\Api\SensorReadingController;
use App\Http\Controllers\Api\SoundscapeController;
use App\Http\Controllers\Api\UmkmController;
use Illuminate\Support\Facades\Route;

Route::get('/umkm', [UmkmController::class, 'index']);
Route::get('/umkm/{id}', [UmkmController::class, 'show']);

Route::get('/festivals', [FestivalController::class, 'index']);
Route::get('/festivals/{id}', [FestivalController::class, 'show']);

// Green space & koridor — menggantikan koleksi Firestore `green_space`/`koridor`
// yang kosong (lihat KoridorController).
// Data referensi yang jarang berubah & tidak ada CRUD-nya di admin: boleh di-cache browser
// 60 dtk + ETag (revalidasi 304 murah), jadi FE yang membuka beberapa halaman berturut-turut
// tidak mengulang request yang sama.
Route::middleware('cache.headers:public;max_age=60;etag')->group(function () {
    Route::get('/green-spaces', [GreenSpaceController::class, 'index']);
    Route::get('/koridors', [KoridorController::class, 'index']);
    Route::get('/koridors/{id}', [KoridorController::class, 'show']);
});

// Sensor. ESP32 mengirim lewat POST (autentikasi X-Device-Key + throttle per
// perangkat); FE membaca snapshot semua jenis sensor dalam satu request.
Route::post('/sensors/readings', [SensorReadingController::class, 'store'])
    ->middleware(['device', 'throttle:sensor-ingest']);
Route::get('/sensors/snapshot', [SensorReadingController::class, 'snapshot']);
Route::get('/sensors/snapshots', [SensorReadingController::class, 'snapshots']); // semua koridor sekaligus (dashboard admin)
Route::get('/sensors/active', [SensorReadingController::class, 'active']); // wilayah yang sedang ON (home)
Route::get('/sensors/history', [SensorReadingController::class, 'history']); // tren harian, Monitoring Lingkungan (admin)

// Autentikasi (Sanctum, token-based — cocok untuk PWA/mobile).
Route::post('/auth/register', [AuthController::class, 'register'])->middleware('throttle:auth-register');
Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:auth-login');
Route::post('/auth/social', [AuthController::class, 'social'])->middleware('throttle:auth-social');
Route::get('/auth/providers', [AuthController::class, 'providers']); // penyedia login sosial yang aktif (untuk FE)
Route::middleware(['auth:sanctum', 'active'])->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);

    // Jejak Wangi: aktivitas jalan kaki, poin, kupon misi (butuh login agar poin & kupon melekat ke akun).
    Route::post('/activities', [JejakWangiController::class, 'storeActivity'])->middleware('throttle:60,1');
    Route::get('/activities', [JejakWangiController::class, 'activities']);
    Route::get('/me/summary', [JejakWangiController::class, 'summary']);
    Route::get('/vouchers', [JejakWangiController::class, 'vouchers']);
    Route::get('/vouchers/tiers', [JejakWangiController::class, 'tiers']);
    Route::post('/vouchers/claim', [JejakWangiController::class, 'claim'])->middleware('throttle:20,1');
    // Zero-waste: kasir menerbitkan QR, pelanggan memindai.
    Route::post('/byo/token', [ByoController::class, 'issue'])->middleware('throttle:30,1');
    Route::post('/byo/confirm', [ByoController::class, 'confirm'])->middleware('throttle:20,1');
    Route::post('/vouchers/{id}/redeem', [JejakWangiController::class, 'redeemVoucher'])->whereNumber('id');
});

// Publik: leaderboard (nama disamarkan; token opsional untuk menandai baris milik sendiri) & charger station surya.
Route::get('/leaderboard', [JejakWangiController::class, 'leaderboard']);
Route::get('/chargers', [ChargerStationController::class, 'index']);
Route::get('/chargers/{id}', [ChargerStationController::class, 'show'])->whereNumber('id');

// Mood Tracker. `store` sengaja TIDAK memakai middleware auth:sanctum agar
// mode tamu (anonim) tetap bisa mengirim mood sesuai Batasan 4.1 proposal —
// middleware itu akan menolak request tanpa token. Kalau token dikirim &
// valid, MoodLogController tetap mengenali user lewat $request->user('sanctum')
// (guard sanctum bisa dipanggil langsung tanpa middleware), jadi login tetap
// opsional, bukan wajib.
Route::post('/mood-logs', [MoodLogController::class, 'store'])->middleware('throttle:mood-store');
Route::get('/mood-logs/summary', [MoodLogController::class, 'summary']);

// Geofencing & Soundscape Therapy. `index` publik (dipakai svarga-app dan
// tabel manajemen zona di admin lewat ?all=1); `store` khusus admin.
Route::get('/geofences', [GeofenceController::class, 'index']);
Route::middleware('cache.headers:public;max_age=60;etag')->group(function () {
    Route::get('/soundscapes', [SoundscapeController::class, 'index']);
    Route::get('/soundscapes/{id}', [SoundscapeController::class, 'show']);
});

// Endpoint khusus dashboard admin (svarga-admin) — menggantikan Prisma/Supabase
// sepenuhnya. Semua di-guard ['auth:sanctum', 'admin'] (lihat EnsureUserIsAdmin).
Route::middleware(['auth:sanctum', 'active', 'admin'])->group(function () {
    Route::prefix('admin')->group(function () {
        Route::get('/users', [AdminUserController::class, 'index']);
        Route::post('/users', [AdminUserController::class, 'store']);
        Route::put('/users/{id}', [AdminUserController::class, 'update']);
        Route::delete('/users/{id}', [AdminUserController::class, 'destroy']);
        Route::get('/jejak-wangi/summary', [AdminJejakWangiController::class, 'summary']);
        Route::get('/activity-monthly', [AdminUserController::class, 'activityMonthly']);
        Route::get('/notifications', [AdminNotificationController::class, 'index']);
        Route::post('/notifications/mark-read', [AdminNotificationController::class, 'markAllRead']);

        // Sensor IoT (perangkat ESP32), pengaturan, dan backup — halaman Sensor IoT & Pengaturan.
        Route::get('/sensor-devices', [AdminSensorDeviceController::class, 'index']);
        Route::post('/sensor-devices', [AdminSensorDeviceController::class, 'store']);
        Route::put('/sensor-devices/{id}', [AdminSensorDeviceController::class, 'update']);
        Route::post('/sensor-devices/{id}/rotate-key', [AdminSensorDeviceController::class, 'rotateKey']);
        Route::delete('/sensor-devices/{id}', [AdminSensorDeviceController::class, 'destroy']);

        Route::get('/settings', [AdminSettingsController::class, 'show']);
        Route::put('/settings', [AdminSettingsController::class, 'update']);
        Route::post('/backup', [AdminSettingsController::class, 'backup']);
        Route::get('/backup/download', [AdminSettingsController::class, 'download']);
    });

    // CRUD zona geofencing & event festival (baca tetap publik, lihat route di atas).
    Route::post('/geofences', [GeofenceController::class, 'store']);
    Route::put('/geofences/{id}', [GeofenceController::class, 'update']);
    Route::delete('/geofences/{id}', [GeofenceController::class, 'destroy']);

    Route::post('/festivals', [FestivalController::class, 'store']);
    Route::put('/festivals/{id}', [FestivalController::class, 'update']);
    Route::delete('/festivals/{id}', [FestivalController::class, 'destroy']);
});

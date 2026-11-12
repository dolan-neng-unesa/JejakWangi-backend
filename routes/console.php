<?php

use Illuminate\Support\Facades\Schedule;

// Hapus bacaan sensor lama supaya tabel tidak membengkak (jalankan scheduler:
// `* * * * * php artisan schedule:run` di cron server).
Schedule::command('sensors:prune')->dailyAt('03:00');

// Cek sensor offline & AQI tinggi tiap 5 menit → catat ke notifikasi admin.
Schedule::command('jejakwangi:monitor')->everyFiveMinutes();

// Buang token Sanctum yang sudah kedaluwarsa lebih dari 24 jam supaya
// personal_access_tokens tidak membengkak (token bermasa-berlaku sejak SANCTUM_EXPIRATION).
Schedule::command('sanctum:prune-expired --hours=24')->daily();

<?php

// SVARGA — konfigurasi CORS supaya browser (svarga-app di :5173, svarga-admin di :5174,
// atau domain produksi) diizinkan memanggil API Laravel ini.
//
// Produksi: isi CORS_ALLOWED_ORIGINS di .env dengan domain asli, dipisah koma, mis.
//   CORS_ALLOWED_ORIGINS=https://app.svarga.id,https://admin.svarga.id
// Pola LAN (192.168.x.x / 10.x.x.x) HANYA aktif saat APP_ENV=local, supaya server
// produksi tidak menerima origin dari jaringan privat sembarang.

return [
    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', env('CORS_ALLOWED_ORIGINS', 'http://localhost:5173,http://localhost:5174'))
    ))),

    // Dev: izinkan akses dari HP/laptop lain di WiFi yang sama (mis. http://192.168.1.x:5173).
    'allowed_origins_patterns' => env('APP_ENV', 'production') === 'local'
        ? ['#^http://(localhost|127\.0\.0\.1|192\.168\.\d+\.\d+|10\.\d+\.\d+\.\d+):\d+$#']
        : [],

    'allowed_headers' => ['*'],

    // Retry-After dibutuhkan FE untuk menampilkan "coba lagi dalam N detik" saat kena 429.
    'exposed_headers' => ['Retry-After'],

    // Browser boleh menyimpan hasil preflight 10 menit. Dengan 0, setiap POST/PUT JSON
    // ber-Authorization (login, mood, semua aksi admin) memicu satu request OPTIONS ekstra.
    'max_age' => 600,

    'supports_credentials' => false,
];

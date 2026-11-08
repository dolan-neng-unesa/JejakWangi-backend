<?php

// Sumber data kualitas udara/suhu dari Open-Meteo (gratis, tanpa API key).
// Dokumentasi: https://open-meteo.com/en/docs/air-quality-api
// Catatan lisensi: gratis untuk pemakaian non-komersial; data CC BY 4.0 (cantumkan atribusi "Open-Meteo.com").
return [
    // off        = hanya sensor ESP32 (perilaku lama)
    // auto       = sensor ESP32 diutamakan; Open-Meteo dipakai saat sensor mati / data > 5 menit
    // openmeteo  = selalu Open-Meteo (sensor ESP32 diabaikan untuk polutan & suhu)
    'mode' => env('OPENMETEO_MODE', 'off'),

    // Koridor yang dipakai kartu ISPU di home saat tidak ada wilayah ESP32 yang aktif. Kosong = koridor pertama.
    'default_koridor_id' => env('OPENMETEO_DEFAULT_KORIDOR_ID'),

    'cache_minutes' => (int) env('OPENMETEO_CACHE_MINUTES', 10),
    'timeout_sec' => (int) env('OPENMETEO_TIMEOUT_SEC', 6),

    'air_url' => 'https://air-quality-api.open-meteo.com/v1/air-quality',
    'weather_url' => 'https://api.open-meteo.com/v1/forecast',
];

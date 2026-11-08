# Eco-Coach (AI) — Jejak Wangi

Prinsip: **angka dihitung sistem, AI hanya menjelaskan dan menyarankan.**

| Mode | Endpoint | Siapa | Dipakai di |
|---|---|---|---|
| `ringkasan` | `POST /api/eco-coach` | wajib login | kartu Eco-Coach di halaman hasil aktivitas |
| `tanya` | `POST /api/eco-coach` | tamu boleh | halaman chat `/eco-coach` di app |
| `laporan` | `POST /api/admin/eco-coach/laporan` | admin | kartu "Asisten Laporan" di halaman Laporan admin |

Jawaban: `{ "data": { "teks": "...", "sumber": "ai" | "template" } }`. App menampilkan label
"Dijelaskan AI" / "Ringkasan otomatis". Batas 10 permintaan/menit per pengguna (atau IP untuk tamu).

## Mengaktifkan AI (`.env` backend)

API key hanya di server. Tanpa `LLM_PROVIDER`, Eco-Coach tetap menjawab memakai template (tanpa AI).

```
LLM_PROVIDER=anthropic
ANTHROPIC_API_KEY=...
LLM_MODEL=claude-haiku-5-5
# atau endpoint kompatibel OpenAI:
# LLM_PROVIDER=openai
# OPENAI_API_KEY=...
# LLM_BASE_URL=https://alamat-endpoint/v1
# LLM_MODEL=nama-model
```

## Asal angka (`app/Services/EcoCoach/EcoCoachData.php`)

- ISPU: sensor ESP32 koridor (PM2.5, PM10, SO2, CO, O3, NO2), rumus Permen LHK P.14/2020
  (`app/Services/Ispu.php`, identik dengan `jejak-wangi-app/src/lib/ispu.js`). Hanya bacaan <= 5 menit.
- Suhu: sensor suhu koridor (<= 5 menit).
- Jarak, CO2 terhindar, rupiah, bensin: tabel `activities` (non-simulasi), dihitung `ActivityImpact`.
- Kemasan sekali pakai dihindari: QR Bawa Tempat Sendiri yang terkonfirmasi (`byo_tokens.used_at`).
- Pohon, skor teduh, kendaraan/hari: input pengelola di `koridors` (bukan hitungan kamera).
- Data yang tidak punya sumber (mis. jam terbaik, laporan sampah liar) **sengaja tidak dikirim**.

## Pengaman

1. Semua angka di jawaban AI harus ada di data; kalau tidak, jawaban dibuang -> template.
2. Template dipakai saat tanpa key, offline, LLM error, atau cek angka gagal.
3. Konteks tanpa nama, email, id pengguna, atau koordinat.
4. Alasan fallback hanya di log server (dan di respons saat `APP_DEBUG=true`).

## Uji

`php artisan test --filter=EcoCoach` (unit: `tests/Unit/EcoCoachTest.php`, endpoint: `tests/Feature/EcoCoachApiTest.php`).

## Sumber ISPU: Open-Meteo (opsional)

`OPENMETEO_MODE` di `.env` backend: `off` (hanya sensor ESP32), `auto` (sensor diutamakan, Open-Meteo saat sensor mati / data > 5 menit),
`openmeteo` (selalu Open-Meteo). Gratis tanpa API key; lisensi non-komersial, data CC BY 4.0 (cantumkan "Open-Meteo.com").
Nilainya estimasi model (CAMS) per grid, bukan ukuran di koridor; di mode `auto` kelompok polutan tidak dicampur dua sumber.
Dipakai oleh `/api/sensors/snapshot(s)`, `/api/sensors/active` (kartu ISPU home) dan Eco-Coach. Tes: `php artisan test --filter=OpenMeteoAirTest`.

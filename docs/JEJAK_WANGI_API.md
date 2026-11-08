# API Jejak Wangi

🔒 = butuh `Authorization: Bearer <token Sanctum>`. Aturan & angka ada di `config/jejakwangi.php`.

## Aktivitas & ringkasan
| Method | Path | Keterangan |
|---|---|---|
| POST 🔒 | `/api/activities` | Body: `client_id` (unik per perangkat, idempoten), `distance_m`, `duration_sec`, `started_at`, opsional `transport`, `koridor_id`, `is_simulated`. Dampak dihitung ulang di server. 422 bila kecepatan > 4,5 m/s. Simulasi tidak dihitung ke poin/progres/leaderboard. |
| GET 🔒 | `/api/activities` | 50 aktivitas terakhir. |
| GET 🔒 | `/api/me/summary` | Total km/CO₂/rupiah/poin/level + `bulan_km`, `rank_bulan`, `badge`. |

## Problem 1 — Kupon bertingkat + cooldown
Progres = total km jalan kaki (non-simulasi) **di bulan kalender berjalan, zona Asia/Jakarta**.

| Tingkat | Syarat | Hadiah | Masa pakai |
|---|---|---|---|
| 1 Pelari Mager | 1 km | Air Mineral 1 pcs | 7 hari |
| 2 Pelari Niat | 3 km | Air Isotonik 1 pcs | 7 hari |
| 3 Pelari Kalcer | 5 km | Air Isotonik 1 pcs + Jajan 1 pcs | 14 hari |

Setiap tingkat punya cooldown **30 hari sejak diklaim** (per pengguna, per tingkat).

| Method | Path | Keterangan |
|---|---|---|
| GET 🔒 | `/api/vouchers/tiers` | Status tiap tingkat: `terkunci` / `bisa_diklaim` / `cooldown`, progres km, `cooldown_until`, `reset_at`. |
| POST 🔒 | `/api/vouchers/claim` | Body `{tier: 1..3}`. 422 bila progres kurang atau masih cooldown. |
| GET 🔒 | `/api/vouchers` | Kupon (`tersedia/digunakan/kedaluwarsa`). |
| POST 🔒 | `/api/vouchers/{id}/redeem` | Tandai kupon ditukar di UMKM. |

## Problem 2 — Leaderboard bulanan & badge
| Method | Path | Keterangan |
|---|---|---|
| GET | `/api/leaderboard` | Top 100 jarak terjauh **bulan ini**; nama disamarkan; token opsional (menandai `is_me` dan mengisi `me` bila di luar daftar). |

Badge: peringkat 1 → Top 1, 2–3 → Top 3, 4–5 → Top 5, 6–10 → Top 10. Reset otomatis tiap awal bulan (jendela kalender; data lama tidak dihapus), termasuk badge.
Seri jarak berbagi peringkat.

## Problem 3 — Zero-waste (bawa tempat sendiri)
| Method | Path | Keterangan |
|---|---|---|
| POST 🔒 kasir | `/api/byo/token` | Hanya akun dengan `users.umkm_id` (diatur admin). Menerbitkan QR `jejakwangi:byo:<token>`, sekali pakai, berlaku 5 menit. |
| POST 🔒 | `/api/byo/confirm` | Body `{token}`. Memangkas cooldown **tiap kupon yang sedang cooldown sebesar 1 hari**. Bila tidak ada kupon cooldown, QR tidak dihabiskan (`applied:false`). Maks 3 konfirmasi/pelanggan/hari. Kasir tak bisa memindai QR sendiri. |

## Lainnya
`GET /api/chargers`, `GET /api/chargers/{id}` (publik) · `GET /api/admin/jejak-wangi/summary` (admin).

Menjalankan: `php artisan migrate --seed`. Seeder membuat kasir demo `kasir@jejakwangi.id` / `jejakwangi123` (hanya non-produksi).
Di produksi: admin mengisi **ID UMKM** pada akun pengguna (dashboard admin → Pengguna).

## Lapor Koridor & Sampah/Pangan (baru)
Setelah menempel: `php artisan migrate && php artisan db:seed --class=ReportsWasteSeeder && php artisan storage:link`
(storage:link wajib agar lampiran laporan bisa dibuka).
- Admin: `GET/POST /admin/reports`, `GET/POST /admin/reports/{id}` (POST = ubah status, multipart), `GET /admin/waste{,/meta,/summary,/checkins}`, `POST/PUT/DELETE /admin/waste`, `POST /admin/waste/{id}/process`
- Pengguna: `GET /reports/meta`, `POST /reports`, `GET /me/reports`, `POST /reports/{id}/respond`, `POST /waste/checkin`, `GET /waste/mine`, `POST /waste/{id}/confirm|correction`
- QR lokasi timbang: `jejakwangi:timbang:BSS-001` (daftar stasiun & tarif poin di `config/jejakwangi.php` → `sampah`).

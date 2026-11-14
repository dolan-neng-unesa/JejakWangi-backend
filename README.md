<<<<<<< HEAD
# JEJAK WANGI Admin Dashboard

Dashboard admin untuk ekosistem JEJAK WANGI (React 19 + Vite + Tailwind 4 + Leaflet — stack yang sama dengan `jejak-wangi-app`).
Tampilan mengikuti mockup "JEJAK WANGI UI Design"; data langsung dari `jejak-wangi-backend` (Laravel + Sanctum).

## Menjalankan

```bash
# 1. Backend: salin isi ../backend-patch ke jejak-wangi-backend/ lalu
php artisan migrate && php artisan serve          # http://localhost:8000

# 2. Admin
cp .env.example .env                               # VITE_API_URL=http://localhost:8000/api
npm install
npm run dev                                        # http://localhost:5174
```
Login memakai akun `is_admin = true` (seeder: `admin@jejakwangi.id`). Akun non-admin ditolak.

**Tanpa backend (hanya preview UI):** `npm run mock` lalu `npm run dev` — mock API (`dev/mock-api.mjs`)
meniru kontrak backend, login `admin@jejakwangi.id` / `jejakwangi123`.

## Halaman ↔ endpoint

| Halaman | Endpoint | Fungsi |
|---|---|---|
| Login | `POST /auth/login`, `GET /auth/me`, `POST /auth/logout` | sesi token, cek `is_admin` |
| Dashboard | `/koridors`, `/sensors/snapshot`, `/mood-logs/summary`, `/admin/activity-monthly`, `/festivals`, `/geofences`, `/admin/sensor-devices` | ringkasan + peta, auto-refresh |
| Monitoring | `/sensors/snapshot`, `/sensors/history` | tab AQI/Suhu/Kelembapan/UV/Bising, filter periode & lokasi, detail lokasi |
| Smart Green Route | `/green-spaces`, `/geofences`, `/koridors` + routing OSM | cari rute, moda, prioritas, skor AQI/bising/teduh |
| Kalender Festival | `/festivals` (CRUD) | kalender, filter, tambah/ubah/hapus event |
| Sensor IoT | `/admin/sensor-devices` (CRUD, rotate-key) | daftar, tambah (kunci API sekali), ubah, perawatan, hapus, peta |
| Mood & Wellbeing | `/mood-logs/summary` | tren, distribusi, daftar laporan + CSV |
| Geofencing | `/geofences` (CRUD), `/soundscapes` | tabel, peta, tambah/ubah dengan pemilih peta |
| Laporan & Analitik | gabungan | grafik, ekspor CSV, cetak/PDF |
| Pengguna | `/admin/users` (CRUD) | cari, filter, paginasi |
| Notifikasi | `/admin/notifications`, `/admin/settings` | filter kategori, tandai dibaca, push browser |
| Pengaturan | `/admin/settings`, `/admin/users/{id}`, `/admin/backup` | profil, aplikasi, sensor, geofence, notifikasi, integrasi, backup |

## Penyimpangan dari mockup (disengaja)
- **Kalender Festival**: filter "Kecamatan" → "Lokasi" (backend tak punya kecamatan). Tiga kartu statistik event
  (Rute/Notifikasi/UMKM) diganti Kategori/Durasi/Hitung Mundur karena angka itu tidak ada di backend.
- **Geofencing**: pemilih "Periode" → filter "Status" (tidak ada log kehadiran per periode).
- **Sensor IoT**: satu baris = satu perangkat ESP32 (bukan per jenis sensor); jenis sensor tampil sebagai chip.
- Tombol/aksi tambahan yang berguna: Tambah Event, Hapus di form ubah, peta sensor, menu Laporan & Analitik (tak ada mockup-nya).

## Catatan jujur
- **Kelembapan, UV, Kebisingan** belum punya sensor di `sensor_readings`; nilainya dari snapshot baseline koridor
  (ditandai "Baseline" di UI) dan otomatis berganti saat sensornya ada.
- **Email & SMS** pada Notifikasi/Integrasi tersimpan sebagai preferensi; pengiriman nyata butuh SMTP/gateway SMS di server
  (`MAIL_MAILER=log` saat ini). Push browser berfungsi saat dashboard terbuka.
- Backup berisi semua tabel kecuali `sensor_readings` (deret waktu besar).
- Routing jalan memakai layanan publik `routing.openstreetmap.de`; bila tak terjangkau jalur jatuh ke garis lurus
  (ada penanda di UI). Ganti dengan `VITE_ROUTING_URL_BASE`.
=======
# backend-patch — tambahan jejak-wangi-backend untuk dashboard admin

Salin isi folder ini ke `jejak-wangi-backend/` (timpa file yang sama), lalu:

```bash
php artisan migrate
php artisan route:list --path=admin     # cek route baru
```

Baru:
- `AdminSensorDeviceController`  — CRUD perangkat sensor + putar kunci API
- `AdminSettingsController`     — pengaturan, info sistem, backup & unduh backup
- `AppSettingModel`, 2 migrasi  — tabel `app_settings`; kolom `name` & `is_maintenance` di `sensor_devices`
- `Console/Commands/MonitorSystem` — `jejakwangi:monitor` (sensor offline & AQI tinggi → notifikasi), terjadwal tiap 5 menit

Diubah (perubahan kecil, ditandai di kode):
- `routes/api.php`, `routes/console.php`
- `AdminNotificationController` — filter `?category=`, `counts`, `filtered_total`
- `MoodLogController@summary` — field `unique_contributors`
- `SensorDeviceModel`, `AdminNotificationModel::notify()`
- `AdminUserController`, `AuthController`, `FestivalController`, `GeofenceController` — mencatat notifikasi admin saat ada data baru
- `PruneSensorReadings` — default hari retensi dari pengaturan `sensor.retention_days`

## Login sosial (Google & Facebook)

Selain email/kata sandi, `POST /api/auth/social` menerima login Google dan Facebook.
FE mengambil kredensial dari SDK penyedia, backend **memverifikasinya langsung ke
Google/Facebook** (`app/Services/SocialIdentityVerifier.php`), lalu menerbitkan token
Sanctum yang sama seperti login email. Tidak butuh paket tambahan (tanpa Socialite).

```http
POST /api/auth/social
{ "provider": "google" | "facebook", "token": "<ID token Google | access token Facebook>" }
GET  /api/auth/providers      → penyedia yang aktif + ID publik aplikasinya (untuk FE)
```

Aktifkan di `.env` (kosong = penyedia itu nonaktif dan tombolnya tidak muncul di FE):

```env
GOOGLE_CLIENT_ID=xxxx.apps.googleusercontent.com   # Google Cloud Console → Credentials → OAuth client ID (Web)
FACEBOOK_APP_ID=...                                 # Meta for Developers → Facebook Login (izin: public_profile, email)
FACEBOOK_APP_SECRET=...
```

- Di Google Cloud Console, tambahkan domain FE ke **Authorized JavaScript origins**
  (mis. `https://jejakwangi.id` dan `http://localhost:5173`). Di Meta, tambahkan domain FE ke
  **App Domains** dan aktifkan Facebook Login for Web. Facebook mewajibkan HTTPS di production.
- Urutan pencarian akun: ID penyedia → email yang sudah diverifikasi penyedia (ditautkan ke
  akun email yang ada) → akun baru. Akun sosial baru **tidak pernah admin**.
- Akun sosial memakai kata sandi acak yang tak diketahui siapa pun; tidak bisa login lewat
  `/auth/login`.
- Setelah menarik versi ini: `php artisan migrate` (menambah kolom `google_id`, `facebook_id`).

## Database (SQLite)

Backend memakai SQLite. File yang dipakai ditentukan `DB_DATABASE` di `.env`; nilai relatif
dihitung dari **root proyek** (bukan folder `database/`). Contoh: `DB_DATABASE=laravel` berarti
file `laravel` di root — bukan `database/database.sqlite`. Production: isi path **absolut**
dan pastikan file serta foldernya bisa ditulis oleh user web server.
>>>>>>> 11277a5008152cfb3fea2a9f9fdf4ba8d2265cde

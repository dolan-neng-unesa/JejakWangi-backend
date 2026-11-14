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
<<<<<<< HEAD
=======



## Figma Link 
https://www.figma.com/design/MNV5DGRhN07Mosy57J71ot/jejak-wangi?node-id=16-715&t=etKgyPRCwnAplZk3-1


## Flowchart Jejak wangi Figma Link
https://www.figma.com/board/8zv6Mj1PGNH1sV0DiL1XwK/jejak-wangi-Flowchart?node-id=0-1&t=PABP7Q5QMjR69Lww-1
>>>>>>> 0e65e5b26d27c7d03831a1380f3391c670d64ff1

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

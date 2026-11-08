# Menyambungkan ESP32 ke JEJAK WANGI

## 1. Daftarkan perangkat (sekali per ESP32)

```bash
php artisan devices:register ESP32-KOR1-DIORAMA --koridor=1
# X-Device-Key: svg_...   <- simpan, hanya tampil sekali
```

Kunci hilang / bocor: jalankan perintah yang sama lagi (kunci diputar, yang lama langsung mati).
Nonaktifkan perangkat: `php artisan devices:register ESP32-KOR1-DIORAMA --disable`.

## 2. Kirim data

`POST /api/sensors/readings` — header `X-Device-Key` (atau `Authorization: Bearer <kunci>`), body JSON datar.
Kirim hanya sensor yang ada; semua jenis dalam **satu** request:

```json
{"temperature":30.2,"air_quality":55,"pm10":41,"so2":20,"co":2300,"o3":100,"no2":45}
```

| Field | Satuan | Catatan |
|---|---|---|
| `temperature` | °C | |
| `pm25` | µg/m³ | |
| `co2` | ppm | |
| `o2` | % | |
| `air_quality` | AQI | |
| `pm10`, `so2`, `co`, `o3`, `no2` | **µg/m³** | CO juga µg/m³ (bukan ppm) — ISPU di aplikasi dihitung dari satuan ini |
| `ts` *(opsional)* | epoch detik | Hilangkan kalau ESP belum sinkron NTP; `ts` di luar rentang −24 jam/+5 menit diabaikan |

### Banyak wilayah dalam satu request

```json
{"wilayah":[{"no":1,"temperature":31,"pm25":54},{"no":2,"temperature":33.5,"pm25":72}]}
```

`no` menjadi `koridor_id` (field tak dikenal seperti `led` diabaikan). Firmware lengkap 5 wilayah + TFT: `jejakwangi/jejakwangi.ino`.

`device_code` dan `koridor_id` (bentuk datar) **tidak perlu dikirim** — diambil dari perangkat yang terdaftar.

Balasan sukses (`201`): `{"ok":true,"n":6,"t":1790000000}` — `t` = jam server (epoch), bisa dipakai menyetel jam ESP tanpa NTP.

| Status | Arti | Tindakan ESP |
|---|---|---|
| 201 | Tersimpan | Lanjut normal |
| 401 | Kunci salah / perangkat nonaktif | Cek kunci; jangan retry cepat |
| 422 | Tidak ada field dikenal / nilai bukan angka | Perbaiki payload |
| 429 | > 20 request/menit | Tunggu (`Retry-After`) |

Interval yang disarankan: 30 detik. Frontend menganggap data **lama** bila > 5 menit.

## 3. Cek tanpa hardware

```bash
curl -X POST http://127.0.0.1:8000/api/sensors/readings \
  -H "X-Device-Key: svg_..." -H "Content-Type: application/json" \
  -d '{"temperature":31.7,"pm10":48,"co":2100}'

curl "http://127.0.0.1:8000/api/sensors/snapshot?koridor_id=1"
```

Firmware lengkap: [`jejakwangi/`](jejakwangi/) (salin `config.h.example` menjadi `config.h`).

## 4. Produksi

- Pakai **HTTPS** — kunci perangkat ikut terkirim di header. Di ESP32 gunakan `WiFiClientSecure` + sertifikat root.
- Jalankan scheduler (`* * * * * php artisan schedule:run`) agar `sensors:prune` menghapus bacaan > 30 hari.
- Banyak perangkat/pengguna: ganti SQLite ke MySQL/PostgreSQL (`DB_CONNECTION` di `.env`) — kode tidak berubah.

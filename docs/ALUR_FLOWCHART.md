# Alur Flowchart JEJAK WANGI → Kode

Cara cek **satu siklus penuh** (input → olah → keputusan → tindak lanjut → data baru) memakai data seder:

```bash
php artisan migrate
php artisan jejakwangi:cycle-check --seed     # isi data seder 48 jam + ringkasan tiap tahap
php artisan test --filter=FullCycleTest        # uji otomatis seluruh alur lewat API
```

Perangkat seder berkode `seed-*`; halaman admin **Tindak Lanjut → Sumber Data** menandainya "Seder"
dan akan otomatis tergantikan begitu sensor nyata mengirim data (`/api/sensors/readings`, tipe baru:
`solar_w`, `vehicle_motor`, `vehicle_car`, `temperature_open`).

| Flowchart | Kode |
|---|---|
| Sensor Solar Bench | sensor `solar_w` → `hourly_metrics.solar_kwh` |
| Kalender Banyuwangi Festival | `festivals` → `hari_festival` per jam |
| Survei pohon & AC toko | `koridors.carbon_inputs` → `CarbonBalance` |
| Kamera YOLO | sensor `vehicle_motor/vehicle_car` (cadangan: angka survei) |
| Check-in QR warga | `POST /api/checkins/start`, `/{id}/finish` |
| API Open-Meteo | `OpenMeteo::tarik()` (PM10, PM2.5, suhu) · jadwal tiap jam `engine:hourly` |
| Sensor suhu kanopi & terbuka | sensor `temperature` & `temperature_open` → `selisih_suhu` |
| Timbang sisa pangan | `waste_weighings` (kind pangan) → `sisa_pangan_kg` |
| Energi surya, serapan pohon, emisi AC/kendaraan/plastik, ISPU, selisih suhu, neraca | `HourlyAggregator` |
| Database per jam | tabel `hourly_metrics` |
| Asisten Laporan AI (3 temuan, 3 usulan) | `POST /api/admin/asisten/laporan` (aturan server + penjelasan AI) |
| Usulan disetujui? | `POST /api/admin/proposals/{id}/setuju` · `/tolak` · `/revisi` |
| Simulator skenario / Perbaiki dampak | `POST /api/admin/engine/simulasi`, `Simulator::perbaiki` |
| Baseline & target mitigasi | `/api/admin/engine/baseline` |
| Rapor serapan koridor | `/api/admin/engine/rapor` |
| Nilai ekonomi hijau | `/api/admin/engine/ekonomi` |
| Pemilahan mandiri / jadwal penjemputan | `/api/admin/follow-ups` |
| Data baru → Database per jam | `ProposalWorkflow`, `CheckinService`, selesai penjemputan memanggil `HourlyAggregator` |
| Scan QR kantong parkir → ISPU sehat? → saran | `GET /api/qr/parkir/{kode}` |
| Bawa tas sendiri? / QR tujuan kedua / kecepatan wajar? | `CheckinService` (batas di `config/jejakwangi.php → checkin`) |
| Lapor Koridor → dashboard DLH → status laporan | sudah ada: `/api/reports`, `/api/me/reports` |
| Kartu Dampak & SDGs, poin, voucher/bibit | `GET /api/me/impact`, `/api/rewards`, `POST /api/rewards/redeem` |

## Asumsi yang HARUS dikonfirmasi (bukan data resmi)
`config/jejakwangi.php`: harga karbon Rp75.000/ton, emisi 0,04 kg CO₂e per kantong plastik, Solar Bench 0,45 kWh/hari,
pemetaan stasiun sampah → koridor, ambang kecepatan check-in, data survei koridor masih berlabel ILUSTRASI.

## Open-Meteo: dua jalur, satu klien
`App\Services\OpenMeteoAir` adalah satu-satunya klien API (URL/timeout di `config/openmeteo.php`).
- **Baca langsung** (`OPENMETEO_MODE=off|auto|openmeteo`): melengkapi `/api/sensors/snapshot(s)`, kartu ISPU home, dan Eco-Coach saat sensor mati.
- **Simpan per jam** (`engine:hourly` → `Engine\OpenMeteo::tarik()`): menulis bacaan `open-meteo` ke `sensor_readings` untuk Database per jam.
  Polutan → ISPU kawasan; suhu model disimpan sebagai `temperature_open` (suhu area terbuka) → selisih suhu terhadap sensor kanopi.
  Penyimpanan per jam berjalan walau `OPENMETEO_MODE=off`.
Lisensi: gratis non-komersial, data CC BY 4.0 — cantumkan atribusi "Open-Meteo.com".

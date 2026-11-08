<?php

namespace App\Services\Engine;

use App\Models\FestivalModel;
use App\Models\HourlyMetric;
use App\Models\KoridorModel;
use App\Models\QrCheckin;
use App\Models\SensorReadingModel;
use App\Models\WasteWeighingModel;
use App\Services\Ispu;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * "Database per jam" pada flowchart. Untuk tiap koridor & jam, menggabungkan semua sumber data
 * (sensor, API Open-Meteo, kamera YOLO, survei, check-in QR, timbangan, kalender festival) menjadi
 * satu baris dan menghitung neraca karbon:
 *
 *   neraca = serapan pohon + emisi terhindar (jalan kaki + surya + kantong plastik) − emisi AC − emisi kendaraan
 *
 * Positif = koridor surplus (menyerap lebih banyak daripada memancarkan). Idempoten: memanggil ulang
 * jam yang sama menimpa baris (updateOrCreate) — dipakai juga oleh jalur "Data baru" dari Tindak Lanjut.
 */
class HourlyAggregator
{
    private function tz(): string
    {
        return config('jejakwangi.timezone', 'Asia/Jakarta');
    }

    /** Hitung satu jam untuk semua koridor (atau satu). @return Collection<int, HourlyMetric> */
    public function jalankan(Carbon $jam, ?int $koridorId = null): Collection
    {
        $awal = $jam->copy()->utc()->startOfHour();
        $akhir = $awal->copy()->addHour();

        $festival = $this->festival($awal);
        $koridors = KoridorModel::when($koridorId, fn ($q) => $q->where('id', $koridorId))->orderBy('id')->get();
        $out = collect();

        foreach ($koridors as $k) {
            $out->push($this->hitungKoridor($k, $awal, $akhir, $festival));
        }

        return $out;
    }

    /** Hitung rentang jam (inklusif awal, eksklusif akhir). @return int jumlah baris */
    public function rentang(Carbon $dari, Carbon $sampai, ?int $koridorId = null): int
    {
        $n = 0;
        for ($t = $dari->copy()->utc()->startOfHour(); $t->lt($sampai); $t->addHour()) {
            $n += $this->jalankan($t, $koridorId)->count();
        }

        return $n;
    }

    private function festival(Carbon $awal): ?string
    {
        $tanggal = $awal->copy()->setTimezone($this->tz())->toDateString();

        return FestivalModel::active()->whereDate('event_date', $tanggal)->orderBy('start_time')->value('name');
    }

    private function hitungKoridor(KoridorModel $k, Carbon $awal, Carbon $akhir, ?string $festival): HourlyMetric
    {
        $cfg = config('jejakwangi.engine');
        $lokal = $awal->copy()->setTimezone($this->tz());
        $jamLokal = $lokal->hour;
        $in = CarbonBalance::masukan($k);
        $sumber = [];

        $rows = SensorReadingModel::where('koridor_id', (string) $k->id)
            ->where('recorded_at', '>=', $awal)->where('recorded_at', '<', $akhir)->get();
        $rata = fn (string $type) => ($r = $rows->where('sensor_type', $type))->isEmpty() ? null : round((float) $r->avg('value'), 2);
        $asal = function (string $type) use ($rows): string {
            $d = $rows->where('sensor_type', $type)->pluck('device_code')->unique();
            if ($d->contains(fn ($c) => $c !== OpenMeteo::DEVICE && ! str_starts_with($c, 'seed'))) {
                return 'sensor';
            }

            return $d->contains(OpenMeteo::DEVICE) ? 'api' : 'seed';
        };

        // Energi surya terpakai (Solar Bench): rata-rata daya (W) dalam jam = kWh.
        $watt = $rata(SensorReadingModel::TYPE_SOLAR_W);
        $solarKwh = $watt !== null ? round($watt / 1000, 3) : null;
        $sumber['surya'] = $watt !== null ? $asal(SensorReadingModel::TYPE_SOLAR_W) : null;

        // ISPU kawasan dari polutan.
        $nilai = [];
        foreach (array_merge([SensorReadingModel::TYPE_PM25], SensorReadingModel::POLLUTANT_TYPES) as $t) {
            if (($v = $rata($t)) !== null) {
                $nilai[$t] = $v;
            }
        }
        $ispu = Ispu::hitung($nilai);
        $sumber['ispu'] = $ispu ? $asal(isset($nilai['pm25']) ? 'pm25' : 'pm10') : null;

        // Selisih suhu: terbuka − kanopi (positif = kanopi lebih sejuk).
        $kanopi = $rata(SensorReadingModel::TYPE_TEMPERATURE);
        $terbuka = $rata(SensorReadingModel::TYPE_TEMPERATURE_OPEN);
        $selisih = ($kanopi !== null && $terbuka !== null) ? round($terbuka - $kanopi, 2) : null;
        $sumber['suhu'] = $kanopi !== null ? $asal(SensorReadingModel::TYPE_TEMPERATURE) : null;

        // Kendaraan: hitungan kamera YOLO; bila belum ada, sebar angka survei harian ke jam aktif.
        $yolo = $rows->whereIn('sensor_type', [SensorReadingModel::TYPE_VEHICLE_MOTOR, SensorReadingModel::TYPE_VEHICLE_CAR])->isNotEmpty();
        if ($yolo) {
            $motor = (int) round($rows->where('sensor_type', SensorReadingModel::TYPE_VEHICLE_MOTOR)->sum('value'));
            $mobil = (int) round($rows->where('sensor_type', SensorReadingModel::TYPE_VEHICLE_CAR)->sum('value'));
            $sumber['kendaraan'] = 'yolo';
        } else {
            $aktif = $jamLokal >= $cfg['jam_aktif_mulai'] && $jamLokal < $cfg['jam_aktif_mulai'] + $cfg['jam_aktif_kendaraan'];
            $pengali = $festival ? $cfg['festival_pengali_kendaraan'] : 1;
            $motor = $aktif ? (int) round(($in['kendaraanPerHari']['motor'] ?? 0) / $cfg['jam_aktif_kendaraan'] * $pengali) : 0;
            $mobil = $aktif ? (int) round(($in['kendaraanPerHari']['mobil'] ?? 0) / $cfg['jam_aktif_kendaraan'] * $pengali) : 0;
            $sumber['kendaraan'] = $this->tandaiSurvei($in['sumberData']['kendaraan'] ?? null);
        }

        // Check-in QR warga pada jam ini.
        $ci = QrCheckin::where('status', 'dihitung')->where('koridor_id', $k->id)
            ->where('finished_at', '>=', $awal)->where('finished_at', '<', $akhir)
            ->selectRaw('COUNT(*) as n, COALESCE(SUM(co2_kg),0) as co2, COALESCE(SUM(plastik_pcs),0) as plastik')->first();
        $plastik = (int) $ci->plastik;
        $co2Jalan = (float) $ci->co2;

        // Sisa pangan pasar yang ditimbang & dikonfirmasi pada jam ini (stasiun → koridor).
        $stasiun = array_keys(array_filter(config('jejakwangi.sampah.stasiun_koridor'), fn ($id) => (int) $id === (int) $k->id));
        $pangan = $stasiun ? (float) WasteWeighingModel::where('kind', 'pangan')->where('status', 'terkonfirmasi')
            ->whereIn('station_code', $stasiun)->where('weighed_at', '>=', $awal)->where('weighed_at', '<', $akhir)->sum('total_kg') : 0.0;

        // Neraca karbon jam ini.
        $serapan = CarbonBalance::serapanPohon($in['pohon']) / 24;
        $emisiAc = 0.0;
        foreach ($in['toko'] as $t) {
            $buka = (int) $cfg['jam_toko_mulai'];
            if ($jamLokal >= $buka && $jamLokal < $buka + (float) ($t['jamOperasi'] ?? 0)) {
                $emisiAc += (float) ($t['unitAC'] ?? 0) * (float) ($t['kWPerUnit'] ?? 0) * (float) ($t['faktorBeban'] ?? 0.7) * $cfg['faktor_grid'];
            }
        }
        // Bus & truk tidak dihitung kamera YOLO di sini: ikut angka survei harian disebar ke jam aktif.
        $berat = 0.0;
        $aktifBerat = $jamLokal >= $cfg['jam_aktif_mulai'] && $jamLokal < $cfg['jam_aktif_mulai'] + $cfg['jam_aktif_kendaraan'];
        foreach (['bus', 'truk'] as $j) {
            $berat += $aktifBerat ? (float) ($in['kendaraanPerHari'][$j] ?? 0) / $cfg['jam_aktif_kendaraan'] * CarbonBalance::emisiPerKm($j) : 0.0;
        }
        $emisiKend = ($motor * CarbonBalance::emisiPerKm('motor') + $mobil * CarbonBalance::emisiPerKm('mobil') + $berat) * $in['panjangKm'];
        $terhindar = $co2Jalan + ($solarKwh ?? 0) * $cfg['faktor_grid'] + $plastik * $cfg['plastik']['kg_co2_per_kantong'];
        $neraca = $serapan + $terhindar - $emisiAc - $emisiKend;

        $sumber['pohon'] = $this->tandaiSurvei($in['sumberData']['pohon'] ?? null);
        $sumber['ac'] = $this->tandaiSurvei($in['sumberData']['ac'] ?? null);

        return HourlyMetric::updateOrCreate(
            ['koridor_id' => $k->id, 'hour_at' => $awal],
            [
                'solar_kwh' => $solarKwh,
                'ispu' => $ispu['value'] ?? null,
                'ispu_kategori' => $ispu['kategori'] ?? null,
                'polutan_dominan' => $ispu['dominan'] ?? null,
                'pm25' => $nilai['pm25'] ?? null,
                'pm10' => $nilai['pm10'] ?? null,
                'suhu_kanopi' => $kanopi,
                'suhu_terbuka' => $terbuka,
                'selisih_suhu' => $selisih,
                'kendaraan_motor' => $motor,
                'kendaraan_mobil' => $mobil,
                'hari_festival' => $festival !== null,
                'festival_nama' => $festival,
                'checkin_jumlah' => (int) $ci->n,
                'plastik_terhindar' => $plastik,
                'sisa_pangan_kg' => round($pangan, 2),
                'serapan_kg' => round($serapan, 4),
                'emisi_ac_kg' => round($emisiAc, 4),
                'emisi_kendaraan_kg' => round($emisiKend, 4),
                'emisi_terhindar_kg' => round($terhindar, 4),
                'neraca_kg' => round($neraca, 4),
                'sumber' => $sumber,
            ]
        );
    }

    /** sumberData koridor: "ILUSTRASI"/"ESTIMASI" = belum survei. */
    private function tandaiSurvei(?string $nilai): string
    {
        return $nilai !== null && ! preg_match('/ilustrasi|estimasi|asumsi|contoh/i', $nilai) ? 'survei' : 'ilustrasi';
    }
}

<?php

namespace App\Services\EcoCoach;

use App\Models\ActivityModel;
use App\Models\ByoTokenModel;
use App\Models\KoridorModel;
use App\Models\SensorReadingModel;
use App\Models\UserModel;
use App\Services\ActivityImpact;
use App\Services\Ispu;
use App\Services\OpenMeteoAir;
use Illuminate\Support\Carbon;

/**
 * Menyusun data untuk Eco-Coach dari database. HANYA berisi angka hasil hitungan sistem — tanpa
 * nama, email, id pengguna, atau koordinat. Kolom yang tidak punya sumber data dihilangkan
 * (bukan diisi perkiraan), supaya AI tidak punya angka untuk dikarang dan template tidak menulis nilai kosong.
 */
class EcoCoachData
{
    private static function tz(): string
    {
        return config('jejakwangi.timezone', 'Asia/Jakarta');
    }

    /** Data untuk mode ringkasan & tanya. */
    public static function untukWarga(?UserModel $user, ?int $koridorId): array
    {
        $data = ['kawasan' => self::kawasanBulanIni()];
        $kunjungan = $user ? self::kunjunganTerakhir($user) : null;

        if ($user) {
            $data['pengguna'] = self::pengguna($user, $kunjungan);
        }
        // Koridor: yang diminta klien; kalau tidak ada, koridor dari kunjungan terakhir pengguna.
        $koridorId ??= $kunjungan?->koridor_id;
        if ($koridorId && ($k = self::koridor((int) $koridorId))) {
            $data['koridor'] = $k;
        }

        return $data;
    }

    /** Data untuk mode laporan (admin). */
    public static function untukLaporan(): array
    {
        $awal = Carbon::now(self::tz())->startOfWeek()->utc();
        $awalLalu = $awal->copy()->subWeek();

        $aktivitas = ActivityModel::where('is_simulated', false)->where('started_at', '>=', $awal);
        $agg = (clone $aktivitas)->selectRaw('COUNT(*) as n, COUNT(DISTINCT user_id) as u, COALESCE(SUM(distance_m),0) as m, COALESCE(SUM(co2_kg),0) as co2')->first();

        $mingguan = [
            'aktivitas' => (int) $agg->n,
            'pejalanAktif' => (int) $agg->u,
            'jarakJalanKm' => round($agg->m / 1000, 1),
            'co2TerhindarKg' => round((float) $agg->co2, 1),
        ];

        if ($jam = self::jamTerpadat($awal)) {
            $mingguan['jamTerpadatPejalan'] = $jam;
        }

        $kini = self::hitungKemasan($awal);
        $lalu = self::hitungKemasan($awalLalu, $awal);
        $mingguan['kemasanSekaliPakaiDihindari'] = $kini;
        if ($lalu > 0) {
            // Selisih disimpan tanpa tanda minus (arah di kolom terpisah) agar cek angka tidak salah menolak "turun 20%".
            $selisih = (int) round(($kini - $lalu) / $lalu * 100);
            $mingguan['perubahanKemasanPersen'] = abs($selisih);
            $mingguan['arahPerubahanKemasan'] = $selisih > 0 ? 'naik' : ($selisih < 0 ? 'turun' : 'tetap');
        }

        $koridor = [];
        foreach (KoridorModel::orderBy('sort_order')->orderBy('id')->limit(10)->pluck('id') as $id) {
            if ($k = self::koridor((int) $id)) {
                $koridor[] = array_intersect_key($k, array_flip(['nama', 'ispu', 'kategoriIspu', 'polutanDominan', 'suhuC', 'skorTeduh']));
            }
        }

        return ['mingguan' => $mingguan, 'koridorSemua' => $koridor, 'kawasan' => self::kawasanBulanIni()];
    }

    // ------------------------------------------------------------------ bagian-bagian

    private static function kunjunganTerakhir(UserModel $user): ?ActivityModel
    {
        return ActivityModel::where('user_id', $user->id)->where('is_simulated', false)->orderByDesc('started_at')->first();
    }

    private static function pengguna(UserModel $user, ?ActivityModel $a): array
    {
        $p = [];
        if ($a) {
            $impact = ActivityImpact::hitung((int) $a->distance_m, $a->transport);
            $p['jarakJalanM'] = (int) $a->distance_m;
            $p['co2TerhindarG'] = (int) round(((float) $a->co2_kg) * 1000);
            $p['bensinHematL'] = round($impact['liter'], 2);
            $p['rupiahHemat'] = (int) $a->rupiah;
        }

        $awal = Carbon::now(self::tz())->startOfWeek()->utc();
        $minggu = ActivityModel::where('user_id', $user->id)->where('is_simulated', false)->where('started_at', '>=', $awal)
            ->selectRaw('COUNT(*) as n, COALESCE(SUM(distance_m),0) as m, COALESCE(SUM(co2_kg),0) as co2')->first();
        if ((int) $minggu->n > 0) {
            $p['mingguIni'] = [
                'kunjungan' => (int) $minggu->n,
                'jarakJalanM' => (int) $minggu->m,
                'co2TerhindarG' => (int) round(((float) $minggu->co2) * 1000),
            ];
        }
        $kemasan = ByoTokenModel::where('used_by', $user->id)->where('used_at', '>=', $awal)->count();
        if ($kemasan > 0) {
            $p['mingguIni']['kemasanSekaliPakaiDihindari'] = $kemasan;
        }

        return $p;
    }

    /** Agregat kolektif bulan berjalan (tidak ada data individu). */
    private static function kawasanBulanIni(): array
    {
        $awal = Carbon::now(self::tz())->startOfMonth()->utc();
        $agg = ActivityModel::where('is_simulated', false)->where('started_at', '>=', $awal)
            ->selectRaw('COUNT(DISTINCT user_id) as u, COALESCE(SUM(distance_m),0) as m, COALESCE(SUM(co2_kg),0) as co2')->first();

        return [
            'pejalanAktif' => (int) $agg->u,
            'jarakJalanKm' => round($agg->m / 1000, 1),
            'co2TerhindarKg' => round((float) $agg->co2, 1),
            'kemasanSekaliPakaiDihindari' => self::hitungKemasan($awal),
        ];
    }

    /** QR Bawa Tempat Sendiri yang benar-benar dikonfirmasi (token terpakai). */
    private static function hitungKemasan(Carbon $dari, ?Carbon $sampai = null): int
    {
        return ByoTokenModel::whereNotNull('used_at')->where('used_at', '>=', $dari)
            ->when($sampai, fn ($q) => $q->where('used_at', '<', $sampai))->count();
    }

    /** Rentang satu jam dengan aktivitas jalan terbanyak minggu ini (zona waktu lokal), mis. "06.00–07.00". */
    private static function jamTerpadat(Carbon $awal): ?string
    {
        $perJam = [];
        ActivityModel::where('is_simulated', false)->where('started_at', '>=', $awal)->limit(20000)->pluck('started_at')
            ->each(function ($t) use (&$perJam) {
                $h = Carbon::parse($t)->setTimezone(self::tz())->hour;
                $perJam[$h] = ($perJam[$h] ?? 0) + 1;
            });
        if ($perJam === []) {
            return null;
        }
        arsort($perJam);
        $h = array_key_first($perJam);

        return sprintf('%02d.00–%02d.00', $h, ($h + 1) % 24);
    }

    /** Kondisi satu koridor: data pengelola + bacaan sensor yang masih baru (<= 5 menit). */
    public static function koridor(int $id): ?array
    {
        $k = KoridorModel::find($id);
        if (! $k) {
            return null;
        }
        $out = ['nama' => $k->short_name];
        if ($k->shade_score !== null) {
            $out['skorTeduh'] = (int) $k->shade_score;
        }
        $in = (array) $k->carbon_inputs;
        $pohon = (int) collect($in['pohon'] ?? [])->sum('jumlah');
        if ($pohon > 0) {
            $out['jumlahPohon'] = $pohon;
        }
        if (isset($in['kendaraanPerHari']['motor'])) {
            $out['kendaraanPerHariMotor'] = (int) $in['kendaraanPerHari']['motor'];
        }
        if (isset($in['kendaraanPerHari']['mobil'])) {
            $out['kendaraanPerHariMobil'] = (int) $in['kendaraanPerHari']['mobil'];
        }
        if ($k->co2_reduced_kg_day !== null) {
            $out['co2DiserapKgPerHari'] = (float) $k->co2_reduced_kg_day;
        }

        $segar = SensorReadingModel::where('koridor_id', (string) $id)
            ->where('recorded_at', '>', Carbon::now()->subMinutes(SensorReadingModel::FRESHNESS_WINDOW_MINUTES))
            ->orderByDesc('recorded_at')->get()->unique('sensor_type')->mapWithKeys(fn ($r) => [$r->sensor_type => (float) $r->value]);

        if ($segar->has(SensorReadingModel::TYPE_TEMPERATURE)) {
            $out['suhuC'] = round($segar[SensorReadingModel::TYPE_TEMPERATURE], 1);
        }
        // Open-Meteo (bila aktif): isi / ganti bacaan sensor sesuai OPENMETEO_MODE. Dicatat sumbernya agar AI jujur soal asal data.
        $nilai = $segar->all();
        $dariOm = [];
        if ($om = OpenMeteoAir::bacaan($k)) {
            ['nilai' => $nilai, 'dariOm' => $dariOm] = OpenMeteoAir::isi($nilai, $om['values']);
            if (in_array(SensorReadingModel::TYPE_TEMPERATURE, $dariOm, true)) {
                $out['suhuC'] = round($nilai[SensorReadingModel::TYPE_TEMPERATURE], 1);
            }
        }
        $polutan = array_intersect_key($nilai, array_flip(array_merge([SensorReadingModel::TYPE_PM25], SensorReadingModel::POLLUTANT_TYPES)));
        if ($ispu = Ispu::hitung($polutan)) {
            $out['ispu'] = $ispu['value'];
            $out['kategoriIspu'] = $ispu['kategori'];
            $out['polutanDominan'] = $ispu['dominan'];
            $out['sumberUdara'] = array_intersect($dariOm, array_keys($polutan)) !== [] ? 'model Open-Meteo (bukan sensor koridor)' : 'sensor koridor';
        }

        return $out;
    }
}

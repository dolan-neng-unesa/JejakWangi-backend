<?php

namespace App\Services\Engine;

use App\Models\ActivityModel;
use App\Models\QrCheckin;
use App\Models\QrPoint;
use App\Models\UserModel;
use App\Services\ActivityImpact;
use Illuminate\Support\Carbon;

/**
 * Check-in QR warga (flowchart, aplikasi warga):
 *   Jalan ke pasar/taman → Scan QR lapak → "Bawa tas sendiri?"
 *       Ya, tercatat ───────────────────────────────► Server hitung emisi terhindar dan plastik
 *       Tidak → Scan QR tujuan kedua → "Kecepatan wajar?" ── Ya ──► Server hitung ...
 *                                                       └─ Tidak ► Check-in tidak dihitung
 *   Tanpa belanja (garis putus-putus) → langsung Scan QR tujuan kedua.
 */
class CheckinService
{
    public static function kodeDariQr(string $isi): string
    {
        $isi = trim($isi);

        return str_starts_with($isi, 'jejakwangi:titik:') ? substr($isi, strlen('jejakwangi:titik:')) : $isi;
    }

    public static function jarakMeter(QrPoint $a, QrPoint $b): ?int
    {
        if ($a->lat === null || $a->lng === null || $b->lat === null || $b->lng === null) {
            return null;
        }
        $r = 6371000;
        $dLat = deg2rad($b->lat - $a->lat);
        $dLng = deg2rad($b->lng - $a->lng);
        $h = sin($dLat / 2) ** 2 + cos(deg2rad($a->lat)) * cos(deg2rad($b->lat)) * sin($dLng / 2) ** 2;

        return (int) round(2 * $r * asin(min(1, sqrt($h))));
    }

    /** Tutup check-in lama yang tak diselesaikan (gugur, tidak dihitung). */
    public function bersihkanKedaluwarsa(UserModel $u): void
    {
        QrCheckin::where('user_id', $u->id)->where('status', 'menunggu_tujuan')
            ->where('started_at', '<', now()->subMinutes(config('jejakwangi.checkin.kedaluwarsa_menit')))
            ->update(['status' => 'tidak_dihitung', 'alasan' => 'Check-in tidak diselesaikan (kedaluwarsa).', 'finished_at' => now()]);
    }

    /** Langkah 1: scan QR lapak / titik awal. @return array{checkin: QrCheckin, langkah: string, pesan: string} */
    public function mulai(UserModel $u, QrPoint $titik, ?bool $bawaTas, bool $tanpaBelanja): array
    {
        $this->bersihkanKedaluwarsa($u);
        // Hanya satu check-in terbuka per pengguna: yang lama digugurkan saat memulai yang baru.
        QrCheckin::where('user_id', $u->id)->where('status', 'menunggu_tujuan')
            ->update(['status' => 'tidak_dihitung', 'alasan' => 'Digantikan check-in baru.', 'finished_at' => now()]);

        $base = ['user_id' => $u->id, 'koridor_id' => $titik->koridor_id, 'start_point_id' => $titik->id, 'tanpa_belanja' => $tanpaBelanja, 'started_at' => now()];

        if (! $tanpaBelanja && $titik->type === 'lapak') {
            if ($bawaTas === null) {
                return ['checkin' => null, 'langkah' => 'tanya_tas', 'pesan' => 'Apakah kamu membawa tas sendiri?'];
            }
            if ($bawaTas === true) {
                $maks = (int) config('jejakwangi.byo.maks_per_hari');
                $hariIni = QrCheckin::where('user_id', $u->id)->where('bawa_tas', true)->where('status', 'dihitung')
                    ->where('started_at', '>=', Carbon::now(config('jejakwangi.timezone'))->startOfDay()->utc())->count();
                if ($hariIni >= $maks) {
                    $c = QrCheckin::create($base + ['bawa_tas' => true, 'status' => 'tidak_dihitung', 'alasan' => "Batas {$maks} check-in bawa tas per hari tercapai.", 'finished_at' => now()]);

                    return ['checkin' => $c, 'langkah' => 'tidak_dihitung', 'pesan' => $c->alasan];
                }
                $p = config('jejakwangi.engine.plastik');
                $pcs = (int) $p['kantong_per_transaksi'];
                $c = QrCheckin::create($base + [
                    'bawa_tas' => true, 'status' => 'dihitung', 'plastik_pcs' => $pcs, 'co2_kg' => round($pcs * $p['kg_co2_per_kantong'], 3),
                    'points' => (int) $p['poin_per_transaksi'], 'finished_at' => now(),
                ]);
                $this->perbaruiJam($c);

                return ['checkin' => $c, 'langkah' => 'selesai', 'pesan' => 'Tercatat! Kamu mencegah '.$pcs.' kantong plastik.'];
            }
        }

        $c = QrCheckin::create($base + ['bawa_tas' => $tanpaBelanja ? null : false, 'status' => 'menunggu_tujuan']);

        return ['checkin' => $c, 'langkah' => 'scan_tujuan_kedua', 'pesan' => 'Jalan ke tujuan berikutnya lalu scan QR di sana.'];
    }

    /** Langkah 2: scan QR tujuan kedua → "Kecepatan wajar?". */
    public function selesaikan(UserModel $u, QrCheckin $c, QrPoint $tujuan): QrCheckin
    {
        $cfg = config('jejakwangi.checkin');
        $awal = QrPoint::find($c->start_point_id);
        $durasi = max(1, (int) $c->started_at->diffInSeconds(now()));
        $jarak = $awal ? self::jarakMeter($awal, $tujuan) : null;

        $tolak = function (string $alasan) use ($c, $durasi, $jarak, $tujuan): QrCheckin {
            $c->fill(['status' => 'tidak_dihitung', 'alasan' => $alasan, 'end_point_id' => $tujuan->id, 'jarak_m' => $jarak ?? 0, 'durasi_detik' => $durasi, 'finished_at' => now(),
                'kecepatan_mps' => $jarak ? round($jarak / $durasi, 2) : null])->save();

            return $c;
        };

        if ($awal && $awal->id === $tujuan->id) {
            return $tolak('QR tujuan kedua harus berbeda dari titik awal.');
        }
        if ($jarak === null) {
            return $tolak('Koordinat titik QR belum diisi pengelola.');
        }
        if ($jarak < $cfg['min_jarak_m']) {
            return $tolak("Jarak terlalu dekat ({$jarak} m).");
        }
        if ($durasi < $cfg['min_durasi_detik'] || $durasi > $cfg['maks_durasi_detik']) {
            return $tolak('Durasi perjalanan tidak wajar.');
        }
        $v = $jarak / $durasi;
        if ($v > $cfg['maks_kecepatan_mps']) {
            return $tolak(sprintf('Kecepatan %.1f m/s terlalu cepat untuk berjalan kaki.', $v));
        }
        if ($v < $cfg['min_kecepatan_mps']) {
            return $tolak(sprintf('Kecepatan %.2f m/s terlalu lambat untuk satu perjalanan.', $v));
        }

        $d = ActivityImpact::hitung($jarak, 'jalan');
        $c->fill([
            'status' => 'dihitung', 'end_point_id' => $tujuan->id, 'jarak_m' => $jarak, 'durasi_detik' => $durasi,
            'kecepatan_mps' => round($v, 2), 'co2_kg' => $d['co2_kg'], 'points' => $d['points'], 'finished_at' => now(),
        ])->save();

        // Masuk ke leaderboard & kupon bertingkat sebagai aktivitas jalan kaki.
        ActivityModel::create([
            'user_id' => $u->id, 'client_id' => 'qr-'.$c->id, 'koridor_id' => $c->koridor_id, 'transport' => 'jalan',
            'distance_m' => $jarak, 'duration_sec' => $durasi, 'kkal' => $d['kkal'], 'co2_kg' => $d['co2_kg'], 'rupiah' => $d['rupiah'],
            'points' => $d['points'], 'is_simulated' => false, 'started_at' => $c->started_at,
        ]);
        $this->perbaruiJam($c);

        return $c;
    }

    /** "Server hitung emisi terhindar dan plastik" → tulis ulang jam berjalan di Database per jam. */
    private function perbaruiJam(QrCheckin $c): void
    {
        if ($c->koridor_id) {
            app(HourlyAggregator::class)->jalankan(now(), (int) $c->koridor_id);
        }
    }
}

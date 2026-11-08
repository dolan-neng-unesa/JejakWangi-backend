<?php

namespace App\Services\Engine;

use App\Models\KoridorModel;
use App\Models\MitigationTarget;
use Illuminate\Support\Carbon;

/** Baseline dan target mitigasi + Rapor serapan koridor. */
class Baseline
{
    /** Tetapkan baseline (rata-rata emisi bersih N hari terakhir) dan target penurunan persen. */
    public static function tetapkan(KoridorModel $k, float $targetPersen, ?string $tenggat, ?int $userId, ?int $proposalId = null): MitigationTarget
    {
        $hari = (int) config('jejakwangi.engine.baseline_hari', 7);
        $sampai = Carbon::now();
        $dari = $sampai->copy()->subDays($hari);
        $b = Neraca::emisiBersihPerHari($k, $dari, $sampai);
        $baseline = $b['nilai'];
        // Target: turun sebesar persen dari |baseline| (baseline negatif = sudah surplus → targetnya surplus lebih besar).
        $target = round($baseline - abs($baseline) * $targetPersen / 100, 3);

        return MitigationTarget::create([
            'koridor_id' => $k->id,
            'baseline_kg_hari' => $baseline,
            'target_persen' => $targetPersen,
            'target_kg_hari' => $target,
            'baseline_dari' => $dari->toDateString(),
            'baseline_sampai' => $sampai->toDateString(),
            'tenggat' => $tenggat,
            'proposal_id' => $proposalId,
            'set_by' => $userId,
        ]);
    }

    /** Progres target terbaru satu koridor terhadap emisi bersih 7 hari terakhir. */
    public static function progres(KoridorModel $k, ?MitigationTarget $t = null): ?array
    {
        $t ??= MitigationTarget::where('koridor_id', $k->id)->latest('id')->first();
        if (! $t) {
            return null;
        }
        $kini = Neraca::emisiBersihPerHari($k, Carbon::now()->subDays(7), Carbon::now());
        $jarak = $t->baseline_kg_hari - $t->target_kg_hari;
        $capaian = $jarak != 0 ? ($t->baseline_kg_hari - $kini['nilai']) / $jarak * 100 : null;

        return [
            'id' => $t->id,
            'koridor_id' => $k->id,
            'koridor' => $k->short_name,
            'baseline_kg_hari' => $t->baseline_kg_hari,
            'target_persen' => $t->target_persen,
            'target_kg_hari' => $t->target_kg_hari,
            'kini_kg_hari' => $kini['nilai'],
            'sumber_kini' => $kini['sumber'],
            'capaian_persen' => $capaian === null ? null : round(max(0, min(150, $capaian)), 1),
            'tercapai' => $kini['nilai'] <= $t->target_kg_hari,
            'tenggat' => $t->tenggat?->toDateString(),
            'baseline_dari' => $t->baseline_dari?->toDateString(),
            'baseline_sampai' => $t->baseline_sampai?->toDateString(),
        ];
    }

    /** Rapor serapan semua koridor untuk periode N hari (dibanding N hari sebelumnya). */
    public static function rapor(int $hari = 7): array
    {
        $sampai = Carbon::now();
        $dari = $sampai->copy()->subDays($hari);
        $dariLalu = $dari->copy()->subDays($hari);
        $rows = [];

        foreach (KoridorModel::orderBy('sort_order')->orderBy('id')->get() as $k) {
            $r = Neraca::ringkas($k->id, $dari, $sampai);
            $lalu = Neraca::ringkas($k->id, $dariLalu, $dari);
            $harian = CarbonBalance::hitung($k);
            // Tanpa Database per jam, rapor jatuh ke perhitungan harian dari survei (ditandai sumber).
            $adaJam = $r['jam_tercatat'] >= 24;
            $rasio = $adaJam ? $r['rasio_persen'] : ($harian['totalEmisi'] > 0 ? round($harian['serapanPohon'] / $harian['totalEmisi'] * 100, 1) : null);
            $rows[] = [
                'koridor_id' => $k->id,
                'koridor' => $k->short_name,
                'serapan_kg' => $adaJam ? $r['serapan_kg'] : round($harian['serapanPohon'] * $hari, 2),
                'emisi_kg' => $adaJam ? $r['emisi_total_kg'] : round($harian['totalEmisi'] * $hari, 2),
                'terhindar_kg' => $r['terhindar_kg'],
                'neraca_kg' => $adaJam ? $r['neraca_kg'] : round(($harian['serapanPohon'] - $harian['totalEmisi']) * $hari, 2),
                'rasio_persen' => $rasio,
                'grade' => Neraca::grade($rasio),
                'perubahan_neraca_kg' => $lalu['jam_tercatat'] >= 24 && $adaJam ? round($r['neraca_kg'] - $lalu['neraca_kg'], 2) : null,
                'ispu_rata' => $r['ispu_rata'],
                'selisih_suhu_rata' => $r['selisih_suhu_rata'],
                'pohon_tambahan_untuk_netral' => $harian['pohonTambahanUntukNetral'],
                'sumber' => $adaJam ? 'database_per_jam' : 'survei_harian',
            ];
        }
        usort($rows, fn ($a, $b) => ($a['rasio_persen'] ?? INF) <=> ($b['rasio_persen'] ?? INF));
        foreach ($rows as $i => &$row) {
            $row['prioritas'] = $i + 1; // rasio terendah = paling butuh intervensi
        }

        return ['periode_hari' => $hari, 'dibuat' => now()->toIso8601String(), 'koridor' => $rows];
    }
}

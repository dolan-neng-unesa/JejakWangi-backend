<?php

namespace App\Services\Engine;

use App\Models\ActivityModel;
use App\Models\RewardRedemption;
use App\Models\WasteWeighingModel;
use Illuminate\Support\Carbon;

/**
 * Nilai ekonomi hijau (Rupiah). Harga & tarif ada di config/jejakwangi.php → engine/ekonomi
 * dan ditampilkan sebagai ASUMSI di halaman "Cara Kami Menghitung" — bukan angka resmi.
 */
class GreenEconomy
{
    public static function hitung(?int $koridorId, Carbon $dari, Carbon $sampai): array
    {
        $h = config('jejakwangi.ekonomi');
        $r = Neraca::ringkas($koridorId, $dari, $sampai);

        $karbonKg = $r['serapan_kg'] + $r['terhindar_kg'];
        $komponen = [];
        $komponen['karbon'] = ['label' => 'Karbon diserap & dihindari', 'jumlah' => round($karbonKg, 1), 'satuan' => 'kg CO₂e', 'rupiah' => (int) round($karbonKg / 1000 * $h['harga_karbon_per_ton'])];
        $komponen['listrik_surya'] = ['label' => 'Listrik Solar Bench', 'jumlah' => $r['surya_kwh'], 'satuan' => 'kWh', 'rupiah' => (int) round($r['surya_kwh'] * $h['tarif_listrik_kwh'])];

        $bbm = ActivityModel::where('is_simulated', false)->where('started_at', '>=', $dari->copy()->utc())->where('started_at', '<', $sampai->copy()->utc())
            ->when($koridorId, fn ($q) => $q->where('koridor_id', $koridorId))->sum('rupiah');
        $komponen['bbm_hemat'] = ['label' => 'BBM hemat (jalan kaki)', 'jumlah' => (int) $bbm, 'satuan' => 'Rp', 'rupiah' => (int) $bbm];
        $komponen['plastik'] = ['label' => 'Kantong plastik terhindar', 'jumlah' => $r['plastik_terhindar'], 'satuan' => 'kantong', 'rupiah' => $r['plastik_terhindar'] * (int) config('jejakwangi.engine.plastik.harga_per_kantong')];

        $w = WasteWeighingModel::where('status', 'terkonfirmasi')->where('weighed_at', '>=', $dari->copy()->utc())->where('weighed_at', '<', $sampai->copy()->utc())
            ->selectRaw('COALESCE(SUM(organik_kg),0) as o, COALESCE(SUM(anorganik_kg),0) as a, COALESCE(SUM(pangan_kg),0) as p')->first();
        $sampah = $w->o * $h['harga_sampah_per_kg']['organik'] + $w->a * $h['harga_sampah_per_kg']['anorganik'] + $w->p * $h['harga_sampah_per_kg']['pangan'];
        $komponen['sampah'] = ['label' => 'Sampah terpilah & terolah', 'jumlah' => round((float) ($w->o + $w->a + $w->p), 1), 'satuan' => 'kg', 'rupiah' => (int) round($sampah)];

        $bibit = RewardRedemption::where('hadiah', 'bibit')->where('created_at', '>=', $dari->copy()->utc())->where('created_at', '<', $sampai->copy()->utc())->count();
        $komponen['bibit'] = ['label' => 'Bibit pohon ditanam', 'jumlah' => $bibit, 'satuan' => 'bibit', 'rupiah' => $bibit * $h['harga_bibit']];

        return [
            'periode' => ['dari' => $dari->toDateString(), 'sampai' => $sampai->toDateString()],
            'koridor_id' => $koridorId,
            'komponen' => $komponen,
            'total_rupiah' => array_sum(array_column($komponen, 'rupiah')),
            'asumsi' => [
                'harga_karbon_per_ton' => $h['harga_karbon_per_ton'],
                'tarif_listrik_kwh' => $h['tarif_listrik_kwh'],
                'catatan' => 'Harga adalah asumsi awal (ubah di config/jejakwangi.php); belum harga pasar resmi.',
            ],
            'jam_tercatat' => $r['jam_tercatat'],
        ];
    }

    /** Nilai tahunan perbaikan skenario simulator. */
    public static function nilaiSkenario(array $hasil): array
    {
        $h = config('jejakwangi.ekonomi');
        $kgTahun = max(0, $hasil['selisih']['emisi_bersih_turun_kg_tahun']);
        $karbon = (int) round($kgTahun / 1000 * $h['harga_karbon_per_ton']);
        $pohon = (int) ($hasil['parameter']['tambah_pohon']['jumlah'] ?? 0);

        return ['nilai_karbon_rupiah' => $karbon, 'biaya_bibit_rupiah' => $pohon * (int) $h['harga_bibit'], 'catatan' => 'Nilai karbon memakai harga asumsi; biaya hanya bibit (belum perawatan).'];
    }
}

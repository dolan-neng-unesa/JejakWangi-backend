<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityModel;
use App\Models\ByoTokenModel;
use App\Models\VoucherModel;

/** Ringkasan program Jejak Wangi untuk dashboard admin (data agregat, tanpa identitas). */
class AdminJejakWangiController extends Controller
{
    public function summary()
    {
        $a = ActivityModel::where('is_simulated', false)
            ->selectRaw('COUNT(*) as n, COUNT(DISTINCT user_id) as u, COALESCE(SUM(distance_m),0) as m, COALESCE(SUM(co2_kg),0) as co2, COALESCE(SUM(rupiah),0) as rp')
            ->first();

        $perKoridor = ActivityModel::where('is_simulated', false)->whereNotNull('koridor_id')
            ->groupBy('koridor_id')->selectRaw('koridor_id, COUNT(*) as n, SUM(distance_m) as m')
            ->orderByDesc('n')->get()->map(fn ($r) => ['koridor_id' => (int) $r->koridor_id, 'aktivitas' => (int) $r->n, 'km' => round($r->m / 1000, 2)]);

        $moda = ActivityModel::whereNotNull('transport')->groupBy('transport')
            ->selectRaw('transport, COUNT(*) as n')->pluck('n', 'transport');

        $voucher = VoucherModel::all()->groupBy(fn ($v) => $v->effectiveStatus())->map->count();

        return response()->json(['data' => [
            'total_aktivitas' => (int) $a->n,
            'pengguna_aktif' => (int) $a->u,
            'total_km' => round($a->m / 1000, 2),
            'total_co2_kg' => round((float) $a->co2, 3),
            'total_rupiah' => (int) $a->rp,
            'per_koridor' => $perKoridor,
            'moda_tiba' => $moda,
            'byo_terkonfirmasi' => ByoTokenModel::whereNotNull('used_at')->count(),
            'kupon_per_tingkat' => VoucherModel::whereNotNull('tier')->groupBy('tier')->selectRaw('tier, COUNT(*) as n')->pluck('n', 'tier'),
            'kupon' => ['tersedia' => (int) ($voucher['tersedia'] ?? 0), 'digunakan' => (int) ($voucher['digunakan'] ?? 0), 'kedaluwarsa' => (int) ($voucher['kedaluwarsa'] ?? 0)],
        ]]);
    }
}

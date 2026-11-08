<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityModel;
use App\Models\QrCheckin;
use App\Models\RewardRedemption;
use App\Models\WasteWeighingModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/** Kartu Dampak & SDGs, poin, dan penukaran voucher lapak / bibit (aplikasi warga). */
class ImpactController extends Controller
{
    private function poinTotal($user): int
    {
        return (int) ActivityModel::where('user_id', $user->id)->where('is_simulated', false)->sum('points')
            + (int) $user->bonus_points
            + (int) QrCheckin::where('user_id', $user->id)->where('status', 'dihitung')->whereNull('end_point_id')->sum('points'); // bonus bawa tas (aktivitas jalan sudah dihitung di activities)
    }

    private function poinTersedia($user): int
    {
        return max(0, $this->poinTotal($user) - (int) RewardRedemption::where('user_id', $user->id)->sum('poin'));
    }

    public function impact(Request $request)
    {
        $u = $request->user();
        $a = ActivityModel::where('user_id', $u->id)->where('is_simulated', false)->selectRaw('COALESCE(SUM(distance_m),0) m, COALESCE(SUM(co2_kg),0) co2')->first();
        $plastik = (int) QrCheckin::where('user_id', $u->id)->where('status', 'dihitung')->sum('plastik_pcs');
        $co2Plastik = (float) QrCheckin::where('user_id', $u->id)->where('status', 'dihitung')->where('plastik_pcs', '>', 0)->sum('co2_kg');
        $sampah = (float) WasteWeighingModel::where('user_id', $u->id)->where('status', 'terkonfirmasi')->sum('total_kg');
        $bibit = RewardRedemption::where('user_id', $u->id)->where('hadiah', 'bibit')->count();
        $co2 = round((float) $a->co2 + $co2Plastik, 3);
        $km = round($a->m / 1000, 2);
        $s = config('jejakwangi.sdgs');

        return response()->json(['data' => [
            'co2_terhindar_kg' => $co2, 'jalan_km' => $km, 'plastik_terhindar' => $plastik, 'sampah_terpilah_kg' => round($sampah, 2), 'bibit' => $bibit,
            'checkin_dihitung' => QrCheckin::where('user_id', $u->id)->where('status', 'dihitung')->count(),
            'sdgs' => [
                ['nomor' => 3, 'nama' => $s[3], 'nilai' => $km, 'satuan' => 'km jalan kaki'],
                ['nomor' => 11, 'nama' => $s[11], 'nilai' => QrCheckin::where('user_id', $u->id)->where('status', 'dihitung')->count(), 'satuan' => 'check-in koridor'],
                ['nomor' => 12, 'nama' => $s[12], 'nilai' => $plastik, 'satuan' => 'kantong plastik terhindar'],
                ['nomor' => 13, 'nama' => $s[13], 'nilai' => $co2, 'satuan' => 'kg CO₂ terhindar'],
            ],
            'poin_total' => $this->poinTotal($u), 'poin_tersedia' => $this->poinTersedia($u),
        ]]);
    }

    public function rewards(Request $request)
    {
        $cat = collect(config('jejakwangi.hadiah'))->map(fn ($h, $k) => ['kode' => $k] + $h)->values();

        return response()->json(['data' => $cat, 'poin_tersedia' => $this->poinTersedia($request->user()),
            'riwayat' => RewardRedemption::where('user_id', $request->user()->id)->orderByDesc('id')->limit(20)->get()]);
    }

    public function redeem(Request $request)
    {
        $v = Validator::make($request->all(), ['hadiah' => ['required', 'in:'.implode(',', array_keys(config('jejakwangi.hadiah')))]]);
        if ($v->fails()) {
            return response()->json(['message' => 'Hadiah tidak valid', 'errors' => $v->errors()], 422);
        }
        $h = config('jejakwangi.hadiah.'.$v->validated()['hadiah']);
        if ($this->poinTersedia($request->user()) < $h['poin']) {
            return response()->json(['message' => 'Poin belum cukup.'], 422);
        }
        $r = RewardRedemption::create(['user_id' => $request->user()->id, 'hadiah' => $v->validated()['hadiah'], 'poin' => $h['poin'],
            'kode' => ($v->validated()['hadiah'] === 'bibit' ? 'BBT-' : 'VCH-').strtoupper(Str::random(6))]);

        return response()->json(['data' => $r, 'poin_tersedia' => $this->poinTersedia($request->user())], 201);
    }
}

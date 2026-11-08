<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityModel;
use App\Models\VoucherCooldownModel;
use App\Models\VoucherModel;
use App\Services\ActivityImpact;
use App\Services\MonthlyRanking;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Jejak Wangi: rekam aktivitas jalan kaki, kupon bertingkat (cooldown), dan leaderboard bulanan.
 * Dampak (CO2, rupiah, kalori, poin) selalu dihitung ulang di server dari jarak & moda.
 */
class JejakWangiController extends Controller
{
    public function storeActivity(Request $request)
    {
        $v = Validator::make($request->all(), [
            'client_id' => ['required', 'string', 'max:64'],
            'koridor_id' => ['nullable', 'integer', 'min:1'],
            'transport' => ['nullable', 'in:motor,mobil,jalan,sepeda'],
            'distance_m' => ['required', 'integer', 'min:1', 'max:'.config('jejakwangi.maks_jarak_m')],
            'duration_sec' => ['required', 'integer', 'min:1', 'max:86400'],
            'started_at' => ['required', 'date'],
            'is_simulated' => ['nullable', 'boolean'],
            'path' => ['nullable', 'array', 'max:5000'],
        ]);
        if ($v->fails()) {
            return response()->json(['message' => 'Data aktivitas tidak valid', 'errors' => $v->errors()], 422);
        }
        $d = $v->validated();

        // Kecepatan rata-rata tak masuk akal untuk jalan kaki/jogging → tolak (cegah kecurangan naik kendaraan).
        if ($d['distance_m'] / $d['duration_sec'] > config('jejakwangi.maks_kecepatan_mps')) {
            return response()->json(['message' => 'Kecepatan terlalu tinggi untuk aktivitas jalan kaki', 'errors' => ['distance_m' => ['Kecepatan tidak wajar.']]], 422);
        }

        $user = $request->user();

        $existing = ActivityModel::where('user_id', $user->id)->where('client_id', $d['client_id'])->first();
        if ($existing) {
            return response()->json(['message' => 'Aktivitas sudah tersimpan', 'data' => $this->activityResource($existing)]);
        }

        $dampak = ActivityImpact::hitung($d['distance_m'], $d['transport'] ?? null);
        $simulated = (bool) ($d['is_simulated'] ?? false);

        $activity = ActivityModel::create([
            'user_id' => $user->id,
            'client_id' => $d['client_id'],
            'koridor_id' => $d['koridor_id'] ?? null,
            'transport' => $d['transport'] ?? null,
            'distance_m' => $d['distance_m'],
            'duration_sec' => $d['duration_sec'],
            'kkal' => $dampak['kkal'],
            'co2_kg' => $dampak['co2_kg'],
            'rupiah' => $dampak['rupiah'],
            'points' => $simulated ? 0 : $dampak['points'],
            'is_simulated' => $simulated,
            'path' => $d['path'] ?? null,
            'started_at' => Carbon::parse($d['started_at']),
        ]);

        return response()->json(['message' => 'Aktivitas tersimpan', 'data' => $this->activityResource($activity)], 201);
    }

    public function activities(Request $request)
    {
        $rows = ActivityModel::where('user_id', $request->user()->id)
            ->orderByDesc('started_at')->limit(50)->get()
            ->map(fn ($a) => $this->activityResource($a));

        return response()->json(['data' => $rows]);
    }

    public function summary(Request $request)
    {
        $user = $request->user();
        $agg = ActivityModel::where('user_id', $user->id)->where('is_simulated', false)
            ->selectRaw('COALESCE(SUM(distance_m),0) as m, COALESCE(SUM(co2_kg),0) as co2, COALESCE(SUM(points),0) as p, COALESCE(SUM(rupiah),0) as rp, COUNT(*) as n')
            ->first();
        $poin = (int) $agg->p + (int) $user->bonus_points;

        $bulanM = MonthlyRanking::userMeters($user->id);
        $rank = $bulanM > 0 ? MonthlyRanking::rankOf($bulanM) : null;

        return response()->json(['data' => [
            'total_km' => round($agg->m / 1000, 2),
            'total_co2_kg' => round((float) $agg->co2, 3),
            'total_rupiah' => (int) $agg->rp,
            'jumlah_aktivitas' => (int) $agg->n,
            'points' => $poin,
            'level' => intdiv($poin, 250) + 1,
            'poin_menuju_level_berikutnya' => 250 - ($poin % 250),
            // Periode bulan berjalan: dasar progres kupon & leaderboard.
            'bulan_km' => round($bulanM / 1000, 2),
            'rank_bulan' => $rank,
            'badge' => $rank ? MonthlyRanking::badgeFor($rank) : null,
        ]]);
    }

    // ------------------------------------------------------------------ KUPON BERTINGKAT (cooldown)

    /** Status tiap tingkat kupon: terkunci | bisa_diklaim | cooldown (+ progres km bulan ini). */
    public function tiers(Request $request)
    {
        $user = $request->user();
        $km = MonthlyRanking::userMeters($user->id) / 1000;
        $cool = VoucherCooldownModel::where('user_id', $user->id)->get()->keyBy('tier');
        $now = now();

        $rows = [];
        foreach (config('jejakwangi.tiers') as $tier => $t) {
            $until = $cool[$tier]->available_at ?? null;
            $inCooldown = $until && $until->gt($now);
            $rows[] = [
                'tier' => $tier,
                'kode' => $t['kode'],
                'nama' => $t['nama'],
                'target_km' => $t['target_km'],
                'hadiah' => $t['hadiah'],
                'berlaku_hari' => $t['berlaku_hari'],
                'progress_km' => round(min($km, $t['target_km']), 2),
                'status' => $inCooldown ? 'cooldown' : ($km >= $t['target_km'] ? 'bisa_diklaim' : 'terkunci'),
                'cooldown_until' => $inCooldown ? $until->toIso8601String() : null,
                'sisa_hari_cooldown' => $inCooldown ? (int) ceil($now->diffInSeconds($until, false) / 86400) : 0,
            ];
        }

        return response()->json(['data' => $rows, 'bulan_km' => round($km, 2), 'reset_at' => MonthlyRanking::resetAt()->toIso8601String()]);
    }

    public function claim(Request $request)
    {
        $v = Validator::make($request->all(), ['tier' => ['required', 'integer', 'in:'.implode(',', array_keys(config('jejakwangi.tiers')))]]);
        if ($v->fails()) {
            return response()->json(['message' => 'Tingkat kupon tidak valid', 'errors' => $v->errors()], 422);
        }
        $tier = (int) $v->validated()['tier'];
        $cfg = config("jejakwangi.tiers.$tier");
        $user = $request->user();

        // Kunci per pengguna+tingkat agar klaim ganda bersamaan tidak lolos.
        $lock = Cache::lock("klaim:{$user->id}:{$tier}", 10);
        if (! $lock->get()) {
            return response()->json(['message' => 'Klaim sedang diproses, coba lagi sebentar.'], 429);
        }

        try {
            $cooldown = VoucherCooldownModel::where('user_id', $user->id)->where('tier', $tier)->first();
            if ($cooldown && $cooldown->available_at->gt(now())) {
                return response()->json(['message' => "Kupon {$cfg['nama']} masih cooldown sampai ".$this->tanggalId($cooldown->available_at).'.', 'cooldown_until' => $cooldown->available_at->toIso8601String()], 422);
            }
            if (MonthlyRanking::userMeters($user->id) < $cfg['target_km'] * 1000) {
                return response()->json(['message' => "Total jalan kaki bulan ini belum mencapai {$cfg['target_km']} km."], 422);
            }

            $voucher = DB::transaction(function () use ($user, $tier, $cfg) {
                VoucherCooldownModel::updateOrCreate(
                    ['user_id' => $user->id, 'tier' => $tier],
                    ['available_at' => now()->addDays(config('jejakwangi.kupon_cooldown_hari'))]
                );

                return VoucherModel::create([
                    'user_id' => $user->id,
                    'code' => 'JW-'.Str::upper(Str::random(6)),
                    'title' => $cfg['nama'],
                    'merchant' => config('jejakwangi.kupon_merchant'),
                    'description' => $cfg['hadiah'],
                    'min_spend' => 0,
                    'max_discount' => 0,
                    'source' => 'tier-'.$tier,
                    'tier' => $tier,
                    'status' => 'tersedia',
                    'expires_at' => now()->addDays($cfg['berlaku_hari']),
                ]);
            });
        } finally {
            $lock->release();
        }

        return response()->json(['message' => 'Kupon berhasil diklaim', 'data' => $this->voucherResource($voucher)], 201);
    }

    public function vouchers(Request $request)
    {
        $rows = VoucherModel::where('user_id', $request->user()->id)->orderByDesc('created_at')->get()
            ->map(fn ($v) => $this->voucherResource($v));

        return response()->json(['data' => $rows]);
    }

    /** Menandai kupon terpakai (ditukar di UMKM). */
    public function redeemVoucher(Request $request, int $id)
    {
        $voucher = VoucherModel::where('user_id', $request->user()->id)->find($id);
        if (! $voucher) {
            return response()->json(['message' => 'Kupon tidak ditemukan'], 404);
        }
        if ($voucher->effectiveStatus() !== 'tersedia') {
            return response()->json(['message' => 'Kupon sudah digunakan atau kedaluwarsa'], 422);
        }
        $voucher->update(['status' => 'digunakan', 'used_at' => now()]);

        return response()->json(['message' => 'Kupon digunakan', 'data' => $this->voucherResource($voucher->fresh())]);
    }

    // ------------------------------------------------------------------ LEADERBOARD BULANAN

    /**
     * Top N pengguna dengan jarak jalan kaki terjauh BULAN INI. Reset otomatis tiap awal bulan (periode
     * dihitung dari kalender, data lama tidak dihapus). Nama disamarkan; simulasi tidak dihitung.
     */
    public function leaderboard(Request $request)
    {
        $max = (int) config('jejakwangi.leaderboard_maks');
        $limit = max(1, min($max, (int) ($request->query('limit') ?? $max)));
        $meId = $request->user('sanctum')?->id;

        $rows = MonthlyRanking::top($limit)->values()->map(fn ($r, $i) => [
            'rank' => $i + 1,
            'name' => $this->maskName($r->name),
            'km' => round($r->m / 1000, 2),
            'badge' => MonthlyRanking::badgeFor($i + 1),
            'is_me' => $meId === (int) $r->uid,
        ]);

        // Pengguna di luar daftar tetap melihat posisinya sendiri.
        $me = null;
        if ($meId && ! $rows->firstWhere('is_me', true)) {
            $m = MonthlyRanking::userMeters($meId);
            if ($m > 0) {
                $rank = MonthlyRanking::rankOf($m);
                $me = ['rank' => $rank, 'km' => round($m / 1000, 2), 'badge' => MonthlyRanking::badgeFor($rank)];
            }
        }

        return response()->json([
            'data' => $rows,
            'me' => $me,
            'period' => ['start' => MonthlyRanking::range()[0]->toIso8601String(), 'reset_at' => MonthlyRanking::resetAt()->toIso8601String()],
        ]);
    }

    /** Tanggal bahasa Indonesia tanpa bergantung locale server (APP_LOCALE default 'en'). */
    private function tanggalId(Carbon $d): string
    {
        $bulan = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
        $d = $d->copy()->setTimezone(config('jejakwangi.timezone'));

        return $d->day.' '.$bulan[$d->month - 1].' '.$d->year;
    }

    private function maskName(?string $name): string
    {
        $parts = preg_split('/\s+/', trim((string) $name), -1, PREG_SPLIT_NO_EMPTY);
        if (! $parts) {
            return 'Warga';
        }

        return count($parts) > 1 ? $parts[0].' '.Str::upper(mb_substr($parts[1], 0, 1)).'.' : $parts[0];
    }

    private function activityResource(ActivityModel $a): array
    {
        return [
            'id' => $a->id, 'client_id' => $a->client_id, 'koridor_id' => $a->koridor_id, 'transport' => $a->transport,
            'distance_m' => $a->distance_m, 'duration_sec' => $a->duration_sec, 'kkal' => $a->kkal,
            'co2_kg' => $a->co2_kg, 'rupiah' => $a->rupiah, 'points' => $a->points,
            'is_simulated' => $a->is_simulated, 'started_at' => $a->started_at->toIso8601String(),
        ];
    }

    private function voucherResource(VoucherModel $v): array
    {
        return [
            'id' => $v->id, 'code' => $v->code, 'title' => $v->title, 'merchant' => $v->merchant,
            'description' => $v->description, 'min_spend' => $v->min_spend, 'max_discount' => $v->max_discount,
            'source' => $v->source, 'tier' => $v->tier, 'status' => $v->effectiveStatus(), 'expires_at' => $v->expires_at->toIso8601String(),
            'used_at' => $v->used_at?->toIso8601String(),
        ];
    }
}

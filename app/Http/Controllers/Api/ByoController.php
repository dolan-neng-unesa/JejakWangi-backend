<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ByoTokenModel;
use App\Models\VoucherCooldownModel;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Zero-waste: kasir menekan "Customer membawa tempat sendiri" → menerbitkan QR sekali pakai;
 * pelanggan memindai → server mengonfirmasi dan memangkas cooldown kupon yang sedang cooldown.
 */
class ByoController extends Controller
{
    /** Hanya akun kasir (tertaut ke UMKM) yang boleh menerbitkan QR. */
    public function issue(Request $request)
    {
        $kasir = $request->user();
        if (! $kasir->umkm_id) {
            return response()->json(['message' => 'Hanya akun kasir UMKM yang dapat membuat QR ini.'], 403);
        }

        $minutes = (int) config('jejakwangi.byo.berlaku_menit');
        $row = ByoTokenModel::create([
            'token' => Str::random(40),
            'umkm_id' => $kasir->umkm_id,
            'issued_by' => $kasir->id,
            'expires_at' => now()->addMinutes($minutes),
        ]);

        return response()->json(['data' => [
            'qr_payload' => 'jejakwangi:byo:'.$row->token,
            'expires_at' => $row->expires_at->toIso8601String(),
            'berlaku_menit' => $minutes,
        ]], 201);
    }

    /** Pelanggan memindai QR kasir. */
    public function confirm(Request $request)
    {
        $v = Validator::make($request->all(), ['token' => ['required', 'string', 'max:64']]);
        if ($v->fails()) {
            return response()->json(['message' => 'QR tidak valid', 'errors' => $v->errors()], 422);
        }
        $user = $request->user();
        $cfg = config('jejakwangi.byo');

        $token = ByoTokenModel::where('token', $v->validated()['token'])->first();
        if (! $token) {
            return response()->json(['message' => 'QR tidak dikenali.'], 404);
        }
        if ($token->used_at) {
            return response()->json(['message' => 'QR ini sudah digunakan.'], 422);
        }
        if ($token->expires_at->isPast()) {
            return response()->json(['message' => 'QR sudah kedaluwarsa. Minta kasir membuat QR baru.'], 422);
        }
        if ($token->issued_by === $user->id) {
            return response()->json(['message' => 'Kasir tidak dapat memindai QR miliknya sendiri.'], 422);
        }

        $tz = config('jejakwangi.timezone');
        $hariIni = Carbon::now($tz)->startOfDay()->utc();
        $hariIniCount = ByoTokenModel::where('used_by', $user->id)->where('used_at', '>=', $hariIni)->count();
        if ($hariIniCount >= $cfg['maks_per_hari']) {
            return response()->json(['message' => "Batas konfirmasi per hari ({$cfg['maks_per_hari']}) sudah tercapai."], 429);
        }

        $cooldowns = VoucherCooldownModel::where('user_id', $user->id)->where('available_at', '>', now())->get();
        if ($cooldowns->isEmpty()) {
            // Tidak ada yang bisa dipangkas → QR TIDAK dihabiskan agar bisa dipindai lagi setelah klaim kupon.
            return response()->json(['message' => 'Terima kasih sudah membawa tempat sendiri! Belum ada kupon yang sedang cooldown, jadi belum ada yang dikurangi.', 'applied' => false, 'data' => ['dikurangi_hari' => 0, 'cooldowns' => []]]);
        }

        $applied = DB::transaction(function () use ($token, $user, $cooldowns, $cfg) {
            // Habiskan token secara atomik: hanya satu pemindai yang menang.
            $won = ByoTokenModel::where('id', $token->id)->whereNull('used_at')
                ->update(['used_by' => $user->id, 'used_at' => now()]);
            if (! $won) {
                return false;
            }
            foreach ($cooldowns as $c) {
                $c->update(['available_at' => $c->available_at->copy()->subDays($cfg['kurangi_hari'])]);
            }

            return true;
        });
        if (! $applied) {
            return response()->json(['message' => 'QR ini sudah digunakan.'], 422);
        }

        $tiers = config('jejakwangi.tiers');

        return response()->json([
            'message' => "Cooldown kuponmu berkurang {$cfg['kurangi_hari']} hari. Terima kasih!",
            'applied' => true,
            'data' => [
                'dikurangi_hari' => $cfg['kurangi_hari'],
                'cooldowns' => $cooldowns->fresh()->map(fn ($c) => [
                    'tier' => $c->tier,
                    'nama' => $tiers[$c->tier]['nama'] ?? "Tingkat {$c->tier}",
                    'cooldown_until' => $c->available_at->toIso8601String(),
                    'sudah_bisa_diklaim' => $c->available_at->lte(now()),
                ])->values(),
            ],
        ]);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\QrCheckin;
use App\Models\QrPoint;
use App\Services\Engine\CheckinService;
use App\Services\Engine\ParkingAdvisor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Aplikasi warga — alur QR pada flowchart.
 *   GET  /api/qr/parkir/{kode}        Scan QR kantong parkir → ISPU, suhu, jam ramai, sehat?, saran
 *   POST /api/checkins/start          Scan QR lapak (bawa_tas? / tanpa_belanja) atau titik awal
 *   POST /api/checkins/{id}/finish    Scan QR tujuan kedua → kecepatan wajar? → dihitung / tidak dihitung
 *   GET  /api/me/checkins             riwayat check-in
 */
class CheckinController extends Controller
{
    public function __construct(private CheckinService $svc, private ParkingAdvisor $parkir)
    {
    }

    public function parkir(string $kode)
    {
        $titik = QrPoint::where('code', CheckinService::kodeDariQr($kode))->where('type', 'parkir')->where('is_active', true)->first();
        if (! $titik) {
            return response()->json(['message' => 'QR kantong parkir tidak dikenali.'], 404);
        }

        return response()->json(['data' => $this->parkir->periksa($titik)]);
    }

    public function start(Request $request)
    {
        $v = Validator::make($request->all(), [
            'code' => ['required', 'string', 'max:80'],
            'bawa_tas' => ['nullable', 'boolean'],
            'tanpa_belanja' => ['nullable', 'boolean'],
        ]);
        if ($v->fails()) {
            return response()->json(['message' => 'Data check-in tidak valid', 'errors' => $v->errors()], 422);
        }
        $d = $v->validated();
        $titik = QrPoint::where('code', CheckinService::kodeDariQr($d['code']))->whereIn('type', ['lapak', 'tujuan'])->where('is_active', true)->first();
        if (! $titik) {
            return response()->json(['message' => 'QR lapak/tujuan tidak dikenali.'], 404);
        }

        $r = $this->svc->mulai($request->user(), $titik, array_key_exists('bawa_tas', $d) ? $d['bawa_tas'] : null, (bool) ($d['tanpa_belanja'] ?? false));

        return response()->json(['data' => [
            'langkah' => $r['langkah'], // tanya_tas | scan_tujuan_kedua | selesai | tidak_dihitung
            'pesan' => $r['pesan'],
            'titik' => ['id' => $titik->id, 'nama' => $titik->name, 'tipe' => $titik->type],
            'checkin' => $r['checkin'] ? $this->resource($r['checkin']) : null,
        ]], $r['checkin'] ? 201 : 200);
    }

    public function finish(Request $request, int $id)
    {
        $v = Validator::make($request->all(), ['code' => ['required', 'string', 'max:80']]);
        if ($v->fails()) {
            return response()->json(['message' => 'QR tidak valid', 'errors' => $v->errors()], 422);
        }
        $c = QrCheckin::where('id', $id)->where('user_id', $request->user()->id)->first();
        if (! $c) {
            return response()->json(['message' => 'Check-in tidak ditemukan.'], 404);
        }
        if ($c->status !== 'menunggu_tujuan') {
            return response()->json(['message' => 'Check-in ini sudah selesai.', 'data' => $this->resource($c)], 422);
        }
        $tujuan = QrPoint::where('code', CheckinService::kodeDariQr($v->validated()['code']))->whereIn('type', ['lapak', 'tujuan'])->where('is_active', true)->first();
        if (! $tujuan) {
            return response()->json(['message' => 'QR tujuan kedua tidak dikenali.'], 404);
        }
        $c = $this->svc->selesaikan($request->user(), $c, $tujuan);

        return response()->json(['data' => $this->resource($c)]);
    }

    public function mine(Request $request)
    {
        $rows = QrCheckin::where('user_id', $request->user()->id)->orderByDesc('started_at')->limit(50)->get()->map(fn ($c) => $this->resource($c));

        return response()->json(['data' => $rows]);
    }

    private function resource(QrCheckin $c): array
    {
        $pts = QrPoint::whereIn('id', array_filter([$c->start_point_id, $c->end_point_id]))->get()->keyBy('id');

        return [
            'id' => $c->id,
            'status' => $c->status, // menunggu_tujuan | dihitung | tidak_dihitung
            'alasan' => $c->alasan,
            'dari' => $pts[$c->start_point_id]->name ?? null,
            'ke' => $pts[$c->end_point_id]->name ?? null,
            'koridor_id' => $c->koridor_id,
            'tanpa_belanja' => (bool) $c->tanpa_belanja,
            'bawa_tas' => $c->bawa_tas,
            'jarak_m' => (int) $c->jarak_m,
            'durasi_detik' => (int) $c->durasi_detik,
            'kecepatan_mps' => $c->kecepatan_mps,
            'co2_kg' => (float) $c->co2_kg,
            'plastik_pcs' => (int) $c->plastik_pcs,
            'points' => (int) $c->points,
            'started_at' => $c->started_at?->toIso8601String(),
            'finished_at' => $c->finished_at?->toIso8601String(),
        ];
    }
}

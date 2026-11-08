<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\EcoCoach\EcoCoach;
use App\Services\EcoCoach\EcoCoachData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * Eco-Coach: penjelasan data berbahasa sehari-hari. Angka dihitung server (EcoCoachData),
 * AI hanya menjelaskan; bila AI tidak tersedia/ngarang angka, dipakai template cadangan.
 *
 *   POST /api/eco-coach                 mode ringkasan (wajib login) | tanya (tamu boleh)
 *   POST /api/admin/eco-coach/laporan   Asisten Laporan mingguan (admin)
 */
class EcoCoachController extends Controller
{
    public function __construct(private EcoCoach $coach)
    {
    }

    public function jawab(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'mode' => ['required', 'in:ringkasan,tanya'],
            'pertanyaan' => ['nullable', 'string', 'max:300'],
            'koridor_id' => ['nullable', 'integer', 'min:1'],
        ]);
        if ($v->fails()) {
            return response()->json(['message' => 'Permintaan tidak valid', 'errors' => $v->errors()], 422);
        }
        $in = $v->validated();

        // Guard sanctum bisa dipanggil tanpa middleware: login opsional untuk "tanya", wajib untuk "ringkasan".
        $user = $request->user('sanctum');
        if ($user && ! $user->is_active) {
            $user = null;
        }

        $pertanyaan = trim((string) ($in['pertanyaan'] ?? ''));
        if ($in['mode'] === 'ringkasan' && ! $user) {
            return response()->json(['message' => 'Silakan masuk untuk melihat ringkasan dampakmu.'], 401);
        }
        if ($in['mode'] === 'tanya' && mb_strlen($pertanyaan) < 2) {
            return response()->json(['message' => 'Tulis pertanyaanmu dulu.', 'errors' => ['pertanyaan' => ['Pertanyaan wajib diisi.']]], 422);
        }

        $data = EcoCoachData::untukWarga($user, $in['koridor_id'] ?? null);

        return $this->balas($this->coach->jawab($in['mode'], $data, $pertanyaan), $in['mode']);
    }

    /** Khusus admin (route di grup ['auth:sanctum','active','admin']). */
    public function laporan(): JsonResponse
    {
        return $this->balas($this->coach->jawab('laporan', EcoCoachData::untukLaporan()), 'laporan');
    }

    private function balas(array $hasil, string $mode): JsonResponse
    {
        // Alasan fallback bersifat internal (mis. "LLM error 401") — dicatat di log, tidak dibocorkan ke klien kecuali debug.
        if ($hasil['sumber'] === 'template' && isset($hasil['alasan']) && config('ecocoach.provider')) {
            Log::warning('Eco-Coach memakai template cadangan', ['mode' => $mode, 'alasan' => $hasil['alasan']]);
        }
        if (! config('app.debug')) {
            unset($hasil['alasan']);
        }

        return response()->json(['data' => $hasil]);
    }
}

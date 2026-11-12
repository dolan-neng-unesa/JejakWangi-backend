<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MoodLogModel;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;

/**
 * Menggantikan koleksi Firestore `mood_logs` + `mood_aggregates` (lihat
 * moodService.js di jejak-wangi-app). Data mood tetap agregat/anonim di
 * response (bukan tugas endpoint ini menyingkap identitas pengguna),
 * sesuai Batasan 4.1 proposal — mood_score individual memang tersimpan di
 * DB untuk keperluan agregasi, tapi endpoint publik hanya membuka
 * ringkasan (`summary`), tidak pernah daftar entri per-pengguna.
 */
class MoodLogController extends Controller
{
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'green_space_id' => ['required', 'integer', 'min:1'],
            'mood_score' => ['required', 'integer', 'min:1', 'max:5'],
            'activity' => ['nullable', 'string', 'max:50'],
            'note' => ['nullable', 'string', 'max:1000'],
            'anonymous_session_id' => ['nullable', 'string', 'max:100'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Data mood tidak valid', 'errors' => $validator->errors()], 422);
        }

        $payload = $validator->validated();
        $payload['user_id'] = $request->user('sanctum')?->id; // null kalau tamu (token tidak dikirim/tidak valid)
        $payload['logged_at'] = now();

        $log = MoodLogModel::create($payload);

        return response()->json(['message' => 'Mood tersimpan', 'data' => ['id' => $log->id]], 201);
    }

    /**
     * Ringkasan agregat untuk halaman Riwayat Mood: tren rata-rata per hari
     * selama N hari terakhir + distribusi skor mood. Presentasi (label,
     * emoji, warna) sengaja tetap jadi urusan FE (lihat MoodHistoryPage.jsx),
     * di sini hanya angka mentah.
     */
    public function summary(Request $request)
    {
        $validator = Validator::make($request->all(), [
            // Nullable: dashboard admin butuh agregat se-kota (semua green space),
            // sedangkan MoodHistoryPage.jsx di jejak-wangi-app mengirim satu green_space_id.
            'green_space_id' => ['nullable', 'integer', 'min:1'],
            'days' => ['nullable', 'integer', 'min:1', 'max:90'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Parameter tidak valid', 'errors' => $validator->errors()], 422);
        }

        $greenSpaceId = $request->filled('green_space_id') ? (int) $request->query('green_space_id') : null;
        $days = (int) ($request->query('days') ?? 7);
        $since = Carbon::now()->subDays($days - 1)->startOfDay();

        $base = fn () => MoodLogModel::query()
            ->when($greenSpaceId, fn ($q) => $q->where('green_space_id', $greenSpaceId))
            ->where('logged_at', '>=', $since);

        // Semua agregasi dikerjakan di database (GROUP BY). Versi lama memuat SELURUH log
        // 1–90 hari ke memori PHP lalu menghitung ulang di loop — memori & waktunya
        // tumbuh linear dengan jumlah catatan, padahal endpoint ini publik.
        $perDayScore = $base()
            ->selectRaw('DATE(logged_at) as day, mood_score, COUNT(*) as n')
            ->groupBy('day', 'mood_score')
            ->get();

        // Tren harian: rata-rata skor per tanggal, mengisi hari tanpa data dengan null
        // supaya grafik FE tetap punya sumbu-x yang lengkap (bukan bolong). Distribusi
        // per hari (per_score) disertakan juga supaya dashboard admin bisa menggambar
        // grafik tren multi-garis (Sangat Baik/Baik/Biasa Saja/Buruk) per tanggal,
        // bukan cuma satu garis rata-rata.
        $byDate = $perDayScore->groupBy('day');

        $distribution = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
        $daily = [];
        for ($i = 0; $i < $days; $i++) {
            $date = $since->copy()->addDays($i)->toDateString();
            $perScore = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
            $count = 0;
            $sum = 0;
            foreach ($byDate->get($date, []) as $row) {
                $score = (int) $row->mood_score;
                $n = (int) $row->n;
                $perScore[$score] = ($perScore[$score] ?? 0) + $n;
                $distribution[$score] = ($distribution[$score] ?? 0) + $n;
                $count += $n;
                $sum += $score * $n;
            }
            $daily[] = [
                'date' => $date,
                'average_score' => $count === 0 ? null : round($sum / $count, 2),
                'count' => $count,
                'per_score' => $perScore,
            ];
        }

        // Kontributor unik: akun login + sesi tamu. Catatan tanpa identitas apa pun
        // (tidak bisa di-dedupe) dihitung sebagai satu kontributor masing-masing.
        // Sesi tamu hanya dihitung untuk baris tanpa user_id (akun login menang).
        $totals = $base()->selectRaw(
            "COUNT(*) as total,
             AVG(mood_score) as avg_score,
             COUNT(DISTINCT user_id) as users,
             COUNT(DISTINCT CASE WHEN user_id IS NULL AND anonymous_session_id <> '' THEN anonymous_session_id END) as sessions,
             SUM(CASE WHEN user_id IS NULL AND (anonymous_session_id IS NULL OR anonymous_session_id = '') THEN 1 ELSE 0 END) as unidentified"
        )->first();

        $total = (int) ($totals->total ?? 0);

        return response()->json([
            'green_space_id' => $greenSpaceId,
            'unique_contributors' => (int) $totals->users + (int) $totals->sessions + (int) $totals->unidentified,
            'days' => $days,
            'total_entries' => $total,
            'average_score' => $total === 0 ? null : round((float) $totals->avg_score, 2),
            'daily' => $daily,
            'distribution' => $distribution,
        ]);
    }
}

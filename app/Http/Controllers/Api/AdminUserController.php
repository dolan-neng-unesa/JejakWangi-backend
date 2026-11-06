<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AdminUserResource;
use App\Models\AdminNotificationModel;
use App\Models\MoodLogModel;
use App\Models\UserModel;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Endpoint khusus dashboard admin (svarga-admin) — menggantikan
 * userService.js + dashboardService.js sisi Prisma/Supabase. Semua route
 * di sini di-guard middleware ['auth:sanctum', 'admin'] (lihat routes/api.php).
 */
class AdminUserController extends Controller
{
    public function index(Request $request)
    {
        $perPage = max(1, min(100, (int) $request->query('per_page', 5)));

        $query = UserModel::query()->orderByDesc('created_at')->orderByDesc('id');

        // Pencarian nama/email + filter status — dikerjakan di sisi server
        // supaya hasilnya benar lintas halaman (bukan cuma halaman yang tampil).
        // Wildcard LIKE (% dan _) dari input di-escape: tanpa itu pencarian "_" atau "%"
        // cocok dengan semua baris. Escape char '!' dipakai karena portabel lintas
        // SQLite/MySQL/PostgreSQL (backslash diperlakukan beda tiap driver).
        if ($request->filled('q')) {
            $raw = mb_substr(trim((string) $request->query('q')), 0, 100);
            $term = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $raw) . '%';
            $query->where(fn ($w) => $w
                ->whereRaw("name LIKE ? ESCAPE '!'", [$term])
                ->orWhereRaw("email LIKE ? ESCAPE '!'", [$term]));
        }
        if ($request->query('status') === 'aktif') {
            $query->where('is_active', true);
        } elseif ($request->query('status') === 'nonaktif') {
            $query->where('is_active', false);
        }

        $users = $query->paginate($perPage);

        // Empat hitungan ringkasan dalam SATU query (sebelumnya 4 query COUNT terpisah).
        $stats = UserModel::query()->selectRaw(
            'COUNT(*) as total,
             SUM(CASE WHEN is_active THEN 1 ELSE 0 END) as aktif,
             SUM(CASE WHEN is_active THEN 0 ELSE 1 END) as nonaktif,
             SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) as baru',
            [now()->subDays(7)]
        )->first();

        return response()->json([
            'data' => AdminUserResource::collection($users->items())->resolve(),
            'page' => $users->currentPage(),
            'last_page' => $users->lastPage(),
            'per_page' => $users->perPage(),
            'filtered_total' => $users->total(),
            'total' => (int) $stats->total,
            'aktif' => (int) $stats->aktif,
            'nonaktif' => (int) $stats->nonaktif,
            'baru_7_hari' => (int) $stats->baru,
        ]);
    }

    public function store(Request $request)
    {
        if (is_string($request->input('email'))) {
            $request->merge(['email' => mb_strtolower(trim($request->input('email')))]);
        }

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'max:128'],
            'is_admin' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Data pengguna tidak valid', 'errors' => $validator->errors()], 422);
        }

        // Berbeda dari register publik: di sini ADMIN yang membuat akun, jadi
        // is_admin boleh diset (endpoint ini di-guard middleware 'admin').
        $user = UserModel::create([
            'name' => $request->input('name'),
            'email' => $request->input('email'),
            'password' => Hash::make($request->input('password')),
            'is_admin' => $request->boolean('is_admin', false),
            'is_active' => $request->boolean('is_active', true),
        ]);

        AdminNotificationModel::notify('pengguna', 'Pengguna Baru Ditambahkan', "Akun {$user->email} dibuat oleh admin.");

        return response()->json(['message' => 'Pengguna berhasil dibuat', 'data' => new AdminUserResource($user)], 201);
    }

    public function update(Request $request, $id)
    {
        $user = UserModel::findOrFail($id);

        if (is_string($request->input('email'))) {
            $request->merge(['email' => mb_strtolower(trim($request->input('email')))]);
        }

        $validator = Validator::make($request->all(), [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'email', 'max:255', 'unique:users,email,' . $user->id],
            'password' => ['nullable', 'string', 'min:8', 'max:128'],
            'is_admin' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Data pengguna tidak valid', 'errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();

        // Cegah admin mencabut hak admin / menonaktifkan DIRINYA SENDIRI —
        // kalau tidak, satu klik salah bisa mengunci semua orang dari dashboard.
        if ($request->user('sanctum')->id === $user->id) {
            if (array_key_exists('is_admin', $data) && !$data['is_admin']) {
                return response()->json(['message' => 'Tidak bisa mencabut hak admin akun sendiri.'], 422);
            }
            if (array_key_exists('is_active', $data) && !$data['is_active']) {
                return response()->json(['message' => 'Tidak bisa menonaktifkan akun sendiri.'], 422);
            }
        }

        $passwordChanged = !empty($data['password']);
        if ($passwordChanged) {
            $data['password'] = Hash::make($data['password']);
        } else {
            unset($data['password']);
        }

        $user->update($data);

        // Akun dinonaktifkan atau kata sandi diganti → semua sesi/token lama harus mati,
        // kalau tidak token yang sudah terbit tetap berlaku sampai kedaluwarsa. Bila yang
        // diubah akun admin sendiri, token yang sedang dipakai dipertahankan.
        if ($passwordChanged || (array_key_exists('is_active', $data) && !$data['is_active'])) {
            $this->revokeTokens($request, $user);
        }

        return response()->json(['message' => 'Pengguna berhasil diperbarui', 'data' => new AdminUserResource($user)]);
    }

    public function destroy(Request $request, $id)
    {
        $user = UserModel::findOrFail($id);

        if ($request->user('sanctum')->id === $user->id) {
            return response()->json(['message' => 'Tidak bisa menghapus akun sendiri.'], 422);
        }

        // personal_access_tokens bersifat polimorfik (tanpa FK cascade) — hapus manual
        // supaya tidak ada token yatim tersisa.
        $user->tokens()->delete();
        $user->delete();

        return response()->json(['message' => 'Pengguna berhasil dihapus']);
    }

    /**
     * Tren bulanan pendaftaran pengguna vs catatan mood, N bulan terakhir (maks. 24) —
     * dipakai grafik "Aktivitas Pengguna vs Catatan Mood" di halaman
     * Laporan & Analitik admin. Dua query total (satu per tabel, SUM(CASE) per bulan),
     * bukan 2×N query seperti sebelumnya, dan `months` dibatasi supaya tidak bisa dipakai
     * membebani server (?months=100000).
     */
    public function activityMonthly(Request $request)
    {
        $months = max(1, min(24, (int) ($request->query('months') ?? 5)));
        $bulanLabel = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

        $ranges = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $start = Carbon::now()->subMonths($i)->startOfMonth();
            $ranges[] = [$start, $start->copy()->endOfMonth()];
        }

        $bucket = function ($model, string $column) use ($ranges) {
            $select = [];
            $bindings = [];
            foreach ($ranges as $k => [$start, $end]) {
                $select[] = "SUM(CASE WHEN {$column} BETWEEN ? AND ? THEN 1 ELSE 0 END) as m{$k}";
                array_push($bindings, $start, $end);
            }

            return $model::query()
                ->where($column, '>=', $ranges[0][0])
                ->selectRaw(implode(', ', $select), $bindings)
                ->first();
        };

        $users = $bucket(UserModel::class, 'created_at');
        $moods = $bucket(MoodLogModel::class, 'logged_at');

        $result = [];
        foreach ($ranges as $k => [$start]) {
            $result[] = [
                'bulan' => $bulanLabel[$start->month - 1],
                'aktif' => (int) ($users->{"m{$k}"} ?? 0),
                'catatan_mood' => (int) ($moods->{"m{$k}"} ?? 0),
            ];
        }

        return response()->json(['data' => $result]);
    }

    /** Cabut token pengguna; token yang sedang dipakai request ini (kalau milik akun yang sama) dipertahankan. */
    private function revokeTokens(Request $request, UserModel $user): void
    {
        $current = $request->user('sanctum')?->currentAccessToken();
        $keep = ($request->user('sanctum')?->id === $user->id && $current instanceof PersonalAccessToken)
            ? $current->id
            : null;

        $user->tokens()->when($keep, fn ($q) => $q->where('id', '!=', $keep))->delete();
    }
}

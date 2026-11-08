<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AdminNotificationModel;
use App\Models\UmkmModel;
use App\Models\UserModel;
use App\Models\WasteCheckinModel;
use App\Models\WasteWeighingModel;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Sampah & Pangan. Alur: pengguna memindai QR lokasi (check-in) → petugas menimbang & mencatat →
 * hasil dikirim ke pengguna → pengguna mengonfirmasi (poin masuk) atau meminta koreksi.
 * Setoran lapak (UMKM) langsung terkonfirmasi. Rute `admin/waste*` khusus admin; `waste/*` untuk pengguna login.
 */
class WasteController extends Controller
{
    private const STATUS_LABEL = [
        'draft' => 'Draf', 'menunggu_konfirmasi' => 'Menunggu konfirmasi',
        'terkonfirmasi' => 'Terkonfirmasi', 'perlu_koreksi' => 'Perlu koreksi',
    ];

    // ---------------------------------------------------------------- admin

    public function meta()
    {
        $cfg = config('jejakwangi.sampah');

        return response()->json(['data' => [
            'stasiun' => collect($cfg['stasiun'])->map(fn ($nama, $kode) => ['kode' => $kode, 'nama' => $nama, 'qr_payload' => "jejakwangi:timbang:$kode"])->values(),
            'jenis' => collect($cfg['jenis'])->map(fn ($j, $k) => ['kode' => $k] + $j)->values(),
            'status' => collect(self::STATUS_LABEL)->map(fn ($l, $k) => ['value' => $k, 'label' => $l])->values(),
            'maks_baris' => $cfg['maks_baris'],
            'maks_kg_per_baris' => $cfg['maks_kg_per_baris'],
            'lapak' => UmkmModel::orderBy('business_name')->limit(100)->get(['id', 'business_name'])->map(fn ($u) => ['id' => $u->id, 'nama' => $u->business_name])->values(),
        ]]);
    }

    public function summary(Request $request)
    {
        $kind = $request->query('kind') === 'pangan' ? 'pangan' : 'sampah';
        $base = $this->filtered($request, $kind)->where('status', 'terkonfirmasi');

        $t = (clone $base)->selectRaw(
            'COALESCE(SUM(total_kg),0) as total, COALESCE(SUM(organik_kg),0) as org, COALESCE(SUM(anorganik_kg),0) as anorg, COALESCE(SUM(pangan_kg),0) as pangan,
             COALESCE(SUM(CASE WHEN processed_at IS NOT NULL THEN organik_kg ELSE 0 END),0) as org_done,
             COALESCE(SUM(CASE WHEN processed_at IS NOT NULL THEN anorganik_kg ELSE 0 END),0) as anorg_done,
             COALESCE(SUM(CASE WHEN processed_at IS NOT NULL THEN pangan_kg ELSE 0 END),0) as pangan_done,
             COALESCE(SUM(points),0) as poin, COUNT(*) as n'
        )->first();

        $perPenyetor = (clone $base)->selectRaw('depositor_type, depositor_name, SUM(total_kg) as kg')
            ->groupBy('depositor_type', 'depositor_name')->orderByDesc('kg')->get();
        $top = $perPenyetor->take(5)->map(fn ($r) => ['nama' => $r->depositor_name, 'tipe' => $r->depositor_type, 'kg' => round((float) $r->kg, 1)])->values();

        $org = round((float) $t->org, 1);
        $anorg = round((float) $t->anorg, 1);
        $pangan = round((float) $t->pangan, 1);
        $orgDone = round((float) $t->org_done, 1);
        $anorgDone = round((float) $t->anorg_done, 1);
        $panganDone = round((float) $t->pangan_done, 1);

        return response()->json(['data' => [
            'kind' => $kind,
            'jumlah_penimbangan' => (int) $t->n,
            'total_kg' => round((float) $t->total, 1),
            'organik_kg' => $org, 'organik_diolah_kg' => $orgDone, 'organik_menunggu_kg' => round($org - $orgDone, 1),
            'anorganik_kg' => $anorg, 'anorganik_didaur_kg' => $anorgDone, 'anorganik_menunggu_kg' => round($anorg - $anorgDone, 1),
            'pangan_kg' => $pangan, 'pangan_disalurkan_kg' => $panganDone, 'pangan_menunggu_kg' => round($pangan - $panganDone, 1),
            'menunggu_pengolahan_kg' => round(($org - $orgDone) + ($anorg - $anorgDone) + ($pangan - $panganDone), 1),
            'poin' => (int) $t->poin,
            'lapak_kg' => round((float) $perPenyetor->where('depositor_type', 'lapak')->sum('kg'), 1),
            'pengguna_kg' => round((float) $perPenyetor->where('depositor_type', 'pengguna')->sum('kg'), 1),
            'pengguna_lainnya_kg' => round((float) $perPenyetor->where('depositor_type', 'pengguna')->filter(fn ($r) => ! $top->contains(fn ($x) => $x['nama'] === $r->depositor_name && $x['tipe'] === 'pengguna'))->sum('kg'), 1),
            'kontributor' => $top,
        ]]);
    }

    public function index(Request $request)
    {
        $kind = $request->query('kind') === 'pangan' ? 'pangan' : 'sampah';
        $perPage = max(1, min(100, (int) $request->query('per_page', 6)));
        $q = $this->filtered($request, $kind)
            ->when($request->filled('status'), fn ($w) => $w->where('status', $request->query('status')))
            ->when($request->query('pending') === '1', fn ($w) => $w->where('status', 'terkonfirmasi')->whereNull('processed_at'))
            ->when($request->filled('q'), function ($w) use ($request) {
                $raw = mb_substr(trim((string) $request->query('q')), 0, 100);
                $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $raw).'%';
                $w->where(fn ($x) => $x->whereRaw("code LIKE ? ESCAPE '!'", [$term])->orWhereRaw("depositor_name LIKE ? ESCAPE '!'", [$term]));
            })
            ->orderByDesc('weighed_at')->orderByDesc('id');
        $page = $q->paginate($perPage);

        return response()->json([
            'data' => collect($page->items())->map(fn ($w) => $this->present($w))->values(),
            'page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(),
            'filtered_total' => $page->total(),
            'total' => WasteWeighingModel::where('kind', $kind)->count(),
        ]);
    }

    public function show(int $id)
    {
        return response()->json(['data' => $this->present(WasteWeighingModel::findOrFail($id), true)]);
    }

    /** Pengguna yang baru memindai QR lokasi (untuk memilih penyetor di form timbang). */
    public function checkins(Request $request)
    {
        $since = now()->subMinutes((int) config('jejakwangi.sampah.checkin_berlaku_menit'));
        $rows = WasteCheckinModel::where('scanned_at', '>=', $since)
            ->when($request->filled('station_code'), fn ($w) => $w->where('station_code', $request->query('station_code')))
            ->orderByDesc('scanned_at')->get();
        $names = UserModel::whereIn('id', $rows->pluck('user_id')->unique())->pluck('name', 'id');

        return response()->json(['data' => $rows->unique('user_id')->map(fn ($c) => [
            'user_id' => $c->user_id, 'nama' => $names[$c->user_id] ?? '—',
            'station_code' => $c->station_code, 'scanned_at' => $c->scanned_at->toIso8601String(),
        ])->values()]);
    }

    public function store(Request $request)
    {
        $parsed = $this->parse($request);
        if ($parsed instanceof \Illuminate\Http\JsonResponse) {
            return $parsed;
        }
        [$d, $calc, $penyetor] = $parsed;

        $kirim = ! $request->boolean('draft');
        $w = WasteWeighingModel::create($this->payload($d, $calc, $penyetor, $kirim) + [
            'weighed_by' => $request->user()->id, 'weighed_at' => now(),
        ]);
        $w->update(['code' => sprintf('TMB-%05d', $w->id)]);
        $this->afterSend($w);

        return response()->json(['message' => $this->sendMessage($w), 'data' => $this->present($w->fresh(), true)], 201);
    }

    /** Perbaiki draf / hasil yang diminta koreksi, lalu kirim ulang. */
    public function update(Request $request, int $id)
    {
        $w = WasteWeighingModel::findOrFail($id);
        if (! in_array($w->status, ['draft', 'perlu_koreksi'], true)) {
            return response()->json(['message' => 'Hanya draf atau hasil yang diminta koreksi yang dapat diubah.'], 422);
        }
        $parsed = $this->parse($request);
        if ($parsed instanceof \Illuminate\Http\JsonResponse) {
            return $parsed;
        }
        [$d, $calc, $penyetor] = $parsed;
        $w->update($this->payload($d, $calc, $penyetor, ! $request->boolean('draft')) + ['correction_note' => null]);
        $this->afterSend($w);

        return response()->json(['message' => $this->sendMessage($w), 'data' => $this->present($w->fresh(), true)]);
    }

    public function destroy(int $id)
    {
        $w = WasteWeighingModel::findOrFail($id);
        if ($w->status !== 'draft') {
            return response()->json(['message' => 'Hanya draf yang dapat dihapus.'], 422);
        }
        $w->delete();

        return response()->json(['message' => 'Draf dihapus.']);
    }

    /** "Perbarui pengolahan": tandai setoran terkonfirmasi sudah dikompos/didaur ulang/disalurkan. */
    public function process(Request $request, int $id)
    {
        $w = WasteWeighingModel::findOrFail($id);
        if ($w->status !== 'terkonfirmasi') {
            return response()->json(['message' => 'Hanya setoran terkonfirmasi yang dapat ditandai selesai diolah.'], 422);
        }
        $w->update(['processed_at' => $request->boolean('undo') ? null : now()]);

        return response()->json(['data' => $this->present($w->fresh())]);
    }

    // ---------------------------------------------------------------- pengguna

    /** Pindai QR lokasi: `jejakwangi:timbang:BSS-001` atau kode stasiun langsung. */
    public function checkin(Request $request)
    {
        $v = Validator::make($request->all(), ['station_code' => ['required', 'string', 'max:60']]);
        if ($v->fails()) {
            return response()->json(['message' => 'QR lokasi tidak valid.', 'errors' => $v->errors()], 422);
        }
        $code = strtoupper(trim(preg_replace('/^jejakwangi:timbang:/i', '', $v->validated()['station_code'])));
        $stations = config('jejakwangi.sampah.stasiun');
        if (! isset($stations[$code])) {
            return response()->json(['message' => 'QR lokasi tidak dikenali.'], 404);
        }
        $c = WasteCheckinModel::create(['user_id' => $request->user()->id, 'station_code' => $code, 'scanned_at' => now()]);

        return response()->json(['message' => "Terhubung ke {$stations[$code]}. Petugas dapat mencatat setoranmu.", 'data' => [
            'station_code' => $code, 'station_name' => $stations[$code], 'scanned_at' => $c->scanned_at->toIso8601String(),
        ]], 201);
    }

    public function mine(Request $request)
    {
        $rows = WasteWeighingModel::where('user_id', $request->user()->id)->where('status', '!=', 'draft')
            ->orderByDesc('weighed_at')->orderByDesc('id')->limit(50)->get();

        return response()->json(['data' => $rows->map(fn ($w) => $this->present($w, true))->values(),
            'menunggu' => $rows->where('status', 'menunggu_konfirmasi')->count()]);
    }

    public function confirm(Request $request, int $id)
    {
        $user = $request->user();
        $result = DB::transaction(function () use ($user, $id) {
            $w = WasteWeighingModel::where('id', $id)->where('user_id', $user->id)->lockForUpdate()->first();
            if (! $w) {
                return 404;
            }
            // Pembaruan bersyarat: hanya satu permintaan yang menang → poin tidak bisa dobel.
            $won = WasteWeighingModel::where('id', $w->id)->where('status', 'menunggu_konfirmasi')
                ->update(['status' => 'terkonfirmasi', 'confirmed_at' => now()]);
            if (! $won) {
                return 422;
            }
            UserModel::where('id', $user->id)->increment('bonus_points', $w->points);

            return $w->id;
        });
        if ($result === 404) {
            return response()->json(['message' => 'Setoran tidak ditemukan.'], 404);
        }
        if ($result === 422) {
            return response()->json(['message' => 'Setoran ini tidak menunggu konfirmasi.'], 422);
        }
        $w = WasteWeighingModel::find($result);

        return response()->json(['message' => "Terkonfirmasi. +{$w->points} poin masuk ke akunmu.", 'data' => $this->present($w, true)]);
    }

    public function correction(Request $request, int $id)
    {
        $w = WasteWeighingModel::where('user_id', $request->user()->id)->findOrFail($id);
        $v = Validator::make($request->all(), ['note' => ['required', 'string', 'max:500']], [], ['note' => 'catatan koreksi']);
        if ($v->fails()) {
            return response()->json(['message' => 'Jelaskan bagian yang perlu dikoreksi.', 'errors' => $v->errors()], 422);
        }
        if ($w->status !== 'menunggu_konfirmasi') {
            return response()->json(['message' => 'Setoran ini tidak menunggu konfirmasi.'], 422);
        }
        $w->update(['status' => 'perlu_koreksi', 'correction_note' => $v->validated()['note']]);
        AdminNotificationModel::notify('pengguna', "Koreksi timbangan diminta: {$w->code}", "{$w->depositor_name} • {$v->validated()['note']}");

        return response()->json(['message' => 'Permintaan koreksi dikirim ke petugas.', 'data' => $this->present($w->fresh(), true)]);
    }

    // ---------------------------------------------------------------- util

    private function filtered(Request $request, string $kind)
    {
        $tz = config('jejakwangi.timezone');
        $from = $request->filled('from') ? Carbon::parse($request->query('from'), $tz)->startOfDay() : Carbon::now($tz)->startOfMonth();
        $to = $request->filled('to') ? Carbon::parse($request->query('to'), $tz)->endOfDay() : Carbon::now($tz)->endOfDay();

        return WasteWeighingModel::query()->where('kind', $kind)
            ->whereBetween('weighed_at', [$from->copy()->utc(), $to->copy()->utc()])
            ->when($request->filled('station_code'), fn ($w) => $w->where('station_code', $request->query('station_code')));
    }

    /** @return array{0: array, 1: array, 2: array}|\Illuminate\Http\JsonResponse */
    private function parse(Request $request)
    {
        $cfg = config('jejakwangi.sampah');
        $v = Validator::make($request->all(), [
            'kind' => ['nullable', Rule::in(['sampah', 'pangan'])],
            'station_code' => ['required', Rule::in(array_keys($cfg['stasiun']))],
            'depositor_type' => ['required', Rule::in(['pengguna', 'lapak'])],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'umkm_id' => ['nullable', 'integer', 'exists:umkm_models,id'],
            'items' => ['required', 'array', 'min:1', 'max:'.$cfg['maks_baris']],
            'items.*.jenis' => ['required', Rule::in(array_keys($cfg['jenis']))],
            'items.*.kg' => ['required', 'numeric', 'gt:0', 'max:'.$cfg['maks_kg_per_baris']],
            'note' => ['nullable', 'string', 'max:500'],
        ], [], ['station_code' => 'tempat timbang', 'depositor_type' => 'penyetor', 'items' => 'jenis & berat', 'items.*.kg' => 'berat bersih']);
        if ($v->fails()) {
            return response()->json(['message' => 'Data penimbangan belum lengkap.', 'errors' => $v->errors()], 422);
        }
        $d = $v->validated();

        if ($d['depositor_type'] === 'pengguna' && empty($d['user_id'])) {
            return response()->json(['message' => 'Pilih pengguna penyetor.', 'errors' => ['user_id' => ['Pilih pengguna penyetor.']]], 422);
        }
        if ($d['depositor_type'] === 'lapak' && empty($d['umkm_id'])) {
            return response()->json(['message' => 'Pilih lapak penyetor.', 'errors' => ['umkm_id' => ['Pilih lapak penyetor.']]], 422);
        }

        $grupSet = collect($d['items'])->map(fn ($i) => $cfg['jenis'][$i['jenis']]['grup'] === 'pangan')->unique();
        if ($grupSet->count() > 1) {
            return response()->json(['message' => 'Sampah dan pangan dicatat pada penimbangan terpisah.', 'errors' => ['items' => ['Jangan campur jenis sampah dan pangan dalam satu penimbangan.']]], 422);
        }

        $items = []; $sum = ['organik' => 0.0, 'anorganik' => 0.0, 'pangan' => 0.0]; $poin = 0;
        foreach ($d['items'] as $i) {
            $j = $cfg['jenis'][$i['jenis']];
            $kg = round((float) $i['kg'], 2);
            $p = (int) round($kg * $j['poin_per_kg']);
            $items[] = ['jenis' => $i['jenis'], 'label' => $j['label'], 'grup' => $j['grup'], 'kg' => $kg, 'poin_per_kg' => $j['poin_per_kg'], 'poin' => $p];
            $sum[$j['grup']] += $kg;
            $poin += $p;
        }
        $penyetor = $d['depositor_type'] === 'lapak'
            ? ['nama' => UmkmModel::find($d['umkm_id'])?->business_name ?? 'Lapak', 'user_id' => null]
            : ['nama' => UserModel::find($d['user_id'])?->name ?? 'Pengguna', 'user_id' => $d['user_id']];

        $d['kind'] = $grupSet->first() ? 'pangan' : 'sampah';

        return [$d, ['items' => $items, 'sum' => $sum, 'poin' => $poin], $penyetor];
    }

    private function payload(array $d, array $calc, array $penyetor, bool $kirim): array
    {
        $isLapak = $d['depositor_type'] === 'lapak';

        return [
            'kind' => $d['kind'],
            'station_code' => $d['station_code'],
            'station_name' => config('jejakwangi.sampah.stasiun')[$d['station_code']],
            'depositor_type' => $d['depositor_type'],
            'depositor_name' => $penyetor['nama'],
            'user_id' => $penyetor['user_id'],
            'umkm_id' => $isLapak ? $d['umkm_id'] : null,
            'items' => $calc['items'],
            'total_kg' => round(array_sum($calc['sum']), 2),
            'organik_kg' => $calc['sum']['organik'],
            'anorganik_kg' => $calc['sum']['anorganik'],
            'pangan_kg' => $calc['sum']['pangan'],
            'points' => $calc['poin'],
            'note' => $d['note'] ?? null,
            // Lapak tidak punya akun aplikasi untuk mengonfirmasi → langsung terkonfirmasi.
            'status' => ! $kirim ? 'draft' : ($isLapak ? 'terkonfirmasi' : 'menunggu_konfirmasi'),
            'confirmed_at' => $kirim && $isLapak ? now() : null,
        ];
    }

    private function afterSend(WasteWeighingModel $w): void
    {
        if ($w->status === 'terkonfirmasi' && $w->depositor_type === 'lapak') {
            AdminNotificationModel::notify('lingkungan', "Setoran lapak tercatat: {$w->code}", "{$w->depositor_name} • {$w->total_kg} kg di {$w->station_name}");
        }
    }

    private function sendMessage(WasteWeighingModel $w): string
    {
        return match ($w->status) {
            'draft' => 'Draf tersimpan.',
            'terkonfirmasi' => 'Setoran lapak tercatat.',
            default => "Hasil dikirim ke {$w->depositor_name}. Poin masuk setelah pengguna mengonfirmasi.",
        };
    }

    private function present(WasteWeighingModel $w, bool $detail = false): array
    {
        $row = [
            'id' => $w->id, 'code' => $w->code, 'kind' => $w->kind,
            'depositor_name' => $w->depositor_name, 'depositor_type' => $w->depositor_type,
            'station_code' => $w->station_code, 'station_name' => $w->station_name,
            'total_kg' => $w->total_kg, 'points' => $w->points,
            'status' => $w->status, 'status_label' => self::STATUS_LABEL[$w->status] ?? $w->status,
            'processed' => $w->processed_at !== null,
            'weighed_at' => $w->weighed_at?->toIso8601String(),
        ];
        if ($detail) {
            $row += [
                'user_id' => $w->user_id, 'umkm_id' => $w->umkm_id, 'items' => $w->items,
                'organik_kg' => $w->organik_kg, 'anorganik_kg' => $w->anorganik_kg, 'pangan_kg' => $w->pangan_kg,
                'note' => $w->note, 'correction_note' => $w->correction_note,
                'confirmed_at' => $w->confirmed_at?->toIso8601String(), 'processed_at' => $w->processed_at?->toIso8601String(),
            ];
        }

        return $row;
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AdminNotificationModel;
use App\Models\CorridorReportModel;
use App\Models\KoridorModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Lapor Koridor: warga/admin mencatat temuan → admin verifikasi → DLH menindaklanjuti → pelapor menerima hasil.
 * Admin: index/show/update. Login biasa: store, mine, respond (hanya laporan miliknya).
 */
class CorridorReportController extends Controller
{
    public const CATEGORIES = [
        'sampah' => 'Sampah & kebersihan',
        'udara' => 'Kualitas udara & asap',
        'drainase' => 'Drainase & banjir',
        'fasilitas' => 'Fasilitas rusak',
        'lainnya' => 'Lainnya',
    ];
    public const STATUSES = ['menunggu' => 'Menunggu verifikasi', 'diproses' => 'Diproses DLH', 'selesai' => 'Selesai', 'ditolak' => 'Ditolak'];
    public const ASSIGNEES = ['Admin DLH', 'Tim Kebersihan', 'Tim Drainase', 'Tim Taman'];

    public function meta()
    {
        return response()->json(['data' => [
            'kategori' => collect(self::CATEGORIES)->map(fn ($l, $k) => ['value' => $k, 'label' => $l])->values(),
            'status' => collect(self::STATUSES)->map(fn ($l, $k) => ['value' => $k, 'label' => $l])->values(),
            'penanggung_jawab' => self::ASSIGNEES,
            'koridor' => KoridorModel::orderBy('sort_order')->orderBy('id')->get(['id', 'short_name'])
                ->map(fn ($k) => ['id' => $k->id, 'nama' => $k->short_name])->values(),
        ]]);
    }

    public function index(Request $request)
    {
        $perPage = max(1, min(100, (int) $request->query('per_page', 6)));
        $q = CorridorReportModel::query()
            ->when($request->filled('status'), fn ($w) => $w->where('status', $request->query('status')))
            ->when($request->filled('koridor_id'), fn ($w) => $w->where('koridor_id', (int) $request->query('koridor_id')))
            ->when($request->filled('q'), function ($w) use ($request) {
                $raw = mb_substr(trim((string) $request->query('q')), 0, 100);
                $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $raw).'%';
                $w->where(fn ($x) => $x->whereRaw("code LIKE ? ESCAPE '!'", [$term])->orWhereRaw("title LIKE ? ESCAPE '!'", [$term]));
            })
            ->orderByDesc('created_at')->orderByDesc('id');

        $page = $q->paginate($perPage);
        $names = $this->koridorNames();
        $counts = CorridorReportModel::query()->selectRaw('status, COUNT(*) as n')->groupBy('status')->pluck('n', 'status');

        return response()->json([
            'data' => collect($page->items())->map(fn ($r) => $this->present($r, $names))->values(),
            'page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'per_page' => $page->perPage(),
            'filtered_total' => $page->total(),
            'total' => (int) $counts->sum(),
            'menunggu' => (int) ($counts['menunggu'] ?? 0),
            'diproses' => (int) ($counts['diproses'] ?? 0),
            'selesai' => (int) ($counts['selesai'] ?? 0),
            'ditolak' => (int) ($counts['ditolak'] ?? 0),
        ]);
    }

    public function show(int $id)
    {
        return response()->json(['data' => $this->present(CorridorReportModel::findOrFail($id), $this->koridorNames(), true)]);
    }

    /** Admin mencatat laporan, atau warga yang login mengirim laporan sendiri. */
    public function store(Request $request)
    {
        $v = Validator::make($request->all(), [
            'koridor_id' => ['required', 'integer', 'exists:koridors,id'],
            'location' => ['required', 'string', 'max:200'],
            'category' => ['required', Rule::in(array_keys(self::CATEGORIES))],
            'title' => ['required', 'string', 'max:120'],
            'description' => ['required', 'string', 'max:2000'],
            'evidence' => ['nullable', 'array', 'max:3'],
            'evidence.*' => ['file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
        ], [], ['location' => 'lokasi', 'category' => 'kategori', 'title' => 'judul', 'description' => 'deskripsi', 'koridor_id' => 'koridor']);
        if ($v->fails()) {
            return response()->json(['message' => 'Data laporan belum lengkap.', 'errors' => $v->errors()], 422);
        }
        $d = $v->validated();
        $user = $request->user();

        $report = CorridorReportModel::create([
            'koridor_id' => $d['koridor_id'],
            'location' => $d['location'],
            'category' => $d['category'],
            'title' => $d['title'],
            'description' => $d['description'],
            'status' => 'menunggu',
            'reporter_id' => $user->id,
            'reporter_name' => $user->name,
            'evidence' => $this->saveFiles($request->file('evidence')),
            'history' => [],
        ]);
        $report->update([
            'code' => sprintf('KRD-%04d', $report->id),
            'history' => [$this->event('Laporan diterima', "Warga menerima ID laporan KRD-".sprintf('%04d', $report->id).'.')],
        ]);

        AdminNotificationModel::notify('lingkungan', "Laporan koridor baru: {$report->title}", "{$report->code} • {$report->location}");

        return response()->json(['message' => 'Laporan terkirim.', 'data' => $this->present($report->fresh(), $this->koridorNames(), true)], 201);
    }

    /** Admin: verifikasi, penanggung jawab, ubah status + catatan untuk pelapor. */
    public function update(Request $request, int $id)
    {
        $report = CorridorReportModel::findOrFail($id);
        $v = Validator::make($request->all(), [
            'status' => ['required', Rule::in(array_keys(self::STATUSES))],
            'assignee' => ['nullable', Rule::in(self::ASSIGNEES)],
            'note' => ['required', 'string', 'max:1000'],
            'action_evidence' => ['nullable', 'array', 'max:3'],
            'action_evidence.*' => ['file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
        ], [], ['note' => 'catatan untuk pelapor', 'status' => 'status']);
        if ($v->fails()) {
            return response()->json(['message' => 'Tindak lanjut belum lengkap.', 'errors' => $v->errors()], 422);
        }
        $d = $v->validated();

        $newFiles = $this->saveFiles($request->file('action_evidence'));
        $evidence = array_merge($report->action_evidence ?? [], $newFiles);
        if ($d['status'] === 'selesai' && count($evidence) === 0) {
            return response()->json(['message' => 'Penyelesaian wajib memiliki bukti tindakan.', 'errors' => ['action_evidence' => ['Bukti tindakan wajib dilampirkan jika status Selesai.']]], 422);
        }

        $label = self::STATUSES[$d['status']];
        $history = $report->history ?? [];
        if ($report->status === 'menunggu' && $d['status'] !== 'menunggu') {
            $history[] = $this->event('Diverifikasi admin', 'Lokasi dan kategori sesuai; diteruskan ke DLH.');
        }
        $history[] = $this->event($label, $d['note'].($d['assignee'] ?? null ? " (Penanggung jawab: {$d['assignee']})" : ''));

        $report->update([
            'status' => $d['status'],
            'assignee' => $d['assignee'] ?? $report->assignee,
            'admin_note' => $d['note'],
            'action_evidence' => $evidence,
            'history' => $history,
        ]);

        return response()->json(['message' => 'Tindak lanjut disimpan; pelapor akan mendapat kabar.', 'data' => $this->present($report->fresh(), $this->koridorNames(), true)]);
    }

    public function mine(Request $request)
    {
        $names = $this->koridorNames();
        $rows = CorridorReportModel::where('reporter_id', $request->user()->id)->orderByDesc('id')->limit(50)->get();

        return response()->json(['data' => $rows->map(fn ($r) => $this->present($r, $names, true))->values()]);
    }

    /** Pelapor menanggapi hasil: konfirmasi selesai, atau minta tinjauan ulang. */
    public function respond(Request $request, int $id)
    {
        $report = CorridorReportModel::where('reporter_id', $request->user()->id)->findOrFail($id);
        $v = Validator::make($request->all(), [
            'action' => ['required', Rule::in(['konfirmasi', 'tinjau_ulang'])],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        if ($v->fails()) {
            return response()->json(['message' => 'Tanggapan tidak valid.', 'errors' => $v->errors()], 422);
        }
        if (! in_array($report->status, ['selesai', 'ditolak'], true)) {
            return response()->json(['message' => 'Laporan belum selesai ditangani.'], 422);
        }
        $d = $v->validated();
        $history = $report->history ?? [];
        if ($d['action'] === 'konfirmasi') {
            $history[] = $this->event('Dikonfirmasi pelapor', $d['note'] ?? 'Pelapor mengonfirmasi hasil penanganan.');
            $report->update(['history' => $history]);
        } else {
            $history[] = $this->event('Peninjauan ulang diminta', $d['note'] ?? 'Pelapor meminta peninjauan ulang.');
            $report->update(['status' => 'menunggu', 'history' => $history]);
            AdminNotificationModel::notify('lingkungan', "Peninjauan ulang: {$report->title}", $report->code);
        }

        return response()->json(['data' => $this->present($report->fresh(), $this->koridorNames(), true)]);
    }

    // ---------------------------------------------------------------------------------------------

    private function koridorNames(): array
    {
        return KoridorModel::pluck('short_name', 'id')->all();
    }

    private function event(string $title, string $desc): array
    {
        return ['title' => $title, 'desc' => $desc, 'at' => now()->toIso8601String()];
    }

    /** @param  array<int, \Illuminate\Http\UploadedFile>|null  $files */
    private function saveFiles($files): array
    {
        $out = [];
        foreach ((array) $files as $f) {
            if (! $f) {
                continue;
            }
            $out[] = ['name' => $f->getClientOriginalName(), 'size' => $f->getSize(), 'path' => $f->store('reports', 'public')];
        }

        return $out;
    }

    private function files(?array $list): array
    {
        return collect($list ?? [])->map(fn ($f) => [
            'name' => $f['name'] ?? 'berkas',
            'size' => (int) ($f['size'] ?? 0),
            'url' => Storage::disk('public')->url($f['path'] ?? ''),
        ])->values()->all();
    }

    private function present(CorridorReportModel $r, array $names, bool $detail = false): array
    {
        $row = [
            'id' => $r->id,
            'code' => $r->code,
            'title' => $r->title,
            'koridor_id' => $r->koridor_id,
            'koridor' => $names[$r->koridor_id] ?? null,
            'category' => $r->category,
            'category_label' => self::CATEGORIES[$r->category] ?? $r->category,
            'status' => $r->status,
            'status_label' => self::STATUSES[$r->status] ?? $r->status,
            'assignee' => $r->assignee,
            'created_at' => $r->created_at?->toIso8601String(),
            'updated_at' => $r->updated_at?->toIso8601String(),
        ];
        if ($detail) {
            $row += [
                'location' => $r->location,
                'description' => $r->description,
                'reporter_name' => $r->reporter_name,
                'admin_note' => $r->admin_note,
                'evidence' => $this->files($r->evidence),
                'action_evidence' => $this->files($r->action_evidence),
                'history' => $r->history ?? [],
            ];
        }

        return $row;
    }
}

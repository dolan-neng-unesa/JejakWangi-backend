<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\SensorReadingResource;
use App\Models\KoridorModel;
use App\Models\SensorReadingModel;
use App\Services\OpenMeteoAir;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Alur sensor:
 *
 *   ESP32 --POST /api/sensors/readings (X-Device-Key)--> store()
 *   FE    --GET  /api/sensors/snapshot?koridor_id=1----> snapshot()   (semua jenis, 1 request)
 *   Admin --GET  /api/sensors/snapshots----------------> snapshots()  (semua koridor, 1 request)
 *   FE    --GET  /api/sensors/active-------------------> active()     (wilayah yang sedang ON)
 *   Admin --GET  /api/sensors/history?sensor_type=...--> history()
 *
 * Payload ESP32 — datar (koridor milik perangkat) atau multi-wilayah:
 *   {"temperature":30.2,"pm10":41,"co":2300,"ts":1790000000}
 *   {"wilayah":[{"no":1,"temperature":30.2,"pm25":54},{"no":2,...}]}   // koridor_id = no
 * Hanya SATU wilayah yang bisa ON di perangkat. ESP32 menyertakan `aktif` (no wilayah, 0 = semua OFF)
 * dan hanya mengirim data wilayah aktif:
 *   {"aktif":2,"wilayah":[{"no":2,"temperature":33.5,"pm25":72,"led":true}]}
 * `ts` (epoch detik) opsional; tanpa NTP cukup dihilangkan, server memakai jam sendiri.
 */
class SensorReadingController extends Controller
{
    private const SNAPSHOT_TTL_SECONDS = 15;
    private const ACTIVE_CACHE_KEY = 'sensor:active_koridor';
    private const ALL_SNAPSHOTS_CACHE_KEY = 'sensor:snapshots';
    /** Id koridor dari query string dipakai sebagai bagian kunci cache — batasi bentuknya. */
    private const KORIDOR_ID_RULE = ['nullable', 'string', 'max:50', 'regex:/^[A-Za-z0-9_-]+$/'];

    public function store(Request $request)
    {
        $device = $request->attributes->get('device');

        $rules = [
            'ts' => ['nullable', 'integer'],
            'aktif' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'wilayah' => ['nullable', 'array', 'max:20'],
            'wilayah.*.no' => ['required_with:wilayah', 'integer', 'min:1'],
            'wilayah.*.led' => ['nullable', 'boolean'],
        ];
        foreach (SensorReadingModel::TYPES as $type) {
            $rules[$type] = ['nullable', 'numeric', 'between:-100000,10000000'];
            $rules["wilayah.*.$type"] = ['nullable', 'numeric', 'between:-100000,10000000'];
        }

        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            return response()->json(['message' => 'Data sensor tidak valid', 'errors' => $validator->errors()], 422);
        }

        // Dua bentuk payload:
        //  - datar            {"temperature":30.2,"pm10":41}      -> koridor milik perangkat
        //  - multi-wilayah    {"wilayah":[{"no":1,"temperature":..},{"no":2,..}]}  -> koridor_id = no
        $groups = [];
        $data = $validator->validated();
        foreach ($data['wilayah'] ?? [] as $w) {
            $groups[(string) $w['no']] = $this->onlySensorValues($w);
        }
        if (!$groups && ($flat = $this->onlySensorValues($data))) {
            $groups[(string) $device->koridor_id] = $flat;
        }
        $groups = array_filter($groups);

        // Wilayah aktif (LED ON) dari perangkat. 0 / tanpa wilayah = semua OFF.
        $reportsActive = array_key_exists('aktif', $data) && $data['aktif'] !== null;
        if ($reportsActive) {
            $aktif = (int) $data['aktif'];
            Cache::forever(self::ACTIVE_CACHE_KEY, [
                'koridor_id' => $aktif > 0 ? (string) $aktif : null,
                'at' => time(),
            ]);
        }

        if (!$groups && $reportsActive) {
            // Semua wilayah OFF: tidak ada bacaan untuk disimpan, tapi status aktif sudah tercatat.
            $device->forceFill(['last_seen_at' => now()])->save();

            return response()->json(['ok' => true, 'n' => 0, 't' => time()], 201);
        }
        if (!$groups) {
            return response()->json(['message' => 'Tidak ada nilai sensor yang dikenali.', 'allowed' => SensorReadingModel::TYPES], 422);
        }

        $recordedAt = $this->resolveTimestamp($request->input('ts'));
        $rows = [];
        foreach ($groups as $koridorId => $values) {
            foreach ($values as $type => $value) {
                $rows[] = [
                    'device_code' => $device->device_code,
                    'koridor_id' => $koridorId === '' ? null : $koridorId,
                    'sensor_type' => $type,
                    'value' => $value,
                    'recorded_at' => $recordedAt,
                ];
            }
        }

        SensorReadingModel::insert($rows); // satu query untuk semua wilayah & jenis sensor
        $device->forceFill(['last_seen_at' => now()])->save();
        foreach (array_keys($groups) as $koridorId) {
            Cache::forget($this->snapshotCacheKey($koridorId === '' ? null : (string) $koridorId));
        }
        // Snapshot gabungan ("semua koridor" & versi tanpa filter) ikut basi saat ada data baru.
        Cache::forget($this->snapshotCacheKey(null));
        Cache::forget(self::ALL_SNAPSHOTS_CACHE_KEY);

        // Balasan sengaja sekecil mungkin (hemat RAM/bandwidth ESP32).
        // `t` = jam server (epoch) supaya ESP bisa menyetel jam tanpa NTP.
        return response()->json(['ok' => true, 'n' => count($rows), 't' => time()], 201);
    }

    /**
     * Wilayah (koridor) yang sedang ON di perangkat — dipakai kartu ISPU di home.
     * `koridor_id` null = semua OFF, atau perangkat tidak melapor > 5 menit.
     */
    public function active()
    {
        $state = Cache::get(self::ACTIVE_CACHE_KEY);
        $reportedAt = $state ? Carbon::createFromTimestamp($state['at']) : null;
        $isStale = !$reportedAt || $reportedAt->lte(Carbon::now()->subMinutes(SensorReadingModel::FRESHNESS_WINDOW_MINUTES));
        $koridorId = $isStale ? null : $state['koridor_id'];
        $sumber = 'sensor';

        // Open-Meteo: tanpa wilayah ESP32 yang aktif (atau mode "openmeteo"), kartu ISPU memakai koridor bawaan.
        if (OpenMeteoAir::aktif() && ($koridorId === null || OpenMeteoAir::mode() === 'openmeteo')) {
            if (($bawaan = OpenMeteoAir::koridorBawaan()) !== null) {
                $koridorId = (string) $bawaan;
                $isStale = false;
                $sumber = OpenMeteoAir::SUMBER;
            }
        }

        return response()->json([
            'koridor_id' => $koridorId,
            'updated_at' => $reportedAt?->toIso8601String(),
            'is_stale' => $isStale,
            'source' => $sumber,
        ]);
    }

    /**
     * Bacaan terakhir SEMUA jenis sensor satu koridor dalam satu request.
     * Tiap bacaan membawa `is_stale` (umur > 5 menit) — data lama tetap
     * dikembalikan sebagai "last known" dari DB, jadi FE tidak perlu dua endpoint.
     */
    public function snapshot(Request $request)
    {
        $validator = Validator::make($request->query(), ['koridor_id' => self::KORIDOR_ID_RULE]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Parameter tidak valid', 'errors' => $validator->errors()], 422);
        }

        $koridorId = $request->query('koridor_id');
        $koridorId = is_scalar($koridorId) && $koridorId !== '' ? (string) $koridorId : null;

        $payload = Cache::remember(
            $this->snapshotCacheKey($koridorId),
            self::SNAPSHOT_TTL_SECONDS,
            fn () => $this->buildSnapshot($koridorId),
        );

        return response()->json($payload);
    }

    /**
     * Snapshot SEMUA koridor sekaligus: { snapshots: { "1": {koridor_id, updated_at, is_stale, readings}, ... } }.
     * Dashboard admin memuat kondisi tiap koridor; sebelumnya itu N request /snapshot paralel
     * di setiap polling, sekarang cukup satu request + satu query. Koridor yang belum punya
     * bacaan sama sekali tidak muncul di `snapshots` (FE menganggapnya kosong).
     */
    public function snapshots()
    {
        $payload = Cache::remember(
            self::ALL_SNAPSHOTS_CACHE_KEY,
            self::SNAPSHOT_TTL_SECONDS,
            fn () => $this->buildAllSnapshots(),
        );

        return response()->json($payload);
    }

    private function buildSnapshot(?string $koridorId): array
    {
        // Satu query: baris terbaru per sensor_type (join ke MAX(recorded_at)).
        $latest = SensorReadingModel::query()
            ->from('sensor_readings as r')
            ->joinSub(
                SensorReadingModel::query()
                    ->select('sensor_type', DB::raw('MAX(recorded_at) as max_at'))
                    ->when($koridorId, fn ($q) => $q->where('koridor_id', $koridorId))
                    ->groupBy('sensor_type'),
                'm',
                fn ($j) => $j->on('r.sensor_type', '=', 'm.sensor_type')->on('r.recorded_at', '=', 'm.max_at'),
            )
            ->when($koridorId, fn ($q) => $q->where('r.koridor_id', $koridorId))
            ->select('r.*')
            ->orderByDesc('r.id')
            ->get()
            ->unique('sensor_type');

        $koridor = ($koridorId !== null && ctype_digit($koridorId)) ? KoridorModel::find((int) $koridorId) : null;

        return $this->formatSnapshot($koridorId, $latest, $this->freshnessThreshold(), $koridor);
    }

    private function buildAllSnapshots(): array
    {
        // Satu query untuk semua koridor: baris terbaru per (koridor_id, sensor_type).
        $latest = SensorReadingModel::query()
            ->from('sensor_readings as r')
            ->joinSub(
                SensorReadingModel::query()
                    ->select('koridor_id', 'sensor_type', DB::raw('MAX(recorded_at) as max_at'))
                    ->whereNotNull('koridor_id')
                    ->groupBy('koridor_id', 'sensor_type'),
                'm',
                fn ($j) => $j->on('r.koridor_id', '=', 'm.koridor_id')
                    ->on('r.sensor_type', '=', 'm.sensor_type')
                    ->on('r.recorded_at', '=', 'm.max_at'),
            )
            ->select('r.*')
            ->orderByDesc('r.id')
            ->get()
            ->unique(fn ($r) => $r->koridor_id . '|' . $r->sensor_type);

        $threshold = $this->freshnessThreshold();
        $perKoridor = $latest->groupBy('koridor_id');
        // Open-Meteo aktif: koridor yang belum punya bacaan sensor pun ikut (dari Open-Meteo).
        $koridors = OpenMeteoAir::aktif() ? KoridorModel::orderBy('sort_order')->orderBy('id')->get()->keyBy(fn ($k) => (string) $k->id) : collect();

        $snapshots = [];
        $ids = $perKoridor->keys()->map(fn ($k) => (string) $k)->merge($koridors->keys())->unique();
        foreach ($ids as $koridorId) {
            $rows = $perKoridor->get($koridorId, collect());
            $snapshots[$koridorId] = $this->formatSnapshot($koridorId, $rows, $threshold, $koridors->get($koridorId));
        }

        return ['snapshots' => (object) $snapshots];
    }

    private function freshnessThreshold(): Carbon
    {
        return Carbon::now()->subMinutes(SensorReadingModel::FRESHNESS_WINDOW_MINUTES);
    }

    /**
     * @param \Illuminate\Support\Collection<int, SensorReadingModel> $latest satu baris terbaru per sensor_type
     * @param KoridorModel|null $koridor dipakai untuk melengkapi dengan Open-Meteo bila aktif
     */
    private function formatSnapshot(?string $koridorId, $latest, Carbon $threshold, ?KoridorModel $koridor = null): array
    {
        $readings = [];
        $segar = [];
        foreach ($latest as $row) {
            $stale = $row->recorded_at->lte($threshold);
            $readings[$row->sensor_type] = [
                ...(new SensorReadingResource($row))->resolve(),
                'is_stale' => $stale,
            ];
            if (! $stale) {
                $segar[$row->sensor_type] = (float) $row->value;
            }
        }

        $newest = $latest->max('recorded_at');
        $stale = !$newest || $newest->lte($threshold);

        if ($koridor && ($om = OpenMeteoAir::bacaan($koridor))) {
            $isi = OpenMeteoAir::isi($segar, $om['values']);
            foreach ($isi['dariOm'] as $tipe) {
                $readings[$tipe] = [
                    'sensor_type' => $tipe,
                    'value' => $om['values'][$tipe],
                    'unit' => SensorReadingModel::unitFor($tipe),
                    'recorded_at' => $om['at']->toIso8601String(),
                    'is_stale' => false,
                    'source' => OpenMeteoAir::SUMBER,
                ];
            }
            if ($isi['dariOm'] !== []) {
                $newest = $newest && $newest->gt($om['at']) ? $newest : $om['at'];
                $stale = false;
            }
        }

        return [
            'koridor_id' => $koridorId,
            'updated_at' => $newest?->toIso8601String(),
            'is_stale' => $stale,
            'readings' => (object) $readings,
        ];
    }

    /** Tren harian (rata-rata per tanggal) — dihitung di database, bukan di PHP. */
    public function history(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'sensor_type' => ['required', 'string', 'in:' . implode(',', SensorReadingModel::TYPES)],
            'koridor_id' => ['nullable', 'string', 'max:50'],
            'days' => ['nullable', 'integer', 'min:1', 'max:90'],
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Parameter tidak valid', 'errors' => $validator->errors()], 422);
        }

        $days = (int) ($request->query('days') ?? 7);
        $since = Carbon::now()->subDays($days - 1)->startOfDay();

        $byDate = SensorReadingModel::query()
            ->where('sensor_type', $request->query('sensor_type'))
            ->when($request->query('koridor_id'), fn ($q, $id) => $q->where('koridor_id', $id))
            ->where('recorded_at', '>=', $since)
            ->selectRaw('DATE(recorded_at) as day, AVG(value) as avg_value, COUNT(*) as n')
            ->groupBy('day')
            ->get()
            ->keyBy('day');

        $daily = [];
        for ($i = 0; $i < $days; $i++) {
            $date = $since->copy()->addDays($i)->toDateString();
            $row = $byDate->get($date);
            $daily[] = [
                'date' => $date,
                'average_value' => $row ? round((float) $row->avg_value, 1) : null,
                'count' => $row ? (int) $row->n : 0,
            ];
        }

        return response()->json(['sensor_type' => $request->query('sensor_type'), 'days' => $days, 'daily' => $daily]);
    }

    /** @return array<string, float|int|string> hanya field yang merupakan jenis sensor & tidak null */
    private function onlySensorValues(array $source): array
    {
        return array_filter(
            array_intersect_key($source, array_flip(SensorReadingModel::TYPES)),
            fn ($v) => $v !== null,
        );
    }

    /** Pakai `ts` dari ESP hanya jika masuk akal (jam ESP tanpa NTP sering ngawur). */
    private function resolveTimestamp(mixed $ts): Carbon
    {
        if ($ts !== null) {
            $candidate = Carbon::createFromTimestampUTC((int) $ts);
            if ($candidate->between(now()->subDay(), now()->addMinutes(5))) {
                return $candidate;
            }
        }

        return now();
    }

    private function snapshotCacheKey(?string $koridorId): string
    {
        return 'sensor:snapshot:' . ($koridorId ?? 'all');
    }
}

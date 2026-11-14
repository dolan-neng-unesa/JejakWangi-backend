<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppSettingModel;
use App\Models\FestivalModel;
use App\Models\GeofenceModel;
use App\Models\SensorDeviceModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/** Halaman "Pengaturan" dashboard admin: pengaturan, info sistem, dan backup data. */
class AdminSettingsController extends Controller
{
    private const BACKUP_DIR = 'backups';
    private const BACKUP_KEEP = 10;
    // Tidak ikut backup: tabel sementara/rahasia sesi, dan sensor_readings (deret waktu besar, dipangkas berkala).
    private const BACKUP_SKIP = ['sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'password_reset_tokens', 'personal_access_tokens', 'sensor_readings'];

    public function show()
    {
        return response()->json([
            'data' => AppSettingModel::values(),
            'system' => [
                'version' => config('app.version', 'v1.0.0'),
                'updated_at' => $this->deployedAt(),
                'last_backup_at' => AppSettingModel::raw('system.last_backup_at'),
                'last_backup_file' => AppSettingModel::raw('system.last_backup_file'),
                'laravel' => app()->version(),
                'php' => PHP_VERSION,
                'database' => config('database.default'),
                'integrations' => [
                    'festival' => ['count' => FestivalModel::count(), 'active' => FestivalModel::where('is_active', true)->count()],
                    'sensor' => ['count' => SensorDeviceModel::count(), 'last_seen_at' => SensorDeviceModel::max('last_seen_at')],
                    'geofence' => ['count' => GeofenceModel::count(), 'active' => GeofenceModel::where('is_active', true)->count()],
                    'email' => ['mailer' => config('mail.default')],
                ],
            ],
        ]);
    }

    public function update(Request $request)
    {
        $input = $request->input('settings');
        if (!is_array($input) || $input === []) {
            return response()->json(['message' => 'Tidak ada pengaturan yang dikirim.'], 422);
        }

        $rules = $this->rules();
        $unknown = array_diff(array_keys($input), array_keys($rules));
        if ($unknown) {
            return response()->json(['message' => 'Pengaturan tidak dikenal.', 'errors' => array_fill_keys($unknown, ['Pengaturan tidak dikenal.'])], 422);
        }

        // Kunci berisi titik ("app.name") akan dibaca sebagai array bersarang oleh validator,
        // jadi diganti sementara dengan "__".
        $safe = fn (string $k) => str_replace('.', '__', $k);
        $validator = Validator::make(
            collect($input)->mapWithKeys(fn ($v, $k) => [$safe($k) => $v])->all(),
            collect($rules)->only(array_keys($input))->mapWithKeys(fn ($r, $k) => [$safe($k) => $r])->all(),
        );

        if ($validator->fails()) {
            $errors = [];
            foreach ($validator->errors()->messages() as $k => $msgs) {
                $errors[str_replace('__', '.', $k)] = $msgs;
            }

            return response()->json(['message' => 'Pengaturan tidak valid.', 'errors' => $errors], 422);
        }

        foreach ($validator->validated() as $k => $v) {
            AppSettingModel::put(str_replace('__', '.', $k), $v);
        }

        return response()->json(['message' => 'Pengaturan disimpan.', 'data' => AppSettingModel::values()]);
    }

    /** Salin semua tabel data ke satu berkas JSON di storage (disk "local"). */
    public function backup()
    {
        $tables = collect(Schema::getTableListing())
            ->map(fn ($t) => Str::afterLast($t, '.'))
            ->reject(fn ($t) => in_array($t, self::BACKUP_SKIP, true))
            ->unique()
            ->values();

        $dump = ['app' => config('app.name'), 'created_at' => now()->toIso8601String(), 'tables' => []];
        foreach ($tables as $table) {
            $dump['tables'][$table] = DB::table($table)->get()->map(fn ($r) => (array) $r)->all();
        }

        $file = 'jejak-wangi-backup-' . now()->format('Ymd-His') . '.json';
        $disk = Storage::disk('local');
        $disk->put(self::BACKUP_DIR . '/' . $file, json_encode($dump, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        // Simpan hanya BACKUP_KEEP berkas terbaru.
        collect($disk->files(self::BACKUP_DIR))->sort()->reverse()->slice(self::BACKUP_KEEP)->each(fn ($f) => $disk->delete($f));

        AppSettingModel::put('system.last_backup_at', now()->toIso8601String());
        AppSettingModel::put('system.last_backup_file', $file);

        return response()->json(['message' => 'Backup selesai.', 'data' => [
            'file' => $file,
            'size' => $disk->size(self::BACKUP_DIR . '/' . $file),
            'created_at' => now()->toIso8601String(),
        ]]);
    }

    public function download(Request $request)
    {
        $file = (string) $request->query('file');
        // Whitelist nama berkas — cegah path traversal.
        if (!preg_match('/^jejak-wangi-backup-\d{8}-\d{6}\.json$/', $file) || !Storage::disk('local')->exists(self::BACKUP_DIR . '/' . $file)) {
            return response()->json(['message' => 'Berkas backup tidak ditemukan.'], 404);
        }

        return Storage::disk('local')->download(self::BACKUP_DIR . '/' . $file);
    }

    private function deployedAt(): string
    {
        $lock = base_path('composer.lock');

        return ($lock && file_exists($lock) ? \Illuminate\Support\Carbon::createFromTimestamp(filemtime($lock)) : now())->toIso8601String();
    }

    private function rules(): array
    {
        $bool = ['boolean'];
        $rules = [
            'app.name' => ['string', 'min:1', 'max:30'],
            'app.region_label' => ['string', 'min:1', 'max:40'],
            'app.language' => ['in:id'],
            'app.timezone' => ['in:Asia/Jakarta,Asia/Makassar,Asia/Jayapura'],
            'sensor.offline_minutes' => ['integer', 'between:1,120'],
            'sensor.retention_days' => ['integer', 'between:1,365'],
            'sensor.auto_refresh_seconds' => ['integer', 'between:10,300'],
            'geofence.default_radius' => ['integer', 'between:1,5000'],
            'geofence.default_active' => $bool,
            'integration.email.recipients' => ['nullable', 'string', 'max:500'],
            'integration.email.digest' => ['in:harian,mingguan,nonaktif'],
        ];
        foreach (array_keys(AppSettingModel::DEFAULTS) as $key) {
            if (str_starts_with($key, 'notify.') || (str_starts_with($key, 'integration.') && str_ends_with($key, '.enabled'))) {
                $rules[$key] = $bool;
            }
        }

        // Nilai null tidak boleh lolos (validator melewati aturan non-implisit untuk null).
        return array_map(fn ($r) => in_array('nullable', $r, true) ? $r : array_merge(['required'], $r), $rules);
    }
}

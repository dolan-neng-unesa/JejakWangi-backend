<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AdminNotificationModel;
use App\Models\AppSettingModel;
use App\Models\KoridorModel;
use App\Models\SensorDeviceModel;
use App\Models\SensorReadingModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Manajemen perangkat sensor (ESP32) untuk halaman "Sensor IoT" dashboard admin.
 * Pengganti perintah artisan `devices:register`. Semua route di-guard ['auth:sanctum', 'admin'].
 *
 * Status perangkat: nonaktif (is_active=false) > perawatan (is_maintenance) >
 * offline (tidak mengirim data lebih lama dari pengaturan sensor.offline_minutes) > aktif.
 */
class AdminSensorDeviceController extends Controller
{
    public function index()
    {
        $offlineMin = max(1, (int) AppSettingModel::get('sensor.offline_minutes', 5));
        $devices = SensorDeviceModel::orderBy('id')->get();
        $koridors = KoridorModel::pluck('formal_name', 'id');

        // Bacaan terakhir tiap (perangkat, jenis sensor) dalam satu query.
        $latest = collect();
        if ($devices->isNotEmpty()) {
            $latest = SensorReadingModel::query()
                ->from('sensor_readings as r')
                ->joinSub(
                    SensorReadingModel::query()
                        ->select('device_code', 'sensor_type', DB::raw('MAX(recorded_at) as max_at'))
                        ->whereIn('device_code', $devices->pluck('device_code'))
                        ->groupBy('device_code', 'sensor_type'),
                    'm',
                    fn ($j) => $j->on('r.device_code', '=', 'm.device_code')
                        ->on('r.sensor_type', '=', 'm.sensor_type')
                        ->on('r.recorded_at', '=', 'm.max_at'),
                )
                ->select('r.*')
                ->orderByDesc('r.id')
                ->get()
                ->unique(fn ($r) => $r->device_code . '|' . $r->sensor_type);
        }

        $rows = $devices->map(fn ($d) => $this->format($d, $koridors, $latest->where('device_code', $d->device_code), $offlineMin))->values();

        $count = fn (string $s) => $rows->where('status', $s)->count();

        return response()->json([
            'data' => $rows,
            'summary' => [
                'total' => $rows->count(),
                'aktif' => $count('aktif'),
                'offline' => $count('offline'),
                'perawatan' => $count('perawatan'),
                'nonaktif' => $count('nonaktif'),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'device_code' => ['required', 'string', 'min:3', 'max:100', 'regex:/^[A-Za-z0-9_.-]+$/', 'unique:sensor_devices,device_code'],
            'name' => ['nullable', 'string', 'max:120'],
            'koridor_id' => ['nullable', 'integer', 'exists:koridors,id'],
            'is_active' => ['sometimes', 'boolean'],
            'is_maintenance' => ['sometimes', 'boolean'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Data sensor tidak valid', 'errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();
        $plainKey = SensorDeviceModel::newPlainKey();

        $device = SensorDeviceModel::create([
            'device_code' => $data['device_code'],
            'name' => $data['name'] ?? null,
            'koridor_id' => isset($data['koridor_id']) ? (string) $data['koridor_id'] : null,
            'key_hash' => SensorDeviceModel::hashKey($plainKey),
            'is_active' => $request->boolean('is_active', true),
            'is_maintenance' => $request->boolean('is_maintenance', false),
        ]);

        AdminNotificationModel::notify('sensor', 'Sensor IoT Baru Terdaftar', "Perangkat {$device->device_code} ditambahkan dan menunggu data pertama.");

        return response()->json([
            'message' => 'Sensor berhasil didaftarkan',
            'data' => $this->formatOne($device),
            'api_key' => $plainKey, // hanya ditampilkan sekali
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $device = SensorDeviceModel::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'name' => ['nullable', 'string', 'max:120'],
            'koridor_id' => ['nullable', 'integer', 'exists:koridors,id'],
            'is_active' => ['sometimes', 'boolean'],
            'is_maintenance' => ['sometimes', 'boolean'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Data sensor tidak valid', 'errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();
        if (array_key_exists('koridor_id', $data)) {
            $data['koridor_id'] = $data['koridor_id'] !== null ? (string) $data['koridor_id'] : null;
        }
        $device->update($data);

        return response()->json(['message' => 'Sensor berhasil diperbarui', 'data' => $this->formatOne($device->fresh())]);
    }

    /** Putar kunci API: kunci lama langsung tidak berlaku. */
    public function rotateKey($id)
    {
        $device = SensorDeviceModel::findOrFail($id);
        $plainKey = SensorDeviceModel::newPlainKey();
        $device->update(['key_hash' => SensorDeviceModel::hashKey($plainKey)]);

        return response()->json(['message' => 'Kunci API diputar ulang', 'data' => $this->formatOne($device->fresh()), 'api_key' => $plainKey]);
    }

    public function destroy($id)
    {
        SensorDeviceModel::findOrFail($id)->delete();

        return response()->json(['message' => 'Sensor berhasil dihapus']);
    }

    private function formatOne(SensorDeviceModel $d): array
    {
        $offlineMin = max(1, (int) AppSettingModel::get('sensor.offline_minutes', 5));

        return $this->format($d, KoridorModel::pluck('formal_name', 'id'), collect(), $offlineMin);
    }

    private function format(SensorDeviceModel $d, $koridors, $readings, int $offlineMin): array
    {
        if (!$d->is_active) {
            $status = 'nonaktif';
        } elseif ($d->is_maintenance) {
            $status = 'perawatan';
        } elseif (!$d->last_seen_at || $d->last_seen_at->lte(now()->subMinutes($offlineMin))) {
            $status = 'offline';
        } else {
            $status = 'aktif';
        }

        $byType = [];
        foreach ($readings as $r) {
            $byType[$r->sensor_type] = [
                'value' => (float) $r->value,
                'unit' => SensorReadingModel::unitFor($r->sensor_type),
                'recorded_at' => $r->recorded_at?->toIso8601String(),
            ];
        }

        return [
            'id' => $d->id,
            'device_code' => $d->device_code,
            'name' => $d->name,
            'koridor_id' => $d->koridor_id,
            'koridor_name' => $d->koridor_id !== null ? ($koridors[(int) $d->koridor_id] ?? null) : null,
            'is_active' => (bool) $d->is_active,
            'is_maintenance' => (bool) $d->is_maintenance,
            'status' => $status,
            'last_seen_at' => $d->last_seen_at?->toIso8601String(),
            'types' => array_keys($byType),
            'readings' => (object) $byType,
        ];
    }
}

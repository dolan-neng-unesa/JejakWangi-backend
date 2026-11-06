<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\GeofenceResource;
use App\Models\AdminNotificationModel;
use App\Models\GeofenceModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class GeofenceController extends Controller
{
    /**
     * Daftar zona geofencing. Default hanya yang aktif (dikonsumsi
     * GeofenceContext.jsx di svarga-app). Admin dashboard butuh melihat
     * semua zona (termasuk nonaktif) lewat ?all=1.
     */
    public function index(Request $request)
    {
        $zones = $request->boolean('all')
            ? GeofenceModel::orderBy('id')->get()
            : GeofenceModel::where('is_active', true)->get();

        return GeofenceResource::collection($zones);
    }

    /** Buat zona baru — dipakai tombol "Tambah Zona Baru" di dashboard admin. Admin-only. */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'radius_meter' => ['required', 'integer', 'min:1', 'max:50000'],
            'is_active' => ['sometimes', 'boolean'],
            'soundscape_id' => ['nullable', 'integer', 'exists:soundscapes,id'],
            'welcome_title' => ['nullable', 'string', 'max:255'],
            'welcome_desc' => ['nullable', 'string'],
            'koridor_id' => ['nullable', 'integer', 'exists:koridors,id'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Data zona tidak valid', 'errors' => $validator->errors()], 422);
        }

        $zone = GeofenceModel::create($validator->validated() + ['is_active' => $request->boolean('is_active', true)]);

        AdminNotificationModel::notify('geofence', 'Zona Geofence Baru', "Zona {$zone->name} (radius {$zone->radius_meter} m) ditambahkan.");

        return response()->json(['message' => 'Zona berhasil dibuat', 'data' => new GeofenceResource($zone)], 201);
    }

    public function update(Request $request, $id)
    {
        $zone = GeofenceModel::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'latitude' => ['sometimes', 'required', 'numeric', 'between:-90,90'],
            'longitude' => ['sometimes', 'required', 'numeric', 'between:-180,180'],
            'radius_meter' => ['sometimes', 'required', 'integer', 'min:1', 'max:50000'],
            'is_active' => ['sometimes', 'boolean'],
            'soundscape_id' => ['nullable', 'integer', 'exists:soundscapes,id'],
            'welcome_title' => ['nullable', 'string', 'max:255'],
            'welcome_desc' => ['nullable', 'string'],
            'koridor_id' => ['nullable', 'integer', 'exists:koridors,id'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Data zona tidak valid', 'errors' => $validator->errors()], 422);
        }

        $zone->update($validator->validated());

        return response()->json(['message' => 'Zona berhasil diperbarui', 'data' => new GeofenceResource($zone)]);
    }

    public function destroy($id)
    {
        $zone = GeofenceModel::findOrFail($id);
        $zone->delete();

        return response()->json(['message' => 'Zona berhasil dihapus']);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChargerStationModel;

class ChargerStationController extends Controller
{
    public function index()
    {
        return response()->json(['data' => ChargerStationModel::where('is_active', true)->orderBy('id')->get()->map(fn ($c) => $this->resource($c))]);
    }

    public function show(int $id)
    {
        $c = ChargerStationModel::where('is_active', true)->find($id);

        return $c ? response()->json(['data' => $this->resource($c)]) : response()->json(['message' => 'Charger station tidak ditemukan'], 404);
    }

    private function resource(ChargerStationModel $c): array
    {
        $img = $c->image_url;

        return [
            'id' => $c->id, 'name' => $c->name, 'location' => $c->location, 'koridor_id' => $c->koridor_id,
            'latitude' => $c->latitude, 'longitude' => $c->longitude, 'solar_kwp' => $c->solar_kwp,
            'battery_percent' => $c->battery_percent, 'ports_total' => $c->ports_total, 'ports_available' => $c->ports_available,
            'opening_hours' => $c->opening_hours, 'is_available' => $c->ports_available > 0,
            'image_url' => $img && ! str_starts_with($img, 'http') ? url($img) : $img,
        ];
    }
}

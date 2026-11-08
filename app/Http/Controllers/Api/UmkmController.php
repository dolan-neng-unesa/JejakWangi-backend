<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UmkmResource;
use App\Models\UmkmModel;
use Illuminate\Http\Request;

class UmkmController extends Controller
{
    public function index(Request $request)
    {
        $query = UmkmModel::query()->active();

        if ($request->filled('koridor_id')) {
            $query->where('koridor_id', $request->query('koridor_id'));
        }

        $umkms = $query->orderBy('business_name')->orderBy('id')->paginate($this->perPage($request));

        return response()->json([
            'data' => UmkmResource::collection($umkms->items()),
            'meta' => [
                'current_page' => $umkms->currentPage(),
                'last_page' => $umkms->lastPage(),
                'per_page' => $umkms->perPage(),
                'total' => $umkms->total(),
            ],
        ], 200);
    }

    public function show(int $id)
    {
        $umkm = UmkmModel::query()->active()->find($id);

        if (!$umkm) {
            return response()->json(['message' => 'UMKM tidak ditemukan'], 404);
        }

        return response()->json(['data' => new UmkmResource($umkm)], 200);
    }
}

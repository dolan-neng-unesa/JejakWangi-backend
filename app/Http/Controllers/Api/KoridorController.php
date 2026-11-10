<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\KoridorResource;
use App\Models\KoridorModel;

/**
 * Menggantikan koleksi Firestore `koridor` yang selama ini kosong di
 * project Firebase asli — inilah sebabnya gambar koridor tidak pernah
 * muncul walau data mock sudah lengkap (lihat percakapan sebelumnya).
 */
class KoridorController extends Controller
{
    public function index()
    {
        $koridors = KoridorModel::orderBy('sort_order')->orderBy('id')->get();

        return KoridorResource::collection($koridors);
    }

    public function show($id)
    {
        $koridor = KoridorModel::findOrFail($id);

        return new KoridorResource($koridor);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\GreenSpaceResource;
use App\Models\GreenSpaceModel;

class GreenSpaceController extends Controller
{
    public function index()
    {
        $spaces = GreenSpaceModel::orderBy('name')->get();

        return GreenSpaceResource::collection($spaces);
    }
}

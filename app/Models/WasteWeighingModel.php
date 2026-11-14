<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WasteWeighingModel extends Model
{
    protected $table = 'waste_weighings';

    protected $fillable = [
        'code', 'kind', 'station_code', 'station_name', 'depositor_type', 'depositor_name', 'user_id', 'umkm_id',
        'items', 'total_kg', 'organik_kg', 'anorganik_kg', 'pangan_kg', 'points', 'status', 'note',
        'correction_note', 'weighed_at', 'confirmed_at', 'processed_at', 'weighed_by',
    ];

    protected $casts = [
        'items' => 'array',
        'total_kg' => 'float',
        'organik_kg' => 'float',
        'anorganik_kg' => 'float',
        'pangan_kg' => 'float',
        'points' => 'integer',
        'weighed_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'processed_at' => 'datetime',
    ];
}

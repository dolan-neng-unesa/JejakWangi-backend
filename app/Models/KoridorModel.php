<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class KoridorModel extends Model
{
    use HasFactory;

    protected $table = 'koridors';

    protected $fillable = [
        'green_space_id', 'short_name', 'formal_name', 'route_label', 'desc',
        'thumbnail', 'hero_image', 'hijau_level', 'hijau_score', 'umkm_count',
        'status', 'distance_meter', 'estimate_minutes', 'path', 'shade_score',
        'condition_title', 'condition_desc', 'sensor_snapshot',
        'oxygen_kg_day', 'co2_reduced_kg_day', 'carbon_inputs',
    ];

    protected $casts = [
        'hijau_score' => 'integer',
        'umkm_count' => 'integer',
        'distance_meter' => 'integer',
        'estimate_minutes' => 'integer',
        'shade_score' => 'integer',
        'oxygen_kg_day' => 'float',
        'co2_reduced_kg_day' => 'float',
        'sensor_snapshot' => 'array',
        'path' => 'array',
        'carbon_inputs' => 'array',
    ];

    public function greenSpace()
    {
        return $this->belongsTo(GreenSpaceModel::class, 'green_space_id');
    }
}

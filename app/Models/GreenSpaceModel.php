<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GreenSpaceModel extends Model
{
    use HasFactory;

    protected $table = 'green_spaces';

    protected $fillable = [
        'name', 'location_type', 'description', 'latitude', 'longitude',
        'address', 'shade_score', 'is_active',
    ];

    protected $casts = [
        'latitude' => 'float',
        'longitude' => 'float',
        'shade_score' => 'integer',
        'is_active' => 'boolean',
    ];
}

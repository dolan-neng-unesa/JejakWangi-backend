<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GeofenceModel extends Model
{
    use HasFactory;

    protected $table = 'geofences';

    protected $fillable = [
        'name',
        'latitude',
        'longitude',
        'radius_meter',
        'is_active',
        'soundscape_id',
        'welcome_title',
        'welcome_desc',
        'koridor_id',
        'background_image',
    ];

    protected $casts = [
        'latitude' => 'float',
        'longitude' => 'float',
        'radius_meter' => 'integer',
        'is_active' => 'boolean',
    ];

    public function soundscape()
    {
        return $this->belongsTo(SoundscapeModel::class, 'soundscape_id');
    }
}

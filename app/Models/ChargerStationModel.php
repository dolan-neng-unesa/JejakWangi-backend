<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChargerStationModel extends Model
{
    protected $table = 'charger_stations';

    protected $fillable = [
        'name', 'location', 'koridor_id', 'latitude', 'longitude', 'solar_kwp', 'battery_percent',
        'ports_total', 'ports_available', 'opening_hours', 'image_url', 'is_active',
    ];

    protected $casts = [
        'latitude' => 'float',
        'longitude' => 'float',
        'solar_kwp' => 'float',
        'battery_percent' => 'integer',
        'ports_total' => 'integer',
        'ports_available' => 'integer',
        'is_active' => 'boolean',
    ];
}

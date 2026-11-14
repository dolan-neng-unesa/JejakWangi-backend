<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WasteCheckinModel extends Model
{
    protected $table = 'waste_checkins';

    protected $fillable = ['user_id', 'station_code', 'scanned_at'];

    protected $casts = ['scanned_at' => 'datetime'];
}

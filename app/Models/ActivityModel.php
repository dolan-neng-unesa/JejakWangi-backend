<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ActivityModel extends Model
{
    protected $table = 'activities';

    protected $fillable = [
        'user_id', 'client_id', 'koridor_id', 'transport', 'distance_m', 'duration_sec',
        'kkal', 'co2_kg', 'rupiah', 'points', 'is_simulated', 'path', 'started_at',
    ];

    protected $casts = [
        'distance_m' => 'integer',
        'duration_sec' => 'integer',
        'kkal' => 'integer',
        'co2_kg' => 'float',
        'rupiah' => 'integer',
        'points' => 'integer',
        'is_simulated' => 'boolean',
        'path' => 'array',
        'started_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(UserModel::class, 'user_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class SensorDeviceModel extends Model
{
    protected $table = 'sensor_devices';

    protected $fillable = ['device_code', 'name', 'koridor_id', 'key_hash', 'is_active', 'is_maintenance', 'last_seen_at'];

    protected $hidden = ['key_hash'];

    protected $casts = [
        'is_active' => 'boolean',
        'is_maintenance' => 'boolean',
        'last_seen_at' => 'datetime',
    ];

    public static function hashKey(string $plainKey): string
    {
        return hash('sha256', $plainKey);
    }

    /** Kunci acak 48 karakter — cukup panjang sehingga sha256 tanpa salt aman. */
    public static function newPlainKey(): string
    {
        return 'svg_' . Str::random(44);
    }
}

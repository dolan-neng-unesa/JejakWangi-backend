<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ByoTokenModel extends Model
{
    protected $table = 'byo_tokens';

    protected $fillable = ['token', 'umkm_id', 'issued_by', 'expires_at', 'used_by', 'used_at'];

    protected $casts = ['umkm_id' => 'integer', 'expires_at' => 'datetime', 'used_at' => 'datetime'];
}

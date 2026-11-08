<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VoucherCooldownModel extends Model
{
    protected $table = 'voucher_cooldowns';

    protected $fillable = ['user_id', 'tier', 'available_at'];

    protected $casts = ['tier' => 'integer', 'available_at' => 'datetime'];
}

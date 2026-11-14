<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QrPoint extends Model
{
    protected $table = 'qr_points';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['lat'=>'float','lng'=>'float','is_active'=>'boolean'];
    }
}

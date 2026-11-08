<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QrCheckin extends Model
{
    protected $table = 'qr_checkins';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['tanpa_belanja'=>'boolean','bawa_tas'=>'boolean','co2_kg'=>'float','kecepatan_mps'=>'float','started_at'=>'datetime','finished_at'=>'datetime'];
    }
}

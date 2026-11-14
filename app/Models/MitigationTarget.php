<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MitigationTarget extends Model
{
    protected $table = 'mitigation_targets';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['baseline_kg_hari'=>'float','target_persen'=>'float','target_kg_hari'=>'float','baseline_dari'=>'date','baseline_sampai'=>'date','tenggat'=>'date'];
    }
}

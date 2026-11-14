<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FollowUpAction extends Model
{
    protected $table = 'follow_up_actions';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['target_kg'=>'float','tanggal'=>'date:Y-m-d','payload'=>'array'];
    }
}

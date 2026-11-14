<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiReport extends Model
{
    protected $table = 'ai_reports';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['temuan'=>'array'];
    }
}

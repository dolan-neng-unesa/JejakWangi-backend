<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Scenario extends Model
{
    protected $table = 'scenarios';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['parameter'=>'array','hasil'=>'array'];
    }
}

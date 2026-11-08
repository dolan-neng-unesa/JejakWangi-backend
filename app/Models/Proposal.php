<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Proposal extends Model
{
    protected $table = 'proposals';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['dampak'=>'array','parameter'=>'array','decided_at'=>'datetime'];
    }
}

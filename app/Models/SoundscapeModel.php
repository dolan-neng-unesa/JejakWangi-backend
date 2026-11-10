<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SoundscapeModel extends Model
{
    use HasFactory;

    protected $table = 'soundscapes';

    protected $fillable = ['title', 'category', 'duration', 'audio_url', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];
}

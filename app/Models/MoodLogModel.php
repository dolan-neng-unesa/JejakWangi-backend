<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MoodLogModel extends Model
{
    use HasFactory;

    protected $table = 'mood_logs';

    protected $fillable = [
        'user_id',
        'anonymous_session_id',
        'green_space_id',
        'mood_score',
        'activity',
        'note',
        'logged_at',
    ];

    protected $casts = [
        'mood_score' => 'integer',
        'green_space_id' => 'integer',
        'logged_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(UserModel::class, 'user_id');
    }
}

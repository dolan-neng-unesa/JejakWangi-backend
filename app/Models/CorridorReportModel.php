<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CorridorReportModel extends Model
{
    protected $table = 'corridor_reports';

    protected $fillable = [
        'code', 'koridor_id', 'location', 'category', 'title', 'description', 'status', 'assignee',
        'admin_note', 'reporter_id', 'reporter_name', 'evidence', 'action_evidence', 'history',
    ];

    protected $casts = [
        'koridor_id' => 'integer',
        'evidence' => 'array',
        'action_evidence' => 'array',
        'history' => 'array',
    ];
}

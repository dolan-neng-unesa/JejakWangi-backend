<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FestivalModel extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'location_type',
        'location_name',
        'category',
        'address',
        'event_date',
        'start_time',
        'end_time',
        'description',
        'image_url',
        'is_active',
    ];

    protected $casts = [
        'event_date' => 'date',
        'is_active' => 'boolean',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeUpcoming($query)
    {
        return $query->where('event_date', '>=', now()->toDateString());
    }

    public function scopeInMonth($query, string $yearMonth)
    {
        // whereYear/whereMonth dari Laravel portable lintas driver DB
        // (SQLite untuk dev/test, MySQL/PostgreSQL untuk produksi) — beda
        // dengan strftime() yang hanya jalan di SQLite.
        [$year, $month] = array_map('intval', explode('-', $yearMonth));

        return $query->whereYear('event_date', $year)->whereMonth('event_date', $month);
    }

    public function scopeCategory($query, ?string $category)
    {
        return $category ? $query->where('category', $category) : $query;
    }
}

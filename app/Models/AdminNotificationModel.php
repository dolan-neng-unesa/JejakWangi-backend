<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AdminNotificationModel extends Model
{
    use HasFactory;

    protected $table = 'admin_notifications';

    protected $fillable = ['category', 'title', 'description', 'is_read'];

    protected $casts = ['is_read' => 'boolean'];

    /**
     * Catat notifikasi untuk dashboard admin. Tidak pernah melempar galat —
     * gagal mencatat notifikasi tidak boleh menggagalkan aksi utamanya.
     * category: 'lingkungan' | 'sensor' | 'festival' | 'pengguna' | 'geofence' | 'mood'
     */
    public static function notify(string $category, string $title, ?string $description = null): void
    {
        try {
            static::create([
                'category' => $category,
                'title' => $title,
                'description' => $description,
                'is_read' => false,
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}

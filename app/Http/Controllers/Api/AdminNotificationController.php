<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AdminNotificationModel;
use Illuminate\Http\Request;

// Admin-only (lihat routes/api.php: middleware ['auth:sanctum', 'admin']).
class AdminNotificationController extends Controller
{
    private const ICON_MAP = ['lingkungan' => 'Activity', 'sensor' => 'Cpu', 'festival' => 'Calendar', 'pengguna' => 'Users', 'mood' => 'Smile', 'geofence' => 'MapPin'];
    private const TONE_MAP = ['lingkungan' => 'danger', 'sensor' => 'warn'];

    public function index(Request $request)
    {
        $perPage = max(1, min(100, (int) $request->query('per_page', 20)));
        $logs = AdminNotificationModel::query()
            ->when($request->filled('category'), fn ($q) => $q->where('category', $request->query('category')))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage);

        return response()->json([
            'data' => collect($logs->items())->map(fn ($n) => [
                'id' => $n->id,
                'kategori' => $n->category,
                'icon' => self::ICON_MAP[$n->category] ?? 'Bell',
                'tone' => self::TONE_MAP[$n->category] ?? 'neutral',
                'title' => $n->title,
                'desc' => $n->description ?? '',
                'time' => $n->created_at->toIso8601String(),
                'unread' => !$n->is_read,
            ]),
            'total' => AdminNotificationModel::count(),
            'filtered_total' => $logs->total(),
            'counts' => AdminNotificationModel::query()->selectRaw('category, COUNT(*) as n')->groupBy('category')->pluck('n', 'category'),
            'belum_dibaca' => AdminNotificationModel::where('is_read', false)->count(),
            'hari_ini' => AdminNotificationModel::whereDate('created_at', now()->toDateString())->count(),
        ]);
    }

    public function markAllRead()
    {
        AdminNotificationModel::where('is_read', false)->update(['is_read' => true]);

        return response()->json(['message' => 'Semua notifikasi ditandai terbaca.']);
    }
}

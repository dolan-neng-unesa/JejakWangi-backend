<?php

namespace App\Services;

use App\Models\ActivityModel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Periode & peringkat bulanan. "Reset tiap awal bulan" dicapai lewat jendela kalender (bukan menghapus
 * data): jarak bulan ini dihitung dari aktivitas non-simulasi sejak tanggal 1 pukul 00:00 zona Jakarta.
 */
class MonthlyRanking
{
    /** @return array{0: Carbon, 1: Carbon} awal & akhir bulan berjalan, dalam UTC untuk query. */
    public static function range(): array
    {
        $tz = config('jejakwangi.timezone');
        $start = Carbon::now($tz)->startOfMonth();
        $end = $start->copy()->addMonth();

        return [$start->copy()->utc(), $end->copy()->utc()];
    }

    /** Kapan leaderboard & progres bulanan reset berikutnya. */
    public static function resetAt(): Carbon
    {
        return self::range()[1];
    }

    private static function base()
    {
        [$start, $end] = self::range();

        return ActivityModel::query()
            ->where('activities.is_simulated', false)
            ->where('activities.started_at', '>=', $start)
            ->where('activities.started_at', '<', $end);
    }

    public static function userMeters(int $userId): int
    {
        return (int) self::base()->where('activities.user_id', $userId)->sum('activities.distance_m');
    }

    /** @return Collection<int, object{uid:int,name:string,m:int}> terurut jarak desc, seri → pengguna lebih lama dulu. */
    public static function top(int $limit): Collection
    {
        return self::base()
            ->join('users', 'users.id', '=', 'activities.user_id')
            ->groupBy('users.id', 'users.name')
            ->selectRaw('users.id as uid, users.name as name, SUM(activities.distance_m) as m')
            ->orderByDesc('m')->orderBy('users.id')
            ->limit($limit)->get();
    }

    /** Peringkat = jumlah pengguna dengan jarak lebih jauh + 1 (seri berbagi peringkat). */
    public static function rankOf(int $meters): int
    {
        $sub = self::base()->groupBy('activities.user_id')
            ->selectRaw('activities.user_id, SUM(activities.distance_m) as m')
            ->havingRaw('SUM(activities.distance_m) > ?', [$meters]);

        return DB::query()->fromSub($sub, 't')->count() + 1;
    }

    /** Badge tingkat tertinggi untuk peringkat tertentu (null bila di luar Top 10). */
    public static function badgeFor(int $rank): ?array
    {
        foreach (config('jejakwangi.badges') as $b) {
            if ($rank <= $b['rank_max']) {
                return ['kode' => $b['kode'], 'label' => $b['label']];
            }
        }

        return null;
    }
}

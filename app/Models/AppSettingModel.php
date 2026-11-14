<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Pengaturan dashboard admin (key-value, nilai disimpan sebagai JSON).
 * DEFAULTS harus sama dengan DEFAULT_SETTINGS di jejak-wangi-admin/src/context/SettingsContext.jsx.
 */
class AppSettingModel extends Model
{
    protected $table = 'app_settings';

    protected $fillable = ['key', 'value'];

    public const DEFAULTS = [
        'app.name' => 'JEJAK WANGI',
        'app.region_label' => 'Banyuwangi Mandiri',
        'app.language' => 'id',
        'app.timezone' => 'Asia/Jakarta',
        'sensor.offline_minutes' => 5,
        'sensor.retention_days' => 30,
        'sensor.auto_refresh_seconds' => 30,
        'geofence.default_radius' => 70,
        'geofence.default_active' => true,
        'notify.push' => true,
        'notify.email' => true,
        'notify.sms' => false,
        'notify.cat.lingkungan' => true,
        'notify.cat.sensor' => true,
        'notify.cat.pengguna' => false,
        'notify.cat.geofence' => true,
        'notify.cat.festival' => true,
        'notify.cat.mood' => true,
        'integration.festival.enabled' => true,
        'integration.sensor.enabled' => true,
        'integration.geofence.enabled' => true,
        'integration.email.enabled' => true,
        'integration.email.recipients' => '',
        'integration.email.digest' => 'harian',
    ];

    /** Semua pengaturan (nilai tersimpan menimpa default). */
    public static function values(): array
    {
        $out = self::DEFAULTS;
        foreach (static::query()->get(['key', 'value']) as $row) {
            if (array_key_exists($row->key, $out)) {
                $out[$row->key] = json_decode($row->value, true);
            }
        }

        return $out;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::values()[$key] ?? $default;
    }

    public static function put(string $key, mixed $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => json_encode($value)]);
    }

    /** Nilai mentah untuk kunci internal di luar DEFAULTS (mis. system.last_backup_at). */
    public static function raw(string $key): mixed
    {
        $row = static::where('key', $key)->first();

        return $row ? json_decode($row->value, true) : null;
    }
}

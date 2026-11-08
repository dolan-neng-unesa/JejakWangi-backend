<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SensorReadingModel extends Model
{
    protected $table = 'sensor_readings';

    public $timestamps = false;

    public const TYPE_TEMPERATURE = 'temperature';
    public const TYPE_AIR_QUALITY = 'air_quality';
    public const TYPE_PM10 = 'pm10';
    public const TYPE_SO2 = 'so2';
    public const TYPE_CO = 'co';
    public const TYPE_O3 = 'o3';
    public const TYPE_NO2 = 'no2';
    public const TYPE_PM25 = 'pm25';
    public const TYPE_CO2 = 'co2';
    public const TYPE_O2 = 'o2';
    // Sumber data flowchart: suhu area terbuka (pembanding kanopi), daya Solar Bench (W), hitungan kendaraan kamera YOLO.
    public const TYPE_HUMIDITY = 'humidity'; // DHT11/DHT22 (%RH)
    public const TYPE_TEMPERATURE_OPEN = 'temperature_open';
    public const TYPE_SOLAR_W = 'solar_w';
    public const TYPE_VEHICLE_MOTOR = 'vehicle_motor';
    public const TYPE_VEHICLE_CAR = 'vehicle_car';

    public const POLLUTANT_TYPES = [
        self::TYPE_PM10, self::TYPE_SO2, self::TYPE_CO, self::TYPE_O3, self::TYPE_NO2,
    ];

    /** Semua jenis yang diterima dari ESP32 — nama field di payload JSON. */
    public const TYPES = [
        self::TYPE_TEMPERATURE,
        self::TYPE_AIR_QUALITY,
        self::TYPE_PM25,
        self::TYPE_CO2,
        self::TYPE_O2,
        self::TYPE_HUMIDITY,
        self::TYPE_TEMPERATURE_OPEN,
        self::TYPE_SOLAR_W,
        self::TYPE_VEHICLE_MOTOR,
        self::TYPE_VEHICLE_CAR,
        ...self::POLLUTANT_TYPES,
    ];

    /** Data dianggap "langsung" jika umurnya di bawah ini (arahan mentor: 5 menit). */
    public const FRESHNESS_WINDOW_MINUTES = 5;

    protected $fillable = ['device_code', 'koridor_id', 'sensor_type', 'value', 'recorded_at'];

    protected $casts = [
        'value' => 'float',
        'recorded_at' => 'datetime',
    ];

    public static function unitFor(string $sensorType): ?string
    {
        return match ($sensorType) {
            self::TYPE_TEMPERATURE, self::TYPE_TEMPERATURE_OPEN => '°C',
            self::TYPE_SOLAR_W => 'W',
            self::TYPE_VEHICLE_MOTOR, self::TYPE_VEHICLE_CAR => 'unit',
            self::TYPE_AIR_QUALITY => 'AQI',
            self::TYPE_PM10, self::TYPE_PM25, self::TYPE_SO2, self::TYPE_CO, self::TYPE_O3, self::TYPE_NO2 => 'µg/m³',
            self::TYPE_CO2 => 'ppm',
            self::TYPE_O2, self::TYPE_HUMIDITY => '%',
            default => null,
        };
    }
}

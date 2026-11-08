<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HourlyMetric extends Model
{
    protected $table = 'hourly_metrics';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['hour_at'=>'datetime','solar_kwh'=>'float','pm25'=>'float','pm10'=>'float','suhu_kanopi'=>'float','suhu_terbuka'=>'float','selisih_suhu'=>'float','hari_festival'=>'boolean','sisa_pangan_kg'=>'float','serapan_kg'=>'float','emisi_ac_kg'=>'float','emisi_kendaraan_kg'=>'float','emisi_terhindar_kg'=>'float','neraca_kg'=>'float','sumber'=>'array'];
    }
}

<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// Bentuk field disamakan persis dengan koridors[] di
// src/data/mockGreenSpaces.js (jejak-wangi-app) supaya koridorService.js hanya
// perlu menambah satu cabang fetch, tidak perlu mapping ulang di halaman.
// `thumbnail`/`hero_image` diubah dari path relatif jadi URL absolut di
// sini (asset() ) — gambarnya sama persis dengan yang dulu dibundel Vite,
// cuma sekarang disajikan Laravel dari public/images/koridor/.
class KoridorResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'green_space_id' => $this->green_space_id,
            'short_name' => $this->short_name,
            'formal_name' => $this->formal_name,
            'route_label' => $this->route_label,
            'desc' => $this->desc,
            'thumbnail' => $this->thumbnail ? asset($this->thumbnail) : null,
            'hero_image' => $this->hero_image ? asset($this->hero_image) : null,
            'hijau_level' => $this->hijau_level,
            'hijau_score' => (int) $this->hijau_score,
            'umkm_count' => (int) $this->umkm_count,
            'status' => $this->status,
            // Urutan tampil di daftar koridor (terpisah dari id; lihat migration add_sort_order_and_highlights).
            'sort_order' => (int) $this->sort_order,
            'distance_meter' => $this->distance_meter,
            'estimate_minutes' => $this->estimate_minutes,
            // Jalur untuk peta: [[lat, lng], ...] atau null bila belum ada datanya.
            'path' => $this->path,
            'shade_score' => (int) $this->shade_score,
            // Estimasi kontribusi lingkungan (kg/hari); null bila belum ada kajiannya.
            'oxygen_kg_day' => $this->oxygen_kg_day !== null ? (float) $this->oxygen_kg_day : null,
            'co2_reduced_kg_day' => $this->co2_reduced_kg_day !== null ? (float) $this->co2_reduced_kg_day : null,
            // Masukan neraca karbon (pohon, kendaraan, AC toko); FE menghitung ulang oxygen/co2 darinya.
            'carbon_inputs' => $this->carbon_inputs,
            // Konten opsional halaman detail (kunjungan hari ini, Eco-Coach, dampak bersama); null = tampilan standar.
            'highlights' => $this->highlights,
            'condition_title' => $this->condition_title,
            'condition_desc' => $this->condition_desc,
            // Snapshot statis (baseline) — untuk suhu & kualitas udara, FE
            // meng-overlay data live/DB dari /api/sensors/* di atas nilai ini
            // (lihat useSensorReading.js), jadi field ini cukup jadi fallback.
            'sensor' => $this->sensor_snapshot,

            'hero_video' => $this->hero_video ? asset($this->hero_video) : null,
        ];
    }
}

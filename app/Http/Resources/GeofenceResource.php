<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// Bentuk field disamakan persis dengan geofences[] di
// jejak-wangi-app/src/data/mockContent.js supaya GeofenceContext.jsx tidak perlu
// mapping tambahan saat pindah dari mock ke API ini.
class GeofenceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'latitude' => (float) $this->latitude,
            'longitude' => (float) $this->longitude,
            'radius_meter' => (int) $this->radius_meter,
            'is_active' => (bool) $this->is_active,
            'soundscape_id' => $this->soundscape_id,
            'welcome_title' => $this->welcome_title,
            'welcome_desc' => $this->welcome_desc,
            'koridor_id' => $this->koridor_id,
            'background_image' => $this->background_image ? asset($this->background_image) : null,
        ];
    }
}

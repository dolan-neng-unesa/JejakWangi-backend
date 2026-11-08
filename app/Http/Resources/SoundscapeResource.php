<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SoundscapeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'category' => $this->category,
            'duration' => $this->duration,
            'audio_url' => $this->audio_url,
            'is_active' => (bool) $this->is_active,
        ];
    }
}

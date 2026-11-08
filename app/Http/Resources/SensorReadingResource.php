<?php

namespace App\Http\Resources;

use App\Models\SensorReadingModel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SensorReadingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'sensor_type' => $this->sensor_type,
            'value' => (float) $this->value,
            'unit' => SensorReadingModel::unitFor($this->sensor_type),
            'recorded_at' => $this->recorded_at?->toIso8601String(),
        ];
    }
}

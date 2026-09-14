<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TimeSlotResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'resource_id' => $this->resource_id,
            'start_at' => $this->start_at,
            'end_at' => $this->end_at,
            'capacity' => $this->capacity,
            'booked_count' => $this->booked_count,
            'is_full' => $this->isFull(),
        ];
    }
}

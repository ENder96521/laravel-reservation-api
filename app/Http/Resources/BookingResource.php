<?php

namespace App\Http\Resources;

use App\Models\Booking;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Booking
 */
class BookingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'time_slot_id' => $this->time_slot_id,
            'status' => $this->status,
            'payment_status' => $this->payment_status,
            'payment_url' => $this->payment_url,
            'time_slot' => new TimeSlotResource($this->whenLoaded('timeSlot')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}

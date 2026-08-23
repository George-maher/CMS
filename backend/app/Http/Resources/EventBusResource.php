<?php

namespace App\Http\Resources;

use App\Models\EventBus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin EventBus */
class EventBusResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var int $assigned */
        $assigned = (int) ($this->registrations_count ?? $this->registrations()->count());
        /** @var int $capacity */
        $capacity = (int) $this->capacity;
        $available = max(0, $capacity - $assigned);
        $occupancy = $capacity > 0 ? min(100, round(($assigned / $capacity) * 100)) : 0;

        return [
            'id' => $this->id,
            'event_id' => $this->event_id,
            'bus_number' => $this->bus_number,
            'capacity' => $capacity,
            'driver_name' => $this->driver_name,
            'coordinator_name' => $this->coordinator_name,
            'assigned_count' => $assigned,
            'available_seats' => $available,
            'occupancy_percentage' => $occupancy,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}

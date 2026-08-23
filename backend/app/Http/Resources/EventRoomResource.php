<?php

namespace App\Http\Resources;

use App\Models\EventRoom;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin EventRoom */
class EventRoomResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event_id' => $this->event_id,
            'room_number' => $this->room_number,
            'capacity' => $this->capacity,
            'member_capacity' => $this->member_capacity,
            'is_active' => $this->is_active,
            'total_cells' => $this->when(
                array_key_exists('total_cells_count', $this->getAttributes()),
                function () {
                    /** @var array<string, mixed> $attrs */
                    $attrs = $this->getAttributes();
                    $val = $attrs['total_cells_count'];

                    if (is_int($val)) {
                        return $val;
                    }

                    return 0;
                }
            ),
            'occupied_cells' => $this->when(
                array_key_exists('occupied_cells_count', $this->getAttributes()),
                function () {
                    /** @var array<string, mixed> $attrs */
                    $attrs = $this->getAttributes();
                    $val = $attrs['occupied_cells_count'];

                    if (is_int($val)) {
                        return $val;
                    }

                    return 0;
                }
            ),
            'available_cells' => $this->when(
                array_key_exists('available_cells_count', $this->getAttributes()),
                function () {
                    /** @var array<string, mixed> $attrs */
                    $attrs = $this->getAttributes();
                    $val = $attrs['available_cells_count'];

                    if (is_int($val)) {
                        return $val;
                    }

                    return 0;
                }
            ),
            'cells' => EventRoomCellResource::collection($this->whenLoaded('cells')),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}

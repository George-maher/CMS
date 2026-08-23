<?php

namespace App\Http\Resources;

use App\Models\EventAccommodation;
use App\Models\EventRoomCell;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin EventRoomCell */
class EventRoomCellResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'room_id' => $this->room_id,
            'cell_number' => $this->cell_number,
            'type' => $this->type,
            'type_label' => $this->type === 'servant_reserved' ? 'Servant Reserved' : 'Member',
            'is_available' => $this->is_available,
            'accommodation' => $this->when(
                $this->relationLoaded('accommodation') && $this->accommodation !== null,
                function (): array {
                    /** @var EventAccommodation $acc */
                    $acc = $this->accommodation;

                    return [
                        'id' => $acc->id,
                        'registration_id' => $acc->registration_id,
                        'user' => [
                            'id' => $acc->registration->user->id,
                            'name' => strval($acc->registration->user->name),
                        ],
                    ];
                },
            ),
        ];
    }
}

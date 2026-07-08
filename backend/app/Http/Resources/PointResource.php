<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Point */
class PointResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user' => $this->when($this->user !== null, fn() => [
                'id' => $this->user->id,
                'name' => $this->user->name,
            ]),
            'added_by' => $this->when($this->addedBy !== null, fn() => [
                'id' => $this->addedBy->id,
                'name' => $this->addedBy->name,
            ]),

            'points' => $this->points,
            'type' => $this->type?->value,
            'type_label' => $this->type?->label(),
            'description' => $this->description,
            'created_at' => $this->created_at,
        ];
    }
}

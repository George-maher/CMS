<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PointResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user' => $this->when($this->user, fn() => [
                'id' => $this->user->id,
                'name' => $this->user->name,
            ]),
            'added_by' => $this->when($this->addedBy, fn() => [
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

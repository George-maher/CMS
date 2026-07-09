<?php

namespace App\Http\Resources;

use App\Models\Point;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Point */
class PointResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user' => $this->when($this->user !== null, function () {
                /** @var User $user */
                $user = $this->user;

                return [
                    'id' => $user->id,
                    'name' => $user->name,
                ];
            }),
            'added_by' => $this->when($this->addedBy !== null, function () {
                /** @var User $addedBy */
                $addedBy = $this->addedBy;

                return [
                    'id' => $addedBy->id,
                    'name' => $addedBy->name,
                ];
            }),

            'points' => $this->points,
            'type' => $this->type?->value,
            'type_label' => $this->type?->label(),
            'description' => $this->description,
            'created_at' => $this->created_at,
        ];
    }
}

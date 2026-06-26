<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ClasseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'stage_id' => $this->stage_id,
            'name' => $this->name,
            'description' => $this->description,
            'display_order' => $this->display_order,
            'member_count' => (int) ($this->member_count ?? 0),
            'servant_count' => (int) ($this->servant_count ?? 0),
            'stage' => $this->whenLoaded('stage', fn() => [
                'id' => $this->stage->id,
                'name' => $this->stage->name,
            ]),
            'created_at' => $this->created_at,
        ];
    }
}

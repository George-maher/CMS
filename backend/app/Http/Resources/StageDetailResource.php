<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StageDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'display_order' => $this->display_order,
            'classes_count' => (int) ($this->classes_count ?? 0),
            'classes' => ClasseResource::collection($this->whenLoaded('classes')),
            'created_at' => $this->created_at,
        ];
    }
}

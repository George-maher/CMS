<?php

namespace App\Http\Resources;

use App\Models\EventSession;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin EventSession */
class EventSessionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event_id' => $this->event_id,
            'title' => $this->title,
            'description' => $this->description,
            'speaker_name' => $this->speaker_name,
            'starts_at' => $this->starts_at?->toISOString(),
            'ends_at' => $this->ends_at?->toISOString(),
            'display_order' => $this->display_order,
        ];
    }
}

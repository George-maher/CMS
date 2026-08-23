<?php

namespace App\Http\Resources;

use App\Models\EventSpeaker;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin EventSpeaker */
class EventSpeakerResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event_id' => $this->event_id,
            'name' => $this->name,
            'title' => $this->title,
            'bio' => $this->bio,
        ];
    }
}

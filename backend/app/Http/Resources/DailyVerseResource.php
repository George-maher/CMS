<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DailyVerseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'verse_text' => $this->verse_text,
            'reference' => $this->reference,
            'created_by' => $this->created_by,
            'creator_name' => $this->when($this->creator, fn() => $this->creator->name),
            'is_active' => $this->is_active,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}

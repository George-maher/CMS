<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\DailyVerse */
class DailyVerseResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'verse_text' => $this->verse_text,
            'reference' => $this->reference,
            'created_by' => $this->created_by,
            'creator_name' => $this->when($this->creator !== null, function () {
                /** @var \App\Models\User $creator */
                $creator = $this->creator;
                return $creator->name;
            }),
            'is_active' => $this->is_active,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}

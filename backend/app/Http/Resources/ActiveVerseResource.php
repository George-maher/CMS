<?php

namespace App\Http\Resources;

use App\Models\DailyVerse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Minimal resource for the public/active verse endpoint.
 *
 * Only exposes the fields actually required by the frontend consumer:
 * verse_text and reference. Does NOT expose administrative metadata
 * such as created_by, creator_name, is_active, or timestamps.
 *
 * @mixin DailyVerse
 */
class ActiveVerseResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'verse_text' => $this->verse_text,
            'reference' => $this->reference,
        ];
    }
}

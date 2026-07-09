<?php

namespace App\Http\Resources;

use App\Models\Classe;
use App\Models\Stage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Classe */
class ClasseResource extends JsonResource
{
    /** @return array<string, mixed> */
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
            'stage' => $this->whenLoaded('stage', function () {
                /** @var Stage $stage */
                $stage = $this->stage;

                return [
                    'id' => $stage->id,
                    'name' => $stage->name,
                ];
            }),
            'created_at' => $this->created_at,
        ];
    }
}

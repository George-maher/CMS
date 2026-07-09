<?php

namespace App\Http\Resources;

use App\Models\AttendanceContext;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AttendanceContext */
class AttendanceContextResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'name_ar' => $this->name_ar,
            'slug' => $this->slug,
            'description' => $this->description,
            'is_active' => $this->is_active,
            'created_by' => $this->created_by,
            'creator_name' => $this->when($this->creator !== null, function () {
                /** @var User $creator */
                $creator = $this->creator;

                return $creator->name;
            }),
            'updated_by' => $this->updated_by,
            'updater_name' => $this->updater?->name,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}

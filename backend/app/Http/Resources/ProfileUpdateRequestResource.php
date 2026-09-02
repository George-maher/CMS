<?php

namespace App\Http\Resources;

use App\Models\ProfileUpdateRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ProfileUpdateRequest */
class ProfileUpdateRequestResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'church_id' => $this->church_id,
            'reviewer_id' => $this->reviewer_id,
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            'old_values' => $this->old_values,
            'new_values' => $this->new_values,
            'changes' => $this->getChangesSummary(),
            'rejection_reason' => $this->rejection_reason,
            'reviewer' => $this->when($this->relationLoaded('reviewer') && $this->reviewer, function () {
                /** @var User $reviewer */
                $reviewer = $this->reviewer;

                return [
                    'id' => $reviewer->id,
                    'name' => $reviewer->name,
                ];
            }),
            'reviewed_at' => $this->reviewed_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'user' => $this->when($this->relationLoaded('user') && $this->user, function () {
                /** @var User $user */
                $user = $this->user;

                return [
                    'id' => $user->id,
                    'member_id' => $user->member_id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role?->value,
                    'role_label' => $user->role?->label(),
                    'phone' => $user->phone,
                    'address' => $user->address,
                    'avatar' => $user->avatar,
                    'class_id' => $user->class_id,
                    'classe' => $user->relationLoaded('classe') && $user->classe ? [
                        'id' => $user->classe->id,
                        'name' => $user->classe->name,
                        'stage' => $user->classe->relationLoaded('stage') && $user->classe->stage ? [
                            'id' => $user->classe->stage->id,
                            'name' => $user->classe->stage->name,
                        ] : null,
                    ] : null,
                ];
            }),
        ];
    }
}

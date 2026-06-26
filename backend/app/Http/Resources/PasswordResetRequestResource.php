<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PasswordResetRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $this->user;

        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'email' => $this->email,
            'notes' => $this->notes,
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            'rejection_reason' => $this->rejection_reason,
            'reviewer' => $this->when($this->relationLoaded('reviewer') && $this->reviewer, fn() => [
                'id' => $this->reviewer->id,
                'name' => $this->reviewer->name,
            ]),
            'reviewed_at' => $this->reviewed_at?->toISOString(),
            'token_expires_at' => $this->token_expires_at?->toISOString(),
            'used_at' => $this->used_at?->toISOString(),
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
            'user' => $this->when($this->relationLoaded('user') && $user, fn() => [
                'id' => $user->id,
                'member_id' => $user->member_id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role?->value,
                'role_label' => $user->role?->label(),
                'phone' => $user->phone,
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
            ]),
        ];
    }
}

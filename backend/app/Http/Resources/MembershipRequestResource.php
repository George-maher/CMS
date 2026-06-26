<?php

namespace App\Http\Resources;

use App\Enums\UserRole;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MembershipRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $isAdminOrPlatform = $user && in_array($user->role, [
            UserRole::PlatformAdmin,
            UserRole::Admin,
            UserRole::AssistantAdmin,
        ], true);

        return [
            'id' => $this->id,
            'church_id' => $this->church_id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'birthday' => $this->birthday?->format('Y-m-d'),
            'address' => $this->address,
            'preferred_role' => $this->preferred_role,
            'preferred_role_label' => $this->preferred_role === 'servant' ? 'Servant' : 'Member',
            'status' => $this->status,
            'notes' => $this->notes,
            'rejection_reason' => $this->rejection_reason,
            'file_url' => $this->when($isAdminOrPlatform, fn() => $this->file_url),
            'reviewer' => $this->when($this->relationLoaded('reviewer') && $this->reviewer, fn() => [
                'id' => $this->reviewer->id,
                'name' => $this->reviewer->name,
            ]),
            'reviewed_at' => $this->reviewed_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}

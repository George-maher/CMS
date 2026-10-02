<?php

namespace App\Http\Resources;

use App\Enums\UserRole;
use App\Models\MembershipRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin MembershipRequest */
class MembershipRequestResource extends JsonResource
{
    /** @return array<string, mixed> */
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
            'file_url' => $this->when($isAdminOrPlatform, fn () => $this->file_url),
            'reviewer' => $this->when($this->relationLoaded('reviewer') && $this->reviewer, function () {
                /** @var User $reviewer */
                $reviewer = $this->reviewer;

                return [
                    'id' => $reviewer->id,
                    'name' => $reviewer->name,
                ];
            }),
            'reviewed_at' => $this->reviewed_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}

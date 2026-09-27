<?php

namespace App\Http\Resources;

use App\Contracts\FileUploadServiceInterface;
use App\Models\Church;
use App\Models\Classe;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class UserResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $authUser = $request->user();
        $isStaff = $authUser && ($authUser->isAdmin() || $authUser->isServant());
        $fileUploadService = app(FileUploadServiceInterface::class);

        return [
            'id' => $this->id,
            'member_id' => $this->when($isStaff || $authUser?->id === $this->id, fn () => $this->member_id),
            'church_id' => $this->church_id,
            // Relationship keys are always present (null when unavailable)
            // because the frontend types them as non-optional. They are read
            // only from already-loaded relations: a resource must never issue
            // its own queries, otherwise serializing a page of users becomes
            // an N+1 and an unloaded relation silently becomes null.
            'church' => $this->relationLoaded('church') && $this->church instanceof Church
                ? [
                    'id' => $this->church->id,
                    'name' => $this->church->name,
                    'slug' => $this->church->slug,
                ]
                : null,
            'name' => $this->name,
            'email' => $this->email,
            'birthday' => $this->birthday?->format('Y-m-d'),
            'age' => $this->age,
            'role' => $this->role?->value,
            'role_label' => $this->role?->label(),
            'classe' => $this->relationLoaded('classe') && $this->classe instanceof Classe
                ? new ClasseResource($this->classe)
                : null,
            'class_id' => $this->class_id,
            'servant' => $this->relationLoaded('servant') && $this->servant instanceof User
                ? [
                    'id' => $this->servant->id,
                    'name' => $this->servant->name,
                    'phone' => $this->servant->phone,
                ]
                : null,
            'assigned_members_count' => $this->when((int) $this->assigned_members_count > 0, (int) $this->assigned_members_count),
            'phone' => $this->phone,
            'address' => $this->address,
            'member_address' => $this->member_address,
            'avatar' => $this->avatar
                ? (str_starts_with($this->avatar, 'http') ? $this->avatar : $fileUploadService->url($this->avatar))
                : null,
            'is_active' => $this->is_active,
            'application_status' => $this->application_status,
            'email_verified_at' => $this->email_verified_at?->toISOString(),
            'attendance_qr_token' => $this->when($authUser?->id === $this->id, fn () => $this->attendance_qr_token),
            'total_points' => $this->total_points,
            'created_by' => $this->relationLoaded('createdBy') && $this->createdBy instanceof User
                ? [
                    'id' => $this->createdBy->id,
                    'name' => $this->createdBy->name,
                ]
                : null,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}

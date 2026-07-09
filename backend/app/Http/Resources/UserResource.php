<?php

namespace App\Http\Resources;

use App\Contracts\FileUploadServiceInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\User */
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
            'member_id' => $this->when($isStaff || $authUser?->id === $this->id, fn() => $this->member_id),
            'church_id' => $this->church_id,
            'church' => $this->when($this->relationLoaded('church') && $this->church, function () {
                /** @var \App\Models\Church $church */
                $church = $this->church;
                return [
                    'id' => $church->id,
                    'name' => $church->name,
                    'slug' => $church->slug,
                ];
            }),
            'name' => $this->name,
            'email' => $this->email,
            'birthday' => $this->birthday?->format('Y-m-d'),
            'age' => $this->age,
            'role' => $this->role?->value,
            'role_label' => $this->role?->label(),
            'classe' => new ClasseResource($this->whenLoaded('classe')),
            'class_id' => $this->class_id,
            'servant' => $this->when($this->relationLoaded('servant') && $this->servant, function () {
                /** @var \App\Models\User $servant */
                $servant = $this->servant;
                return [
                    'id' => $servant->id,
                    'name' => $servant->name,
                    'phone' => $servant->phone,
                ];
            }),
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
            'attendance_qr_token' => $this->when($authUser?->id === $this->id, fn() => $this->attendance_qr_token),
            'total_points' => $this->total_points,
            'created_by' => $this->when($this->createdBy !== null, function () {
                /** @var \App\Models\User $createdBy */
                $createdBy = $this->createdBy;
                return [
                    'id' => $createdBy->id,
                    'name' => $createdBy->name,
                ];
            }),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}

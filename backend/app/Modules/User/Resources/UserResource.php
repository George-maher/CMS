<?php

namespace App\Modules\User\Resources;

use App\Contracts\FileUploadServiceInterface;
use App\Http\Resources\ClasseResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $authUser = $request->user();
        $isStaff = $authUser && ($authUser->isAdmin() || $authUser->isServant());
        $fileUploadService = app(FileUploadServiceInterface::class);

        $classe = null;
        if ($this->relationLoaded('classe') && $this->classe) {
            $classeData = [
                'id' => $this->classe->id,
                'name' => $this->classe->name,
            ];
            if ($this->classe->relationLoaded('stage') && $this->classe->stage) {
                $classeData['stage'] = [
                    'id' => $this->classe->stage->id,
                    'name' => $this->classe->stage->name,
                ];
            }
            $classe = $classeData;
        }

        return [
            'id' => $this->id,
            'member_id' => $this->when($isStaff || $authUser?->id === $this->id, fn() => $this->member_id),
            'church_id' => $this->church_id,
            'church' => $this->when($this->relationLoaded('church') && $this->church, fn() => [
                'id' => $this->church->id,
                'name' => $this->church->name,
                'slug' => $this->church->slug,
            ]),
            'name' => $this->name,
            'email' => $this->email,
            'birthday' => $this->birthday?->format('Y-m-d'),
            'age' => $this->age,
            'role' => $this->role?->value,
            'role_label' => $this->role?->label(),
            'stage' => $classe['stage'] ?? null,
            'classe' => $classe,
            'class_id' => $this->class_id,
            'servant' => $this->when($this->relationLoaded('servant') && $this->servant, fn() => [
                'id' => $this->servant->id,
                'name' => $this->servant->name,
                'phone' => $this->servant->phone,
            ]),
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
            'created_by' => $this->when($this->createdBy, fn() => [
                'id' => $this->createdBy->id,
                'name' => $this->createdBy->name,
            ]),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}

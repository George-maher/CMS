<?php

namespace App\Modules\User\Resources;

use App\Contracts\FileUploadServiceInterface;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $authUser = $request->user();
        $isStaff = $authUser !== null && ($authUser->isAdmin() || $authUser->isServant());
        $fileUploadService = app(FileUploadServiceInterface::class);

        $classe = null;
        if ($this->relationLoaded('classe') && $this->classe !== null) {
            $classeData = [
                'id' => $this->classe->id,
                'name' => $this->classe->name,
            ];
            if ($this->classe->relationLoaded('stage') && $this->classe->stage !== null) {
                $classeData['stage'] = [
                    'id' => $this->classe->stage->id,
                    'name' => $this->classe->stage->name,
                ];
            }
            $classe = $classeData;
        }

        $church = null;
        if ($this->relationLoaded('church') && $this->church !== null) {
            $church = [
                'id' => $this->church->id,
                'name' => $this->church->name,
                'slug' => $this->church->slug,
            ];
        }

        $servant = null;
        if ($this->relationLoaded('servant') && $this->servant !== null) {
            $servant = [
                'id' => $this->servant->id,
                'name' => $this->servant->name,
                'phone' => $this->servant->phone,
            ];
        }

        $createdBy = null;
        if ($this->createdBy !== null && $this->relationLoaded('createdBy')) {
            $createdBy = [
                'id' => $this->createdBy->id,
                'name' => $this->createdBy->name,
            ];
        }

        return [
            'id' => $this->id,
            'member_id' => $this->when($isStaff || ($authUser !== null && $authUser->id === $this->id), fn () => $this->member_id),
            'church_id' => $this->church_id,
            'church' => $this->when($church !== null, fn () => $church),
            'name' => $this->name,
            'email' => $this->email,
            'birthday' => $this->birthday?->format('Y-m-d'),
            'age' => $this->age,
            'role' => $this->role?->value,
            'role_label' => $this->role?->label(),
            'stage' => $classe !== null ? ($classe['stage'] ?? null) : null,
            'classe' => $classe,
            'class_id' => $this->class_id,
            'servant' => $this->when($servant !== null, fn () => $servant),
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
            'attendance_qr_token' => $this->when($authUser !== null && $authUser->id === $this->id, fn () => $this->attendance_qr_token),
            'total_points' => $this->total_points,
            'created_by' => $this->when($createdBy !== null, fn () => $createdBy),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}

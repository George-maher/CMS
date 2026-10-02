<?php

namespace App\Http\Resources;

use App\Contracts\FileUploadServiceInterface;
use App\Models\ChurchApplication;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ChurchApplication */
class ChurchApplicationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $fileUploadService = app(FileUploadServiceInterface::class);

        return [
            'id' => $this->id,
            'church_name' => $this->church_name,
            'service_name' => $this->service_name,
            'priest_name' => $this->priest_name,
            'main_servant_name' => $this->main_servant_name,
            'priest_phone' => $this->priest_phone,
            'phone' => $this->phone,
            'address' => $this->address,
            'contact_email' => $this->contact_email,
            'front_id_url' => $this->front_id_path
                ? $fileUploadService->url($this->front_id_path)
                : null,
            'back_id_url' => $this->back_id_path
                ? $fileUploadService->url($this->back_id_path)
                : null,
            'church_permission_doc_url' => $this->church_permission_doc_path
                ? $fileUploadService->url($this->church_permission_doc_path)
                : null,
            'id_type' => $this->church_permission_doc_path ? 'church_permission' : ($this->front_id_path ? 'national_id' : null),
            'status' => $this->status,
            'admin_notes' => $this->admin_notes,
            'rejection_reason' => $this->rejection_reason,
            'reviewed_by' => $this->whenLoaded('reviewer', function () {
                /** @var User $reviewer */
                $reviewer = $this->reviewer;

                return [
                    'id' => $reviewer->id,
                    'name' => $reviewer->name,
                ];
            }),
            'reviewed_at' => $this->reviewed_at?->toISOString(),
            'rejected_at' => $this->rejected_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}

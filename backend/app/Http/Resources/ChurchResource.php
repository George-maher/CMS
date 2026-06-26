<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChurchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'service_name' => $this->service_name,
            'priest_name' => $this->priest_name,
            'main_servant_name' => $this->main_servant_name,
            'priest_phone' => $this->priest_phone,
            'phone' => $this->phone,
            'address' => $this->address,
            'contact_email' => $this->contact_email,
            'description' => $this->description,
            'is_active' => $this->is_active,
            'is_suspended' => $this->is_suspended,
            'member_count' => $this->whenCounted('users'),
            'created_at' => $this->created_at->toISOString(),
        ];
    }
}

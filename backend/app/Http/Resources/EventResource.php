<?php

namespace App\Http\Resources;

use App\Contracts\FileUploadServiceInterface;
use App\Enums\UserRole;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EventResource extends JsonResource
{
    public bool $isDetailView = false;

    public function toArray(Request $request): array
    {
        $user = $request->user();
        $isAdminOrServant = $user && in_array($user->role, [UserRole::Admin, UserRole::AssistantAdmin, UserRole::Servant], true);
        $isMember = $user && $user->role === UserRole::Member;

        $targetClasses = null;
        if ($this->relationLoaded('targets')) {
            $targetClasses = $this->targets
                ->filter(fn($t) => !$t->is_all_classes && $t->classe)
                ->map(fn($t) => ['id' => $t->classe->id, 'name' => $t->classe->name])
                ->values();
        }

        $isAllClasses = $this->is_all_classes;
        if ($this->relationLoaded('targets')) {
            $isAllClasses = $this->is_all_classes || $this->targets->contains('is_all_classes', true);
        }

        $fileUploadService = app(FileUploadServiceInterface::class);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type?->value,
            'type_label' => $this->type?->label(),
            'image' => $this->image
                ? (str_starts_with($this->image, 'http') ? $this->image : $fileUploadService->url($this->image))
                : null,
            'description' => $this->when(
                $this->isDetailView || $isAdminOrServant,
                fn() => $this->description,
            ),
            'preview' => $this->truncateDescription($this->description, 100),
            'event_date' => $this->event_date,
            'location' => $this->location,
            'is_active' => $this->is_active,
            'is_all_classes' => $isAllClasses,
            'target_classes' => $targetClasses,
            'classe' => $this->when($this->relationLoaded('classe') && $this->classe, fn() => [
                'id' => $this->classe->id,
                'name' => $this->classe->name,
            ]),
            'class_id' => $this->class_year_id ?? ($targetClasses && $targetClasses->isNotEmpty() ? $targetClasses->first()['id'] : null),
            'class_year_id' => $this->class_year_id,
            'creator' => $this->when($this->creator, fn() => [
                'id' => $this->creator->id,
                'name' => $this->creator->name,
            ]),
            'view_count' => $this->when($isAdminOrServant, fn() => $this->viewCount()),
            'views' => $this->when($isAdminOrServant && $this->relationLoaded('views'), fn() =>
                $this->views->map(fn($v) => [
                    'user' => ['id' => $v->user_id, 'name' => $v->user?->name ?? 'Unknown'],
                    'viewed_at' => $v->viewed_at,
                ])
            ),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    private function truncateDescription(?string $description, int $length = 100): ?string
    {
        if ($description === null) {
            return null;
        }

        $truncated = mb_substr($description, 0, $length);

        if (mb_strlen($description) > $length || mb_strlen($truncated) > 0) {
            $truncated .= '...';
        }

        return $truncated;
    }
}

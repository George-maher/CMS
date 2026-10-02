<?php

namespace App\Http\Resources;

use App\Contracts\FileUploadServiceInterface;
use App\Enums\EventType;
use App\Enums\UserRole;
use App\Models\Classe;
use App\Models\Event;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Event */
class EventResource extends JsonResource
{
    public bool $isDetailView = false;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $isAdminOrServant = $user && in_array($user->role, [UserRole::Admin, UserRole::AssistantAdmin, UserRole::Servant], true);
        $isMember = $user && $user->role === UserRole::Member;

        $targetClasses = null;
        if ($this->relationLoaded('targets')) {
            $targetClasses = $this->targets
                ->filter(fn ($t) => ! $t->is_all_classes && $t->classe !== null)
                ->map(fn ($t) => ['id' => $t->classe?->id, 'name' => $t->classe?->name])
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
                fn () => $this->description,
            ),
            'preview' => $this->truncateDescription($this->description, 100),
            'event_date' => $this->event_date,
            'end_date' => $this->when($this->isDetailView || $isAdminOrServant, fn () => $this->end_date?->toDateString()),
            'start_time' => $this->start_time,
            'end_time' => $this->end_time,
            'status' => $this->when($this->isDetailView || $isAdminOrServant, fn () => $this->status?->value),
            'status_label' => $this->when($this->isDetailView || $isAdminOrServant, fn () => $this->status?->label()),
            'max_capacity' => $this->when($this->isDetailView || $isAdminOrServant, fn () => $this->max_capacity),
            // Conference-specific
            'theme' => $this->when($this->type === EventType::Conference && ($this->isDetailView || $isAdminOrServant), fn () => $this->theme),
            'target_age_group' => $this->when($this->isDetailView || $isAdminOrServant, fn () => $this->target_age_group),
            'target_group' => $this->when($this->isDetailView || $isAdminOrServant, fn () => $this->target_group),
            // Trip-specific
            'destination' => $this->when($this->isDetailView || $isAdminOrServant, fn () => $this->destination),
            'departure_location' => $this->when($this->isDetailView || $isAdminOrServant, fn () => $this->departure_location),
            'departure_at' => $this->when($this->isDetailView || $isAdminOrServant, fn () => $this->departure_at),
            'return_at' => $this->when($this->isDetailView || $isAdminOrServant, fn () => $this->return_at),
            'transportation_type' => $this->when($this->isDetailView || $isAdminOrServant, fn () => $this->transportation_type),
            'coordinator_name' => $this->when($this->isDetailView || $isAdminOrServant, fn () => $this->coordinator_name),
            'coordinator_phone' => $this->when($this->isDetailView || $isAdminOrServant, fn () => $this->coordinator_phone),
            'price_per_participant' => $this->when($this->isDetailView || $isAdminOrServant, fn () => number_format((float) $this->price_per_participant, 2, '.', '')),
            'location' => $this->location,
            'is_active' => $this->is_active,
            'is_all_classes' => $isAllClasses,
            'target_classes' => $targetClasses,
            'classe' => $this->when($this->relationLoaded('classe') && $this->classe, function () {
                /** @var Classe $classe */
                $classe = $this->classe;

                return [
                    'id' => $classe->id,
                    'name' => $classe->name,
                ];
            }),
            'class_id' => $this->class_year_id ?? ($targetClasses && $targetClasses->isNotEmpty() ? $targetClasses->first()['id'] : null),
            'class_year_id' => $this->class_year_id,
            'creator' => $this->when($this->creator !== null, function () {
                /** @var User $creator */
                $creator = $this->creator;

                return [
                    'id' => $creator->id,
                    'name' => $creator->name,
                ];
            }),
            'view_count' => $this->when($isAdminOrServant, fn () => $this->viewCount()),
            'views' => $this->when($isAdminOrServant && $this->relationLoaded('views'), fn () => $this->views->map(fn ($v) => [
                'user' => ['id' => $v->user_id, 'name' => ($v->user->name ?? 'Unknown')],
                'viewed_at' => $v->viewed_at,
            ])
            ),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'registered_count' => $this->when(
                $this->relationLoaded('registrations') || $this->relationLoaded('buses'),
                fn () => $this->registeredCount(),
            ),
            'responsible_servant_id' => $this->when(
                $this->isDetailView || $isAdminOrServant,
                fn () => $this->responsible_servant_id,
            ),
            'responsible_servant' => $this->when(
                ($this->isDetailView || $isAdminOrServant) && $this->relationLoaded('responsibleServant') && $this->responsibleServant !== null,
                function () {
                    /** @var User $servant */
                    $servant = $this->responsibleServant;

                    return [
                        'id' => $servant->id,
                        'name' => $servant->name,
                        'phone' => $servant->phone ?? null,
                        'avatar' => $servant->avatar ?? null,
                    ];
                },
            ),
            'has_accommodation' => $this->when(
                $this->isDetailView || $isAdminOrServant,
                fn () => $this->hasAccommodation(),
            ),
            'rooms_count' => $this->when(
                ($this->isDetailView || $isAdminOrServant) && $this->hasAccommodation(),
                fn () => $this->totalRooms(),
            ),
            'total_capacity' => $this->when(
                ($this->isDetailView || $isAdminOrServant) && $this->hasAccommodation(),
                fn () => $this->totalCapacity(),
            ),
            'total_member_capacity' => $this->when(
                ($this->isDetailView || $isAdminOrServant) && $this->hasAccommodation(),
                fn () => $this->totalMemberCapacity(),
            ),
            'available_spaces' => $this->when(
                $this->isDetailView || $isAdminOrServant,
                fn () => $this->availableSpaces() === -1 ? null : $this->availableSpaces(),
            ),
            'occupancy_percentage' => $this->when($this->isDetailView || $isAdminOrServant, fn () => $this->occupancyPercentage()),
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

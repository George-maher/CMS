<?php

namespace App\Services;

use App\Contracts\EventRepositoryInterface;
use App\Contracts\EventServiceInterface;
use App\Contracts\NotificationServiceInterface;
use App\Enums\UserRole;
use App\Http\Resources\EventResource;
use App\Models\Event;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class EventService implements EventServiceInterface
{
    public function __construct(
        private readonly EventRepositoryInterface $eventRepository,
        private readonly NotificationServiceInterface $notificationService,
        private readonly CacheService $cacheService,
    ) {}

    public function list(int $perPage = 15, array $filters = [], ?int $userId = null, ?string $userRole = null): array
    {
        $queryFilters = $filters;

        if ($userRole === UserRole::Servant->value && $userId) {
            $user = User::find($userId);
            $classIds = $user?->getServantClassIds();

            if (!empty($classIds)) {
                $queryFilters['class_year_ids'] = $classIds;
            } else {
                $queryFilters['class_year_ids'] = [0];
            }
        }

        if ($userRole === UserRole::Member->value) {
            $queryFilters['active_only'] = true;
            if ($userId) {
                $user = User::find($userId);
                if ($user?->class_id) {
                    $queryFilters['member_class_id'] = $user->class_id;
                }
            }
        }

        $churchId = auth()->user()?->church_id;

        return $this->cacheService->rememberEventList(
            $churchId,
            md5(serialize([$perPage, $queryFilters, $userRole])),
            function () use ($perPage, $queryFilters) {
                $paginator = $this->eventRepository->paginate($perPage, $queryFilters);

                $data = EventResource::collection($paginator->items());

                return [
                    'data' => $data,
                    'meta' => [
                        'current_page' => $paginator->currentPage(),
                        'last_page' => $paginator->lastPage(),
                        'per_page' => $paginator->perPage(),
                        'total' => $paginator->total(),
                    ],
                ];
            }
        );
    }

    public function findById(int $id, ?int $userId = null, ?string $userRole = null): ?array
    {
        $event = $this->eventRepository->findById($id);

        if (!$event) {
            return null;
        }

        if ($userRole && in_array($userRole, [UserRole::Admin->value, UserRole::AssistantAdmin->value, UserRole::Servant->value], true)) {
            $event->load(['views.user.classe', 'targets.classe']);
        }

        $resource = new EventResource($event);
        $resource->isDetailView = true;

        return [
            'data' => $resource,
        ];
    }

    public function create(array $data, int $creatorId, ?string $creatorRole = null, ?int $creatorClassYearId = null): array
    {
        if (!isset($data['type'])) {
            $data['type'] = 'service';
        }

        if ($creatorRole === UserRole::Servant->value && $creatorClassYearId) {
            if (!isset($data['class_year_id']) && !isset($data['target_class_ids']) && empty($data['is_all_classes'])) {
                $data['class_year_id'] = $creatorClassYearId;
            }
        }

        $event = $this->eventRepository->create([
            ...$data,
            'created_by' => $creatorId,
        ]);

        $this->sendEventNotifications($event);

        $this->cacheService->invalidateEvents($event->church_id);

        return [
            'data' => new EventResource($event->load(['creator', 'classe', 'targets.classe'])),
        ];
    }

    private function sendEventNotifications(Event $event): void
    {
        $query = User::query()->byChurch()->active()->byRole(UserRole::Member);

        $targetClassIds = $event->targets()->where('is_all_classes', false)->pluck('class_id')->filter()->toArray();
        $hasAllClasses = $event->targets()->where('is_all_classes', true)->exists();

        if ($hasAllClasses) {
            // Send to all active members — no additional class filter needed
        } elseif (!empty($targetClassIds)) {
            $query->whereIn('class_id', $targetClassIds);
        } elseif ($event->class_year_id) {
            $query->where('class_year_id', $event->class_year_id);
        }

        $targetUserIds = $query->pluck('id')->toArray();

        if (empty($targetUserIds)) {
            return;
        }

        $churchId = $event->church_id;
        $title = 'New Event Available';
        $body = "{$event->name} — " . ($event->event_date ? $event->event_date->format('M j, Y g:i A') : 'Check it out!');

        $this->notificationService->createForEvent(
            targetUserIds: $targetUserIds,
            eventId: $event->id,
            churchId: $churchId,
            title: $title,
            body: $body,
        );
    }

    public function update(int $id, array $data): array
    {
        $updated = $this->eventRepository->update($id, $data);

        if (!$updated) {
            throw ValidationException::withMessages([
                'event' => ['Event not found.'],
            ]);
        }

        $event = $this->eventRepository->findById($id);

        $this->cacheService->invalidateEvents($event->church_id);

        return [
            'data' => new EventResource($event),
        ];
    }

    public function delete(int $id): void
    {
        $event = $this->eventRepository->findById($id);
        if (!$event) {
            throw ValidationException::withMessages([
                'event' => ['Event not found.'],
            ]);
        }

        $this->eventRepository->delete($id);

        $this->cacheService->invalidateEvents($event->church_id);
    }

    public function viewSummary(int $eventId, array|int|null $servantClassIds = null): array
    {
        $event = $this->eventRepository->findById($eventId);

        if (!$event) {
            throw ValidationException::withMessages([
                'event' => ['Event not found.'],
            ]);
        }

        $event->loadCount('views');
        $totalViews = $event->views_count;

        $targetUsers = $this->targetUsers($event);

        if ($servantClassIds !== null) {
            $servantClassIds = is_array($servantClassIds) ? $servantClassIds : [$servantClassIds];
            $targetUsers = $targetUsers->whereIn('class_id', $servantClassIds);
        }

        $totalTarget = $targetUsers->count();

        if ($servantClassIds !== null) {
            $targetViewIds = $targetUsers->pluck('id')->toArray();
            $totalViews = $event->views()->whereIn('user_id', $targetViewIds)->count();
        }

        return [
            'event_id' => $eventId,
            'total_views' => $totalViews,
            'total_target_members' => $totalTarget,
            'view_percentage' => $totalTarget > 0 ? round(($totalViews / $totalTarget) * 100, 2) : 0,
            'not_viewed_count' => max(0, $totalTarget - $totalViews),
        ];
    }

    public function viewedUsers(int $eventId, array $filters = [], array|int|null $servantClassIds = null): Collection
    {
        $event = $this->eventRepository->findById($eventId);

        if (!$event) {
            throw ValidationException::withMessages([
                'event' => ['Event not found.'],
            ]);
        }

        $viewedUserIds = $event->views()->pluck('user_id');

        if ($viewedUserIds->isEmpty()) {
            return new Collection();
        }

        $query = User::query()->byChurch()->whereIn('id', $viewedUserIds)->with('classe');

        if ($servantClassIds !== null) {
            $servantClassIds = is_array($servantClassIds) ? $servantClassIds : [$servantClassIds];
            $query->whereIn('class_id', $servantClassIds);
        }

        if (!empty($filters['class_id'])) {
            $query->where('class_id', $filters['class_id']);
        }

        if (!empty($filters['search'])) {
            $query->where('name', 'like', '%' . $filters['search'] . '%');
        }

        $users = $query->get();

        $viewedUserIds = $users->pluck('id')->toArray();
        $viewedAtMap = [];
        if (!empty($viewedUserIds)) {
            $views = $event->views()->whereIn('user_id', $viewedUserIds)->get(['user_id', 'viewed_at']);
            foreach ($views as $view) {
                $viewedAtMap[$view->user_id] = $view->viewed_at;
            }
        }

        foreach ($users as $user) {
            $user->viewed_at = $viewedAtMap[$user->id] ?? null;
        }

        return $users;
    }

    public function notViewedUsers(int $eventId, ?int $churchId = null, array $filters = [], array|int|null $servantClassIds = null): Collection
    {
        $event = $this->eventRepository->findById($eventId);

        if (!$event) {
            throw ValidationException::withMessages([
                'event' => ['Event not found.'],
            ]);
        }

        $targetUsers = $this->targetUsers($event);
        $viewedUserIds = $event->views()->pluck('user_id')->toArray();

        $query = $targetUsers->whereNotIn('id', $viewedUserIds);

        if ($servantClassIds !== null) {
            $servantClassIds = is_array($servantClassIds) ? $servantClassIds : [$servantClassIds];
            $query = $query->whereIn('class_id', $servantClassIds);
        }

        if (!empty($filters['class_id'])) {
            $query = $query->where('class_id', $filters['class_id']);
        }

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query = $query->filter(function ($user) use ($search) {
                return stripos($user->name, $search) !== false;
            });
        }

        $query->loadMissing('classe');

        return $query->values();
    }

    public function trackView(int $eventId, int $userId, ?string $ipAddress = null, ?string $userAgent = null): void
    {
        $event = $this->eventRepository->findById($eventId);

        if (!$event) {
            throw ValidationException::withMessages([
                'event' => ['Event not found.'],
            ]);
        }

        $event->trackView($userId, $ipAddress, $userAgent);
    }

    private function targetUsers(Event $event): Collection
    {
        $query = User::query()->byChurch()->byRole(UserRole::Member)->active();

        $targetClassIds = $event->targets()->where('is_all_classes', false)->pluck('class_id')->filter()->toArray();
        $hasAllClasses = $event->targets()->where('is_all_classes', true)->exists();

        if ($hasAllClasses) {
            // All members
        } elseif (!empty($targetClassIds)) {
            $query->whereIn('class_id', $targetClassIds);
        } elseif ($event->class_year_id) {
            $query->where('class_year_id', $event->class_year_id);
        }

        return $query->get();
    }
}

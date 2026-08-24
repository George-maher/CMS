<?php

namespace App\Services;

use App\Contracts\EventRepositoryInterface;
use App\Contracts\EventServiceInterface;
use App\Contracts\NotificationServiceInterface;
use App\Enums\UserRole;
use App\Http\Resources\EventResource;
use App\Models\Event;
use App\Models\EventBus;
use App\Models\EventRoom;
use App\Models\EventRoomCell;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EventService implements EventServiceInterface
{
    public function __construct(
        private readonly EventRepositoryInterface $eventRepository,
        private readonly NotificationServiceInterface $notificationService,
        private readonly CacheService $cacheService,
    ) {}

    /** @param array<string, mixed> $filters */
    /** @return array<string, mixed> */
    public function list(int $perPage = 15, array $filters = [], ?int $userId = null, ?string $userRole = null): array
    {
        $queryFilters = $filters;

        if ($userRole === UserRole::Servant->value && $userId) {
            $user = User::find($userId);
            $classIds = $user?->getServantClassIds();

            if (! empty($classIds)) {
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

        /** @var User|null $authUser */
        $authUser = auth()->user();
        $churchId = $authUser?->church_id;

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

    /** @return ?array<string, mixed> */
    public function findById(int $id, ?int $userId = null, ?string $userRole = null): ?array
    {
        $event = $this->eventRepository->findById($id);

        if (! $event) {
            return null;
        }

        if ($userRole && in_array($userRole, [UserRole::Admin->value, UserRole::AssistantAdmin->value, UserRole::Servant->value], true)) {
            $event->load(['views.user.classe', 'targets.classe', 'responsibleServant', 'rooms.cells.accommodation.registration.user']);
        }

        $resource = new EventResource($event);
        $resource->isDetailView = true;

        return [
            'data' => $resource,
        ];
    }

    /** @param array<string, mixed> $data */
    /** @return array<string, mixed> */
    public function create(array $data, int $creatorId, ?string $creatorRole = null, ?int $creatorClassYearId = null): array
    {
        /** @var array<string, mixed> $data */
        if (! isset($data['type'])) {
            $data['type'] = 'service';
        }

        if ($creatorRole === UserRole::Servant->value && $creatorClassYearId) {
            if (! isset($data['class_year_id']) && ! isset($data['target_class_ids']) && empty($data['is_all_classes'])) {
                $data['class_year_id'] = $creatorClassYearId;
            }
        }

        // Extract bulk configuration before creating event
        /** @var array<int, array{count: int, capacity: int}>|null $roomGroups */
        $roomGroups = $data['room_groups'] ?? null;
        /** @var array<int, array{capacity: int}>|null $busConfig */
        $busConfig = $data['bus_config'] ?? null;
        unset($data['room_groups'], $data['bus_config']);

        /** @var array<string, mixed> $createData */
        $createData = [
            ...$data,
            'created_by' => $creatorId,
        ];

        $event = DB::transaction(function () use ($createData, $roomGroups, $busConfig) {
            /** @var Event $event */
            $event = $this->eventRepository->create($createData);

            if (! empty($roomGroups)) {
                $this->bulkCreateRooms($event, $roomGroups);
            }

            if (! empty($busConfig)) {
                $this->bulkCreateBuses($event, $busConfig);
            }

            return $event;
        });

        $this->sendEventNotifications($event);

        $this->cacheService->invalidateEvents($event->church_id);

        return [
            'data' => new EventResource($event->load(['creator', 'classe', 'targets.classe', 'responsibleServant'])),
        ];
    }

    /**
     * Bulk-create rooms and cells from room group definitions.
     *
     * @param  array<int, array{count: int, capacity: int}>  $roomGroups
     */
    private function bulkCreateRooms(Event $event, array $roomGroups): void
    {
        $roomNumber = 1;

        foreach ($roomGroups as $group) {
            $count = (int) $group['count'];
            $capacity = (int) $group['capacity'];
            $memberCapacity = $capacity - 1; // 1 cell reserved for servant

            for ($i = 0; $i < $count; $i++) {
                /** @var EventRoom $room */
                $room = EventRoom::create([
                    'event_id' => $event->id,
                    'room_number' => $roomNumber,
                    'capacity' => $capacity,
                    'member_capacity' => max(0, $memberCapacity),
                ]);

                // Create servant-reserved cell (cell_number 1)
                EventRoomCell::create([
                    'room_id' => $room->id,
                    'cell_number' => 1,
                    'type' => 'servant_reserved',
                    'is_available' => false,
                ]);

                // Create member cells
                for ($c = 2; $c <= $capacity; $c++) {
                    EventRoomCell::create([
                        'room_id' => $room->id,
                        'cell_number' => $c,
                        'type' => 'member',
                        'is_available' => true,
                    ]);
                }

                $roomNumber++;
            }
        }
    }

    /**
     * Bulk-create buses from per-bus config.
     *
     * @param  array<int, array{capacity: int}>  $busConfig
     */
    private function bulkCreateBuses(Event $event, array $busConfig): void
    {
        $busNumber = 1;

        foreach ($busConfig as $bus) {
            $capacity = (int) $bus['capacity'];

            if ($capacity > 0) {
                EventBus::create([
                    'event_id' => $event->id,
                    'bus_number' => (string) $busNumber,
                    'capacity' => $capacity,
                ]);

                $busNumber++;
            }
        }
    }

    private function sendEventNotifications(Event $event): void
    {
        $query = User::query()->byChurch()->active()->byRole(UserRole::Member);

        $targetClassIds = $event->targets()->where('is_all_classes', false)->pluck('class_id')->filter()->toArray();
        $hasAllClasses = $event->targets()->where('is_all_classes', true)->exists();

        if ($hasAllClasses) {
            // Send to all active members — no additional class filter needed
        } elseif (! empty($targetClassIds)) {
            $query->whereIn('class_id', $targetClassIds);
        } elseif ($event->class_year_id) {
            $query->where('class_year_id', $event->class_year_id);
        }

        /** @var array<int, int> $targetUserIds */
        $targetUserIds = $query->pluck('id')->values()->toArray();

        if (empty($targetUserIds)) {
            return;
        }

        $churchId = $event->church_id ?? 0;
        $title = 'New Event Available';
        $body = strval($event->name).' — '.($event->event_date ? $event->event_date->format('M j, Y g:i A') : 'Check it out!');

        $this->notificationService->createForEvent(
            targetUserIds: $targetUserIds,
            eventId: $event->id,
            churchId: $churchId,
            title: $title,
            body: $body,
        );
    }

    /** @param array<string, mixed> $data */
    /** @return array<string, mixed> */
    public function update(int $id, array $data): array
    {
        /** @var array<string, mixed> $data */
        $updated = $this->eventRepository->update($id, $data);

        if (! $updated) {
            throw ValidationException::withMessages([
                'event' => ['Event not found.'],
            ]);
        }

        $event = $this->eventRepository->findById($id);

        if (! $event) {
            throw ValidationException::withMessages([
                'event' => ['Event not found after update.'],
            ]);
        }

        $this->cacheService->invalidateEvents($event->church_id);

        return [
            'data' => new EventResource($event),
        ];
    }

    public function delete(int $id): void
    {
        $event = $this->eventRepository->findById($id);
        if (! $event) {
            throw ValidationException::withMessages([
                'event' => ['Event not found.'],
            ]);
        }

        $this->eventRepository->delete($id);

        $this->cacheService->invalidateEvents($event->church_id);
    }

    /** @param array<int>|int|null $servantClassIds */
    /** @return array<string, mixed> */
    public function viewSummary(int $eventId, array|int|null $servantClassIds = null): array
    {
        $event = $this->eventRepository->findById($eventId);

        if (! $event) {
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

    /** @param array<string, mixed> $filters */
    /** @param array<int>|int|null $servantClassIds */
    /** @return Collection<int, User> */
    public function viewedUsers(int $eventId, array $filters = [], array|int|null $servantClassIds = null): Collection
    {
        $event = $this->eventRepository->findById($eventId);

        if (! $event) {
            throw ValidationException::withMessages([
                'event' => ['Event not found.'],
            ]);
        }

        $viewedUserIds = $event->views()->pluck('user_id');

        if ($viewedUserIds->isEmpty()) {
            return new Collection;
        }

        $query = User::query()->byChurch()->whereIn('id', $viewedUserIds)->with('classe');

        if ($servantClassIds !== null) {
            $servantClassIds = is_array($servantClassIds) ? $servantClassIds : [$servantClassIds];
            $query->whereIn('class_id', $servantClassIds);
        }

        if (! empty($filters['class_id'])) {
            $query->where('class_id', $filters['class_id']);
        }

        if (! empty($filters['search'])) {
            /** @var string $searchTerm */
            $searchTerm = $filters['search'];
            $query->where('name', 'like', '%'.$searchTerm.'%');
        }

        $users = $query->get();

        $viewedUserIds = $users->pluck('id')->toArray();
        $viewedAtMap = [];
        if (! empty($viewedUserIds)) {
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

    /** @param array<string, mixed> $filters */
    /** @param array<int>|int|null $servantClassIds */
    /** @return Collection<int, User> */
    public function notViewedUsers(int $eventId, ?int $churchId = null, array $filters = [], array|int|null $servantClassIds = null): Collection
    {
        $event = $this->eventRepository->findById($eventId);

        if (! $event) {
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

        if (! empty($filters['class_id'])) {
            $query = $query->where('class_id', $filters['class_id']);
        }

        if (! empty($filters['search'])) {
            /** @var string $searchTerm */
            $searchTerm = $filters['search'];
            $query = $query->filter(function (User $user) use ($searchTerm) {
                return stripos($user->name, $searchTerm) !== false;
            });
        }

        $query->loadMissing('classe');

        /** @var Collection<int, User> $result */
        $result = $query->values();

        return $result;
    }

    public function trackView(int $eventId, int $userId, ?string $ipAddress = null, ?string $userAgent = null): void
    {
        $event = $this->eventRepository->findById($eventId);

        if (! $event) {
            throw ValidationException::withMessages([
                'event' => ['Event not found.'],
            ]);
        }

        $event->trackView($userId, $ipAddress, $userAgent);
    }

    /** @return Collection<int, User> */
    private function targetUsers(Event $event): Collection
    {
        $query = User::query()->byChurch()->byRole(UserRole::Member)->active();

        /** @var array<int, int> $targetClassIds */
        $targetClassIds = $event->targets()->where('is_all_classes', false)->pluck('class_id')->filter()->values()->toArray();
        $hasAllClasses = $event->targets()->where('is_all_classes', true)->exists();

        if ($hasAllClasses) {
            // All members
        } elseif (! empty($targetClassIds)) {
            $query->whereIn('class_id', $targetClassIds);
        } elseif ($event->class_year_id) {
            $query->where('class_year_id', $event->class_year_id);
        }

        return $query->get();
    }

    /**
     * Events where the user is the responsible servant.
     *
     * @return array<string, mixed>
     */
    public function myAssignedEvents(int $userId, int $perPage = 15): array
    {
        $paginator = Event::query()
            ->where('responsible_servant_id', $userId)
            ->withCount([
                'registrations as pending_count' => function (Builder $q) {
                    $q->where('status', 'pending');
                },
                'registrations as confirmed_count' => function (Builder $q) {
                    $q->where('status', 'confirmed');
                },
                'registrations as approved_count' => function (Builder $q) {
                    $q->where('status', 'approved');
                },
            ])
            ->with(['rooms.cells' => function (Relation $q) {
                $q->where('type', 'member');
            }])
            ->orderByDesc('event_date')
            ->paginate($perPage);

        $data = collect($paginator->items())->map(function (Event $event) {
            /** @var array<string, mixed> $resourceData */
            $resourceData = (new EventResource($event))->resolve();

            return array_merge($resourceData, [
                'rooms_count' => $event->totalRooms(),
                'total_capacity' => $event->totalCapacity(),
                'total_member_capacity' => $event->totalMemberCapacity(),
                'pending_count' => $event->pending_count ?? 0,
                'confirmed_count' => $event->confirmed_count ?? 0,
                'approved_count' => $event->approved_count ?? 0,
            ]);
        })->values();

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
}

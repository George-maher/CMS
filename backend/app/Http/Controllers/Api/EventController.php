<?php

namespace App\Http\Controllers\Api;

use App\Contracts\EventLifecycleServiceInterface;
use App\Contracts\EventServiceInterface;
use App\Contracts\FileUploadServiceInterface;
use App\Contracts\ScopeResolverInterface;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\EventRequest;
use App\Http\Resources\EventResource;
use App\Models\Classe;
use App\Models\Event;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

class EventController extends Controller
{
    public function __construct(
        private readonly EventServiceInterface $eventService,
        private readonly EventLifecycleServiceInterface $lifecycleService,
        private readonly FileUploadServiceInterface $fileUploadService,
        private readonly ScopeResolverInterface $scopeResolver,
    ) {}

    /*
    | Lifecycle actions — publish / close / reopen / cancel / complete / duplicate
    */

    public function publish(Request $request, int $id): JsonResponse
    {
        return $this->lifecycleResponse($request, $id, fn ($event) => $this->lifecycleService->publish($event), 'Event published.');
    }

    public function closeRegistration(Request $request, int $id): JsonResponse
    {
        return $this->lifecycleResponse($request, $id, fn ($event) => $this->lifecycleService->closeRegistration($event), 'Registration closed.');
    }

    public function reopenRegistration(Request $request, int $id): JsonResponse
    {
        return $this->lifecycleResponse($request, $id, fn ($event) => $this->lifecycleService->reopenRegistration($event), 'Registration reopened.');
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        return $this->lifecycleResponse($request, $id, fn ($event) => $this->lifecycleService->cancel($event), 'Event cancelled.');
    }

    public function complete(Request $request, int $id): JsonResponse
    {
        return $this->lifecycleResponse($request, $id, fn ($event) => $this->lifecycleService->complete($event), 'Event completed.');
    }

    public function duplicate(Request $request, int $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var array{data: EventResource}|null $existing */
        $existing = $this->eventService->findById($id);

        if (! $existing) {
            return response()->json(['message' => 'Event not found.'], 404);
        }

        /** @var Event $eventModel */
        $eventModel = $existing['data']->resource;

        if ($this->cannotAccessEvent($user, $eventModel)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        /** @var int $creatorId */
        $creatorId = $user->id;

        $result = $this->lifecycleService->duplicate($eventModel, $creatorId);

        return response()->json([
            'message' => 'Event duplicated as draft.',
            'data' => $result['data'],
        ], 201);
    }

    /**
     * @param  callable(Event): Event  $action
     */
    private function lifecycleResponse(Request $request, int $id, callable $action, string $message): JsonResponse
    {
        /** @var Event|null $event */
        $event = Event::query()->find($id);

        if (! $event) {
            return response()->json(['message' => 'Event not found.'], 404);
        }

        /** @var User $user */
        $user = $request->user();

        if ($this->cannotAccessEvent($user, $event)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $updated = $action($event);

        return response()->json([
            'message' => $message,
            'data' => new EventResource($updated),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var array<string, mixed> $filters */
        $filters = $request->only(['upcoming', 'active_only', 'class_year_id', 'search', 'class_id']);
        /** @var int $perPage */
        $perPage = $request->integer('per_page', 15);
        /** @var int $userId */
        $userId = $user->id;

        $result = $this->eventService->list(
            perPage: $perPage,
            filters: $filters,
            userId: $userId,
            userRole: $user->role->value,
        );

        return response()->json([
            'data' => $result['data'],
            'meta' => $result['meta'],
        ]);
    }

    public function store(EventRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var array<string, mixed> $data */
        $data = $request->validated();

        if ($request->hasFile('image')) {
            /** @var UploadedFile $imageFile */
            $imageFile = $request->file('image');
            $data['image'] = $this->fileUploadService->upload($imageFile, 'uploads/events');
        } else {
            unset($data['image']);
        }

        if (isset($data['class_id']) && ! isset($data['class_year_id'])) {
            $data['class_year_id'] = $data['class_id'];
        }
        unset($data['class_id']);

        if ($user->isStageAdmin() && ! $this->stageAdminTargetsInScope($user, $data)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        /** @var int $creatorId */
        $creatorId = $user->id;
        /** @var array<int, int>|null $servantClassIds */
        $servantClassIds = $user->getServantClassIds();
        $result = $this->eventService->create(
            data: $data,
            creatorId: $creatorId,
            creatorRole: $user->role->value,
            creatorClassYearId: $user->role === UserRole::Servant
                ? ($servantClassIds[0] ?? null)
                : ($user->class_year_id ?? $user->class_id),
        );

        return response()->json([
            'message' => 'Event created successfully.',
            'data' => $result['data'],
        ], 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var int $userId */
        $userId = $user->id;
        $result = $this->eventService->findById($id, $userId, $user->role->value);

        if (! $result) {
            return response()->json(['message' => 'Event not found.'], 404);
        }

        /** @var array{data: EventResource, ...} $result */
        /** @var Event $eventModel */
        $eventModel = $result['data']->resource;

        if ($this->cannotAccessEvent($user, $eventModel)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        if ($user->role === UserRole::Member && ! $eventModel->is_active) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        return response()->json($result);
    }

    public function update(EventRequest $request, int $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var array{data: EventResource}|null $existing */
        $existing = $this->eventService->findById($id);

        if (! $existing) {
            return response()->json(['message' => 'Event not found.'], 404);
        }

        /** @var Event $eventModel */
        $eventModel = $existing['data']->resource;

        if ($this->cannotAccessEvent($user, $eventModel)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        /** @var array<string, mixed> $data */
        $data = $request->validated();

        if (isset($data['class_id']) && ! isset($data['class_year_id'])) {
            $data['class_year_id'] = $data['class_id'];
        }
        unset($data['class_id']);

        // Tenant + stage boundary for retargeting: the exists:classes rule is
        // global, so without this a foreign-church class id would be accepted
        // and a stage admin could re-point events at another stage's classes.
        if (! $user->isPlatformAdmin()) {
            $requestedClassIds = [];
            if (is_numeric($data['class_year_id'] ?? null)) {
                $requestedClassIds[] = (int) $data['class_year_id'];
            }
            if (is_array($data['target_class_ids'] ?? null)) {
                foreach ($data['target_class_ids'] as $targetClassId) {
                    if (is_numeric($targetClassId)) {
                        $requestedClassIds[] = (int) $targetClassId;
                    }
                }
            }
            foreach ($requestedClassIds as $classId) {
                if (Classe::find($classId) === null) {
                    return response()->json(['message' => 'Forbidden.'], 403);
                }
            }
            if ($user->isStageAdmin() && ! $this->stageAdminTargetsInScope($user, $data)) {
                return response()->json(['message' => 'Forbidden.'], 403);
            }
        }

        if ($request->hasFile('image')) {
            if ($eventModel->image ?? null) {
                $this->fileUploadService->delete($eventModel->image);
            }
            /** @var UploadedFile $uploadedImage */
            $uploadedImage = $request->file('image');
            $data['image'] = $this->fileUploadService->upload($uploadedImage, 'uploads/events');
        } elseif ($request->boolean('remove_image')) {
            if ($eventModel->image ?? null) {
                $this->fileUploadService->delete($eventModel->image);
            }
            $data['image'] = null;
        }

        unset($data['remove_image']);

        $result = $this->eventService->update($id, $data);

        return response()->json([
            'message' => 'Event updated successfully.',
            'data' => $result['data'],
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var array{data: EventResource}|null $existing */
        $existing = $this->eventService->findById($id);

        if (! $existing) {
            return response()->json(['message' => 'Event not found.'], 404);
        }

        /** @var Event $eventModel */
        $eventModel = $existing['data']->resource;

        if ($this->cannotAccessEvent($user, $eventModel)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $this->eventService->delete($id);

        return response()->json([
            'message' => 'Event deleted successfully.',
        ]);
    }

    private function servantCannotAccessEvent(User $user, Event $event): bool
    {
        if ($user->role !== UserRole::Servant) {
            return false;
        }

        $hasAccess = $event->is_all_classes || $event->targets()->where('is_all_classes', true)->exists();
        /** @var array<int, int> $servantClassIds */
        $servantClassIds = $user->classes()->pluck('classes.id')->toArray();
        /** @var array<int, int> $targetClassIds */
        $targetClassIds = $event->targets()->where('is_all_classes', false)->pluck('class_id')->filter()->toArray();
        $overlap = ! empty($targetClassIds) && ! empty(array_intersect($servantClassIds, $targetClassIds));

        return ! $hasAccess && ! $overlap && $event->class_year_id !== null && $event->class_year_id !== $user->class_year_id;
    }

    private function stageAdminCannotAccessEvent(User $user, Event $event): bool
    {
        $hasAllClasses = $event->is_all_classes
            || $event->targets()->where('is_all_classes', true)->exists();

        if ($hasAllClasses) {
            return false;
        }

        /** @var array<int, int> $allowedClassIds */
        $allowedClassIds = $this->scopeResolver->allowedClassIds($user) ?? [];
        /** @var array<int, int> $targetClassIds */
        $targetClassIds = $event->targets()->where('is_all_classes', false)->pluck('class_id')->filter()->values()->toArray();
        $overlap = ! empty($allowedClassIds) && ! empty($targetClassIds) && ! empty(array_intersect($allowedClassIds, $targetClassIds));

        if ($overlap) {
            return false;
        }

        return $event->class_year_id !== null && ! in_array((int) $event->class_year_id, $allowedClassIds, true);
    }

    private function cannotAccessEvent(User $user, Event $event): bool
    {
        if ($user->isServant()) {
            return $this->servantCannotAccessEvent($user, $event);
        }

        if ($user->isStageAdmin()) {
            return $this->stageAdminCannotAccessEvent($user, $event);
        }

        return false;
    }

    /**
     * Stage admins may only create events targeting classes inside their own
     * stage. Church-wide (is_all_classes) events stay admin/assistant-only.
     *
     * @param  array<string, mixed>  $data
     */
    private function stageAdminTargetsInScope(User $stageAdmin, array $data): bool
    {
        if (! empty($data['is_all_classes'])) {
            return false;
        }

        /** @var array<int, int> $allowedClassIds */
        $allowedClassIds = $this->scopeResolver->allowedClassIds($stageAdmin) ?? [];
        /** @var list<int> $targetIds */
        $targetIds = [];

        if (isset($data['target_class_ids']) && is_array($data['target_class_ids'])) {
            foreach ($data['target_class_ids'] as $classId) {
                if (is_numeric($classId)) {
                    $targetIds[] = (int) $classId;
                }
            }
        }

        if (is_numeric($data['class_year_id'] ?? null)) {
            $targetIds[] = (int) $data['class_year_id'];
        }

        foreach ($targetIds as $classId) {
            if (! in_array($classId, $allowedClassIds, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Events where the authenticated user is the responsible servant.
     */
    public function myAssigned(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var int $perPage */
        $perPage = $request->integer('per_page', 15);

        $result = $this->eventService->myAssignedEvents((int) $user->id, $perPage);

        return response()->json($result);
    }
}

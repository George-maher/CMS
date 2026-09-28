<?php

namespace App\Http\Controllers\Api;

use App\Contracts\EventServiceInterface;
use App\Contracts\FileUploadServiceInterface;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\EventRequest;
use App\Models\Event;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class EventController extends Controller
{
    public function __construct(
        private readonly EventServiceInterface $eventService,
        private readonly FileUploadServiceInterface $fileUploadService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
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
        /** @var \App\Models\User $user */
        $user = $request->user();
        /** @var array<string, mixed> $data */
        $data = $request->validated();

        if ($request->hasFile('image')) {
            /** @var \Illuminate\Http\UploadedFile $imageFile */
            $imageFile = $request->file('image');
            $data['image'] = $this->fileUploadService->upload($imageFile, 'uploads/events');
        } else {
            unset($data['image']);
        }

        if (isset($data['class_id']) && !isset($data['class_year_id'])) {
            $data['class_year_id'] = $data['class_id'];
        }
        unset($data['class_id']);

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
        try {
            /** @var \App\Models\User $user */
            $user = $request->user();
            /** @var int $userId */
            $userId = $user->id;
            $result = $this->eventService->findById($id, $userId, $user->role->value);

            if (!$result) {
                return response()->json(['message' => 'Event not found.'], 404);
            }

            /** @var array{data: \App\Http\Resources\EventResource, ...} $result */
            /** @var \App\Models\Event $eventModel */
            $eventModel = $result['data']->resource;

            if ($this->servantCannotAccessEvent($user, $eventModel)) {
                return response()->json(['message' => 'Forbidden.'], 403);
            }

            if ($user->role === UserRole::Member && !$eventModel->is_active) {
                return response()->json(['message' => 'Forbidden.'], 403);
            }

            return response()->json($result);
        } catch (\Throwable $e) {
            error_log('EVENT_SHOW_ERROR: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            throw $e;
        }
    }

    public function update(EventRequest $request, int $id): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();
        /** @var array{data: \App\Http\Resources\EventResource}|null $existing */
        $existing = $this->eventService->findById($id);

        if (!$existing) {
            return response()->json(['message' => 'Event not found.'], 404);
        }

        /** @var \App\Models\Event $eventModel */
        $eventModel = $existing['data']->resource;

        if ($this->servantCannotAccessEvent($user, $eventModel)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        /** @var array<string, mixed> $data */
        $data = $request->validated();

        if (isset($data['class_id']) && !isset($data['class_year_id'])) {
            $data['class_year_id'] = $data['class_id'];
        }
        unset($data['class_id']);

        if ($request->hasFile('image')) {
            if ($eventModel->image ?? null) {
                $this->fileUploadService->delete($eventModel->image);
            }
            /** @var \Illuminate\Http\UploadedFile $uploadedImage */
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
        /** @var \App\Models\User $user */
        $user = $request->user();
        /** @var array{data: \App\Http\Resources\EventResource}|null $existing */
        $existing = $this->eventService->findById($id);

        if (!$existing) {
            return response()->json(['message' => 'Event not found.'], 404);
        }

        /** @var \App\Models\Event $eventModel */
        $eventModel = $existing['data']->resource;

        if ($this->servantCannotAccessEvent($user, $eventModel)) {
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
        $overlap = !empty($targetClassIds) && !empty(array_intersect($servantClassIds, $targetClassIds));

        return !$hasAccess && !$overlap && $event->class_year_id !== null && $event->class_year_id !== $user->class_year_id;
    }
}

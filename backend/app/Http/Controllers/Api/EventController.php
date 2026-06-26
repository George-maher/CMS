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
        $user = $request->user();
        $filters = $request->only(['upcoming', 'active_only', 'class_year_id', 'search', 'class_id']);

        $result = $this->eventService->list(
            perPage: $request->input('per_page', 15),
            filters: $filters,
            userId: $user->id,
            userRole: $user->role->value,
        );

        return response()->json([
            'data' => $result['data'],
            'meta' => $result['meta'],
        ]);
    }

    public function store(EventRequest $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validated();

        if ($request->hasFile('image')) {
            $data['image'] = $this->fileUploadService->upload($request->file('image'), 'uploads/events', $this->fileUploadService->publicDisk());
        } else {
            unset($data['image']);
        }

        if (isset($data['class_id']) && !isset($data['class_year_id'])) {
            $data['class_year_id'] = $data['class_id'];
        }
        unset($data['class_id']);

        $result = $this->eventService->create(
            data: $data,
            creatorId: $user->id,
            creatorRole: $user->role->value,
            creatorClassYearId: $user->role === UserRole::Servant
                ? ($user->getServantClassIds()[0] ?? null)
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
            $user = $request->user();
            $result = $this->eventService->findById($id, $user->id, $user->role->value);

            if (!$result) {
                return response()->json(['message' => 'Event not found.'], 404);
            }

            $event = $result['data']->resource;

            if ($this->servantCannotAccessEvent($user, $event)) {
                return response()->json(['message' => 'Forbidden.'], 403);
            }

            if ($user->role === UserRole::Member && !$event->is_active) {
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
        $user = $request->user();
        $existing = $this->eventService->findById($id);

        if (!$existing) {
            return response()->json(['message' => 'Event not found.'], 404);
        }

        $eventModel = $existing['data']->resource;

        if ($this->servantCannotAccessEvent($user, $eventModel)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $data = $request->validated();

        if (isset($data['class_id']) && !isset($data['class_year_id'])) {
            $data['class_year_id'] = $data['class_id'];
        }
        unset($data['class_id']);

        if ($request->hasFile('image')) {
            if ($eventModel->image ?? null) {
                $this->fileUploadService->delete($eventModel->image, $this->fileUploadService->publicDisk());
            }
            $data['image'] = $this->fileUploadService->upload($request->file('image'), 'uploads/events', $this->fileUploadService->publicDisk());
        } elseif ($request->boolean('remove_image')) {
            if ($eventModel->image ?? null) {
                $this->fileUploadService->delete($eventModel->image, $this->fileUploadService->publicDisk());
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
        $user = $request->user();
        $existing = $this->eventService->findById($id);

        if (!$existing) {
            return response()->json(['message' => 'Event not found.'], 404);
        }

        $eventModel = $existing['data']->resource;

        if ($this->servantCannotAccessEvent($user, $eventModel)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        if ($eventModel->image ?? null) {
            $this->fileUploadService->delete($eventModel->image, $this->fileUploadService->publicDisk());
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
        $servantClassIds = $user->classes()->pluck('classes.id')->toArray();
        $targetClassIds = $event->targets()->where('is_all_classes', false)->pluck('class_id')->filter()->toArray();
        $overlap = !empty($targetClassIds) && !empty(array_intersect($servantClassIds, $targetClassIds));

        return !$hasAccess && !$overlap && $event->class_year_id && $event->class_year_id !== $user->class_year_id;
    }
}

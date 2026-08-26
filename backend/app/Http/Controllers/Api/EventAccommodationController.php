<?php

namespace App\Http\Controllers\Api;

use App\Contracts\EventAccommodationServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Resources\EventRoomResource;
use App\Models\Event;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class EventAccommodationController extends Controller
{
    public function __construct(
        private readonly EventAccommodationServiceInterface $accommodationService,
    ) {}

    public function dashboard(int $id): JsonResponse
    {
        $event = $this->findEvent($id);

        if (! $event) {
            return response()->json(['message' => 'Event not found.'], 404);
        }

        return response()->json([
            'data' => $this->accommodationService->dashboard($event),
        ]);
    }

    public function roomsIndex(Request $request, int $id): JsonResponse
    {
        $event = $this->findEvent($id);

        if (! $event) {
            return response()->json(['message' => 'Event not found.'], 404);
        }

        /** @var int $perPage */
        $perPage = $request->integer('per_page', 20);

        $paginator = $this->accommodationService->listRooms($event, $perPage);

        return response()->json([
            'data' => EventRoomResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function roomsStore(Request $request, int $id): JsonResponse
    {
        $event = $this->findEvent($id);

        if (! $event) {
            return response()->json(['message' => 'Event not found.'], 404);
        }

        /** @var array<string, mixed> $validated */
        $validated = $request->validate([
            'room_groups' => ['required', 'array', 'min:1'],
            'room_groups.*.count' => ['required', 'integer', 'min:1', 'max:1000'],
            'room_groups.*.capacity' => ['required', 'integer', 'min:2', 'max:100'],
        ]);

        /** @var array<int, array{count: int, capacity: int}> $roomGroups */
        $roomGroups = (array) $validated['room_groups'];

        $result = $this->accommodationService->bulkCreateRooms($event, $roomGroups);

        return response()->json([
            'message' => "Created {$result['rooms_created']} rooms with {$result['cells_created']} cells.",
            'data' => $result,
        ], 201);
    }

    public function roomsShow(int $id, int $roomId): JsonResponse
    {
        $event = $this->findEvent($id);

        if (! $event) {
            return response()->json(['message' => 'Event not found.'], 404);
        }

        try {
            $room = $this->accommodationService->getRoom($event, $roomId);

            return response()->json([
                'data' => new EventRoomResource($room),
            ]);
        } catch (ValidationException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }
    }

    public function roomsUpdate(Request $request, int $id, int $roomId): JsonResponse
    {
        $event = $this->findEvent($id);

        if (! $event) {
            return response()->json(['message' => 'Event not found.'], 404);
        }

        /** @var array<string, mixed> $data */
        $data = $request->validate([
            'capacity' => ['sometimes', 'integer', 'min:2', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        try {
            $room = $this->accommodationService->updateRoom($event, $roomId, $data);

            return response()->json([
                'message' => 'Room updated.',
                'data' => new EventRoomResource($room),
            ]);
        } catch (ValidationException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function roomsDestroy(int $id, int $roomId): JsonResponse
    {
        $event = $this->findEvent($id);

        if (! $event) {
            return response()->json(['message' => 'Event not found.'], 404);
        }

        try {
            $this->accommodationService->deleteRoom($event, $roomId);

            return response()->json(['message' => 'Room deleted.']);
        } catch (ValidationException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function assign(Request $request, int $id): JsonResponse
    {
        $event = $this->findEvent($id);

        if (! $event) {
            return response()->json(['message' => 'Event not found.'], 404);
        }

        /** @var int $registrationId */
        $registrationId = $request->integer('registration_id');
        /** @var int $cellId */
        $cellId = $request->integer('cell_id');

        $request->validate([
            'registration_id' => ['required', 'integer', 'exists:event_registrations,id'],
            'cell_id' => ['required', 'integer', 'exists:event_room_cells,id'],
        ]);

        try {
            $accommodation = $this->accommodationService->assignAccommodation(
                $event,
                $registrationId,
                $cellId,
            );

            return response()->json([
                'message' => 'Accommodation assigned.',
                'data' => $accommodation->load(['cell.room', 'registration.user']),
            ], 201);
        } catch (ValidationException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function removeAccommodation(int $id, int $registrationId): JsonResponse
    {
        $event = $this->findEvent($id);

        if (! $event) {
            return response()->json(['message' => 'Event not found.'], 404);
        }

        try {
            $this->accommodationService->removeAccommodation($event, $registrationId);

            return response()->json(['message' => 'Accommodation removed.']);
        } catch (ValidationException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function unaccommodated(Request $request, int $id): JsonResponse
    {
        $event = $this->findEvent($id);

        if (! $event) {
            return response()->json(['message' => 'Event not found.'], 404);
        }

        /** @var int $perPage */
        $perPage = $request->integer('per_page', 20);

        $result = $this->accommodationService->unaccommodated($event, $perPage);

        return response()->json($result);
    }

    /**
     * Member-facing accommodation view: own status + (approval-gated) rooms.
     */
    public function memberView(Request $request, int $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $event = $this->findEvent($id);

        if (! $event) {
            return response()->json(['message' => 'Event not found.'], 404);
        }

        return response()->json([
            'data' => $this->accommodationService->viewFor($event, (int) $user->id),
        ]);
    }

    /**
     * An approved member selects an available member cell for themselves.
     * Identity is derived from the authenticated user — never from payload.
     */
    public function selectCell(Request $request, int $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $event = $this->findEvent($id);

        if (! $event) {
            return response()->json(['message' => 'Event not found.'], 404);
        }

        $validated = $request->validate([
            'cell_id' => ['required', 'integer', 'exists:event_room_cells,id'],
        ]);

        /** @var array{cell_id: int} $validated */
        $cellId = (int) $validated['cell_id'];

        try {
            $accommodation = $this->accommodationService->selectCell($event, (int) $user->id, $cellId);

            return response()->json([
                'message' => 'Cell assigned.',
                'data' => [
                    'cell_id' => $accommodation->cell_id,
                    'room_number' => $accommodation->cell?->room?->room_number,
                    'cell_number' => $accommodation->cell?->cell_number,
                ],
            ], 201);
        } catch (ValidationException $e) {
            $errors = $e->errors();
            $first = reset($errors);

            /** @var array<int, string> $first */
            return response()->json([
                'message' => is_array($first) && $first !== [] ? strval($first[0]) : __('eventMgmt.actionFailed'),
            ], 422);
        }
    }

    private function findEvent(int $id): ?Event
    {
        return Event::query()->find($id);
    }
}

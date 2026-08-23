<?php

namespace App\Http\Controllers\Api;

use App\Contracts\EventScheduleServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEventBusRequest;
use App\Http\Resources\EventBusResource;
use App\Models\Event;
use Illuminate\Http\JsonResponse;

class EventBusController extends Controller
{
    public function __construct(
        private readonly EventScheduleServiceInterface $scheduleService,
    ) {}

    public function index(int $id): JsonResponse
    {
        $event = Event::query()->find($id);

        if (! $event) {
            return response()->json(['message' => 'Event not found.'], 404);
        }

        $buses = $event->buses()->withCount('registrations')->orderBy('id')->get();

        return response()->json([
            'data' => EventBusResource::collection($buses),
        ]);
    }

    public function store(StoreEventBusRequest $request, int $id): JsonResponse
    {
        $event = Event::query()->find($id);

        if (! $event) {
            return response()->json(['message' => 'Event not found.'], 404);
        }

        /** @var array<string, mixed> $data */
        $data = $request->validated();

        $bus = $this->scheduleService->createBus($event, $data);

        return response()->json([
            'message' => 'Bus created.',
            'data' => new EventBusResource($bus->loadCount('registrations')),
        ], 201);
    }

    public function update(StoreEventBusRequest $request, int $id, int $busId): JsonResponse
    {
        $event = Event::query()->find($id);

        if (! $event) {
            return response()->json(['message' => 'Event not found.'], 404);
        }

        /** @var array<string, mixed> $data */
        $data = $request->validated();

        $bus = $this->scheduleService->updateBus($event, $busId, $data);

        return response()->json([
            'message' => 'Bus updated.',
            'data' => new EventBusResource($bus->loadCount('registrations')),
        ]);
    }

    public function destroy(int $id, int $busId): JsonResponse
    {
        $event = Event::query()->find($id);

        if (! $event) {
            return response()->json(['message' => 'Event not found.'], 404);
        }

        $this->scheduleService->deleteBus($event, $busId);

        return response()->json([
            'message' => 'Bus deleted.',
        ]);
    }
}

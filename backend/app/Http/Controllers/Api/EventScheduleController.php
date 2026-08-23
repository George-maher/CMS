<?php

namespace App\Http\Controllers\Api;

use App\Contracts\EventScheduleServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEventSpeakerRequest;
use App\Http\Resources\EventSessionResource;
use App\Http\Resources\EventSpeakerResource;
use App\Models\Event;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class EventScheduleController extends Controller
{
    public function __construct(
        private readonly EventScheduleServiceInterface $scheduleService,
    ) {}

    /*
    | Sessions
    */

    public function sessionsIndex(int $id): JsonResponse
    {
        $event = Event::query()->find($id);

        if (! $event) {
            return response()->json(['message' => 'Event not found.'], 404);
        }

        return response()->json([
            'data' => EventSessionResource::collection($event->sessions),
        ]);
    }

    public function sessionsStore(Request $request, int $id): JsonResponse
    {
        $event = Event::query()->find($id);

        if (! $event) {
            return response()->json(['message' => 'Event not found.'], 404);
        }

        /** @var array<string, mixed> $data */
        $data = $this->validatedSession($request);

        $session = $this->scheduleService->createSession($event, $data);

        return response()->json([
            'message' => 'Session created.',
            'data' => new EventSessionResource($session),
        ], 201);
    }

    public function sessionsUpdate(Request $request, int $id, int $sessionId): JsonResponse
    {
        $event = Event::query()->find($id);

        if (! $event) {
            return response()->json(['message' => 'Event not found.'], 404);
        }

        /** @var array<string, mixed> $data */
        $data = $this->validatedSession($request);

        $session = $this->scheduleService->updateSession($event, $sessionId, $data);

        return response()->json([
            'message' => 'Session updated.',
            'data' => new EventSessionResource($session),
        ]);
    }

    public function sessionsDestroy(int $id, int $sessionId): JsonResponse
    {
        $event = Event::query()->find($id);

        if (! $event) {
            return response()->json(['message' => 'Event not found.'], 404);
        }

        $this->scheduleService->deleteSession($event, $sessionId);

        return response()->json([
            'message' => 'Session deleted.',
        ]);
    }

    /*
    | Speakers
    */

    public function speakersIndex(int $id): JsonResponse
    {
        $event = Event::query()->find($id);

        if (! $event) {
            return response()->json(['message' => 'Event not found.'], 404);
        }

        return response()->json([
            'data' => EventSpeakerResource::collection($event->speakers),
        ]);
    }

    public function speakersStore(StoreEventSpeakerRequest $request, int $id): JsonResponse
    {
        $event = Event::query()->find($id);

        if (! $event) {
            return response()->json(['message' => 'Event not found.'], 404);
        }

        /** @var array<string, mixed> $data */
        $data = $request->validated();

        $speaker = $this->scheduleService->createSpeaker($event, $data);

        return response()->json([
            'message' => 'Speaker added.',
            'data' => new EventSpeakerResource($speaker),
        ], 201);
    }

    public function speakersUpdate(StoreEventSpeakerRequest $request, int $id, int $speakerId): JsonResponse
    {
        $event = Event::query()->find($id);

        if (! $event) {
            return response()->json(['message' => 'Event not found.'], 404);
        }

        /** @var array<string, mixed> $data */
        $data = $request->validated();

        $speaker = $this->scheduleService->updateSpeaker($event, $speakerId, $data);

        return response()->json([
            'message' => 'Speaker updated.',
            'data' => new EventSpeakerResource($speaker),
        ]);
    }

    public function speakersDestroy(int $id, int $speakerId): JsonResponse
    {
        $event = Event::query()->find($id);

        if (! $event) {
            return response()->json(['message' => 'Event not found.'], 404);
        }

        $this->scheduleService->deleteSpeaker($event, $speakerId);

        return response()->json([
            'message' => 'Speaker removed.',
        ]);
    }

    /**
     * Validate session data manually (route uses numeric ids, no model binding).
     *
     * @return array<string, mixed>
     */
    private function validatedSession(Request $request): array
    {
        $isUpdate = $request->isMethod('PATCH') || $request->isMethod('PUT');

        $rules = [
            'title' => ($isUpdate ? ['sometimes', 'string', 'max:255'] : ['required', 'string', 'max:255']),
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'speaker_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'starts_at' => ['sometimes', 'nullable', 'date'],
            'ends_at' => ['sometimes', 'nullable', 'date', 'after_or_equal:starts_at'],
            'display_order' => ['sometimes', 'integer', 'min:0', 'max:10000'],
        ];

        /** @var \Illuminate\Validation\Validator $validator */
        $validator = Validator::make($request->all(), $rules);

        try {
            $validated = $validator->validate();
        } catch (ValidationException $e) {
            throw $e;
        }

        /** @var array<string, mixed> $validated */
        return $validated;
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Contracts\EventReservationServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Resources\EventRegistrationResource;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class EventReservationController extends Controller
{
    public function __construct(
        private readonly EventReservationServiceInterface $reservationService,
    ) {}

    public function approve(Request $request, int $eventId, int $regId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $event = Event::query()->find($eventId);

        if (! $event) {
            return response()->json(['message' => 'Event not found.'], 404);
        }

        $registration = EventRegistration::query()
            ->where('event_id', $event->id)
            ->find($regId);

        if (! $registration) {
            return response()->json(['message' => 'Registration not found.'], 404);
        }

        try {
            $updated = $this->reservationService->approve($event, $registration, $user);

            return response()->json([
                'message' => 'Reservation approved.',
                'data' => new EventRegistrationResource($updated->load(['user.classe', 'bus'])),
            ]);
        } catch (ValidationException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function reject(Request $request, int $eventId, int $regId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $event = Event::query()->find($eventId);

        if (! $event) {
            return response()->json(['message' => 'Event not found.'], 404);
        }

        $registration = EventRegistration::query()
            ->where('event_id', $event->id)
            ->find($regId);

        if (! $registration) {
            return response()->json(['message' => 'Registration not found.'], 404);
        }

        $reasonRaw = $request->input('reason');
        /** @var string|null $reason */
        $reason = is_string($reasonRaw) ? $reasonRaw : null;

        try {
            $updated = $this->reservationService->reject($event, $registration, $user, $reason);

            return response()->json([
                'message' => 'Reservation rejected.',
                'data' => new EventRegistrationResource($updated->load(['user.classe', 'bus'])),
            ]);
        } catch (ValidationException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}

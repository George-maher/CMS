<?php

namespace App\Http\Controllers\Api;

use App\Contracts\EventRegistrationServiceInterface;
use App\Contracts\EventScheduleServiceInterface;
use App\Enums\RegistrationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\EventCheckInRequest;
use App\Http\Requests\StoreEventRegistrationRequest;
use App\Http\Requests\UpdateEventRegistrationRequest;
use App\Http\Resources\EventRegistrationResource;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EventRegistrationController extends Controller
{
    public function __construct(
        private readonly EventRegistrationServiceInterface $registrationService,
        private readonly EventScheduleServiceInterface $scheduleService,
    ) {}

    public function index(Request $request, int $id): JsonResponse
    {
        $event = $this->findEvent($id);

        if (! $event) {
            return response()->json(['message' => 'Event not found.'], 404);
        }

        /** @var array<string, mixed> $filters */
        $filters = $request->only(['status', 'payment_status', 'attendance_status', 'bus_id', 'without_bus', 'search']);
        /** @var int $perPage */
        $perPage = $request->integer('per_page', 20);

        $result = $this->registrationService->listForEvent($event, $perPage, $filters);

        return response()->json($result);
    }

    public function store(StoreEventRegistrationRequest $request, int $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var int $registeredBy */
        $registeredBy = $user->id;

        $event = $this->findEvent($id);

        if (! $event) {
            return response()->json(['message' => 'Event not found.'], 404);
        }

        /** @var int $memberId */
        $memberId = $request->integer('user_id');
        $notesRaw = $request->input('notes');
        $notes = is_string($notesRaw) ? $notesRaw : null;

        $registration = $this->registrationService->register(
            $event,
            $memberId,
            $registeredBy,
            $notes,
        );

        // Responsible-servant notification is created inside the registration
        // service (notifyRegistration); avoid duplicate notifications here.
        return response()->json([
            'message' => 'Reservation request submitted successfully.',
            'data' => new EventRegistrationResource($registration->load(['user.classe', 'event'])),
        ], 201);
    }

    public function getEventReservationRequests(int $id): JsonResponse
    {
        $event = $this->findEvent($id);

        if (! $event) {
            return response()->json(['message' => 'Event not found.'], 404);
        }

        $registrations = EventRegistration::query()
            ->where('event_id', $event->id)
            ->whereIn('status', [
                RegistrationStatus::Booked->value,
                RegistrationStatus::NotReserved->value,
                RegistrationStatus::Thinking->value,
            ])
            ->with(['user.classe'])
            ->get();

        return response()->json([
            'data' => EventRegistrationResource::collection($registrations->load(['user.classe', 'event'])),
        ]);
    }

    public function submitMemberReservationRequest(Request $request, int $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $event = $this->findEvent($id);

        if (! $event) {
            return response()->json(['message' => 'Event not found.'], 404);
        }

        /** @var string $status */
        $status = $request->input('status');
        $bookedWith = $request->input('booked_with');
        $amountPaid = $request->input('amount_paid');
        $medicalNotes = $request->input('medical_notes');
        $medicationName = $request->input('medication_name');
        $medicationTime = $request->input('medication_time');

        if (! in_array($status, [
            RegistrationStatus::Booked->value,
            RegistrationStatus::NotReserved->value,
            RegistrationStatus::Thinking->value,
        ], true)) {
            return response()->json(['message' => 'Invalid reservation status.'], 422);
        }

        /** @var string|null $bookedWith */
        $bookedWith = is_string($bookedWith) ? $bookedWith : null;
        /** @var string|null $amountPaid */
        $amountPaid = is_string($amountPaid) ? $amountPaid : null;
        /** @var string|null $medicalNotes */
        $medicalNotes = is_string($medicalNotes) ? $medicalNotes : null;
        /** @var string|null $medicationName */
        $medicationName = is_string($medicationName) ? $medicationName : null;
        /** @var string|null $medicationTime */
        $medicationTime = is_string($medicationTime) ? $medicationTime : null;

        /** @var int $userId */
        $userId = $user->id;

        $registration = $this->registrationService->submitMemberReservationRequest(
            $event->id,
            $userId,
            $status,
            $bookedWith,
            $amountPaid,
            $medicalNotes,
            $medicationName,
            $medicationTime
        );

        return response()->json([
            'message' => 'Reservation request submitted successfully.',
            'data' => new EventRegistrationResource($registration->load(['user.classe', 'event'])),
        ], 201);
    }

    public function my(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var int $userId */
        $userId = $user->id;
        $result = $this->registrationService->myRegistrations($userId);

        return response()->json($result);
    }

    public function selfRegister(Request $request, int $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var int $userId */
        $userId = $user->id;

        $event = $this->findEvent($id);

        if (! $event) {
            return response()->json(['message' => 'Event not found.'], 404);
        }

        $registration = $this->registrationService->registerSelf($event, $userId);

        return response()->json([
            'message' => 'Reservation request submitted successfully.',
            'data' => new EventRegistrationResource($registration->load(['user.classe', 'event'])),
        ], 201);
    }

    public function confirm(int $id, int $regId): JsonResponse
    {
        [$event, $registration] = $this->resolveRegistration($id, $regId);

        if ($event === null || $registration === null) {
            return $this->notFoundResponse($event === null ? 'Event' : 'Registration');
        }

        $updated = $this->registrationService->confirm($registration);

        return response()->json([
            'message' => 'Registration confirmed.',
            'data' => new EventRegistrationResource($updated),
        ]);
    }

    public function cancel(int $id, int $regId): JsonResponse
    {
        [$event, $registration] = $this->resolveRegistration($id, $regId);

        if ($event === null || $registration === null) {
            return $this->notFoundResponse($event === null ? 'Event' : 'Registration');
        }

        $updated = $this->registrationService->cancel($registration);

        return response()->json([
            'message' => 'Registration cancelled.',
            'data' => new EventRegistrationResource($updated),
        ]);
    }

    public function waitlist(int $id, int $regId): JsonResponse
    {
        [$event, $registration] = $this->resolveRegistration($id, $regId);

        if ($event === null || $registration === null) {
            return $this->notFoundResponse($event === null ? 'Event' : 'Registration');
        }

        $updated = $this->registrationService->moveToWaitlist($registration);

        return response()->json([
            'message' => 'Participant moved to the waitlist.',
            'data' => new EventRegistrationResource($updated),
        ]);
    }

    public function update(UpdateEventRegistrationRequest $request, int $id, int $regId): JsonResponse
    {
        [$event, $registration] = $this->resolveRegistration($id, $regId);

        if ($event === null || $registration === null) {
            return $this->notFoundResponse($event === null ? 'Event' : 'Registration');
        }

        $updated = $registration;

        if ($request->has('attendance_status')) {
            /** @var string $attendanceStatus */
            $attendanceStatus = $request->input('attendance_status');
            $updated = $this->registrationService->setAttendanceStatus($updated, $attendanceStatus);
        }

        if ($request->has('notes')) {
            /** @var string|null $notes */
            $notes = $request->input('notes');
            $updated = $this->registrationService->updateNotes($updated, strval($notes ?? ''));
        }

        return response()->json([
            'message' => 'Registration updated.',
            'data' => new EventRegistrationResource($updated),
        ]);
    }

    public function destroy(int $id, int $regId): JsonResponse
    {
        [$event, $registration] = $this->resolveRegistration($id, $regId);

        if ($event === null || $registration === null) {
            return $this->notFoundResponse($event === null ? 'Event' : 'Registration');
        }

        $this->registrationService->remove($registration);

        return response()->json([
            'message' => 'Participant removed from the event.',
        ]);
    }

    public function checkIn(int $id, int $regId, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var int $checkedInBy */
        $checkedInBy = $user->id;

        [$event, $registration] = $this->resolveRegistration($id, $regId);

        if ($event === null || $registration === null) {
            return $this->notFoundResponse($event === null ? 'Event' : 'Registration');
        }

        $updated = $this->registrationService->checkIn($registration, $checkedInBy);

        return response()->json([
            'message' => 'Participant checked in.',
            'data' => new EventRegistrationResource($updated),
        ]);
    }

    public function undoCheckIn(int $id, int $regId): JsonResponse
    {
        [$event, $registration] = $this->resolveRegistration($id, $regId);

        if ($event === null || $registration === null) {
            return $this->notFoundResponse($event === null ? 'Event' : 'Registration');
        }

        $updated = $this->registrationService->undoCheckIn($registration);

        return response()->json([
            'message' => 'Check-in undone.',
            'data' => new EventRegistrationResource($updated),
        ]);
    }

    public function checkInByToken(EventCheckInRequest $request, int $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var int $checkedInBy */
        $checkedInBy = $user->id;

        $event = $this->findEvent($id);

        if (! $event) {
            return response()->json(['message' => 'Event not found.'], 404);
        }

        /** @var string $token */
        $token = $request->input('qr_token');

        $registration = $this->registrationService->checkInByToken($token, $checkedInBy);

        if ($registration->event_id !== $event->id) {
            return response()->json([
                'message' => 'This QR code belongs to a different event.',
            ], 422);
        }

        return response()->json([
            'message' => 'Participant checked in.',
            'data' => new EventRegistrationResource($registration),
        ]);
    }

    public function assignBus(Request $request, int $id, int $regId): JsonResponse
    {
        $event = $this->findEvent($id);

        if (! $event) {
            return response()->json(['message' => 'Event not found.'], 404);
        }

        [, $registration] = $this->resolveRegistration($id, $regId);

        if ($registration === null) {
            return response()->json(['message' => 'Registration not found.'], 404);
        }

        $busIdRaw = $request->input('bus_id');

        if ($busIdRaw === null || $busIdRaw === '' || $busIdRaw === 'null') {
            $busId = null;
        } elseif (is_numeric($busIdRaw)) {
            $busId = (int) $busIdRaw;
        } else {
            return response()->json([
                'message' => 'The selected bus is invalid.',
            ], 422);
        }

        $updated = $this->scheduleService->assignBus($event, $registration->id, $busId);

        return response()->json([
            'message' => $busId !== null ? 'Bus assigned.' : 'Bus assignment removed.',
            'data' => new EventRegistrationResource($updated),
        ]);
    }

    /**
     * @return array{0: Event|null, 1: EventRegistration|null}
     */
    private function resolveRegistration(int $eventId, int $regId): array
    {
        $event = $this->findEvent($eventId);

        if (! $event) {
            return [null, null];
        }

        $registration = EventRegistration::query()
            ->where('event_id', $event->id)
            ->with(['user.classe', 'bus'])
            ->find($regId);

        if (! $registration) {
            return [$event, null];
        }

        return [$event, $registration];
    }

    private function notFoundResponse(string $entity): JsonResponse
    {
        return response()->json(['message' => $entity.' not found.'], 404);
    }

    private function findEvent(int $id): ?Event
    {
        return Event::query()->find($id);
    }
}

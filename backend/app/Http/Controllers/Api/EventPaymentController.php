<?php

namespace App\Http\Controllers\Api;

use App\Contracts\EventPaymentServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\RecordEventPaymentRequest;
use App\Http\Resources\EventPaymentResource;
use App\Models\Event;
use App\Models\EventPayment;
use App\Models\EventRegistration;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EventPaymentController extends Controller
{
    public function __construct(
        private readonly EventPaymentServiceInterface $paymentService,
    ) {}

    public function index(Request $request, int $id): JsonResponse
    {
        $event = Event::query()->find($id);

        if (! $event) {
            return response()->json(['message' => 'Event not found.'], 404);
        }

        $query = EventPayment::query()
            ->whereHas('registration', fn ($q) => $q->where('event_id', $event->id))
            ->with(['registration.user', 'recorder']);

        if ($request->boolean('refunded')) {
            $query->where('refunded', true);
        }

        /** @var int $perPage */
        $perPage = $request->integer('per_page', 50);
        $paginator = $query->latest('paid_at')->paginate($perPage);

        return response()->json([
            'data' => EventPaymentResource::collection($paginator->items()),
            'summary' => $this->paymentService->financialSummary($event),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function store(RecordEventPaymentRequest $request, int $id, int $regId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $event = Event::query()->find($id);

        if (! $event) {
            return response()->json(['message' => 'Event not found.'], 404);
        }

        $registration = EventRegistration::query()
            ->where('event_id', $event->id)
            ->with('user')
            ->find($regId);

        if (! $registration) {
            return response()->json(['message' => 'Registration not found.'], 404);
        }

        /** @var array<string, mixed> $data */
        $data = $request->validated();
        $data['recorded_by'] = $user->id;

        $payment = $this->paymentService->recordPayment($event, $registration, $data);

        return response()->json([
            'message' => 'Payment recorded.',
            'data' => new EventPaymentResource($payment->load(['registration.user', 'recorder'])),
        ], 201);
    }

    public function refund(Request $request, int $id, int $regId, int $paymentId): JsonResponse
    {
        $event = Event::query()->find($id);

        if (! $event) {
            return response()->json(['message' => 'Event not found.'], 404);
        }

        $registration = EventRegistration::query()
            ->where('event_id', $event->id)
            ->find($regId);

        if (! $registration) {
            return response()->json(['message' => 'Registration not found.'], 404);
        }

        $belongs = EventPayment::query()->whereKey($paymentId)->where('registration_id', $registration->id)->exists();

        if (! $belongs) {
            return response()->json(['message' => 'Payment not found for this registration.'], 404);
        }

        $payment = $this->paymentService->markRefunded($paymentId, (int) $request->user()?->id);

        return response()->json([
            'message' => 'Payment marked as refunded.',
            'data' => new EventPaymentResource($payment->load(['registration.user', 'recorder'])),
        ]);
    }
}

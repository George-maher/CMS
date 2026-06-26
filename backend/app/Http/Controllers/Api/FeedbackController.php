<?php

namespace App\Http\Controllers\Api;

use App\Contracts\FeedbackServiceInterface;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\FeedbackRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class FeedbackController extends Controller
{
    public function __construct(
        private readonly FeedbackServiceInterface $feedbackService,
    ) {}

    public function submit(FeedbackRequest $request): JsonResponse
    {
        $user = $request->user();

        $result = $this->feedbackService->submit(
            data: $request->validated(),
            userId: $user->id,
            classYearId: $user->class_id
        );

        $msg = $request->boolean('is_anonymous')
            ? 'Feedback submitted anonymously.'
            : 'Feedback submitted successfully.';

        return response()->json([
            'message' => $msg,
            'data' => $result['data'],
        ], 201);
    }

    public function myFeedback(Request $request): JsonResponse
    {
        $user = $request->user();
        $result = $this->feedbackService->list(
            perPage: $request->input('per_page', 15),
            filters: ['user_id' => $user->id],
        );

        return response()->json([
            'data' => $result['data'],
            'meta' => $result['meta'],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->only(['category', 'is_resolved', 'unresolved']);
        $user = $request->user();

        $classYearIds = null;
        if ($user->role === UserRole::Servant) {
            $classYearIds = $user->getServantClassIds();
            if (empty($classYearIds)) {
                return response()->json([
                    'data' => [],
                    'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 15, 'total' => 0],
                    'unresolved_count' => 0,
                ]);
            }
        }

        $result = $this->feedbackService->list(
            perPage: $request->input('per_page', 15),
            filters: $filters,
            classYearIds: $classYearIds
        );

        return response()->json([
            'data' => $result['data'],
            'meta' => $result['meta'],
            'unresolved_count' => $result['unresolved_count'],
        ]);
    }

    public function resolve(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        // Servants: verify the feedback belongs to one of their classes
        if ($user->role === UserRole::Servant) {
            $feedback = \App\Models\Feedback::byChurch()->find($id);
            if (!$feedback || !in_array($feedback->class_year_id, $user->getServantClassIds())) {
                throw ValidationException::withMessages([
                    'feedback' => ['Feedback not found.'],
                ]);
            }
        }

        $result = $this->feedbackService->markAsResolved($id);

        return response()->json($result);
    }

    public function reply(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'message' => ['required', 'string', 'max:2000'],
        ]);

        $user = $request->user();

        // Servants: verify the feedback belongs to one of their classes
        if ($user->role === UserRole::Servant) {
            $feedback = \App\Models\Feedback::byChurch()->find($id);
            if (!$feedback || !in_array($feedback->class_year_id, $user->getServantClassIds())) {
                throw ValidationException::withMessages([
                    'feedback' => ['Feedback not found.'],
                ]);
            }
        }

        $result = $this->feedbackService->reply(
            feedbackId: $id,
            userId: $user->id,
            message: $request->input('message'),
        );

        return response()->json([
            'message' => 'Reply added successfully.',
            'data' => $result['data'],
        ]);
    }

    public function markSeen(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        $result = $this->feedbackService->markAsSeen($id, $user->id);

        return response()->json($result);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $filters = ['id' => $id];

        if ($user->role === UserRole::Servant) {
            $servantClassIds = $user->getServantClassIds();
            if (empty($servantClassIds)) {
                throw ValidationException::withMessages([
                    'feedback' => ['Feedback not found.'],
                ]);
            }
            $filters['class_year_ids'] = $servantClassIds;
        }

        $result = $this->feedbackService->list(
            perPage: 1,
            filters: $filters,
        );

        $feedback = $result['data']->first();
        if (!$feedback) {
            throw ValidationException::withMessages([
                'feedback' => ['Feedback not found.'],
            ]);
        }

        return response()->json([
            'data' => $feedback,
        ]);
    }
}

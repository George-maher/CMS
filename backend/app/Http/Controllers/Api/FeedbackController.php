<?php

namespace App\Http\Controllers\Api;

use App\Contracts\FeedbackServiceInterface;
use App\Contracts\ScopeResolverInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\FeedbackRequest;
use App\Models\Feedback;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class FeedbackController extends Controller
{
    public function __construct(
        private readonly FeedbackServiceInterface $feedbackService,
        private readonly ScopeResolverInterface $scopeResolver,
    ) {}

    public function submit(FeedbackRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var int|null $userId */
        $userId = $user->id;

        $result = $this->feedbackService->submit(
            data: $request->validated(),
            userId: $userId,
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
        /** @var User $user */
        $user = $request->user();
        /** @var int $perPage */
        $perPage = $request->integer('per_page', 15);
        $result = $this->feedbackService->list(
            perPage: $perPage,
            filters: ['user_id' => $user->id],
        );

        return response()->json([
            'data' => $result['data'],
            'meta' => $result['meta'],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        /** @var array<string, mixed> $filters */
        $filters = $request->only(['category', 'is_resolved', 'unresolved']);
        /** @var User $user */
        $user = $request->user();

        $classYearIds = null;
        if ($user->isServant()) {
            $classYearIds = $user->getServantClassIds();
            if (empty($classYearIds)) {
                return response()->json([
                    'data' => [],
                    'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 15, 'total' => 0],
                    'unresolved_count' => 0,
                ]);
            }
        } elseif ($user->isStageAdmin()) {
            $classYearIds = $this->scopeResolver->allowedClassIds($user) ?? [];
            if (empty($classYearIds)) {
                return response()->json([
                    'data' => [],
                    'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 15, 'total' => 0],
                    'unresolved_count' => 0,
                ]);
            }
        }

        $result = $this->feedbackService->list(
            perPage: $request->integer('per_page', 15),
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
        /** @var User $user */
        $user = $request->user();

        // Servants/Stage admins: verify the feedback belongs to their scope
        if ($this->isScopedReviewer($user)) {
            $feedback = Feedback::byChurch()->find($id);
            $scopeClassIds = $this->scopedReviewerClassIds($user);
            if (! $feedback || ! in_array($feedback->class_year_id, $scopeClassIds, true)) {
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

        /** @var User $user */
        $user = $request->user();

        // Servants/Stage admins: verify the feedback belongs to their scope
        if ($this->isScopedReviewer($user)) {
            $feedback = Feedback::byChurch()->find($id);
            $scopeClassIds = $this->scopedReviewerClassIds($user);
            if (! $feedback || ! in_array($feedback->class_year_id, $scopeClassIds, true)) {
                throw ValidationException::withMessages([
                    'feedback' => ['Feedback not found.'],
                ]);
            }
        }

        /** @var int $userId */
        $userId = $user->id;
        $message = (string) $request->str('message');
        $result = $this->feedbackService->reply(
            feedbackId: $id,
            userId: $userId,
            message: $message,
        );

        return response()->json([
            'message' => 'Reply added successfully.',
            'data' => $result['data'],
        ]);
    }

    public function markSeen(Request $request, int $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        /** @var int $userId */
        $userId = $user->id;
        $result = $this->feedbackService->markAsSeen($id, $userId);

        return response()->json($result);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $filters = ['id' => $id];

        if ($this->isScopedReviewer($user)) {
            $scopeClassIds = $this->scopedReviewerClassIds($user);
            if (empty($scopeClassIds)) {
                throw ValidationException::withMessages([
                    'feedback' => ['Feedback not found.'],
                ]);
            }
            $filters['class_year_ids'] = $scopeClassIds;
        }

        $result = $this->feedbackService->list(
            perPage: 1,
            filters: $filters,
        );

        /** @var Collection<int, mixed> $feedbackCollection */
        $feedbackCollection = $result['data'];
        $feedback = $feedbackCollection->first();
        if (! $feedback) {
            throw ValidationException::withMessages([
                'feedback' => ['Feedback not found.'],
            ]);
        }

        return response()->json([
            'data' => $feedback,
        ]);
    }

    private function isScopedReviewer(User $user): bool
    {
        return $user->isServant() || $user->isStageAdmin();
    }

    /**
     * @return array<int, int>
     */
    private function scopedReviewerClassIds(User $user): array
    {
        if ($user->isStageAdmin()) {
            return $this->scopeResolver->allowedClassIds($user) ?? [];
        }

        /** @var array<int, int> $servantClassIds */
        $servantClassIds = $user->getServantClassIds() ?? [];

        return $servantClassIds;
    }
}

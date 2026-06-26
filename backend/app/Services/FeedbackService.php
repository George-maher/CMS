<?php

namespace App\Services;

use App\Contracts\FeedbackRepositoryInterface;
use App\Contracts\FeedbackServiceInterface;
use App\Contracts\NotificationServiceInterface;
use App\Enums\UserRole;
use App\Http\Resources\FeedbackResource;
use App\Models\FeedbackReply;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FeedbackService implements FeedbackServiceInterface
{
    public function __construct(
        private readonly FeedbackRepositoryInterface $feedbackRepository,
        private readonly NotificationServiceInterface $notificationService,
    ) {}

    public function submit(array $data, ?int $userId = null, array|int|null $classYearId = null): array
    {
        // Resolve single class from array if needed
        $singleClassId = is_array($classYearId) ? ($classYearId[0] ?? null) : $classYearId;

        $feedback = $this->feedbackRepository->create([
            'message' => $data['message'],
            'category' => $data['category'] ?? null,
            'user_id' => $userId,
            'is_anonymous' => $data['is_anonymous'] ?? false,
            'class_year_id' => $singleClassId,
        ]);

        $feedback->load(['user', 'user.classe', 'replies.user']);

        // --- Notify admins + servants of the member's class ---
        $this->notifyFeedbackSubmission($feedback);

        return [
            'data' => new FeedbackResource($feedback),
        ];
    }

    public function list(int $perPage = 15, array $filters = [], array|int|null $classYearIds = null): array
    {
        if ($classYearIds !== null) {
            $filters['class_year_ids'] = is_array($classYearIds)
                ? $classYearIds
                : [$classYearIds];
        }

        $paginator = $this->feedbackRepository->paginate($perPage, $filters);

        return [
            'data' => FeedbackResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'unresolved_count' => $this->feedbackRepository->countUnresolved($classYearIds !== null
                ? (is_array($classYearIds) ? $classYearIds : [$classYearIds])
                : null),
        ];
    }

    public function markAsResolved(int $id): array
    {
        $updated = $this->feedbackRepository->markAsResolved($id);

        if (!$updated) {
            throw ValidationException::withMessages([
                'feedback' => ['Feedback not found.'],
            ]);
        }

        return [
            'message' => 'Feedback marked as resolved.',
        ];
    }

    public function reply(int $feedbackId, int $userId, string $message): array
    {
        $feedback = $this->feedbackRepository->findById($feedbackId);

        if (!$feedback) {
            throw ValidationException::withMessages([
                'feedback' => ['Feedback not found.'],
            ]);
        }

        $reply = FeedbackReply::create([
            'feedback_id' => $feedbackId,
            'user_id' => $userId,
            'message' => $message,
        ]);

        $feedback->update(['has_new_reply' => true]);
        $feedback->load('replies.user');

        // Notify the original member that they have a reply
        if ($feedback->user_id) {
            $this->notificationService->createForFeedbackReply(
                feedbackId: $feedback->id,
                userId: $feedback->user_id,
                churchId: $feedback->church_id,
                title: 'New Reply to Your Feedback',
                body: 'Your feedback has received a reply.',
            );
        }

        // Notify admins and other class servants of new reply activity
        $this->notifyFeedbackReply($feedback, $userId);

        return [
            'data' => new FeedbackResource($feedback),
        ];
    }

    public function markAsSeen(int $feedbackId, int $userId): array
    {
        $feedback = $this->feedbackRepository->findById($feedbackId);

        if (!$feedback) {
            throw ValidationException::withMessages([
                'feedback' => ['Feedback not found.'],
            ]);
        }

        if ($feedback->user_id !== $userId) {
            throw ValidationException::withMessages([
                'feedback' => ['Forbidden.'],
            ]);
        }

        $feedback->update(['has_new_reply' => false]);

        return [
            'data' => new FeedbackResource($feedback),
        ];
    }

    // ----------------------------------------------------------------
    // Private notification helpers
    // ----------------------------------------------------------------

    private function notifyStaff(int $churchId, ?int $classId, string $title, string $body, string $type, ?int $excludeUserId = null): void
    {
        if (!$churchId) {
            return;
        }

        $adminQuery = User::byChurch($churchId)
            ->whereIn('role', [UserRole::Admin, UserRole::AssistantAdmin]);

        if ($excludeUserId) {
            $adminQuery->where('id', '!=', $excludeUserId);
        }

        $adminIds = $adminQuery->pluck('id')->toArray();

        foreach ($adminIds as $adminId) {
            $this->notificationService->create(
                userId: $adminId,
                churchId: $churchId,
                title: $title,
                body: $body,
                type: $type,
            );
        }

        if ($classId) {
            $servantQuery = DB::table('class_servant')
                ->join('users', 'class_servant.user_id', '=', 'users.id')
                ->where('class_servant.class_id', $classId)
                ->where('users.church_id', $churchId)
                ->where('users.role', UserRole::Servant->value)
                ->where('users.is_active', true)
                ->whereNull('users.deleted_at');

            if ($excludeUserId) {
                $servantQuery->where('users.id', '!=', $excludeUserId);
            }

            $servantIds = $servantQuery->pluck('users.id')
                ->unique()
                ->values()
                ->toArray();

            foreach ($servantIds as $sid) {
                $this->notificationService->create(
                    userId: $sid,
                    churchId: $churchId,
                    title: $title,
                    body: $body,
                    type: $type,
                );
            }
        }
    }

    private function notifyFeedbackSubmission($feedback): void
    {
        $this->notifyStaff(
            churchId: $feedback->church_id,
            classId: $feedback->class_year_id,
            title: 'New Feedback',
            body: 'A new feedback has been submitted by a member.',
            type: 'feedback_new',
        );
    }

    private function notifyFeedbackReply($feedback, int $replierId): void
    {
        $this->notifyStaff(
            churchId: $feedback->church_id,
            classId: $feedback->class_year_id,
            title: 'Feedback Reply Added',
            body: 'A new reply has been added to a feedback submission.',
            type: 'feedback_reply',
            excludeUserId: $replierId,
        );
    }
}

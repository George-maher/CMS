<?php

namespace App\Services;

use App\Contracts\AuditServiceInterface;
use App\Contracts\NotificationServiceInterface;
use App\Contracts\ProfileUpdateRequestServiceInterface;
use App\Enums\ProfileUpdateRequestStatus;
use App\Models\ProfileUpdateRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class ProfileUpdateRequestService implements ProfileUpdateRequestServiceInterface
{
    public function __construct(
        private readonly NotificationServiceInterface $notificationService,
        private readonly AuditServiceInterface $auditService,
    ) {}

    /** @return array{message: string, request: ProfileUpdateRequest} */
    public function submitRequest(int $userId, array $data): array
    {
        return DB::transaction(function () use ($userId, $data) {
            $user = User::lockForUpdate()->find($userId);

            if (! $user || $user->isPlatformAdmin() || $user->isAdmin()) {
                throw ValidationException::withMessages([
                    'user' => [__('profile_update_requests.not_allowed')],
                ]);
            }

            // Prevent duplicate pending requests
            $existing = ProfileUpdateRequest::where('user_id', $userId)
                ->where('status', ProfileUpdateRequestStatus::Pending)
                ->first();

            if ($existing) {
                throw ValidationException::withMessages([
                    'request' => [__('profile_update_requests.already_pending')],
                ]);
            }

            // Capture current values
            $oldValues = [
                'name' => $user->name,
                'phone' => $user->phone,
                'email' => $user->email,
                'address' => $user->address,
            ];

            // Build new values (only changed fields)
            // Normalize empty strings to null for consistent comparison
            $newValues = [];
            foreach (['name', 'phone', 'email', 'address'] as $field) {
                if (! isset($data[$field])) {
                    continue;
                }
                /** @var mixed $newValue */
                $newValue = $data[$field] === '' ? null : $data[$field];
                /** @var mixed $oldValue */
                $oldValue = $oldValues[$field] ?? null;
                if ($newValue !== $oldValue) {
                    $newValues[$field] = $data[$field];
                }
            }

            if (empty($newValues)) {
                throw ValidationException::withMessages([
                    'data' => [__('profile_update_requests.no_changes')],
                ]);
            }

            // Validate email uniqueness if changing
            if (isset($newValues['email']) && $newValues['email'] !== $oldValues['email']) {
                $exists = User::where('email', $newValues['email'])
                    ->where('id', '!=', $userId)
                    ->exists();
                if ($exists) {
                    throw ValidationException::withMessages([
                        'email' => [__('profile_update_requests.email_taken')],
                    ]);
                }
            }

            // Validate phone uniqueness if changing
            if (isset($newValues['phone']) && $newValues['phone'] !== ($oldValues['phone'] ?? '')) {
                $exists = User::where('phone', $newValues['phone'])
                    ->where('id', '!=', $userId)
                    ->exists();
                if ($exists) {
                    throw ValidationException::withMessages([
                        'phone' => [__('profile_update_requests.phone_taken')],
                    ]);
                }
            }

            // Determine responsible servant
            $reviewerId = $this->findResponsibleServant($user);

            $request = ProfileUpdateRequest::create([
                'user_id' => $userId,
                'church_id' => $user->church_id,
                'reviewer_id' => $reviewerId,
                'status' => ProfileUpdateRequestStatus::Pending,
                'old_values' => $oldValues,
                'new_values' => $newValues,
            ]);

            $this->auditService->log(
                action: 'profile_update_requested',
                resourceType: ProfileUpdateRequest::class,
                resourceId: $request->id,
                oldValues: $oldValues,
                newValues: $newValues,
                userId: $userId,
                churchId: $user->church_id,
            );

            // Notify responsible servant(s)
            $this->notifyResponsibleServant($request, $user);

            Log::info('Profile update request submitted', [
                'request_id' => $request->id,
                'user_id' => $userId,
                'reviewer_id' => $reviewerId,
            ]);

            return [
                'message' => __('profile_update_requests.submitted'),
                'request' => $request,
            ];
        });
    }

    /** @return array{message: string, request: ProfileUpdateRequest} */
    public function approve(int $id, int $reviewerId): array
    {
        return DB::transaction(function () use ($id, $reviewerId) {
            $request = ProfileUpdateRequest::where('id', $id)
                ->lockForUpdate()
                ->first();

            if (! $request) {
                throw ValidationException::withMessages([
                    'request' => [__('profile_update_requests.not_found')],
                ]);
            }

            if (! $request->isPending()) {
                throw ValidationException::withMessages([
                    'request' => [__('profile_update_requests.already_processed')],
                ]);
            }

            // Verify reviewer is authorized
            $reviewer = User::find($reviewerId);
            if (! $reviewer) {
                throw ValidationException::withMessages([
                    'reviewer' => [__('profile_update_requests.reviewer_not_found')],
                ]);
            }

            // Check responsible servant relationship or admin
            if (! $this->canReview($reviewer, $request)) {
                throw ValidationException::withMessages([
                    'authorization' => [__('profile_update_requests.not_authorized')],
                ]);
            }

            // Validate new values again before applying
            $newValues = $request->new_values;
            $user = $request->user;

            if (! $user) {
                throw ValidationException::withMessages([
                    'user' => [__('profile_update_requests.user_not_found')],
                ]);
            }

            if (isset($newValues['email']) && $newValues['email'] !== $user->email) {
                $emailTaken = User::where('email', $newValues['email'])
                    ->where('id', '!=', $user->id)
                    ->exists();
                if ($emailTaken) {
                    throw ValidationException::withMessages([
                        'email' => [__('profile_update_requests.email_taken')],
                    ]);
                }
            }

            if (isset($newValues['phone']) && $newValues['phone'] !== ($user->phone ?? '')) {
                $phoneTaken = User::where('phone', $newValues['phone'])
                    ->where('id', '!=', $user->id)
                    ->exists();
                if ($phoneTaken) {
                    throw ValidationException::withMessages([
                        'phone' => [__('profile_update_requests.phone_taken')],
                    ]);
                }
            }

            // Apply changes
            $user->forceFill($newValues)->save();

            // Mark request as approved
            $request->update([
                'status' => ProfileUpdateRequestStatus::Approved,
                'reviewed_by' => $reviewerId,
                'reviewed_at' => now(),
            ]);

            $this->auditService->log(
                action: 'profile_update_approved',
                resourceType: ProfileUpdateRequest::class,
                resourceId: $request->id,
                oldValues: $request->old_values,
                newValues: $newValues,
                userId: $reviewerId,
                churchId: $request->church_id,
            );

            $this->notifyRequester(
                $request,
                __('profile_update_requests.approved_notification_title'),
                __('profile_update_requests.approved_notification_body'),
            );

            Log::info('Profile update request approved', [
                'request_id' => $request->id,
                'user_id' => $request->user_id,
                'reviewed_by' => $reviewerId,
            ]);

            return [
                'message' => __('profile_update_requests.approved'),
                'request' => $request->fresh(['user', 'reviewer']) ?? $request,
            ];
        });
    }

    /** @return array{message: string, request: ProfileUpdateRequest} */
    public function reject(int $id, int $reviewerId, string $reason): array
    {
        return DB::transaction(function () use ($id, $reviewerId, $reason) {
            $request = ProfileUpdateRequest::where('id', $id)
                ->lockForUpdate()
                ->first();

            if (! $request) {
                throw ValidationException::withMessages([
                    'request' => [__('profile_update_requests.not_found')],
                ]);
            }

            if (! $request->isPending()) {
                throw ValidationException::withMessages([
                    'request' => [__('profile_update_requests.already_processed')],
                ]);
            }

            $reviewer = User::find($reviewerId);
            if (! $reviewer || ! $this->canReview($reviewer, $request)) {
                throw ValidationException::withMessages([
                    'authorization' => [__('profile_update_requests.not_authorized')],
                ]);
            }

            $request->update([
                'status' => ProfileUpdateRequestStatus::Rejected,
                'rejection_reason' => $reason,
                'reviewed_by' => $reviewerId,
                'reviewed_at' => now(),
            ]);

            $this->auditService->log(
                action: 'profile_update_rejected',
                resourceType: ProfileUpdateRequest::class,
                resourceId: $request->id,
                oldValues: null,
                newValues: ['rejection_reason' => $reason],
                userId: $reviewerId,
                churchId: $request->church_id,
            );

            $this->notifyRequester(
                $request,
                __('profile_update_requests.rejected_notification_title'),
                __('profile_update_requests.rejected_notification_body'),
            );

            Log::info('Profile update request rejected', [
                'request_id' => $request->id,
                'user_id' => $request->user_id,
                'reviewed_by' => $reviewerId,
            ]);

            return [
                'message' => __('profile_update_requests.rejected'),
                'request' => $request->fresh(['user', 'reviewer']) ?? $request,
            ];
        });
    }

    /** @return array{data: list<ProfileUpdateRequest>, meta: array<string, mixed>} */
    public function listRequestsForServant(int $servantId, int $perPage = 15, array $filters = []): array
    {
        // Get IDs of members assigned to this servant via class_servant pivot
        $servant = User::find($servantId);
        if (! $servant) {
            /** @var array{data: list<ProfileUpdateRequest>, meta: array<string, mixed>} */
            return ['data' => [], 'meta' => $this->emptyMeta()];
        }

        $memberIds = $this->getServantMemberIds($servant);

        $query = ProfileUpdateRequest::with(['user.classe.stage', 'reviewer'])
            ->whereIn('user_id', $memberIds)
            ->orderBy('created_at', 'desc');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        $paginator = $query->paginate($perPage);

        /** @var list<ProfileUpdateRequest> $items */
        $items = $paginator->items();

        return [
            'data' => $items,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }

    /** @return array{data: list<ProfileUpdateRequest>, meta: array<string, mixed>} */
    public function listRequestsForAdmin(int $churchId, int $perPage = 15, array $filters = []): array
    {
        $query = ProfileUpdateRequest::with(['user.classe.stage', 'reviewer'])
            ->where('church_id', $churchId)
            ->orderBy('created_at', 'desc');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        $paginator = $query->paginate($perPage);

        /** @var list<ProfileUpdateRequest> $items */
        $items = $paginator->items();

        return [
            'data' => $items,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }

    public function findById(int $id, int $churchId): ?ProfileUpdateRequest
    {
        return ProfileUpdateRequest::with(['user.classe.stage', 'reviewer'])
            ->where('church_id', $churchId)
            ->find($id);
    }

    public function findPendingForUser(int $userId): ?ProfileUpdateRequest
    {
        return ProfileUpdateRequest::where('user_id', $userId)
            ->where('status', ProfileUpdateRequestStatus::Pending)
            ->first();
    }

    /** @return array{data: list<ProfileUpdateRequest>, meta: array<string, mixed>} */
    public function listRequestsForMember(int $userId, int $perPage = 15, array $filters = []): array
    {
        $query = ProfileUpdateRequest::with(['reviewer'])
            ->where('user_id', $userId)
            ->orderBy('created_at', 'desc');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        $paginator = $query->paginate($perPage);

        /** @var list<ProfileUpdateRequest> $items */
        $items = $paginator->items();

        return [
            'data' => $items,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }

    /**
     * Determine if the reviewer can review this request.
     * Admin/assistant admin can review any request in their church.
     * Servants can only review requests from their assigned members.
     */
    private function canReview(User $reviewer, ProfileUpdateRequest $request): bool
    {
        // Must be in the same church
        if ($request->church_id !== null && $reviewer->church_id !== $request->church_id) {
            return false;
        }

        // Admin/assistant admin can review any request in their church
        if ($reviewer->isAdminOrAssistantAdmin()) {
            return true;
        }

        // Servant can only review requests from their assigned members
        if ($reviewer->isServant()) {
            $memberIds = $this->getServantMemberIds($reviewer);

            return in_array($request->user_id, $memberIds, true);
        }

        return false;
    }

    /**
     * Get all member IDs that a servant is responsible for.
     * Uses the existing class_servant pivot and servant_id FK.
     *
     * @return list<int>
     */
    private function getServantMemberIds(User $servant): array
    {
        // Members directly assigned via servant_id FK
        /** @var list<int> $directMemberIds */
        $directMemberIds = User::where('servant_id', $servant->id)
            ->where('role', 'member')
            ->pluck('id')
            ->toArray();

        // Members in the servant's classes via class_servant pivot
        /** @var list<int> $classIds */
        $classIds = $servant->classes()->pluck('classes.id')->toArray();
        /** @var list<int> $classMemberIds */
        $classMemberIds = User::where('role', 'member')
            ->whereIn('class_id', $classIds)
            ->pluck('id')
            ->toArray();

        /** @var list<int> */
        return array_values(array_unique(array_merge($directMemberIds, $classMemberIds)));
    }

    /**
     * Find the responsible servant for a member.
     * First tries servant_id FK, then falls back to class-based servant lookup.
     */
    private function findResponsibleServant(User $member): ?int
    {
        // Try direct servant assignment
        if ($member->servant_id) {
            $servant = User::where('id', $member->servant_id)
                ->where('is_active', true)
                ->whereIn('role', ['servant', 'admin', 'assistant_admin'])
                ->first();
            if ($servant) {
                return $servant->id;
            }
        }

        // Try class-based servant lookup
        if ($member->class_id) {
            $servant = User::where('role', 'servant')
                ->where('is_active', true)
                ->whereHas('classes', function ($q) use ($member) {
                    $q->where('classes.id', $member->class_id);
                })
                ->first();
            if ($servant) {
                return $servant->id;
            }
        }

        return null;
    }

    private function notifyResponsibleServant(ProfileUpdateRequest $request, User $member): void
    {
        $reviewerId = $request->reviewer_id;
        if ($reviewerId === null) {
            Log::warning('No responsible servant found for profile update request', [
                'request_id' => $request->id,
                'user_id' => $member->id,
            ]);

            return;
        }

        try {
            $this->notificationService->create(
                userId: $reviewerId,
                churchId: $request->church_id ?? 0,
                title: __('profile_update_requests.submitted_notification_title'),
                body: __('profile_update_requests.submitted_notification_body', ['name' => $member->name ?? '']),
                type: 'profile_update',
            );
        } catch (\Exception $e) {
            Log::warning('Failed to create profile update notification for servant', [
                'request_id' => $request->id,
                'reviewer_id' => $reviewerId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function notifyRequester(ProfileUpdateRequest $request, string $title, string $body): void
    {
        $user = $request->user;
        if (! $user || ! $user->id) {
            return;
        }

        try {
            $this->notificationService->create(
                userId: $user->id,
                churchId: $request->church_id ?? 0,
                title: $title,
                body: $body,
                type: 'profile_update',
            );
        } catch (\Exception $e) {
            Log::warning('Failed to create profile update notification for requester', [
                'request_id' => $request->id,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function emptyMeta(): array
    {
        return [
            'current_page' => 1,
            'last_page' => 1,
            'per_page' => 15,
            'total' => 0,
        ];
    }
}

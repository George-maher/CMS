<?php

namespace App\Services;

use App\Contracts\NotificationServiceInterface;
use App\Contracts\PasswordResetRequestServiceInterface;
use App\Enums\PasswordResetRequestStatus;
use App\Models\PasswordResetRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Admin-approved password reset workflow.
 *
 * Flow: Member/Servant submits request → in-app notification to their Church
 * Admin → admin verifies identity → approve/reject → on approval the admin
 * sets a brand-new password which is hashed and stored.
 *
 * There is deliberately NO email leg: no reset links, no tokens, no mail
 * dependency. The old password is never retrievable — only a new password
 * can be set.
 */
class PasswordResetRequestService implements PasswordResetRequestServiceInterface
{
    public function __construct(
        private readonly NotificationServiceInterface $notificationService,
    ) {}

    /** @param array<string, mixed> $data */
    /** @return array{message: string} */
    public function submitRequest(array $data): array
    {
        $user = User::where('email', $data['email'])->first();

        // Always answer identically whether or not the account exists —
        // prevents account enumeration.
        if (! $user || $user->isPlatformAdmin() || $user->isAdmin()) {
            return [
                'message' => __('password_reset_requests.submitted'),
            ];
        }

        $existing = PasswordResetRequest::where('user_id', $user->id)
            ->where('status', PasswordResetRequestStatus::Pending)
            ->first();

        if ($existing) {
            return [
                'message' => __('password_reset_requests.submitted'),
            ];
        }

        $request = PasswordResetRequest::create([
            'user_id' => $user->id,
            'email' => $user->email,
            'notes' => $data['notes'] ?? null,
            'status' => PasswordResetRequestStatus::Pending,
        ]);

        Log::info('Password reset request submitted', [
            'request_id' => $request->id,
            'user_id' => $user->id,
        ]);

        $this->notifyChurchAdmins($request, $user);

        return [
            'message' => __('password_reset_requests.submitted'),
        ];
    }

    /**
     * Create the in-app notification for every active admin/assistant-admin
     * of the requester's church. Synchronous DB insert — never queued, so the
     * notification always appears regardless of queue or mail health.
     */
    private function notifyChurchAdmins(PasswordResetRequest $request, User $requester): void
    {
        $churchId = $requester->church_id;

        if ($churchId === null) {
            Log::warning('Password reset request submitted by user without a church — no admin can be notified', [
                'request_id' => $request->id,
                'user_id' => $requester->id,
            ]);

            return;
        }

        /** @var Collection<int, User> $admins */
        $admins = User::whereIn('role', ['admin', 'assistant_admin'])
            ->where('church_id', $churchId)
            ->where('is_active', true)
            ->get();

        if ($admins->isEmpty()) {
            Log::warning('No active church admin found for password reset request', [
                'request_id' => $request->id,
                'user_id' => $requester->id,
                'church_id' => $churchId,
            ]);

            return;
        }

        $title = __('password_reset_requests.submitted_notification_title');
        $body = __('password_reset_requests.submitted_notification_body', ['name' => $requester->name ?? '']);

        /** @var array<int, int> $adminIds */
        $adminIds = $admins->pluck('id')->toArray();

        foreach ($adminIds as $adminId) {
            try {
                $this->notificationService->create(
                    userId: $adminId,
                    churchId: $churchId,
                    title: $title,
                    body: $body,
                    type: 'password_reset',
                );
            } catch (\Exception $e) {
                Log::warning('Failed to create in-app notification for admin', [
                    'request_id' => $request->id,
                    'admin_id' => $adminId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /** @return array{message: string, request: PasswordResetRequest} */
    public function approve(int $id, int $adminId): array
    {
        return DB::transaction(function () use ($id, $adminId) {
            $request = PasswordResetRequest::where('id', $id)
                ->lockForUpdate()
                ->first();

            if (! $request) {
                throw ValidationException::withMessages([
                    'request' => [__('password_reset_requests.not_found')],
                ]);
            }

            if (! $request->isPending()) {
                throw ValidationException::withMessages([
                    'request' => [__('password_reset_requests.already_processed')],
                ]);
            }

            $request->update([
                'status' => PasswordResetRequestStatus::Approved,
                'reviewed_by' => $adminId,
                'reviewed_at' => now(),
            ]);

            $this->notifyRequester(
                $request,
                __('password_reset_requests.approved_notification_title'),
                __('password_reset_requests.approved_notification_body'),
            );

            Log::info('Password reset request approved', [
                'request_id' => $request->id,
                'reviewed_by' => $adminId,
            ]);

            return [
                'message' => __('password_reset_requests.approved'),
                'request' => $this->freshWithRelations($request),
            ];
        });
    }

    /** @return array{message: string, request: PasswordResetRequest} */
    public function reject(int $id, int $adminId, string $reason): array
    {
        return DB::transaction(function () use ($id, $adminId, $reason) {
            $request = PasswordResetRequest::where('id', $id)
                ->lockForUpdate()
                ->first();

            if (! $request) {
                throw ValidationException::withMessages([
                    'request' => [__('password_reset_requests.not_found')],
                ]);
            }

            if (! $request->isPending()) {
                throw ValidationException::withMessages([
                    'request' => [__('password_reset_requests.already_processed')],
                ]);
            }

            $request->update([
                'status' => PasswordResetRequestStatus::Rejected,
                'rejection_reason' => $reason,
                'reviewed_by' => $adminId,
                'reviewed_at' => now(),
            ]);

            $this->notifyRequester(
                $request,
                __('password_reset_requests.rejected_notification_title'),
                __('password_reset_requests.rejected_notification_body'),
            );

            Log::info('Password reset request rejected', [
                'request_id' => $request->id,
                'reviewed_by' => $adminId,
            ]);

            return [
                'message' => __('password_reset_requests.rejected'),
                'request' => $this->freshWithRelations($request),
            ];
        });
    }

    /**
     * Set a brand-new password for an approved request.
     * The old password cannot be recovered — it is only ever replaced.
     */
    /** @return array{message: string} */
    public function resetPassword(int $id, int $adminId, string $password): array
    {
        return DB::transaction(function () use ($id, $adminId, $password) {
            $request = PasswordResetRequest::where('id', $id)
                ->lockForUpdate()
                ->first();

            if (! $request) {
                throw ValidationException::withMessages([
                    'request' => [__('password_reset_requests.not_found')],
                ]);
            }

            if (! $request->isApproved()) {
                throw ValidationException::withMessages([
                    'request' => [__('password_reset_requests.not_approved')],
                ]);
            }

            $user = $request->user;

            if ($user === null || $user->id === null) {
                throw ValidationException::withMessages([
                    'request' => [__('password_reset_requests.user_not_found')],
                ]);
            }

            $userId = $user->id;

            $user->forceFill([
                'password' => Hash::make($password),
            ])->save();

            // Revoke all existing sessions/tokens — they were issued under
            // credentials that are no longer valid.
            $user->tokens()->delete();

            $request->update([
                'status' => PasswordResetRequestStatus::Completed,
            ]);

            $this->notifyRequester(
                $request,
                __('password_reset_requests.completed_notification_title'),
                __('password_reset_requests.completed_notification_body'),
            );

            Log::info('Password reset completed by church admin — new password hashed and stored', [
                'request_id' => $request->id,
                'user_id' => $userId,
                'reviewed_by' => $adminId,
            ]);

            return [
                'message' => __('password_reset_requests.completed'),
            ];
        });
    }

    /** In-app notification to the requester (synchronous DB insert). */
    private function notifyRequester(PasswordResetRequest $request, string $title, string $body): void
    {
        $user = $request->user;

        if ($user === null || $user->id === null) {
            return;
        }

        try {
            $this->notificationService->create(
                userId: $user->id,
                churchId: $user->church_id ?? 0,
                title: $title,
                body: $body,
                type: 'password_reset',
            );
        } catch (\Exception $e) {
            Log::warning('Failed to create in-app notification for requester', [
                'request_id' => $request->id,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function freshWithRelations(PasswordResetRequest $request): PasswordResetRequest
    {
        /** @var PasswordResetRequest */
        return $request->fresh(['user.classe.stage', 'reviewer']) ?? $request;
    }

    /** @param array<string, mixed> $filters */
    /** @return array<string, mixed> */
    public function listRequests(int $churchId, int $perPage = 15, array $filters = []): array
    {
        $query = PasswordResetRequest::with(['user.classe', 'reviewer'])
            ->whereHas('user', function ($q) use ($churchId) {
                $q->where('church_id', $churchId);
            });

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        $paginator = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return [
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }

    public function findById(int $id, int $churchId): ?PasswordResetRequest
    {
        return PasswordResetRequest::with(['user.classe.stage', 'reviewer'])
            ->whereHas('user', function ($q) use ($churchId) {
                $q->where('church_id', $churchId);
            })
            ->find($id);
    }
}

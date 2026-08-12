<?php

namespace App\Services;

use App\Contracts\NotificationServiceInterface;
use App\Contracts\PasswordResetRequestServiceInterface;
use App\Enums\PasswordResetRequestStatus;
use App\Models\PasswordResetRequest;
use App\Models\User;
use App\Notifications\PasswordChangedNotification;
use App\Notifications\PasswordResetRequestApprovedNotification;
use App\Notifications\PasswordResetRequestRejectedNotification;
use App\Notifications\PasswordResetRequestSubmittedNotification;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class PasswordResetRequestService implements PasswordResetRequestServiceInterface
{
    private const TOKEN_EXPIRY_HOURS = 24;

    public function __construct(
        private readonly NotificationServiceInterface $notificationService,
    ) {}

    /** @param array<string, mixed> $data */
    /** @return array{message: string} */
    public function submitRequest(array $data): array
    {
        $user = User::where('email', $data['email'])->first();

        if (! $user || $user->isPlatformAdmin()) {
            return [
                'message' => __('password_reset_requests.submitted'),
            ];
        }

        if ($user->isAdmin()) {
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
            'email' => $user->email,
        ]);

        $this->notifyChurchAdmins($request, $user);

        return [
            'message' => __('password_reset_requests.submitted'),
        ];
    }

    /**
     * Notify every active admin/assistant-admin in the requester's church.
     *
     * The in-app notification (synchronous database insert) is created first so
     * the Church Admin always sees the request, regardless of queue/mail health.
     * The queued email is sent as best-effort: a mail failure must never block
     * or skip the in-app notification.
     */
    private function notifyChurchAdmins(PasswordResetRequest $request, User $requester): void
    {
        $churchId = $requester->church_id;

        if ($churchId === null) {
            Log::warning('Password reset request submitted by user without a church — no admin can be notified', [
                'request_id' => $request->id,
                'user_id' => $requester->id,
                'email' => $requester->email,
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

            try {
                $admin = User::find($adminId);
                if ($admin !== null) {
                    $admin->notify(new PasswordResetRequestSubmittedNotification($requester));
                }
            } catch (\Exception $e) {
                Log::warning('Failed to dispatch admin email notification', [
                    'request_id' => $request->id,
                    'admin_id' => $adminId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /** @return array<string, mixed> */
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

            $token = PasswordResetRequest::generateToken();

            $request->update([
                'status' => PasswordResetRequestStatus::Approved,
                'token' => $token,
                'reviewed_by' => $adminId,
                'reviewed_at' => now(),
                'token_expires_at' => now()->addHours(self::TOKEN_EXPIRY_HOURS),
            ]);

            $user = $request->user;

            if ($user === null || $user->id === null) {
                throw ValidationException::withMessages([
                    'request' => [__('password_reset_requests.user_not_found')],
                ]);
            }

            $userId = $user->id;

            /** @var string $frontendUrl */
            $frontendUrl = config('app.frontend_url');
            $resetUrl = $frontendUrl.'/reset-password-request?'.http_build_query([
                'token' => $token,
                'email' => $user->email,
            ]);

            try {
                $user->notify(new PasswordResetRequestApprovedNotification($resetUrl));
            } catch (\Exception $e) {
                Log::warning('Failed to send approval notification', [
                    'request_id' => $request->id,
                    'user_id' => $userId,
                    'error' => $e->getMessage(),
                ]);
            }

            $this->notificationService->create(
                userId: $userId,
                churchId: $user->church_id ?? 0,
                title: __('password_reset_requests.approved_notification_title'),
                body: __('password_reset_requests.approved_notification_body'),
                type: 'password_reset',
            );

            Log::info('Password reset request approved', [
                'request_id' => $request->id,
                'user_id' => $userId,
                'reviewed_by' => $adminId,
            ]);

            /** @var PasswordResetRequest $freshRequest */
            $freshRequest = $request->fresh(['user', 'reviewer']);

            return [
                'message' => __('password_reset_requests.approved'),
                'request' => $freshRequest,
            ];
        });
    }

    /** @return array<string, mixed> */
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

            $user = $request->user;

            if ($user === null || $user->id === null) {
                throw ValidationException::withMessages([
                    'request' => [__('password_reset_requests.user_not_found')],
                ]);
            }

            $userId = $user->id;

            try {
                $user->notify(new PasswordResetRequestRejectedNotification($reason));
            } catch (\Exception $e) {
                Log::warning('Failed to send rejection notification', [
                    'request_id' => $request->id,
                    'user_id' => $userId,
                    'error' => $e->getMessage(),
                ]);
            }

            $this->notificationService->create(
                userId: $userId,
                churchId: $user->church_id ?? 0,
                title: __('password_reset_requests.rejected_notification_title'),
                body: __('password_reset_requests.rejected_notification_body'),
                type: 'password_reset',
            );

            Log::info('Password reset request rejected', [
                'request_id' => $request->id,
                'user_id' => $userId,
                'reviewed_by' => $adminId,
            ]);

            /** @var PasswordResetRequest $freshRequest */
            $freshRequest = $request->fresh(['user', 'reviewer']);

            return [
                'message' => __('password_reset_requests.rejected'),
                'request' => $freshRequest,
            ];
        });
    }

    /** @return array<string, mixed> */
    public function completeReset(string $token, string $password): array
    {
        return DB::transaction(function () use ($token, $password) {
            $request = PasswordResetRequest::where('token', $token)
                ->lockForUpdate()
                ->first();

            if (! $request || ! $request->isValidToken()) {
                throw ValidationException::withMessages([
                    'token' => [__('password_reset_requests.invalid_token')],
                ]);
            }

            $user = $request->user;

            if ($user === null) {
                throw ValidationException::withMessages([
                    'token' => [__('password_reset_requests.invalid_token')],
                ]);
            }

            $user->forceFill([
                'password' => Hash::make($password),
            ])->save();

            $user->tokens()->delete();

            $request->markAsUsed();

            event(new PasswordReset($user));

            try {
                $user->notify(new PasswordChangedNotification);
            } catch (\Exception $e) {
                Log::warning('Failed to send password changed notification', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }

            Log::info('Password reset via admin-approved request completed', [
                'request_id' => $request->id,
                'user_id' => $user->id,
            ]);

            return [
                'message' => __('passwords.reset'),
            ];
        });
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

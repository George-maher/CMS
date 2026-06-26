<?php

namespace App\Services;

use App\Contracts\PasswordResetRequestServiceInterface;
use App\Enums\PasswordResetRequestStatus;
use App\Models\PasswordResetRequest;
use App\Models\User;
use App\Notifications\PasswordResetRequestApprovedNotification;
use App\Notifications\PasswordResetRequestRejectedNotification;
use App\Notifications\PasswordResetRequestSubmittedNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class PasswordResetRequestService implements PasswordResetRequestServiceInterface
{
    private const TOKEN_EXPIRY_HOURS = 24;

    public function submitRequest(array $data): array
    {
        $user = User::where('email', $data['email'])->first();

        if (!$user || $user->isPlatformAdmin()) {
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

        try {
            $admins = User::whereIn('role', ['admin', 'assistant_admin'])
                ->where('church_id', $user->church_id)
                ->where('is_active', true)
                ->get();

            foreach ($admins as $admin) {
                $admin->notify(new PasswordResetRequestSubmittedNotification($request, $user));
            }
        } catch (\Exception $e) {
            Log::warning('Failed to notify admins about password reset request', [
                'request_id' => $request->id,
                'error' => $e->getMessage(),
            ]);
        }

        return [
            'message' => __('password_reset_requests.submitted'),
        ];
    }

    public function approve(int $id, int $adminId): array
    {
        return DB::transaction(function () use ($id, $adminId) {
            $request = PasswordResetRequest::where('id', $id)
                ->lockForUpdate()
                ->first();

            if (!$request) {
                throw ValidationException::withMessages([
                    'request' => [__('password_reset_requests.not_found')],
                ]);
            }

            if (!$request->isPending()) {
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

            $resetUrl = config('app.frontend_url') . '/reset-password-request?' . http_build_query([
                'token' => $token,
                'email' => $user->email,
            ]);

            try {
                $user->notify(new PasswordResetRequestApprovedNotification($resetUrl));
            } catch (\Exception $e) {
                Log::warning('Failed to send approval notification', [
                    'request_id' => $request->id,
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }

            Log::info('Password reset request approved', [
                'request_id' => $request->id,
                'user_id' => $user->id,
                'reviewed_by' => $adminId,
            ]);

            return [
                'message' => __('password_reset_requests.approved'),
                'request' => $request->fresh()->load(['user', 'reviewer']),
            ];
        });
    }

    public function reject(int $id, int $adminId, string $reason): array
    {
        return DB::transaction(function () use ($id, $adminId, $reason) {
            $request = PasswordResetRequest::where('id', $id)
                ->lockForUpdate()
                ->first();

            if (!$request) {
                throw ValidationException::withMessages([
                    'request' => [__('password_reset_requests.not_found')],
                ]);
            }

            if (!$request->isPending()) {
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

            try {
                $user->notify(new PasswordResetRequestRejectedNotification($reason));
            } catch (\Exception $e) {
                Log::warning('Failed to send rejection notification', [
                    'request_id' => $request->id,
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }

            Log::info('Password reset request rejected', [
                'request_id' => $request->id,
                'user_id' => $user->id,
                'reviewed_by' => $adminId,
            ]);

            return [
                'message' => __('password_reset_requests.rejected'),
                'request' => $request->fresh()->load(['user', 'reviewer']),
            ];
        });
    }

    public function completeReset(string $token, string $password): array
    {
        return DB::transaction(function () use ($token, $password) {
            $request = PasswordResetRequest::where('token', $token)
                ->lockForUpdate()
                ->first();

            if (!$request || !$request->isValidToken()) {
                throw ValidationException::withMessages([
                    'token' => [__('password_reset_requests.invalid_token')],
                ]);
            }

            $user = $request->user;

            $user->forceFill([
                'password' => Hash::make($password),
            ])->save();

            $user->tokens()->delete();

            $request->markAsUsed();

            event(new \Illuminate\Auth\Events\PasswordReset($user));

            try {
                $user->notify(new \App\Notifications\PasswordChangedNotification());
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

    public function listRequests(int $churchId, int $perPage = 15, array $filters = []): array
    {
        $query = PasswordResetRequest::with(['user.classe', 'reviewer'])
            ->whereHas('user', function ($q) use ($churchId) {
                $q->where('church_id', $churchId);
            });

        if (!empty($filters['status'])) {
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

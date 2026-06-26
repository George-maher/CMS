<?php

namespace App\Services;

use App\Contracts\AuthServiceInterface;
use App\Contracts\QRInviteServiceInterface;
use App\Contracts\UserRepositoryInterface;
use App\Enums\UserRole;
use App\Models\Classe;
use App\Models\QRInvite;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthService implements AuthServiceInterface
{
    public function __construct(
        private readonly UserRepositoryInterface $userRepository,
        private readonly QRInviteServiceInterface $qrInviteService,
    ) {}

    public function login(array $credentials): array
    {
        $user = $this->userRepository->findByEmail($credentials['email']);

        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if ($user->isPlatformAdmin()) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if (!$user->is_active) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if ($user->application_status === 'rejected') {
            throw ValidationException::withMessages([
                'email' => ['Your account has been rejected and cannot log in.'],
            ]);
        }

        if ($user->application_status !== 'approved') {
            throw ValidationException::withMessages([
                'email' => ['Your account is pending approval.'],
            ]);
        }

        if ($user->church_id) {
            $church = $user->church()->withTrashed()->first();
            if ($church && $church->is_suspended) {
                throw ValidationException::withMessages([
                    'email' => ['Your account is temporarily unavailable. Please contact support.'],
                ]);
            }
            if ($church && $church->trashed()) {
                throw ValidationException::withMessages([
                    'email' => [__('church_deletion.church_not_found')],
                ]);
            }
        }

        $token = $user->createToken('auth-token', [$user->role->value])->plainTextToken;

        return [
            'user' => $user->load(['classe', 'servant', 'church']),
            'token' => $token,
            'token_type' => 'Bearer',
            'application_status' => $user->application_status,
        ];
    }

    public function platformLogin(array $credentials): array
    {
        $user = $this->userRepository->findByEmail($credentials['email']);

        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if (!$user->isPlatformAdmin()) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if (!$user->is_active) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        $token = $user->createToken('auth-token', [$user->role->value])->plainTextToken;

        return [
            'user' => $user->load(['classe', 'servant']),
            'token' => $token,
            'token_type' => 'Bearer',
        ];
    }

    public function logout($user): void
    {
        $user->currentAccessToken()->delete();
    }

    public function register(array $data): array
    {
        $inviteToken = $data['invite_token'] ?? null;
        if (!$inviteToken) {
            throw ValidationException::withMessages([
                'invite_token' => ['A valid invitation token is required to register.'],
            ]);
        }

        $validation = $this->qrInviteService->validateTokenForRegistration($inviteToken);
        $invite = $validation['invite'];
        $role = $validation['role'];

        return DB::transaction(function () use ($data, $invite, $role, $inviteToken) {
            $freshInvite = QRInvite::where('id', $invite->id)
                ->lockForUpdate()
                ->first();

            if (!$freshInvite || !$freshInvite->isValid()) {
                $msg = $freshInvite && $freshInvite->max_uses !== null && $freshInvite->use_count >= $freshInvite->max_uses
                    ? __('invite.max_uses_reached')
                    : __('invite.already_used');
                throw ValidationException::withMessages([
                    'invite_token' => [$msg],
                ]);
            }

            $data['password'] = Hash::make($data['password']);
            $data['role'] = $role->value;
            $data['is_active'] = true;
            $data['created_by'] = $invite->created_by;
            $data['invite_id'] = $invite->id;
            $data['church_id'] = $invite->church_id;

            if ($role === UserRole::Member) {
                $data['servant_id'] = $invite->created_by;
            }

            if (!empty($data['class_id'])) {
                $classe = Classe::where('id', $data['class_id'])
                    ->where('church_id', $invite->church_id)
                    ->first();
                if (!$classe) {
                    throw ValidationException::withMessages([
                        'class_id' => ['The selected class is invalid for this invitation.'],
                    ]);
                }
            }

            $user = $this->userRepository->create($data);

            $user->email_verification_token = Str::random(64);
            $user->save();

            $verificationUrl = config('app.frontend_url') . '/verify-email?token=' . urlencode($user->email_verification_token) . '&email=' . urlencode($user->email);
            try {
                $user->notify(new VerifyEmailNotification($user, $verificationUrl));
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::warning('Failed to send verification email', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }

            $used = $freshInvite->markAsUsed($user->id);
            if (!$used) {
                throw ValidationException::withMessages([
                    'invite_token' => [__('invite.max_uses_reached')],
                ]);
            }

            \Illuminate\Support\Facades\Log::info('Invite consumed via registration', [
                'invite_id' => $freshInvite->id,
                'token' => $inviteToken,
                'user_id' => $user->id,
                'role' => $role->value,
            ]);

            return [
                'user' => $user->load('classe'),
                'message' => 'Registration successful. You can now log in with your credentials.',
            ];
        });
    }

    public function getAuthenticatedUser($user): array
    {
        return [
            'user' => $user->load(['classe', 'createdBy', 'invite', 'servant']),
        ];
    }

    public function forgotPassword(array $data): array
    {
        Password::sendResetLink(
            ['email' => $data['email']]
        );

        return [
            'message' => 'If an account exists, a password reset link has been sent.',
        ];
    }

    public function resetPassword(array $data): array
    {
        $status = Password::reset(
            $data,
            function ($user, $password) {
                $user->forceFill([
                    'password' => Hash::make($password),
                ])->setRememberToken(Str::random(60));

                $user->save();

                // Invalidate all existing Sanctum tokens for security
                $user->tokens()->delete();

                // Send password changed confirmation notification
                try {
                    $user->notify(new \App\Notifications\PasswordChangedNotification());
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::warning('Failed to send password changed notification', [
                        'user_id' => $user->id,
                        'error' => $e->getMessage(),
                    ]);
                }

                event(new PasswordReset($user));
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return [
                'message' => __('passwords.reset'),
            ];
        }

        throw ValidationException::withMessages([
            'email' => [__($status)],
        ]);
    }
}

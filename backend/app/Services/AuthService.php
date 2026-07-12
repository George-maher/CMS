<?php

namespace App\Services;

use App\Contracts\AuthServiceInterface;
use App\Contracts\QRInviteServiceInterface;
use App\Contracts\UserRepositoryInterface;
use App\Enums\UserRole;
use App\Models\Church;
use App\Models\Classe;
use App\Models\QRInvite;
use App\Models\User;
use App\Notifications\PasswordChangedNotification;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthService implements AuthServiceInterface
{
    public function __construct(
        private readonly UserRepositoryInterface $userRepository,
        private readonly QRInviteServiceInterface $qrInviteService,
    ) {}

    /** @param array<string, mixed> $credentials */
    public function login(array $credentials): array
    {
        /** @var string $email */
        $email = $credentials['email'];
        /** @var string $password */
        $password = $credentials['password'];

        $user = $this->userRepository->findByEmail($email);

        if (! $user || ! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => [__('auth.failed')],
            ]);
        }

        if ($user->isPlatformAdmin()) {
            throw ValidationException::withMessages([
                'email' => [__('auth.failed')],
            ]);
        }

        if ($user->is_active === false && $user->isApproved()) {
            throw ValidationException::withMessages([
                'email' => [__('auth.inactive')],
            ]);
        }

        if ($user->church_id) {
            $church = Church::withTrashed()->where('id', $user->church_id)->first();
            if ($church && $church->is_suspended) {
                throw ValidationException::withMessages([
                    'email' => [__('auth.suspended')],
                ]);
            }
            if ($church && $church->trashed()) {
                throw ValidationException::withMessages([
                    'email' => [__('auth.church_deleted')],
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

    /** @param array<string, mixed> $credentials */
    public function platformLogin(array $credentials): array
    {
        /** @var string $email */
        $email = $credentials['email'];
        /** @var string $password */
        $password = $credentials['password'];

        Log::info('[DEBUG] AuthService::platformLogin — lookup by email', ['email' => $email]);
        $user = $this->userRepository->findByEmail($email);
        Log::info('[DEBUG] AuthService::platformLogin — user found', [
            'found' => $user !== null,
            'user_id' => $user?->id,
            'user_role' => $user?->role?->value,
            'is_active' => $user?->is_active,
            'application_status' => $user?->application_status,
        ]);

        if (! $user || ! Hash::check($password, $user->password)) {
            Log::warning('[DEBUG] AuthService::platformLogin — invalid credentials', [
                'user_exists' => $user !== null,
                'password_match' => $user ? Hash::check($password, $user->password) : false,
            ]);
            throw ValidationException::withMessages([
                'email' => [__('auth.failed')],
            ]);
        }

        Log::info('[DEBUG] AuthService::platformLogin — credentials valid');

        if (! $user->isPlatformAdmin()) {
            Log::warning('[DEBUG] AuthService::platformLogin — not platform admin', [
                'role' => $user->role->value,
            ]);
            throw ValidationException::withMessages([
                'email' => [__('auth.failed')],
            ]);
        }

        Log::info('[DEBUG] AuthService::platformLogin — role verified as platform_admin');

        if (! $user->is_active) {
            Log::warning('[DEBUG] AuthService::platformLogin — inactive account');
            throw ValidationException::withMessages([
                'email' => [__('auth.inactive')],
            ]);
        }

        if ($user->application_status === 'pending') {
            Log::warning('[DEBUG] AuthService::platformLogin — pending application');
            throw ValidationException::withMessages([
                'email' => [__('auth.pending')],
            ]);
        }

        if ($user->application_status === 'rejected') {
            Log::warning('[DEBUG] AuthService::platformLogin — rejected application');
            throw ValidationException::withMessages([
                'email' => [__('auth.rejected')],
            ]);
        }

        Log::info('[DEBUG] AuthService::platformLogin — generating token');
        $token = $user->createToken('auth-token', [$user->role->value])->plainTextToken;
        Log::info('[DEBUG] AuthService::platformLogin — token generated', [
            'token_prefix' => substr($token, 0, 10).'...',
        ]);

        return [
            'user' => $user->load(['classe', 'servant']),
            'token' => $token,
            'token_type' => 'Bearer',
        ];
    }

    public function logout(User $user): void
    {
        $user->currentAccessToken()->delete();
    }

    /** @param array{password: string, invite_token?: string, class_id?: int, email?: string, name?: string} $data */
    public function register(array $data): array
    {
        /** @var string|null $inviteToken */
        $inviteToken = $data['invite_token'] ?? null;
        if (! $inviteToken) {
            throw ValidationException::withMessages([
                'invite_token' => [__('invite.not_found')],
            ]);
        }

        /** @var array{invite: QRInvite, role: UserRole} $validation */
        $validation = $this->qrInviteService->validateTokenForRegistration($inviteToken);
        $invite = $validation['invite'];
        $role = $validation['role'];

        return DB::transaction(function () use ($data, $invite, $role, $inviteToken) {
            $freshInvite = QRInvite::where('id', $invite->id)
                ->lockForUpdate()
                ->first();

            if (! $freshInvite || ! $freshInvite->isValid()) {
                $msg = $freshInvite && $freshInvite->max_uses !== null && $freshInvite->use_count >= $freshInvite->max_uses
                    ? __('invite.max_uses_reached')
                    : __('invite.already_used');
                throw ValidationException::withMessages([
                    'invite_token' => [$msg],
                ]);
            }

            /** @var string $password */
            $password = $data['password'];
            $data['password'] = Hash::make($password);
            $data['role'] = $role->value;
            $data['is_active'] = true;
            $data['created_by'] = $invite->created_by;
            $data['invite_id'] = $invite->id;
            $data['church_id'] = $invite->church_id;

            if ($role === UserRole::Member) {
                $data['servant_id'] = $invite->created_by;
            }

            if (! empty($data['class_id'])) {
                /** @var int $classId */
                $classId = $data['class_id'];
                $classe = Classe::where('id', $classId)
                    ->where('church_id', $invite->church_id)
                    ->first();
                if (! $classe) {
                    throw ValidationException::withMessages([
                        'class_id' => [__('invite.class_not_found')],
                    ]);
                }
            }

            $user = $this->userRepository->create($data);

            $user->email_verification_token = Str::random(64);
            $user->save();

            /** @var string $frontendUrl */
            $frontendUrl = config('app.frontend_url');
            $verificationUrl = $frontendUrl.'/verify-email?token='.urlencode($user->email_verification_token).'&email='.urlencode($user->email);
            try {
                $user->notify(new VerifyEmailNotification($user, $verificationUrl));
            } catch (\Exception $e) {
                Log::warning('Failed to send verification email', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }

            /** @var int $userId */
            $userId = $user->id;
            $used = $freshInvite->markAsUsed($userId);
            if (! $used) {
                throw ValidationException::withMessages([
                    'invite_token' => [__('invite.max_uses_reached')],
                ]);
            }

            Log::info('Invite consumed via registration', [
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

    /** @return array<string, mixed> */
    public function getAuthenticatedUser(User $user): array
    {
        return [
            'user' => $user->load(['classe', 'createdBy', 'invite', 'servant']),
        ];
    }

    /** @param array{email: string} $data */
    public function forgotPassword(array $data): array
    {
        /** @var string $email */
        $email = $data['email'];

        Password::sendResetLink(
            ['email' => $email]
        );

        return [
            'message' => 'If an account exists, a password reset link has been sent.',
        ];
    }

    /** @param array<string, mixed> $data */
    public function resetPassword(array $data): array
    {
        /** @var string|null $status */
        $status = Password::reset(
            $data,
            function (CanResetPassword $user, string $password) {
                /** @var User $user */
                $user->forceFill([
                    'password' => Hash::make($password),
                ])->setRememberToken(Str::random(60));

                $user->save();

                $user->tokens()->delete();

                try {
                    $user->notify(new PasswordChangedNotification);
                } catch (\Exception $e) {
                    Log::warning('Failed to send password changed notification', [
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
            'email' => [__(strval($status))],
        ]);
    }
}

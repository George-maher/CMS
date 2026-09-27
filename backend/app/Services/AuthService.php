<?php

namespace App\Services;

use App\Contracts\AuthServiceInterface;
use App\Contracts\PasswordResetRequestServiceInterface;
use App\Contracts\QRInviteServiceInterface;
use App\Contracts\UserRepositoryInterface;
use App\Enums\LoginFailureCode;
use App\Enums\UserRole;
use App\Enums\UserScope;
use App\Exceptions\LoginFailedException;
use App\Models\Church;
use App\Models\Classe;
use App\Models\QRInvite;
use App\Models\Scopes\ChurchScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class AuthService implements AuthServiceInterface
{
    public function __construct(
        private readonly UserRepositoryInterface $userRepository,
        private readonly QRInviteServiceInterface $qrInviteService,
        private readonly PasswordResetRequestServiceInterface $passwordResetRequestService,
        private readonly CacheService $cacheService,
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
            if ($user && Hash::check(Hash::make($password), $user->password)) {
                Log::info('Detected double-hashed password — re-hashing', [
                    'user_id' => $user->id,
                    'email' => $user->email,
                ]);
                $user->password = Hash::make($password);
                $user->save();
            } else {
                throw new LoginFailedException(LoginFailureCode::InvalidCredentials);
            }
        }

        if ($user->isPlatformAdmin()) {
            throw new LoginFailedException(LoginFailureCode::InvalidCredentials);
        }

        if ($user->is_active === false) {
            throw new LoginFailedException(LoginFailureCode::AccountInactive);
        }

        if (! $user->email_verified_at) {
            throw new LoginFailedException(LoginFailureCode::EmailNotVerified);
        }

        if ($user->church_id) {
            $church = Church::withTrashed()->where('id', $user->church_id)->first();
            if ($church && $church->is_suspended) {
                throw new LoginFailedException(LoginFailureCode::ChurchSuspended);
            }
            if ($church && $church->trashed()) {
                throw new LoginFailedException(LoginFailureCode::ChurchDeleted);
            }
        }

        $token = $user->createToken('auth-token', [$user->role->value])->plainTextToken;

        // Login is a public route: the fail-closed ChurchScope must not null out
        // the user's own class relation in the response.
        // Every relation UserResource reads must be eager-loaded here; the
        // resource never lazy-loads, so a missing key means a null field.
        $user->load([
            'classe' => static fn (Relation $query) => $query->withoutGlobalScope(ChurchScope::class),
            'servant',
            'church',
            'churchApplication',
            'createdBy',
        ]);

        $rejectionReason = null;
        if ($user->isRejected() && $user->churchApplication && $user->churchApplication->rejection_reason) {
            $rejectionReason = (string) $user->churchApplication->rejection_reason;
        }

        // Pending/rejected applicants receive an authenticated token so they can
        // track their application status and log out. Application access is later
        // enforced server-side by the CheckApproval/EnsureApproval middleware.
        return [
            'user' => $user,
            'token' => $token,
            'token_type' => 'Bearer',
            'application_status' => $user->application_status,
            'rejection_reason' => $rejectionReason,
        ];
    }

    /** @param array<string, mixed> $credentials */
    public function platformLogin(array $credentials): array
    {
        /** @var string $email */
        $email = $credentials['email'];
        /** @var string $password */
        $password = $credentials['password'];

        $user = $this->userRepository->findByEmail($email);

        if (! $user || ! Hash::check($password, $user->password)) {
            throw new LoginFailedException(LoginFailureCode::InvalidCredentials);
        }

        if (! $user->isPlatformAdmin()) {
            throw new LoginFailedException(LoginFailureCode::InvalidCredentials);
        }

        if (! $user->is_active) {
            throw new LoginFailedException(LoginFailureCode::AccountInactive);
        }

        if ($user->application_status === 'pending') {
            throw new LoginFailedException(LoginFailureCode::ApplicationPending);
        }

        if ($user->application_status === 'rejected') {
            throw new LoginFailedException(LoginFailureCode::ApplicationRejected);
        }

        $token = $user->createToken('auth-token', [$user->role->value])->plainTextToken;

        return [
            'user' => $user->load(['classe', 'createdBy', 'invite', 'servant', 'church']),
            'token' => $token,
            'token_type' => 'Bearer',
        ];
    }

    public function logout(User $user): void
    {
        /** @var int $userId */
        $userId = $user->id;
        $this->cacheService->invalidateUserAuth($userId);
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

        /** @var string $registerEmail */
        $registerEmail = $data['email'] ?? '';
        $data['email'] = strtolower(trim($registerEmail));

        return DB::transaction(function () use ($data, $invite, $role) {
            // The invite was already authorized by its token (findByToken runs
            // unscoped with explicit token binding); public registration has no
            // authenticated tenant, so the fail-closed ChurchScope must not hide
            // this lock re-fetch.
            $freshInvite = QRInvite::withoutGlobalScope(ChurchScope::class)
                ->where('id', $invite->id)
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
            $data['application_status'] = 'approved';
            $data['created_by'] = $invite->created_by;
            $data['invite_id'] = $invite->id;
            $data['church_id'] = $invite->church_id;

            // The QR invitation IS the proof of email ownership for this
            // tenant, and the only onboarding path that has one:
            //  - the token is a 64-char secret, single-use, expiring and
            //    revocable, physically handed over by the Church Admin;
            //  - the invitee sets their own email + password while holding
            //    it, and the Church Admin is vouching for that person;
            //  - church_id/stage_id/class_id are derived from the invite
            //    server-side, never from the client;
            //  - the platform has NO working outbound mail channel
            //    (Resend removed 2026-08-22), so a separate verification
            //    link could never be delivered and would lock the account
            //    out permanently.
            // This mirrors the existing admin-onboarding precedent in
            // ChurchApplicationService (email_verified_at on approval).
            $data['email_verified_at'] = now();

            if ($role === UserRole::Member) {
                $data['servant_id'] = $invite->created_by;
            }

            $resolvedStageId = $invite->stage_id !== null ? (int) $invite->stage_id : null;

            if (! empty($data['class_id'])) {
                /** @var int $classId */
                $classId = $data['class_id'];
                // Explicitly church-bound: safe to bypass the fail-closed scope
                // (public request context) because the church filter is kept.
                $classe = Classe::withoutGlobalScope(ChurchScope::class)
                    ->where('id', $classId)
                    ->where('church_id', $invite->church_id)
                    ->first();
                if (! $classe) {
                    throw ValidationException::withMessages([
                        'class_id' => [__('invite.class_not_found')],
                    ]);
                }
                $classStageId = $classe->stage_id !== null ? (int) $classe->stage_id : null;
                if ($invite->stage_id !== null && $classStageId !== null && $classStageId !== (int) $invite->stage_id) {
                    throw ValidationException::withMessages([
                        'class_id' => [__('invite.class_stage_mismatch')],
                    ]);
                }
                $resolvedStageId ??= $classStageId;
            }

            $data['stage_id'] = $resolvedStageId;
            $data['scope'] = $role === UserRole::Member ? UserScope::Self->value : UserScope::ClassScope->value;

            $user = $this->userRepository->create($data);

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
                'user_id' => $user->id,
                'role' => $role->value,
            ]);

            return [
                'user' => $user->load([
                    'classe' => static fn (Relation $query) => $query->withoutGlobalScope(ChurchScope::class),
                ]),
                'message' => 'Registration successful. You can now log in with your credentials.',
            ];
        });
    }

    /** @return array<string, mixed> */
    public function getAuthenticatedUser(User $user): array
    {
        /** @var int $userId */
        $userId = $user->id;

        $freshUser = $this->cacheService->rememberUserAuth($userId, function () use ($userId) {
            return User::with(['classe', 'createdBy', 'invite', 'servant', 'church'])->find($userId);
        });

        if (! $freshUser) {
            return [
                'user' => $user->load(['classe', 'createdBy', 'invite', 'servant', 'church']),
            ];
        }

        return [
            'user' => $freshUser,
        ];
    }

    /** @param array{email: string} $data */
    public function forgotPassword(array $data): array
    {
        // Security: this endpoint must NOT issue a reset link immediately.
        // All reset requests converge on the admin-approval workflow so the
        // password is only changed after their Church Admin approves and sets
        // the new password. No email is involved in password recovery.
        return $this->passwordResetRequestService->submitRequest($data);
    }
}

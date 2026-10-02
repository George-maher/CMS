<?php

declare(strict_types=1);

namespace App\Modules\User\Services;

use App\Contracts\AttendanceServiceInterface;
use App\Contracts\ScopeResolverInterface;
use App\Contracts\UserProvisioningServiceInterface;
use App\Contracts\UserRepositoryInterface;
use App\Contracts\UserServiceInterface;
use App\Enums\ProvisioningChannel;
use App\Enums\UserRole;
use App\Enums\UserScope;
use App\Models\Classe;
use App\Models\Stage;
use App\Models\User;
use App\Modules\User\Resources\UserResource;
use App\Services\CacheService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class UserService implements UserServiceInterface
{
    public function __construct(
        private readonly UserRepositoryInterface $userRepository,
        private readonly AttendanceServiceInterface $attendanceService,
        private readonly CacheService $cacheService,
        private readonly ScopeResolverInterface $scopeResolver,
        private readonly UserProvisioningServiceInterface $userProvisioning,
    ) {}

    /** @param array<string, mixed> $filters */
    public function listUsers(int $perPage = 15, array $filters = []): array
    {
        /** @var LengthAwarePaginator<int, User> $paginator */
        $paginator = $this->userRepository->paginate($perPage, $filters);

        return [
            'data' => UserResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array
    {
        /** @var User|null $user */
        $user = $this->userRepository->findById($id);

        return $user ? ['data' => new UserResource($user->load(['classe.stage', 'servant', 'church']))] : null;
    }

    /** @param array<string, mixed> $data */
    /** @return array<string, mixed> */
    public function create(array $data, ?int $authUserId = null): array
    {
        $emailInput = $data['email'] ?? '';
        $email = is_string($emailInput) ? strtolower(trim($emailInput)) : '';
        /** @var string $password */
        $password = $data['password'] ?? '';

        /** @var User|null $authUser */
        $authUser = $authUserId ? User::find($authUserId) : null;

        if ($authUser !== null && $authUser->isStageAdmin()) {
            /** @var string $role */
            $role = $data['role'] ?? UserRole::Member->value;
            if (! in_array($role, [UserRole::Member->value, UserRole::Servant->value], true)) {
                throw ValidationException::withMessages(['role' => ['Stage admins may only create members or servants.']]);
            }

            /** @var int|null $classId */
            $classId = $data['class_id'] ?? null;
            if ($classId === null) {
                throw ValidationException::withMessages(['class_id' => ['A class within your stage is required.']]);
            }

            /** @var Classe|null $classe */
            $classe = Classe::query()->where('id', $classId)->first();
            if ($classe === null || ! $this->scopeResolver->canAccessClass($authUser, $classe)) {
                throw ValidationException::withMessages(['class_id' => ['The selected class is outside your stage.']]);
            }

            // The stage is always the class's stage — never trust a client-supplied
            // stage_id from a stage admin (it cannot widen beyond the scoped class).
            if ($classe->stage_id !== null) {
                $data['stage_id'] = (int) $classe->stage_id;
            }
        }

        if ($authUser !== null && ! $authUser->isStageAdmin()) {
            // Defense in depth for the class ownership boundary. The Class is
            // resolved from the database and its owning Church derived from the
            // persisted relationship, never from a client-supplied church_id
            // or from the stored `scope` column. Stage admins keep the
            // stricter branch above unchanged.
            /** @var int|null $classId */
            $classId = isset($data['class_id']) && is_numeric($data['class_id']) ? (int) $data['class_id'] : null;
            if ($classId !== null) {
                /** @var Classe|null $classe */
                $classe = Classe::query()->find($classId);
                if ($classe === null || ! $this->scopeResolver->canAccessClass($authUser, $classe)) {
                    throw new AuthorizationException('The selected class is outside your authority.');
                }
            }

            // Defense in depth for the stage ownership boundary, mirroring the
            // class check above. This service is callable from commands,
            // seeders and jobs where the controller's middleware never runs, so
            // the stage has to be re-resolved here as well.
            // `Stage::query()` carries the `ChurchScope` global scope, so a
            // stage belonging to another church does not resolve at all.
            /** @var int|null $requestedStageId */
            $requestedStageId = isset($data['stage_id']) && is_numeric($data['stage_id']) ? (int) $data['stage_id'] : null;
            if ($requestedStageId !== null) {
                /** @var Stage|null $stage */
                $stage = Stage::query()->find($requestedStageId);
                if ($stage === null || ! $this->scopeResolver->canAccessStage($authUser, $stage)) {
                    throw new AuthorizationException('The selected stage is outside your authority.');
                }
            }
        }

        /** @var array<string, mixed> $data */
        $data['password'] = Hash::make($password);

        /** @var string $role */
        $role = $data['role'] ?? UserRole::Member->value;
        /** @var int|null $stageId */
        $stageId = isset($data['stage_id']) && is_numeric($data['stage_id']) ? (int) $data['stage_id'] : null;
        /** @var int|null $classId */
        $classId = isset($data['class_id']) && is_numeric($data['class_id']) ? (int) $data['class_id'] : null;

        if ($stageId === null && $classId !== null) {
            $stageId = $this->stageForClass($classId);
        }

        if ($role === UserRole::StageAdmin->value && $stageId === null) {
            throw ValidationException::withMessages(['stage_id' => ['A stage is required for stage admins.']]);
        }

        $data['created_by'] = $authUserId;
        $data['church_id'] = $this->resolveChurchId($data, $authUser);
        $data['application_status'] = 'approved';
        $data['is_active'] = $data['is_active'] ?? true;

        $user = $this->userProvisioning->create([
            'name' => $data['name'] ?? '',
            'email' => $email,
            'password' => $data['password'],
            'role' => $role,
            'class_id' => $data['class_id'] ?? null,
            'stage_id' => $stageId,
            'scope' => $this->deriveScopeValue($role, $stageId),
            'phone' => $data['phone'] ?? null,
            'address' => $data['address'] ?? null,
            'birthday' => $data['birthday'] ?? null,
            'member_id' => $data['member_id'] ?? null,
            'member_address' => $data['member_address'] ?? null,
            'church_id' => $data['church_id'],
            'is_active' => $data['is_active'],
            'application_status' => 'approved',
            'created_by' => $authUserId,
            'attendance_qr_token' => User::generateAttendanceQrToken(),
        ], ProvisioningChannel::AdminCreated);

        return [
            'message' => 'User created successfully.',
            'data' => new UserResource($user->load(['classe.stage', 'servant', 'church'])),
        ];
    }

    /** @param array<string, mixed> $data */
    /** @return array<string, mixed>|null */
    public function update(int $id, array $data, int $authUserId): ?array
    {
        /** @var User|null $user */
        $user = $this->userRepository->findById($id);

        if (! $user) {
            return null;
        }

        /** @var User|null $authUser */
        $authUser = User::find($authUserId);
        if ($authUser === null) {
            throw ValidationException::withMessages(['user' => ['Forbidden.']]);
        }

        if (! $authUser->isPlatformAdmin()) {
            // Defense-in-depth for the controller's P0 escalation guards:
            // privileged-role changes and password resets are church-admin tier
            // only, and admin-tier targets are immutable to lower-tier callers.
            if (($user->isAdmin() || $user->isPlatformAdmin()) && ! $authUser->isAdmin()) {
                throw ValidationException::withMessages(['user' => ['Forbidden.']]);
            }

            if (array_key_exists('role', $data) && ! $authUser->isAdmin()) {
                $requestedRoleValue = is_string($data['role']) ? $data['role'] : null;
                $currentRoleValue = $user->role?->value;
                if (in_array($requestedRoleValue, $this->privilegedRoleValues(), true)
                    || in_array($currentRoleValue, $this->privilegedRoleValues(), true)) {
                    throw ValidationException::withMessages(['role' => ['Forbidden.']]);
                }
            }

            if (array_key_exists('password', $data) && ! $authUser->isAdmin()) {
                throw ValidationException::withMessages(['password' => ['Forbidden.']]);
            }
        }

        if (isset($data['password'])) {
            /** @var string $password */
            $password = $data['password'];
            $data['password'] = Hash::make($password);
        }

        /** @var string|null $currentRole */
        $currentRole = $user->role?->value;
        /** @var string|null $targetRole */
        $targetRole = isset($data['role']) && is_string($data['role']) ? $data['role'] : $currentRole;

        // Defense in depth for the class ownership boundary. The target Class is
        // resolved from the database and its owning Church derived from the
        // persisted relationship — never from a client-supplied church_id and
        // never from the stored `scope` column. Mirrors create().
        if (array_key_exists('class_id', $data) && isset($data['class_id']) && is_numeric($data['class_id'])) {
            /** @var int $targetClassId */
            $targetClassId = (int) $data['class_id'];
            /** @var Classe|null $targetClass */
            $targetClass = Classe::query()->find($targetClassId);

            if ($targetClass === null || ! $this->scopeResolver->canAccessClass($authUser, $targetClass)) {
                throw ValidationException::withMessages(['class_id' => ['The selected class is outside your authority.']]);
            }
        }

        if (array_key_exists('stage_id', $data) || $targetRole !== $currentRole || array_key_exists('class_id', $data)) {
            /** @var int|null $stageId */
            $stageId = array_key_exists('stage_id', $data)
                ? (isset($data['stage_id']) && is_numeric($data['stage_id']) ? (int) $data['stage_id'] : null)
                : ($user->stage_id !== null ? (int) $user->stage_id : null);

            if ($stageId === null && array_key_exists('class_id', $data)) {
                /** @var int|null $targetClassId */
                $targetClassId = isset($data['class_id']) && is_numeric($data['class_id']) ? (int) $data['class_id'] : null;
                $stageId = $this->stageForClass($targetClassId);
            }

            if ($targetRole === UserRole::StageAdmin->value && $stageId === null) {
                throw ValidationException::withMessages(['stage_id' => ['A stage is required for stage admins.']]);
            }

            $data['stage_id'] = $stageId;
            $data['scope'] = $this->deriveScopeValue($targetRole ?? UserRole::Member->value, $stageId);
        }

        /** @var array<string, mixed> $updateData */
        $updateData = $data;
        $this->userRepository->update($id, $updateData);
        $this->cacheService->invalidateUserAuth($id);

        return [
            'message' => 'User updated successfully.',
            'data' => new UserResource($user->load(['classe.stage', 'servant', 'church'])),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function privilegedRoleValues(): array
    {
        return [
            UserRole::Admin->value,
            UserRole::AssistantAdmin->value,
            UserRole::StageAdmin->value,
        ];
    }

    public function delete(int $id): bool
    {
        /** @var User|null $user */
        $user = $this->userRepository->findById($id);

        if (! $user) {
            return false;
        }

        $deleted = $this->userRepository->delete($id);
        if ($deleted) {
            $this->cacheService->invalidateUserAuth($id);
        }

        return $deleted;
    }

    /** @return array<string, mixed> */
    public function servants(int $churchId): array
    {
        $servants = $this->userRepository->getServantsByChurch($churchId);

        return [
            'data' => UserResource::collection($servants),
        ];
    }

    /** @return array<string, mixed> */
    public function getMembers(int $servantId, ?int $classYearId = null): array
    {
        $members = $this->userRepository->getMembersByServant($servantId);

        return [
            'data' => UserResource::collection($members),
        ];
    }

    /** @return array<string, mixed> */
    public function promote(int $userId, int $authUserId, string $newRole, ?int $stageId = null): array
    {
        /** @var User|null $user */
        $user = $this->userRepository->findById($userId);

        if (! $user) {
            throw ValidationException::withMessages(['user' => ['User not found.']]);
        }

        /** @var User|null $authUser */
        $authUser = User::find($authUserId);
        if ($authUser !== null && ! $this->scopeResolver->canAccessUser($authUser, $user)) {
            throw ValidationException::withMessages(['user' => ['Forbidden.']]);
        }

        if (in_array($newRole, [UserRole::Admin->value, UserRole::AssistantAdmin->value, UserRole::StageAdmin->value], true)
            && $authUser?->isAdmin() !== true) {
            throw ValidationException::withMessages(['role' => ['Forbidden.']]);
        }

        if ($newRole === UserRole::StageAdmin->value && $stageId === null) {
            throw ValidationException::withMessages(['stage_id' => ['A stage is required for stage admins.']]);
        }

        $user->role = UserRole::from($newRole);

        // Honor the explicitly assigned stage for stage admins (the controller
        // validated it against the actor's scope); privileged church roles and
        // members have no stage; servants inherit their class's stage.
        $effectiveStageId = match ($newRole) {
            UserRole::StageAdmin->value => $stageId,
            UserRole::Admin->value,
            UserRole::AssistantAdmin->value,
            UserRole::Member->value => null,
            default => $this->stageForClass($user->class_id) ?? ($user->stage_id !== null ? (int) $user->stage_id : null),
        };

        $user->stage_id = $effectiveStageId;
        $user->scope = $this->deriveScopeValue($newRole, $effectiveStageId);
        $user->save();

        return [
            'message' => 'User promoted successfully.',
            'data' => new UserResource($user),
        ];
    }

    /** @return array<string, mixed> */
    public function demoteFromAdmin(int $userId, int $authUserId, string $newRole = 'member'): array
    {
        /** @var User|null $user */
        $user = $this->userRepository->findById($userId);

        if (! $user) {
            throw ValidationException::withMessages(['user' => ['User not found.']]);
        }

        /** @var User|null $authUser */
        $authUser = User::find($authUserId);
        if ($authUser !== null && ! $this->scopeResolver->canAccessUser($authUser, $user)) {
            throw ValidationException::withMessages(['user' => ['Forbidden.']]);
        }

        $result = $this->userRepository->demoteFromAdmin($userId, $newRole);

        if (! $result) {
            throw ValidationException::withMessages(['user' => ['User not found or cannot be demoted.']]);
        }

        $user->refresh();

        // Respect the same stage semantics: members lose stage/scope,
        // servants inherit their class's stage (falling back to their current one).
        $effectiveStageId = match ($newRole) {
            UserRole::Member->value => null,
            default => $this->stageForClass($user->class_id) ?? ($user->stage_id !== null ? (int) $user->stage_id : null),
        };

        $user->stage_id = $effectiveStageId;
        $user->scope = $this->deriveScopeValue($newRole, $effectiveStageId);
        $user->save();

        $this->cacheService->invalidateUserAuth($userId);

        return ['message' => 'User demoted from admin successfully.'];
    }

    /** @return array<string, mixed> */
    public function getAttendanceHistory(int $userId, int $perPage = 15): array
    {
        /** @var array<string, mixed> $result */
        $result = $this->attendanceService->getAttendanceHistory($userId, $perPage);

        return $result;
    }

    /** @return array<string, mixed> */
    public function getAvailablePermissions(int $userId): array
    {
        /** @var User|null $user */
        $user = $this->userRepository->findById($userId);

        if (! $user) {
            throw ValidationException::withMessages(['user' => ['User not found.']]);
        }

        return [
            'data' => $user->getAvailablePermissions(),
        ];
    }

    /** @param array<int, string> $permissions */
    /** @return array<string, mixed> */
    public function updatePermissions(int $userId, array $permissions, int $authUserId): array
    {
        /** @var User|null $user */
        $user = $this->userRepository->findById($userId);

        if (! $user) {
            throw ValidationException::withMessages(['user' => ['User not found.']]);
        }

        /** @var User|null $authUser */
        $authUser = User::find($authUserId);
        if ($authUser !== null && ! $this->scopeResolver->canAccessUser($authUser, $user)) {
            throw ValidationException::withMessages(['user' => ['Forbidden.']]);
        }
        if ($user->isAdmin() && $authUser?->isAdmin() !== true) {
            throw ValidationException::withMessages(['user' => ['Forbidden.']]);
        }

        /** @var array<int, string> $permissionList */
        $permissionList = $permissions;
        $user->syncPermissions($permissionList);

        return ['message' => 'Permissions updated successfully.'];
    }

    /**
     * @param  array<int, int>  $userIds
     * @param  array<int, string>  $permissions
     * @return array<string, mixed>
     */
    /**
     * Replace the permission set for a batch of users.
     *
     * ATOMIC. The whole batch is all-or-nothing.
     *
     * The loop below mixes validation with mutation: it authorizes each user
     * and immediately writes that user's permissions. Without a transaction,
     * a request naming [allowed, forbidden] would leave the first user's
     * permissions already rewritten when the second throws — a partial
     * mutation that the caller is told failed. The HTTP controller happens to
     * pre-validate every id before calling this, but the service is public and
     * is called from places where that pre-check does not apply, and it is
     * also the layer that is supposed to be safe on its own.
     *
     * `syncPermissions()` has its own inner transaction, which on PostgreSQL
     * becomes a savepoint once nested, so the outer transaction is what makes
     * the batch atomic rather than merely tidier.
     */
    public function bulkUpdatePermissions(array $userIds, array $permissions, int $authUserId): array
    {
        /** @var Collection<int, User> $users */
        $users = $this->userRepository->findByIds($userIds);

        /** @var User|null $authUser */
        $authUser = User::find($authUserId);

        $updated = DB::transaction(function () use ($users, $authUser, $permissions): int {
            foreach ($users as $user) {
                if ($authUser !== null && ! $this->scopeResolver->canAccessUser($authUser, $user)) {
                    throw ValidationException::withMessages(['user' => ['Forbidden.']]);
                }
                if ($user->isAdmin() && $authUser?->isAdmin() !== true) {
                    throw ValidationException::withMessages(['user' => ['Forbidden.']]);
                }
                $user->syncPermissions($permissions);
            }

            return $users->count();
        });

        return ['message' => 'Permissions updated successfully for '.$updated.' users.'];
    }

    /** @return array<string, mixed> */
    /**
     * Issue a fresh attendance QR token for a user.
     *
     * This is a trusted internal method: it takes a resolved user id and does
     * not re-derive the actor. Two properties make that safe today, and both are
     * asserted by tests so neither can be removed silently:
     *
     *  1. `userRepository->findById()` is church-scoped, so a user from another
     *     tenant cannot be addressed at all (404 / null).
     *  2. Every caller performs the scope check before calling:
     *      - `UserController::regenerateAttendanceToken()` — `canAccessUser()`
     *        against the resolved target.
     *      - `UserController::regenerateOwnQrToken()` — passes the actor's own
     *        id, which is trivially in scope.
     *
     * `canAccessUser()` is deliberately NOT repeated here: it needs the actor
     * id, which this signature does not carry, and adding it would mean
     * changing the public contract for a check that no caller can bypass. If a
     * future caller is added, it must resolve the target the same way — see
     * TenantArchitectureTest for the named-owner guardrail.
     */
    public function regenerateAttendanceToken(int $userId): array
    {
        /** @var User|null $user */
        $user = $this->userRepository->findById($userId);

        if (! $user) {
            throw ValidationException::withMessages(['user' => ['User not found.']]);
        }

        $token = User::generateAttendanceQrToken();
        $user->attendance_qr_token = $token;
        $user->save();

        return [
            'message' => 'Attendance QR token regenerated successfully.',
            'token' => $token,
        ];
    }

    /** @param array<string, mixed> $data */
    private function resolveChurchId(array $data, ?User $authUser): ?int
    {
        if ($authUser !== null) {
            return $authUser->church_id;
        }

        $fallback = $data['church_id'] ?? null;

        return is_int($fallback) ? $fallback : null;
    }

    private function stageForClass(?int $classId): ?int
    {
        if ($classId === null) {
            return null;
        }

        $stageValue = Classe::query()->where('id', $classId)->value('stage_id');

        return is_numeric($stageValue) ? (int) $stageValue : null;
    }

    /**
     * Derive the org scope a role should be stored with.
     */
    private function deriveScopeValue(string $role, ?int $stageId): UserScope
    {
        return match ($role) {
            UserRole::PlatformAdmin->value,
            UserRole::Admin->value,
            UserRole::AssistantAdmin->value => UserScope::Church,
            UserRole::StageAdmin->value => $stageId !== null ? UserScope::Stage : UserScope::Self,
            UserRole::Servant->value => UserScope::ClassScope,
            default => UserScope::Self,
        };
    }
}

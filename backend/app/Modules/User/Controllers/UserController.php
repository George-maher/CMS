<?php

namespace App\Modules\User\Controllers;

use App\Contracts\ScopeResolverInterface;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Classe;
use App\Models\Stage;
use App\Models\User;
use App\Modules\User\Requests\CreateUserRequest;
use App\Modules\User\Requests\RoleRequest;
use App\Modules\User\Requests\UpdateUserRequest;
use App\Modules\User\Services\UserService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function __construct(
        private readonly UserService $userService,
        private readonly ScopeResolverInterface $scopeResolver,
    ) {}

    public function index(Request $request): JsonResponse
    {
        /** @var int|string $perPage */
        $perPage = $request->input('per_page', 15);
        $perPage = (int) $perPage;

        /** @var array<string, mixed> $filters */
        $filters = $request->only(['role', 'class_id', 'search', 'stage_id', 'membership_status', 'is_active']);

        /** @var User|null $authUser */
        $authUser = $request->user();
        if ($authUser !== null && ! $authUser->isAdmin()) {
            unset($filters['class_id']);
            unset($filters['stage_id']);

            if ($authUser->isStageAdmin()) {
                $filters['class_ids'] = $this->scopeResolver->allowedClassIds($authUser);
                if ($authUser->stage_id !== null) {
                    // Stage admins also see stage-scoped users not tied to a class
                    // (e.g. pivot-assigned servants whose users.class_id is null).
                    $filters['scope_stage_id'] = (int) $authUser->stage_id;
                }
            }
        }

        $result = $this->userService->listUsers($perPage, $filters);

        return response()->json($result);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        /** @var User|null $authUser */
        $authUser = $request->user();

        if ($authUser !== null && ! $authUser->isPlatformAdmin()) {
            $target = User::byChurch()->find($id);
            if ($target === null) {
                return response()->json(['message' => 'User not found.'], 404);
            }
            if (! $this->scopeResolver->canAccessUser($authUser, $target)) {
                return response()->json(['message' => 'Forbidden.'], 403);
            }
        }

        $user = $this->userService->findById($id);

        if (! $user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        return response()->json($user);
    }

    public function store(CreateUserRequest $request): JsonResponse
    {
        /** @var array<string, mixed> $data */
        $data = $request->validated();
        $authUser = $request->user();

        if ($authUser !== null && $authUser->isStageAdmin()) {
            /** @var string $role */
            $role = $data['role'] ?? UserRole::Member->value;
            if (! in_array($role, [UserRole::Member->value, UserRole::Servant->value], true)) {
                return response()->json(['message' => 'Forbidden.'], 403);
            }
            /** @var int|null $classId */
            $classId = $data['class_id'] ?? null;
            if ($classId === null || ! $this->classWithinScope($authUser, $classId)) {
                return response()->json(['message' => 'Forbidden.'], 403);
            }
        }

        /** @var string $role */
        $role = $data['role'] ?? UserRole::Member->value;
        if ($role === UserRole::StageAdmin->value) {
            /** @var int|null $stageId */
            $stageId = isset($data['stage_id']) && is_numeric($data['stage_id']) ? (int) $data['stage_id'] : null;
            if ($stageId === null || ! $this->stageWithinScope($authUser, $stageId)) {
                return response()->json(['message' => 'Forbidden.'], 403);
            }
        }

        $result = $this->userService->create($data, $authUser?->id);

        return response()->json($result, 201);
    }

    public function update(UpdateUserRequest $request, int $id): JsonResponse
    {
        /** @var array<string, mixed> $data */
        $data = $request->validated();
        $authUser = $request->user();

        if ($authUser !== null && ! $authUser->isPlatformAdmin()) {
            $target = User::byChurch()->find($id);
            if ($target === null) {
                return response()->json(['message' => 'User not found.'], 404);
            }
            if (! $this->scopeResolver->canAccessUser($authUser, $target)) {
                return response()->json(['message' => 'Forbidden.'], 403);
            }
            if ($authUser->isStageAdmin()) {
                /** @var int|null $classId */
                $classId = isset($data['class_id']) && is_numeric($data['class_id']) ? (int) $data['class_id'] : $target->class_id;
                $classe = $classId !== null ? $this->resolveClasse($classId) : null;
                if ($classe === null || ! $this->scopeResolver->canAccessClass($authUser, $classe)) {
                    return response()->json(['message' => 'Forbidden.'], 403);
                }
            }

            /** @var int|null $requestedStageId */
            $requestedStageId = isset($data['stage_id']) && is_numeric($data['stage_id']) ? (int) $data['stage_id'] : null;
            if (($data['role'] ?? null) === UserRole::StageAdmin->value) {
                /** @var int|null $checkStageId */
                $checkStageId = $requestedStageId ?? $target->stage_id;
                if ($checkStageId === null || ! $this->stageWithinScope($authUser, $checkStageId)) {
                    return response()->json(['message' => 'Forbidden.'], 403);
                }
            } elseif ($requestedStageId !== null) {
                if (! $this->stageWithinScope($authUser, $requestedStageId)) {
                    return response()->json(['message' => 'Forbidden.'], 403);
                }
            }

            // P0 escalation guards (mirror promote() and bulkUpdatePermissions()):
            // privileged-role changes, password resets, and edits to admin-tier
            // targets are church-admin tier only — update() must never bypass the
            // authority rules enforced on the promote()/destroy() endpoints.
            if (($target->isAdmin() || $target->isPlatformAdmin()) && ! $authUser->isAdmin()) {
                return response()->json(['message' => 'Forbidden.'], 403);
            }

            if (array_key_exists('role', $data) && ! $authUser->isAdmin()) {
                $requestedRoleValue = is_string($data['role']) ? $data['role'] : null;
                $currentRoleValue = $target->role?->value;
                if (in_array($requestedRoleValue, $this->privilegedRoleValues(), true)
                    || in_array($currentRoleValue, $this->privilegedRoleValues(), true)) {
                    return response()->json(['message' => 'Forbidden.'], 403);
                }
            }

            if (array_key_exists('password', $data) && ! $authUser->isAdmin()) {
                return response()->json(['message' => 'Forbidden.'], 403);
            }
        }

        if ($authUser === null) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $result = $this->userService->update($id, $data, (int) $authUser->id);

        if (! $result) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        return response()->json($result);
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

    public function destroy(Request $request, int $id): JsonResponse
    {
        /** @var User|null $authUser */
        $authUser = $request->user();
        if ($authUser === null) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }
        if (! $authUser->isAdmin() && ! $authUser->isStageAdmin()) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $target = User::byChurch()->find($id);
        if ($target === null) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        if (! $this->scopeResolver->canAccessUser($authUser, $target)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
        if ($target->isAdmin() || $target->isPlatformAdmin()) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $deleted = $this->userService->delete($id);

        if (! $deleted) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        return response()->json(['message' => 'User deleted successfully.']);
    }

    public function servants(Request $request): JsonResponse
    {
        $authUser = $request->user();
        $churchId = $authUser?->church_id;

        if ($churchId === null) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if ($authUser->isStageAdmin() && $authUser->stage_id) {
            $servants = User::byChurch()
                ->where('role', UserRole::Servant)
                ->where(function (Builder $q) use ($authUser) {
                    $q->where('stage_id', $authUser->stage_id)
                        ->orWhereIn('id', function (\Illuminate\Database\Query\Builder $q2) use ($authUser) {
                            $q2->select('user_id')
                                ->from('class_servant')
                                ->whereIn('class_id', function (\Illuminate\Database\Query\Builder $q3) use ($authUser) {
                                    $q3->select('id')
                                        ->from('classes')
                                        ->where('stage_id', $authUser->stage_id);
                                });
                        });
                })
                ->orderBy('name')
                ->get();

            return response()->json(['data' => $servants]);
        }

        $result = $this->userService->servants($churchId);

        return response()->json($result);
    }

    public function members(Request $request): JsonResponse
    {
        $authUser = $request->user();
        $servantId = $authUser?->id;

        if ($servantId === null) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $result = $this->userService->getMembers($servantId);

        return response()->json($result);
    }

    public function servantsMembers(Request $request, int $servantId): JsonResponse
    {
        /** @var User|null $authUser */
        $authUser = $request->user();
        if ($authUser === null) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }
        if (! $authUser->isPlatformAdmin()) {
            $servant = User::byChurch()->find($servantId);
            if ($servant === null) {
                return response()->json(['message' => 'Servant not found.'], 404);
            }
            if (! $this->scopeResolver->canAccessUser($authUser, $servant)) {
                return response()->json(['message' => 'Forbidden.'], 403);
            }
        }

        $result = $this->userService->getMembers($servantId);

        return response()->json($result);
    }

    /**
     * Resolve a class model, honoring the church global scope.
     */
    private function resolveClasse(int $classId): ?Classe
    {
        return Classe::query()->where('id', $classId)->first();
    }

    /**
     * Whether a stage admin's requested class falls within their stage.
     */
    private function classWithinScope(User $authUser, int $classId): bool
    {
        $classe = $this->resolveClasse($classId);
        if ($classe === null) {
            return false;
        }

        return $this->scopeResolver->canAccessClass($authUser, $classe);
    }

    /**
     * Whether the acting user may assign the given stage (used for stage_admin).
     */
    private function stageWithinScope(?User $authUser, int $stageId): bool
    {
        if ($authUser === null) {
            return false;
        }
        $stage = Stage::query()->find($stageId);
        if ($stage === null) {
            return false;
        }

        return $this->scopeResolver->canAccessStage($authUser, $stage);
    }

    public function promote(RoleRequest $request, int $id): JsonResponse
    {
        /** @var array<string, mixed> $data */
        $data = $request->validated();
        /** @var User $authUser */
        $authUser = $request->user();

        /** @var string $newRole */
        $newRole = $data['role'] ?? '';

        $target = User::byChurch()->find($id);
        if ($target === null) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        if (! $this->scopeResolver->canAccessUser($authUser, $target)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        if (in_array($newRole, [UserRole::Admin->value, UserRole::AssistantAdmin->value, UserRole::StageAdmin->value], true)) {
            // Only church admins may grant privileged roles.
            if (! $authUser->isAdmin()) {
                return response()->json(['message' => 'Forbidden.'], 403);
            }
        }

        /** @var int|null $stageId */
        $stageId = null;
        if ($newRole === UserRole::StageAdmin->value) {
            /** @var int|null $requestedStageId */
            $requestedStageId = isset($data['stage_id']) && is_numeric($data['stage_id']) ? (int) $data['stage_id'] : null;
            if ($requestedStageId === null || ! $this->stageWithinScope($authUser, $requestedStageId)) {
                return response()->json(['message' => 'Forbidden.'], 403);
            }
            $stageId = $requestedStageId;
        }

        /** @var int $authId */
        $authId = $authUser->id;
        $result = $this->userService->promote($id, $authId, $newRole, $stageId);

        return response()->json($result);
    }

    public function demote(Request $request, int $id): JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->user();

        /** @var string $newRole */
        $newRole = $request->input('role', 'member');

        $target = User::byChurch()->find($id);
        if ($target === null) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        if (! $this->scopeResolver->canAccessUser($authUser, $target)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        // Privileged targets (admin / assistant_admin / stage_admin / platform
        // admin) may only be demoted by church-admin tier — mirrors the
        // update() authority guard; stage admins cannot remove peers.
        $targetIsPrivileged = $target->isAdmin()
            || $target->isAssistantAdmin()
            || $target->isStageAdmin()
            || $target->isPlatformAdmin();
        if ($targetIsPrivileged && ! $authUser->isAdmin() && ! $authUser->isPlatformAdmin()) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        if (! in_array($newRole, [UserRole::Member->value, UserRole::Servant->value], true)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        // Stage admins may not demote a member/servant to servant if not in scope;
        // canAccessUser already covers scope. Church admins may demote anyone non-privileged.
        if ($authUser->isStageAdmin() && ! in_array($newRole, [UserRole::Member->value], true)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        /** @var int $authId */
        $authId = $authUser->id;
        $result = $this->userService->demoteFromAdmin($id, $authId, $newRole);

        return response()->json($result);
    }

    public function attendanceHistory(Request $request, int $userId): JsonResponse
    {
        /** @var User|null $authUser */
        $authUser = $request->user();
        if ($authUser === null) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }
        if (! $authUser->isPlatformAdmin()) {
            $target = User::byChurch()->find($userId);
            if ($target === null) {
                return response()->json(['message' => 'User not found.'], 404);
            }
            if (! $this->scopeResolver->canAccessUser($authUser, $target)) {
                return response()->json(['message' => 'Forbidden.'], 403);
            }
        }

        /** @var int|string $perPage */
        $perPage = $request->input('per_page', 15);
        $perPage = (int) $perPage;
        $result = $this->userService->getAttendanceHistory($userId, $perPage);

        return response()->json($result);
    }

    public function availablePermissions(Request $request, int $userId): JsonResponse
    {
        /** @var User|null $authUser */
        $authUser = $request->user();
        if ($authUser === null) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }
        if (! $authUser->isPlatformAdmin()) {
            $target = User::byChurch()->find($userId);
            if ($target === null) {
                return response()->json(['message' => 'User not found.'], 404);
            }
            if (! $this->scopeResolver->canAccessUser($authUser, $target)) {
                return response()->json(['message' => 'Forbidden.'], 403);
            }
        }

        $result = $this->userService->getAvailablePermissions($userId);

        return response()->json($result);
    }

    public function updatePermissions(Request $request, int $userId): JsonResponse
    {
        /** @var array<string, mixed> $data */
        $data = $request->validate([
            'permissions' => 'required|array',
            'permissions.*' => 'string',
        ]);

        /** @var User $authUser */
        $authUser = $request->user();

        $target = User::byChurch()->find($userId);
        if ($target === null) {
            return response()->json(['message' => 'User not found.'], 404);
        }
        if (! $this->scopeResolver->canAccessUser($authUser, $target)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
        if ($target->isAdmin() && ! $authUser->isAdmin()) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        /** @var array<int, string> $permissions */
        $permissions = $data['permissions'];
        /** @var int $authId */
        $authId = $authUser->id;
        $result = $this->userService->updatePermissions($userId, $permissions, $authId);

        return response()->json($result);
    }

    public function bulkUpdatePermissions(Request $request): JsonResponse
    {
        /** @var array{user_ids: array<int, int>, permissions: array<int, string>} $data */
        $data = $request->validate([
            'user_ids' => 'required|array',
            'user_ids.*' => 'integer|exists:users,id',
            'permissions' => 'required|array',
            'permissions.*' => 'string',
        ]);

        /** @var User $authUser */
        $authUser = $request->user();

        $userIds = array_values(array_unique($data['user_ids']));

        foreach ($userIds as $userId) {
            $target = User::byChurch()->find($userId);
            if ($target === null) {
                return response()->json(['message' => 'User not found.'], 404);
            }
            if (! $this->scopeResolver->canAccessUser($authUser, $target)) {
                return response()->json(['message' => 'Forbidden.'], 403);
            }
            if ($target->isAdmin() && ! $authUser->isAdmin()) {
                return response()->json(['message' => 'Forbidden.'], 403);
            }
        }

        /** @var int $authId */
        $authId = $authUser->id;

        $result = $this->userService->bulkUpdatePermissions($userIds, $data['permissions'], $authId);

        return response()->json($result);
    }

    public function regenerateAttendanceToken(Request $request, int $userId): JsonResponse
    {
        /** @var User|null $authUser */
        $authUser = $request->user();
        if ($authUser === null) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if (! $authUser->isPlatformAdmin()) {
            $target = User::byChurch()->find($userId);
            if ($target === null) {
                return response()->json(['message' => 'User not found.'], 404);
            }
            if (! $this->scopeResolver->canAccessUser($authUser, $target)) {
                return response()->json(['message' => 'Forbidden.'], 403);
            }
        }

        /** @var int $authId */
        $authId = $authUser->id;

        $result = $this->userService->regenerateAttendanceToken($userId);

        return response()->json(['data' => [
            'token' => $result['token'] ?? '',
        ]]);
    }

    public function regenerateOwnQrToken(Request $request): JsonResponse
    {
        $authUser = $request->user();
        if ($authUser === null) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $result = $this->userService->regenerateAttendanceToken((int) $authUser->id);

        return response()->json(['data' => [
            'token' => $result['token'] ?? '',
        ]]);
    }
}

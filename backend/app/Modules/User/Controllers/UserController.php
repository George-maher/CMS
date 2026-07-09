<?php

namespace App\Modules\User\Controllers;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Modules\User\Requests\CreateUserRequest;
use App\Modules\User\Requests\UpdateUserRequest;
use App\Modules\User\Requests\RoleRequest;
use App\Modules\User\Services\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function __construct(
        private readonly UserService $userService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        /** @var int|string $perPage */
        $perPage = $request->input('per_page', 15);
        $perPage = (int) $perPage;

        /** @var array<string, mixed> $filters */
        $filters = $request->only(['role', 'class_id', 'search', 'stage_id', 'membership_status', 'is_active']);

        $result = $this->userService->listUsers($perPage, $filters);

        return response()->json($result);
    }

    public function show(int $id): JsonResponse
    {
        $user = $this->userService->findById($id);

        if (!$user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        return response()->json($user);
    }

    public function store(CreateUserRequest $request): JsonResponse
    {
        /** @var array<string, mixed> $data */
        $data = $request->validated();
        $authUser = $request->user();

        $result = $this->userService->create($data, $authUser?->id);

        return response()->json($result, 201);
    }

    public function update(UpdateUserRequest $request, int $id): JsonResponse
    {
        /** @var array<string, mixed> $data */
        $data = $request->validated();

        $result = $this->userService->update($id, $data);

        if (!$result) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        return response()->json($result);
    }

    public function destroy(int $id): JsonResponse
    {
        $deleted = $this->userService->delete($id);

        if (!$deleted) {
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
        $result = $this->userService->getMembers($servantId);

        return response()->json($result);
    }

    private function getAuthId(Request $request): int
    {
        /** @var \App\Models\User|null $user */
        $user = $request->user();
        if ($user === null) {
            throw new \Symfony\Component\HttpKernel\Exception\HttpException(401, 'Unauthenticated.');
        }
        /** @var int $userId */
        $userId = $user->id;
        return $userId;
    }

    public function promote(RoleRequest $request): JsonResponse
    {
        /** @var array<string, mixed> $data */
        $data = $request->validated();
        $authId = $this->getAuthId($request);

        /** @var int|string $rawUserId */
        $rawUserId = $data['user_id'] ?? 0;
        $userId = (int) $rawUserId;
        /** @var string $newRole */
        $newRole = $data['role'] ?? '';

        $result = $this->userService->promote($userId, $authId, $newRole);

        return response()->json($result);
    }

    public function demote(Request $request): JsonResponse
    {
        /** @var int|string $rawUserId */
        $rawUserId = $request->input('user_id', 0);
        $userId = (int) $rawUserId;
        $authId = $this->getAuthId($request);

        $result = $this->userService->demoteFromAdmin($userId, $authId);

        return response()->json($result);
    }

    public function attendanceHistory(Request $request, int $userId): JsonResponse
    {
        /** @var int|string $perPage */
        $perPage = $request->input('per_page', 15);
        $perPage = (int) $perPage;
        $result = $this->userService->getAttendanceHistory($userId, $perPage);

        return response()->json($result);
    }

    public function availablePermissions(Request $request, int $userId): JsonResponse
    {
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

        $authId = $this->getAuthId($request);

        /** @var array<int, string> $permissions */
        $permissions = $data['permissions'];
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

        $authId = $this->getAuthId($request);

        $userIds = array_values(array_unique($data['user_ids']));

        $result = $this->userService->bulkUpdatePermissions($userIds, $data['permissions'], $authId);

        return response()->json($result);
    }

    public function regenerateAttendanceToken(Request $request, int $userId): JsonResponse
    {
        $authUser = $request->user();
        $authId = $authUser?->id;

        if ($authId === null) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $result = $this->userService->regenerateAttendanceToken($userId);

        return response()->json($result);
    }
}

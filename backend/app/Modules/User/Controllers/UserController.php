<?php

namespace App\Modules\User\Controllers;

use App\Contracts\UserServiceInterface;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Modules\User\Requests\CreateUserRequest;
use App\Modules\User\Requests\RoleRequest;
use App\Modules\User\Requests\UpdateUserRequest;
use App\Modules\User\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function __construct(
        private readonly UserServiceInterface $userService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $result = $this->userService->listUsers(
            perPage: $request->input('per_page', 15),
            filters: $request->only(['role', 'class_year_id', 'class_id', 'is_active', 'search', 'created_by'])
        );

        return response()->json([
            'data' => UserResource::collection($result['data']),
            'meta' => $result['meta'],
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $result = $this->userService->getUser($id);

        return response()->json([
            'data' => new UserResource($result['user']),
        ]);
    }

    public function store(CreateUserRequest $request): JsonResponse
    {
        $result = $this->userService->createUser($request->validated());

        return response()->json([
            'message' => 'User created successfully.',
            'data' => new UserResource($result['user']),
        ], 201);
    }

    public function update(UpdateUserRequest $request, int $id): JsonResponse
    {
        $result = $this->userService->updateUser($id, $request->validated());

        return response()->json([
            'message' => 'User updated successfully.',
            'data' => new UserResource($result['user']),
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $this->userService->deleteUser($id);

        return response()->json([
            'message' => 'User deleted successfully.',
        ]);
    }

    public function servants(int $id): JsonResponse
    {
        $result = $this->userService->getServants($id);

        return response()->json([
            'data' => UserResource::collection($result['data']),
        ]);
    }

    public function servantsMe(Request $request): JsonResponse
    {
        return $this->servants($request->user()->id);
    }

    public function members(Request $request, int $servantId = null): JsonResponse
    {
        $user = $request->user();

        if ($user->role === UserRole::Servant) {
            $classId = $user->class_id ?? $user->class_year_id;
            $result = $this->userService->getMembers(
                servantId: $user->id,
                classYearId: $classId
            );
        } else {
            $id = $servantId ?? $user->id;
            $result = $this->userService->getMembers($id);
        }

        return response()->json([
            'data' => UserResource::collection($result['data']),
        ]);
    }

    public function promote(int $id): JsonResponse
    {
        $result = $this->userService->promoteToAdmin($id);

        return response()->json([
            'message' => 'User promoted to admin successfully.',
            'data' => $result['data'],
        ]);
    }

    public function demote(RoleRequest $request, int $id): JsonResponse
    {
        $result = $this->userService->demoteFromAdmin($id, $request->validated()['role']);

        return response()->json([
            'message' => 'Admin demoted successfully.',
            'data' => $result['data'],
        ]);
    }

    public function myClassServants(Request $request): JsonResponse
    {
        $user = $request->user();
        $churchId = $user->church_id;
        $classId = $user->class_id;

        $contacts = [];

        // 1. Fetch servants assigned to this class (pivot + direct)
        if ($classId) {
            $pivotIds = \App\Models\Classe::find($classId)?->servants()
                ->pluck('users.id')
                ->toArray() ?? [];

            $directIds = \App\Models\User::byChurch($churchId)
                ->where('role', UserRole::Servant)
                ->where('class_id', $classId)
                ->whereNotIn('id', $pivotIds)
                ->pluck('id')
                ->toArray();

            $allServantIds = array_unique(array_merge($pivotIds, $directIds));

            if (!empty($allServantIds)) {
                $servants = \App\Models\User::whereIn('id', $allServantIds)
                    ->get(['id', 'name', 'phone', 'avatar', 'role']);

                foreach ($servants as $s) {
                    $contacts[] = [
                        'id' => $s->id,
                        'name' => $s->name,
                        'phone' => $s->phone,
                        'avatar' => $s->avatar,
                        'role' => $s->role?->value,
                        'role_label' => $s->role?->label(),
                        'type' => 'servant',
                    ];
                }
            }
        }

        // 2. Always include Admin + AssistantAdmin from the church
        if ($churchId) {
            $admins = \App\Models\User::byChurch($churchId)
                ->whereIn('role', [UserRole::Admin, UserRole::AssistantAdmin])
                ->get(['id', 'name', 'phone', 'avatar', 'role']);

            foreach ($admins as $a) {
                $exists = collect($contacts)->first(fn($c) => $c['id'] === $a->id);
                if ($exists) continue;

                $contacts[] = [
                    'id' => $a->id,
                    'name' => $a->name,
                    'phone' => $a->phone,
                    'avatar' => $a->avatar,
                    'role' => $a->role?->value,
                    'role_label' => $a->role?->label(),
                    'type' => $a->role === UserRole::Admin ? 'admin' : 'assistant_admin',
                ];
            }
        }

        return response()->json([
            'data' => array_values($contacts),
        ]);
    }

    public function regenerateOwnQrToken(Request $request): JsonResponse
    {
        $result = $this->userService->regenerateAttendanceToken($request->user()->id);

        return response()->json([
            'message' => 'Attendance QR token regenerated successfully.',
            'data' => $result,
        ]);
    }

    public function regenerateUserQrToken(int $id): JsonResponse
    {
        $result = $this->userService->regenerateAttendanceToken($id);

        return response()->json([
            'message' => 'Attendance QR token regenerated successfully.',
            'data' => $result,
        ]);
    }
}

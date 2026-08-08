<?php

declare(strict_types=1);

namespace App\Modules\User\Services;

use App\Contracts\AttendanceServiceInterface;
use App\Contracts\UserRepositoryInterface;
use App\Contracts\UserServiceInterface;
use App\Enums\UserRole;
use App\Models\User;
use App\Modules\User\Resources\UserResource;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class UserService implements UserServiceInterface
{
    public function __construct(
        private readonly UserRepositoryInterface $userRepository,
        private readonly AttendanceServiceInterface $attendanceService,
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

        /** @var array<string, mixed> $data */
        $data['password'] = Hash::make($password);
        $data['created_by'] = $authUserId;
        $data['church_id'] = $this->resolveChurchId($data, $authUser);
        $data['application_status'] = 'approved';
        $data['is_active'] = $data['is_active'] ?? true;

        $user = $this->userRepository->create([
            'name' => $data['name'] ?? '',
            'email' => $email,
            'password' => $data['password'],
            'role' => $data['role'] ?? UserRole::Member->value,
            'class_id' => $data['class_id'] ?? null,
            'class_year_id' => $data['class_year_id'] ?? null,
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
        ]);

        return [
            'message' => 'User created successfully.',
            'data' => new UserResource($user->load(['classe.stage', 'servant', 'church'])),
        ];
    }

    /** @param array<string, mixed> $data */
    /** @return array<string, mixed>|null */
    public function update(int $id, array $data): ?array
    {
        /** @var User|null $user */
        $user = $this->userRepository->findById($id);

        if (! $user) {
            return null;
        }

        if (isset($data['password'])) {
            /** @var string $password */
            $password = $data['password'];
            $data['password'] = Hash::make($password);
        }

        /** @var array<string, mixed> $updateData */
        $updateData = $data;
        $this->userRepository->update($id, $updateData);

        return [
            'message' => 'User updated successfully.',
            'data' => new UserResource($user->load(['classe.stage', 'servant', 'church'])),
        ];
    }

    public function delete(int $id): bool
    {
        /** @var User|null $user */
        $user = $this->userRepository->findById($id);

        if (! $user) {
            return false;
        }

        return $this->userRepository->delete($id);
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
    public function promote(int $userId, int $authUserId, string $newRole): array
    {
        /** @var User|null $user */
        $user = $this->userRepository->findById($userId);

        if (! $user) {
            throw ValidationException::withMessages(['user' => ['User not found.']]);
        }

        $user->role = UserRole::from($newRole);
        $user->save();

        return [
            'message' => 'User promoted successfully.',
            'data' => new UserResource($user),
        ];
    }

    /** @return array<string, mixed> */
    public function demoteFromAdmin(int $userId, int $authUserId, string $newRole = 'member'): array
    {
        $result = $this->userRepository->demoteFromAdmin($userId, $newRole);

        if (! $result) {
            throw ValidationException::withMessages(['user' => ['User not found or cannot be demoted.']]);
        }

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
    public function bulkUpdatePermissions(array $userIds, array $permissions, int $authUserId): array
    {
        /** @var Collection<int, User> $users */
        $users = $this->userRepository->findByIds($userIds);

        foreach ($users as $user) {
            $user->syncPermissions($permissions);
        }

        return ['message' => 'Permissions updated successfully for '.$users->count().' users.'];
    }

    /** @return array<string, mixed> */
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
}

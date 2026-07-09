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

        $data = UserResource::collection($paginator->items());

        return [
            'data' => $paginator,
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
        /** @var string $email */
        $email = $data['email'] ?? '';
        /** @var string $password */
        $password = $data['password'] ?? '';

        /** @var array<string, mixed> $data */
        $data['password'] = Hash::make($password);
        $data['created_by'] = $authUserId;
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
    public function demoteFromAdmin(int $userId, int $authUserId): array
    {
        $result = $this->userRepository->demoteFromAdmin($userId);

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
        /** @var \Illuminate\Support\Collection<int, User> $users */
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
}

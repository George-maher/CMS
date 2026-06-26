<?php

namespace App\Modules\User\Services;

use App\Contracts\AuditServiceInterface;
use App\Contracts\UserRepositoryInterface;
use App\Contracts\UserServiceInterface;
use App\Enums\UserRole;
use App\Models\User;
use App\Modules\User\Resources\UserResource;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class UserService implements UserServiceInterface
{
    public function __construct(
        private readonly UserRepositoryInterface $userRepository,
        private readonly AuditServiceInterface $auditService,
    ) {}

    public function listUsers(int $perPage = 15, array $filters = []): array
    {
        $paginator = $this->userRepository->paginate($perPage, $filters);

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

    public function getUser(int $id): array
    {
        $user = $this->userRepository->findById($id);

        if (!$user) {
            throw ValidationException::withMessages([
                'user' => ['User not found.'],
            ]);
        }

        return [
            'user' => $user->load(['classe.stage', 'createdBy']),
        ];
    }

    public function createUser(array $data): array
    {
        $existingUser = $this->userRepository->findByEmailByChurch($data['email']);
        if ($existingUser) {
            throw ValidationException::withMessages([
                'email' => ['A user with this email already exists in your church.'],
            ]);
        }

        $data['password'] = Hash::make($data['password'] ?? 'password');
        $data['is_active'] = true;
        if (empty($data['church_id']) && auth()->check()) {
            $data['church_id'] = auth()->user()->church_id;
        }

        $user = $this->userRepository->create($data);

        return [
            'user' => $user->load('classe'),
        ];
    }

    public function updateUser(int $id, array $data): array
    {
        $user = $this->userRepository->findById($id);

        if (!$user) {
            throw ValidationException::withMessages([
                'user' => ['User not found.'],
            ]);
        }

        if (!empty($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        } else {
            unset($data['password']);
        }

        $this->userRepository->update($id, $data);

        return [
            'user' => $user->fresh()->load(['classe', 'createdBy']),
        ];
    }

    public function deleteUser(int $id): bool
    {
        $user = $this->userRepository->findById($id);

        if (!$user) {
            throw ValidationException::withMessages([
                'user' => ['User not found.'],
            ]);
        }

        $this->auditService->log(
            action: 'user.deleted',
            resourceType: 'user',
            resourceId: $id,
            oldValues: $user->toArray(),
            newValues: null,
        );

        return $this->userRepository->delete($id);
    }

    public function getServants(int $adminId): array
    {
        $servants = $this->userRepository->findServantsByAdmin($adminId);

        return [
            'data' => $servants,
        ];
    }

    public function getMembers(int $servantId, ?int $classYearId = null): array
    {
        if ($classYearId) {
            $members = $this->userRepository->findMembersByClassYear($classYearId);
        } else {
            $members = $this->userRepository->findMembersByServant($servantId);
        }

        return [
            'data' => $members,
        ];
    }

    public function promoteToAdmin(int $id): array
    {
        $user = $this->userRepository->findById($id);

        if (!$user) {
            throw ValidationException::withMessages([
                'user' => ['User not found.'],
            ]);
        }

        if ($user->role === UserRole::Admin) {
            throw ValidationException::withMessages([
                'user' => ['User is already an admin.'],
            ]);
        }

        $oldRole = $user->role->value;
        $this->userRepository->updateRole($id, UserRole::Admin->value);

        $this->auditService->log(
            action: 'user.promoted',
            resourceType: 'user',
            resourceId: $id,
            oldValues: ['role' => $oldRole],
            newValues: ['role' => UserRole::Admin->value],
        );

        return [
            'data' => new UserResource($user->fresh()->load(['classe', 'createdBy'])),
        ];
    }

    public function demoteFromAdmin(int $id, string $newRole): array
    {
        $user = $this->userRepository->findById($id);

        if (!$user) {
            throw ValidationException::withMessages([
                'user' => ['User not found.'],
            ]);
        }

        if ($user->role !== UserRole::Admin) {
            throw ValidationException::withMessages([
                'user' => ['User is not an admin.'],
            ]);
        }

        $adminCount = $this->userRepository->countAdmins();

        if ($adminCount <= 1) {
            throw ValidationException::withMessages([
                'user' => ['Cannot demote the last admin. At least one admin must remain.'],
            ]);
        }

        $oldRole = $user->role->value;
        $this->userRepository->updateRole($id, $newRole);

        $this->auditService->log(
            action: 'user.demoted',
            resourceType: 'user',
            resourceId: $id,
            oldValues: ['role' => $oldRole],
            newValues: ['role' => $newRole],
        );

        return [
            'data' => new UserResource($user->fresh()->load(['classe', 'createdBy'])),
        ];
    }

    public function regenerateAttendanceToken(int $id): array
    {
        $user = $this->userRepository->findById($id);

        if (!$user) {
            throw ValidationException::withMessages([
                'user' => ['User not found.'],
            ]);
        }

        $oldToken = $user->attendance_qr_token;
        $token = User::generateAttendanceQrToken();

        $this->userRepository->update($id, ['attendance_qr_token' => $token]);

        $this->auditService->log(
            action: 'user.qr_token_regenerated',
            resourceType: 'user',
            resourceId: $id,
            oldValues: ['attendance_qr_token' => '***hidden***'],
            newValues: ['attendance_qr_token' => '***regenerated***'],
        );

        return [
            'token' => $token,
        ];
    }
}

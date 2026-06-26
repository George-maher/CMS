<?php

namespace App\Modules\User\Repositories;

use App\Contracts\UserRepositoryInterface;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class UserRepository implements UserRepositoryInterface
{
    public function findById(int $id)
    {
        $query = User::query();

        if (auth()->check() && !auth()->user()?->isPlatformAdmin()) {
            $query->byChurch();
        }

        return $query->find($id);
    }

    public function findByEmail(string $email)
    {
        return User::where('email', $email)->first();
    }

    public function findByEmailByChurch(string $email): ?User
    {
        return User::byChurch()->where('email', $email)->first();
    }

    public function create(array $data)
    {
        return User::create($data);
    }

    public function update(int $id, array $data): bool
    {
        $user = $this->findById($id);
        if (!$user) {
            return false;
        }
        return $user->update($data);
    }

    public function delete(int $id): bool
    {
        $user = $this->findById($id);
        if (!$user) {
            return false;
        }
        return $user->delete();
    }

    public function paginate(int $perPage = 15, array $filters = []): LengthAwarePaginator
    {
        $query = User::query()->byChurch();

        if (!empty($filters['role'])) {
            $query->where('role', $filters['role']);
        }

        if (!empty($filters['class_id'])) {
            $query->where('class_id', $filters['class_id']);
        } elseif (!empty($filters['class_year_id'])) {
            $query->where('class_year_id', $filters['class_year_id']);
        }

        if (!empty($filters['is_active'])) {
            $query->where('is_active', $filters['is_active'] === 'true' || $filters['is_active'] === true);
        }

        if (!empty($filters['search'])) {
            $query->search($filters['search']);
        }

        if (!empty($filters['created_by'])) {
            $query->where('created_by', $filters['created_by']);
        }

        return $query->with('classe')->latest()->paginate($perPage);
    }

    public function findServantsByAdmin(int $adminId): Collection
    {
        return User::byChurch()
            ->byRole(UserRole::Servant)
            ->where('created_by', $adminId)
            ->active()
            ->get();
    }

    public function findMembersByServant(int $servantId): Collection
    {
        $servant = $this->findById($servantId);
        if (!$servant) return collect();

        $classIds = $servant->classes()->pluck('classes.id');

        if ($classIds->isEmpty()) {
            $fallbackClassId = $servant->class_id ?? $servant->class_year_id;
            if ($fallbackClassId) {
                return User::byChurch()
                    ->byRole(UserRole::Member)
                    ->where(function ($q) use ($fallbackClassId) {
                        $q->where('class_id', $fallbackClassId)
                          ->orWhere('class_year_id', $fallbackClassId);
                    })
                    ->active()
                    ->get();
            }
            return collect();
        }

        return User::byChurch()
            ->byRole(UserRole::Member)
            ->whereIn('class_id', $classIds)
            ->active()
            ->get();
    }

    public function findMembersByClassYear(int $classYearId): Collection
    {
        return User::byChurch()
            ->byRole(UserRole::Member)
            ->where(function ($q) use ($classYearId) {
                $q->where('class_id', $classYearId)
                  ->orWhere('class_year_id', $classYearId);
            })
            ->active()
            ->with(['servant', 'classe'])
            ->get();
    }

    public function paginateMembersByClassYear(int $classYearId, int $perPage = 15): LengthAwarePaginator
    {
        return User::byChurch()
            ->byRole(UserRole::Member)
            ->where(function ($q) use ($classYearId) {
                $q->where('class_id', $classYearId)
                  ->orWhere('class_year_id', $classYearId);
            })
            ->active()
            ->with(['servant', 'classe'])
            ->paginate($perPage);
    }

    public function countAdmins(): int
    {
        return User::byChurch()->byRole(UserRole::Admin)->count();
    }

    public function updateRole(int $id, string $role): bool
    {
        $user = $this->findById($id);
        if (!$user) {
            return false;
        }
        return $user->update(['role' => $role]);
    }
}

<?php

namespace App\Modules\User\Repositories;

use App\Contracts\UserRepositoryInterface;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class UserRepository implements UserRepositoryInterface
{
    public function findById(int $id): ?User
    {
        $query = User::query();

        if (auth()->check() && ! auth()->user()?->isPlatformAdmin()) {
            $query->byChurch();
        }

        return $query->find($id);
    }

    public function findByEmail(string $email): ?User
    {
        return User::where('email', $email)->first();
    }

    public function findByEmailByChurch(string $email): ?User
    {
        return User::byChurch()->where('email', $email)->first();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): User
    {
        return User::create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(int $id, array $data): bool
    {
        $user = $this->findById($id);
        if (! $user) {
            return false;
        }

        return $user->update($data);
    }

    public function delete(int $id): bool
    {
        $user = $this->findById($id);
        if (! $user) {
            return false;
        }

        return (bool) $user->delete();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, User>
     */
    public function paginate(int $perPage = 15, array $filters = []): LengthAwarePaginator
    {
        $query = User::query()->byChurch();

        if (! empty($filters['role'])) {
            $query->where('role', $filters['role']);
        }

        if (! empty($filters['class_id'])) {
            $query->where('class_id', $filters['class_id']);
        } elseif (! empty($filters['class_year_id'])) {
            $query->where('class_year_id', $filters['class_year_id']);
        }

        if (! empty($filters['is_active'])) {
            $query->where('is_active', $filters['is_active'] === 'true' || $filters['is_active'] === true);
        }

        if (! empty($filters['search'])) {
            /** @var string $search */
            $search = $filters['search'];
            $query->search($search);
        }

        if (! empty($filters['created_by'])) {
            $query->where('created_by', $filters['created_by']);
        }

        return $query->with('classe')->latest()->paginate($perPage);
    }

    /**
     * @return Collection<int, User>
     */
    public function findMembersByServant(int $servantId): Collection
    {
        $servant = $this->findById($servantId);
        if (! $servant) {
            return new Collection;
        }

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

            return new Collection;
        }

        return User::byChurch()
            ->byRole(UserRole::Member)
            ->whereIn('class_id', $classIds)
            ->active()
            ->get();
    }

    /**
     * @return Collection<int, User>
     */
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

    /**
     * @return LengthAwarePaginator<int, User>
     */
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
        if (! $user) {
            return false;
        }

        return $user->update(['role' => $role]);
    }

    /**
     * All active Servants belonging to the given church.
     *
     * @return Collection<int, User>
     */
    public function getServantsByChurch(int $churchId): Collection
    {
        return User::query()
            ->byChurch($churchId)
            ->byRole(UserRole::Servant)
            ->active()
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<int, User>
     */
    public function getMembersByServant(int $servantId): Collection
    {
        return $this->findMembersByServant($servantId);
    }

    public function demoteFromAdmin(int $id, string $newRole = 'member'): bool
    {
        return $this->updateRole($id, $newRole);
    }

    /**
     * @param  array<int, int>  $ids
     * @return \Illuminate\Support\Collection<int, User>
     */
    /** @return \Illuminate\Support\Collection<int, User> */
    public function findByIds(array $ids): \Illuminate\Support\Collection
    {
        if (empty($ids)) {
            /** @var \Illuminate\Support\Collection<int, User> $empty */
            $empty = collect();

            return $empty;
        }

        $query = User::query();

        if (auth()->check() && ! auth()->user()?->isPlatformAdmin()) {
            $query->byChurch();
        }

        return $query->whereIn('id', $ids)->get()->keyBy('id');
    }
}

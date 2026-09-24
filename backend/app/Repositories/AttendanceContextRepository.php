<?php

namespace App\Repositories;

use App\Contracts\AttendanceContextRepositoryInterface;
use App\Models\AttendanceContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Schema;

class AttendanceContextRepository implements AttendanceContextRepositoryInterface
{
    public function findById(int $id): ?AttendanceContext
    {
        return AttendanceContext::query()->find($id);
    }

    public function findBySlug(string $slug): ?AttendanceContext
    {
        return AttendanceContext::query()->where('slug', $slug)->first();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): AttendanceContext
    {
        return AttendanceContext::create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(int $id, array $data): bool
    {
        $context = $this->findById($id);
        if (! $context) {
            return false;
        }

        return $context->update($data);
    }

    public function delete(int $id): bool
    {
        $context = $this->findById($id);
        if (! $context) {
            return false;
        }

        return (bool) $context->delete();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, AttendanceContext>
     */
    public function paginate(int $perPage, array $filters = []): LengthAwarePaginator
    {
        $query = AttendanceContext::query()
            ->with('creator')
            ->orderBy('is_active', 'desc')
            ->orderBy('name');

        if (Schema::hasColumn('attendance_contexts', 'updated_by')) {
            $query->with('updater');
        }

        if (array_key_exists('church_id', $filters) && $filters['church_id'] !== null) {
            $query->where(function ($q) use ($filters) {
                $q->where('church_id', $filters['church_id'])
                    ->orWhereNull('church_id');
            });
        }

        return $query->paginate($perPage);
    }

    /**
     * @return Collection<int, AttendanceContext>
     */
    public function getActive(): Collection
    {
        $churchId = auth()->user()?->church_id;
        if (! $churchId) {
            return new Collection;
        }

        $query = AttendanceContext::query()
            ->active()
            ->orderBy('name');

        $query->where(function ($q) use ($churchId) {
            $q->where('church_id', $churchId)
                ->orWhereNull('church_id');
        });

        return $query->get();
    }

    public function getDefault(): ?AttendanceContext
    {
        return null;
    }

    public function clearDefault(): int
    {
        return 0;
    }

    /**
     * @return Collection<int, AttendanceContext>
     */
    public function getActiveForChurch(int $churchId): Collection
    {
        return AttendanceContext::query()
            ->where(function ($q) use ($churchId) {
                $q->where('church_id', $churchId)
                    ->orWhereNull('church_id');
            })
            ->active()
            ->orderBy('name')
            ->get();
    }
}

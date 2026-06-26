<?php

namespace App\Repositories;

use App\Contracts\AttendanceContextRepositoryInterface;
use App\Models\AttendanceContext;
use App\Models\Scopes\ChurchScope;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Schema;

class AttendanceContextRepository implements AttendanceContextRepositoryInterface
{
    public function findById(int $id)
    {
        return AttendanceContext::withoutGlobalScope(ChurchScope::class)->find($id);
    }

    public function findBySlug(string $slug)
    {
        return AttendanceContext::withoutGlobalScope(ChurchScope::class)->where('slug', $slug)->first();
    }

    public function create(array $data)
    {
        return AttendanceContext::create($data);
    }

    public function update(int $id, array $data): bool
    {
        $context = $this->findById($id);
        if (!$context) return false;
        return $context->update($data);
    }

    public function delete(int $id): bool
    {
        $context = $this->findById($id);
        if (!$context) return false;
        return $context->delete();
    }

    public function paginate(int $perPage, array $filters = []): LengthAwarePaginator
    {
        $query = AttendanceContext::withoutGlobalScope(ChurchScope::class)
            ->with('creator')
            ->orderBy('is_active', 'desc')
            ->orderBy('name');

        if (Schema::hasColumn('attendance_contexts', 'updated_by')) {
            $query->with('updater');
        }

        if (!empty($filters['church_id'])) {
            $query->where(function ($q) use ($filters) {
                $q->where('church_id', $filters['church_id'])
                  ->orWhereNull('church_id');
            });
        }

        return $query->paginate($perPage);
    }

    public function getActive()
    {
        $churchId = auth()->user()?->church_id;
        $query = AttendanceContext::withoutGlobalScope(ChurchScope::class)
            ->active()
            ->orderBy('name');

        if ($churchId) {
            $query->where(function ($q) use ($churchId) {
                $q->where('church_id', $churchId)
                  ->orWhereNull('church_id');
            });
        }

        return $query->get();
    }

    public function getDefault()
    {
        return null;
    }

    public function clearDefault(): int
    {
        return 0;
    }

    public function getActiveForChurch(int $churchId)
    {
        return AttendanceContext::withoutGlobalScope(ChurchScope::class)
            ->where(function ($q) use ($churchId) {
                $q->where('church_id', $churchId)
                  ->orWhereNull('church_id');
            })
            ->active()
            ->orderBy('name')
            ->get();
    }
}

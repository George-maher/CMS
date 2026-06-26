<?php

namespace App\Repositories;

use App\Contracts\VerseRepositoryInterface;
use App\Models\DailyVerse;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class VerseRepository implements VerseRepositoryInterface
{
    public function findById(int $id)
    {
        return DailyVerse::with('creator')->find($id);
    }

    public function create(array $data)
    {
        return DailyVerse::create($data);
    }

    public function update(int $id, array $data): bool
    {
        $verse = $this->findById($id);
        if (!$verse) return false;
        return $verse->update($data);
    }

    public function delete(int $id): bool
    {
        $verse = $this->findById($id);
        if (!$verse) return false;
        return $verse->delete();
    }

    public function paginate(int $perPage, array $filters = []): LengthAwarePaginator
    {
        return DailyVerse::with('creator')
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);
    }

    public function getActive()
    {
        return DailyVerse::withoutGlobalScope(\App\Models\Scopes\ChurchScope::class)
            ->with('creator')
            ->active()
            ->latest()
            ->first();
    }

    public function deactivateAll(): int
    {
        return DailyVerse::where('is_active', true)->update(['is_active' => false]);
    }
}

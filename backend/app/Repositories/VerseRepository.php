<?php

namespace App\Repositories;

use App\Contracts\VerseRepositoryInterface;
use App\Models\DailyVerse;
use App\Models\Scopes\ChurchScope;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class VerseRepository implements VerseRepositoryInterface
{
    public function findById(int $id): ?DailyVerse
    {
        return DailyVerse::with('creator')->find($id);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): DailyVerse
    {
        return DailyVerse::create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(int $id, array $data): bool
    {
        $verse = $this->findById($id);
        if (! $verse) {
            return false;
        }

        return $verse->update($data);
    }

    public function delete(int $id): bool
    {
        $verse = $this->findById($id);
        if (! $verse) {
            return false;
        }

        return (bool) $verse->delete();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, DailyVerse>
     */
    public function paginate(int $perPage, array $filters = []): LengthAwarePaginator
    {
        return DailyVerse::with('creator')
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);
    }

    public function getActive(): ?DailyVerse
    {
        return DailyVerse::withoutGlobalScope(ChurchScope::class)
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

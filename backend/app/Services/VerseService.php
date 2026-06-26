<?php

namespace App\Services;

use App\Contracts\VerseRepositoryInterface;
use App\Contracts\VerseServiceInterface;
use App\Http\Resources\DailyVerseResource;
use Illuminate\Validation\ValidationException;

class VerseService implements VerseServiceInterface
{
    public function __construct(
        private readonly VerseRepositoryInterface $verseRepository,
        private readonly CacheService $cacheService,
    ) {}

    public function list(int $perPage = 15): array
    {
        $paginator = $this->verseRepository->paginate($perPage);

        return [
            'data' => DailyVerseResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }

    public function findById(int $id): ?array
    {
        $verse = $this->verseRepository->findById($id);
        if (!$verse) return null;

        return [
            'data' => new DailyVerseResource($verse),
        ];
    }

    public function create(array $data, int $creatorId): array
    {
        $verse = $this->verseRepository->create([
            'verse_text' => $data['verse_text'],
            'reference' => $data['reference'],
            'created_by' => $creatorId,
            'is_active' => $data['is_active'] ?? false,
        ]);

        if ($verse->is_active) {
            $this->verseRepository->deactivateAll();
            $verse->update(['is_active' => true]);
        }

        $this->cacheService->invalidateVerse($verse->church_id);

        return [
            'data' => new DailyVerseResource($verse->load('creator')),
        ];
    }

    public function update(int $id, array $data): array
    {
        $verse = $this->verseRepository->findById($id);
        if (!$verse) {
            throw ValidationException::withMessages([
                'id' => ['Verse not found.'],
            ]);
        }

        $this->verseRepository->update($id, $data);

        if (!empty($data['is_active'])) {
            $this->verseRepository->deactivateAll();
            $verse->update(['is_active' => true]);
        }

        $this->cacheService->invalidateVerse($verse->church_id);

        return [
            'data' => new DailyVerseResource($verse->fresh()->load('creator')),
        ];
    }

    public function delete(int $id): void
    {
        $verse = $this->verseRepository->findById($id);
        if (!$verse) {
            throw ValidationException::withMessages([
                'id' => ['Verse not found.'],
            ]);
        }
        $this->verseRepository->delete($id);
    }

    public function activate(int $id): array
    {
        $verse = $this->verseRepository->findById($id);
        if (!$verse) {
            throw ValidationException::withMessages([
                'id' => ['Verse not found.'],
            ]);
        }

        $this->verseRepository->deactivateAll();
        $verse->update(['is_active' => true]);

        return [
            'data' => new DailyVerseResource($verse->fresh()->load('creator')),
        ];
    }

    public function getActive(): ?array
    {
        $churchId = auth()->user()->church_id;

        return $this->cacheService->rememberActiveVerse($churchId, function () {
            $verse = $this->verseRepository->getActive();
            if (!$verse) return null;

            return [
                'data' => new DailyVerseResource($verse),
            ];
        });
    }
}

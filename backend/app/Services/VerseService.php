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

    /** @return array<string, mixed> */
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

    /** @return ?array<string, mixed> */
    public function findById(int $id): ?array
    {
        $verse = $this->verseRepository->findById($id);
        if (! $verse) {
            return null;
        }

        return [
            'data' => new DailyVerseResource($verse),
        ];
    }

    /** @param array<string, mixed> $data */
    /** @return array<string, mixed> */
    public function create(array $data, int $creatorId): array
    {
        /** @var string $verseText */
        $verseText = $data['verse_text'];
        /** @var string $reference */
        $reference = $data['reference'];

        $verse = $this->verseRepository->create([
            'verse_text' => $verseText,
            'reference' => $reference,
            'created_by' => $creatorId,
            'is_active' => $data['is_active'] ?? false,
        ]);

        if ($verse->is_active) {
            $this->verseRepository->deactivateAll();
            $verse->update(['is_active' => true]);
        }

        $this->cacheService->invalidateVerse(0);

        return [
            'data' => new DailyVerseResource($verse->load('creator')),
        ];
    }

    /** @param array<string, mixed> $data */
    /** @return array<string, mixed> */
    public function update(int $id, array $data): array
    {
        /** @var array<string, mixed> $data */
        $verse = $this->verseRepository->findById($id);
        if (! $verse) {
            throw ValidationException::withMessages([
                'id' => ['Verse not found.'],
            ]);
        }

        $this->verseRepository->update($id, $data);

        if (! empty($data['is_active'])) {
            $this->verseRepository->deactivateAll();
            $verse->update(['is_active' => true]);
        }

        $this->cacheService->invalidateVerse(0);

        return [
            'data' => new DailyVerseResource(($verse->fresh() ?? $verse)->load('creator')),
        ];
    }

    public function delete(int $id): void
    {
        $verse = $this->verseRepository->findById($id);
        if (! $verse) {
            throw ValidationException::withMessages([
                'id' => ['Verse not found.'],
            ]);
        }
        $this->verseRepository->delete($id);
    }

    /** @return array<string, mixed> */
    public function activate(int $id): array
    {
        $verse = $this->verseRepository->findById($id);
        if (! $verse) {
            throw ValidationException::withMessages([
                'id' => ['Verse not found.'],
            ]);
        }

        $this->verseRepository->deactivateAll();
        $verse->update(['is_active' => true]);

        $this->cacheService->invalidateVerse(0);

        return [
            'data' => new DailyVerseResource(($verse->fresh() ?? $verse)->load('creator')),
        ];
    }

    /** @return ?array<string, mixed> */
    public function getActive(): ?array
    {
        return $this->cacheService->rememberActiveVerse(0, function () {
            $verse = $this->verseRepository->getActive();
            if (! $verse) {
                return null;
            }

            return [
                'data' => new DailyVerseResource($verse),
            ];
        });
    }
}

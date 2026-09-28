<?php

namespace App\Contracts;

use App\Models\DailyVerse;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface VerseRepositoryInterface
{
    public function findById(int $id): ?DailyVerse;

    /** @param array<string, mixed> $data */
    public function create(array $data): DailyVerse;

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): bool;

    public function delete(int $id): bool;

    /** @param array<string, mixed> $filters */
    /** @return \Illuminate\Contracts\Pagination\LengthAwarePaginator<int, \App\Models\DailyVerse> */
    public function paginate(int $perPage, array $filters = []): LengthAwarePaginator;
    public function getActive(): ?DailyVerse;
    public function deactivateAll(): int;
}

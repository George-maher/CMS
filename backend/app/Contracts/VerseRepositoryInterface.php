<?php

namespace App\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface VerseRepositoryInterface
{
    public function findById(int $id): ?\App\Models\DailyVerse;
    public function create(array $data): \App\Models\DailyVerse;
    public function update(int $id, array $data): bool;
    public function delete(int $id): bool;
    public function paginate(int $perPage, array $filters = []): LengthAwarePaginator;
    public function getActive(): ?\App\Models\DailyVerse;
    public function deactivateAll(): int;
}

<?php

namespace App\Contracts;

interface VerseServiceInterface
{
    public function list(int $perPage = 15): array;
    public function findById(int $id): ?array;
    public function create(array $data, int $creatorId): array;
    public function update(int $id, array $data): array;
    public function delete(int $id): void;
    public function activate(int $id): array;
    public function getActive(): ?array;
}

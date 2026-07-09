<?php

namespace App\Contracts;

interface VerseServiceInterface
{
    /** @return array<string, mixed> */
    public function list(int $perPage = 15): array;
    /** @return array<string, mixed>|null */
    public function getActive(): ?array;
    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array;

    /** @param array<string, mixed> $data */
    /** @return array<string, mixed> */
    public function create(array $data, int $creatorId): array;

    /** @param array<string, mixed> $data */
    /** @return array<string, mixed> */
    public function update(int $id, array $data): array;

    public function delete(int $id): void;
    /** @return array<string, mixed> */
    public function activate(int $id): array;
}

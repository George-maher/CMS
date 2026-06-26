<?php

namespace App\Contracts;

interface UserServiceInterface
{
    public function listUsers(int $perPage = 15, array $filters = []): array;
    public function getUser(int $id): array;
    public function createUser(array $data): array;
    public function updateUser(int $id, array $data): array;
    public function deleteUser(int $id): bool;
    public function getServants(int $adminId): array;
    public function getMembers(int $servantId, ?int $classYearId = null): array;
    public function promoteToAdmin(int $id): array;
    public function demoteFromAdmin(int $id, string $newRole): array;
    public function regenerateAttendanceToken(int $id): array;
}

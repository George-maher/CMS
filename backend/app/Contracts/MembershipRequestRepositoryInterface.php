<?php

namespace App\Contracts;

interface MembershipRequestRepositoryInterface
{
    public function create(array $data);
    public function findById(int $id);
    public function paginate(int $perPage = 15, array $filters = []);
    public function update(int $id, array $data): bool;
    public function findByEmailChurch(string $email, int $churchId);
}

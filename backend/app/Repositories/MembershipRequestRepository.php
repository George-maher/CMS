<?php

namespace App\Repositories;

use App\Contracts\MembershipRequestRepositoryInterface;
use App\Models\MembershipRequest;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class MembershipRequestRepository implements MembershipRequestRepositoryInterface
{
    public function create(array $data)
    {
        return MembershipRequest::create($data);
    }

    public function findById(int $id)
    {
        return MembershipRequest::with(['reviewer'])->find($id);
    }

    public function paginate(int $perPage = 15, array $filters = []): LengthAwarePaginator
    {
        $query = MembershipRequest::query();

        if (!empty($filters['church_id'])) {
            $query->where('church_id', $filters['church_id']);
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query->latest()->paginate($perPage);
    }

    public function update(int $id, array $data): bool
    {
        $request = $this->findById($id);
        if (!$request) return false;
        return $request->update($data);
    }

    public function findByEmailChurch(string $email, int $churchId)
    {
        return MembershipRequest::where('email', $email)
            ->where('church_id', $churchId)
            ->first();
    }
}

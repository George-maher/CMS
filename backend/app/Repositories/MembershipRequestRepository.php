<?php

namespace App\Repositories;

use App\Contracts\MembershipRequestRepositoryInterface;
use App\Models\MembershipRequest;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class MembershipRequestRepository implements MembershipRequestRepositoryInterface
{
    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): MembershipRequest
    {
        return MembershipRequest::create($data);
    }

    public function findById(int $id): ?MembershipRequest
    {
        return MembershipRequest::with(['reviewer'])->find($id);
    }

    /**
     * @param array<string, mixed> $filters
     * @return LengthAwarePaginator<int, MembershipRequest>
     */
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

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): bool
    {
        $request = $this->findById($id);
        if (!$request) return false;
        return $request->update($data);
    }

    public function findByEmailChurch(string $email, int $churchId): ?MembershipRequest
    {
        return MembershipRequest::where('email', $email)
            ->where('church_id', $churchId)
            ->first();
    }
}

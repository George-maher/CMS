<?php

namespace App\Repositories;

use App\Contracts\FeedbackRepositoryInterface;
use App\Models\Feedback;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class FeedbackRepository implements FeedbackRepositoryInterface
{
    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): Feedback
    {
        return Feedback::create($data);
    }

    /**
     * @param array<string, mixed> $filters
     * @return LengthAwarePaginator<int, Feedback>
     */
    public function paginate(int $perPage = 15, array $filters = []): LengthAwarePaginator
    {
        $query = Feedback::with(['user', 'user.classe', 'user.classe.stage', 'replies.user']);

        if (!empty($filters['category'])) {
            $query->where('category', $filters['category']);
        }

        if (!empty($filters['is_resolved'])) {
            $query->where('is_resolved', $filters['is_resolved'] === 'true' || $filters['is_resolved'] === true);
        }

        if (!empty($filters['unresolved'])) {
            $query->where('is_resolved', false);
        }

        if (!empty($filters['class_year_ids']) && is_array($filters['class_year_ids'])) {
            $query->whereIn('class_year_id', $filters['class_year_ids']);
        } elseif (!empty($filters['class_year_id'])) {
            $query->where('class_year_id', $filters['class_year_id']);
        }

        if (!empty($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }

        if (!empty($filters['id'])) {
            $query->where('id', $filters['id']);
        }

        return $query->latest()->paginate($perPage);
    }

    public function findById(int $id): ?Feedback
    {
        return Feedback::with(['user', 'user.classe', 'user.classe.stage', 'replies.user'])->find($id);
    }

    public function markAsResolved(int $id): bool
    {
        $feedback = $this->findById($id);
        if (!$feedback) {
            return false;
        }
        return $feedback->update(['is_resolved' => true]);
    }

    /**
     * @param array<int, int>|int|null $classYearIds
     */
    public function countUnresolved(array|int|null $classYearIds = null): int
    {
        $query = Feedback::where('is_resolved', false);

        if (is_array($classYearIds) && !empty($classYearIds)) {
            $query->whereIn('class_year_id', $classYearIds);
        } elseif (is_numeric($classYearIds)) {
            $query->where('class_year_id', $classYearIds);
        }

        return $query->count();
    }
}

<?php

namespace App\Repositories;

use App\Contracts\QRInviteRepositoryInterface;
use App\Models\QRInvite;
use App\Models\Scopes\ChurchScope;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class QRInviteRepository implements QRInviteRepositoryInterface
{
    public function findById(int $id): ?QRInvite
    {
        return QRInvite::with(['classe.stage'])->find($id);
    }

    public function findByToken(string $token): ?QRInvite
    {
        return QRInvite::withoutGlobalScope(ChurchScope::class)
            ->where('token', $token)
            ->first();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): QRInvite
    {
        return QRInvite::create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(int $id, array $data): bool
    {
        $invite = $this->findById($id);
        if (! $invite) {
            return false;
        }

        return $invite->update($data);
    }

    public function delete(int $id): bool
    {
        $invite = $this->findById($id);
        if (! $invite) {
            return false;
        }

        return (bool) $invite->delete();
    }

    /**
     * @param  Builder<QRInvite>  $query
     */
    private function applySearch(Builder $query, string $search): void
    {
        $query->where(function (Builder $q) use ($search) {
            $q->where('token', 'like', "%{$search}%")
                ->orWhereHas('creator', function (Builder $u) use ($search) {
                    $u->where('name', 'ilike', "%{$search}%")
                        ->orWhere('email', 'ilike', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%")
                        ->orWhere('member_id', 'like', "%{$search}%");
                })
                ->orWhereHas('usedBy', function (Builder $u) use ($search) {
                    $u->where('name', 'ilike', "%{$search}%")
                        ->orWhere('email', 'ilike', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%")
                        ->orWhere('member_id', 'like', "%{$search}%");
                });
        });
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, QRInvite>
     */
    public function paginate(int $perPage = 15, array $filters = []): LengthAwarePaginator
    {
        $query = QRInvite::query();

        if (! empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (! empty($filters['created_by'])) {
            $query->where('created_by', $filters['created_by']);
        }

        if (! empty($filters['is_revoked'])) {
            $query->where('is_revoked', $filters['is_revoked'] === 'true' || $filters['is_revoked'] === true);
        }

        if (! empty($filters['status'])) {
            $status = $filters['status'];
            if ($status === 'used') {
                $query->where(function (Builder $q) {
                    $q->whereNotNull('used_at')
                        ->orWhere(function (Builder $q2) {
                            $q2->whereNotNull('max_uses')
                                ->whereColumn('use_count', '>=', 'max_uses');
                        });
                });
            } elseif ($status === 'unused') {
                $query->whereNull('used_at')->where('is_revoked', false)->where('expires_at', '>', now())
                    ->where(function (Builder $q) {
                        $q->whereNull('max_uses')
                            ->orWhereColumn('use_count', '<', 'max_uses');
                    });
            } elseif ($status === 'expired') {
                $query->whereNull('used_at')->where('expires_at', '<', now());
            } elseif ($status === 'revoked') {
                $query->where('is_revoked', true);
            }
        }

        if (! empty($filters['class_id'])) {
            /** @var int|string $classId */
            $classId = $filters['class_id'];
            $classId = (int) $classId;
            $query->where(function (Builder $q) use ($classId) {
                $q->where('qr_invites.class_id', $classId)
                    ->orWhereHas('usedBy', function (Builder $userQ) use ($classId) {
                        $userQ->where('class_id', $classId);
                    });

                // Prefer a native JSON containment check on PostgreSQL; other
                // drivers (e.g. SQLite in the test suite) fall back to the
                // relational checks above, which are fully DB-agnostic.
                if (DB::connection()->getDriverName() === 'pgsql') {
                    $q->orWhereRaw(
                        "EXISTS (SELECT 1 FROM jsonb_array_elements(used_by_users::jsonb) AS elem WHERE (elem->>'class_id')::int = ?)",
                        [$classId]
                    );
                }
            });
        } elseif (! empty($filters['class_year_id'])) {
            $query->where('class_year_id', $filters['class_year_id']);
        }

        if (! empty($filters['date_from'])) {
            /** @var string $dateFrom */
            $dateFrom = $filters['date_from'];
            $query->whereDate('created_at', '>=', $dateFrom);
        }

        if (! empty($filters['date_to'])) {
            /** @var string $dateTo */
            $dateTo = $filters['date_to'];
            $query->whereDate('created_at', '<=', $dateTo);
        }

        if (! empty($filters['expires_from'])) {
            /** @var string $expiresFrom */
            $expiresFrom = $filters['expires_from'];
            $query->whereDate('expires_at', '>=', $expiresFrom);
        }

        if (! empty($filters['expires_to'])) {
            /** @var string $expiresTo */
            $expiresTo = $filters['expires_to'];
            $query->whereDate('expires_at', '<=', $expiresTo);
        }

        if (! empty($filters['search'])) {
            /** @var string $search */
            $search = $filters['search'];
            $this->applySearch($query, $search);
        }

        return $query->with(['creator', 'usedBy.classe.stage', 'classe.stage', 'attendanceContext'])->latest()->paginate($perPage);
    }

    public function revoke(int $id): bool
    {
        $invite = $this->findById($id);
        if (! $invite) {
            return false;
        }

        return $invite->update(['is_revoked' => true]);
    }
}

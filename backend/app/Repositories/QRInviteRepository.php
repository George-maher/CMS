<?php

namespace App\Repositories;

use App\Contracts\QRInviteRepositoryInterface;
use App\Models\QRInvite;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class QRInviteRepository implements QRInviteRepositoryInterface
{
    public function findById(int $id)
    {
        return QRInvite::with(['classe.stage'])->find($id);
    }

    public function findByToken(string $token)
    {
        return QRInvite::withoutGlobalScope(\App\Models\Scopes\ChurchScope::class)
            ->where('token', $token)
            ->first();
    }

    public function create(array $data)
    {
        return QRInvite::create($data);
    }

    public function update(int $id, array $data): bool
    {
        $invite = $this->findById($id);
        if (!$invite) {
            return false;
        }
        return $invite->update($data);
    }

    public function delete(int $id): bool
    {
        $invite = $this->findById($id);
        if (!$invite) {
            return false;
        }
        return $invite->delete();
    }

    private function applySearch(\Illuminate\Database\Eloquent\Builder $query, string $search): void
    {
        $query->where(function ($q) use ($search) {
            $q->where('token', 'like', "%{$search}%")
              ->orWhereHas('creator', function ($u) use ($search) {
                  $u->where('name', 'ilike', "%{$search}%")
                    ->orWhere('email', 'ilike', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('member_id', 'like', "%{$search}%");
              })
              ->orWhereHas('usedBy', function ($u) use ($search) {
                  $u->where('name', 'ilike', "%{$search}%")
                    ->orWhere('email', 'ilike', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('member_id', 'like', "%{$search}%");
              });
        });
    }

    public function paginate(int $perPage = 15, array $filters = []): LengthAwarePaginator
    {
        $query = QRInvite::query();

        if (!empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (!empty($filters['created_by'])) {
            $query->where('created_by', $filters['created_by']);
        }

        if (!empty($filters['is_revoked'])) {
            $query->where('is_revoked', $filters['is_revoked'] === 'true' || $filters['is_revoked'] === true);
        }

        if (!empty($filters['status'])) {
            $status = $filters['status'];
            if ($status === 'used') {
                $query->where(function ($q) {
                    $q->whereNotNull('used_at')
                      ->orWhere(function ($q2) {
                          $q2->whereNotNull('max_uses')
                             ->whereColumn('use_count', '>=', 'max_uses');
                      });
                });
            } elseif ($status === 'unused') {
                $query->whereNull('used_at')->where('is_revoked', false)->where('expires_at', '>', now())
                    ->where(function ($q) {
                        $q->whereNull('max_uses')
                          ->orWhereColumn('use_count', '<', 'max_uses');
                    });
            } elseif ($status === 'expired') {
                $query->whereNull('used_at')->where('expires_at', '<', now());
            } elseif ($status === 'revoked') {
                $query->where('is_revoked', true);
            }
        }

        if (!empty($filters['class_id'])) {
            $classId = (int) $filters['class_id'];
            $query->where(function ($q) use ($classId) {
                $q->where('qr_invites.class_id', $classId)
                  ->orWhereRaw(
                      "EXISTS (SELECT 1 FROM jsonb_array_elements(used_by_users::jsonb) AS elem WHERE (elem->>'class_id')::int = ?)",
                      [$classId]
                  )
                  ->orWhereHas('usedBy', function ($userQ) use ($classId) {
                      $userQ->where('class_id', $classId);
                  });
            });
        } elseif (!empty($filters['class_year_id'])) {
            $query->where('class_year_id', $filters['class_year_id']);
        }

        if (!empty($filters['date_from'])) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }

        if (!empty($filters['expires_from'])) {
            $query->whereDate('expires_at', '>=', $filters['expires_from']);
        }

        if (!empty($filters['expires_to'])) {
            $query->whereDate('expires_at', '<=', $filters['expires_to']);
        }

        if (!empty($filters['search'])) {
            $this->applySearch($query, $filters['search']);
        }

        return $query->with(['creator', 'usedBy.classe.stage', 'classe.stage', 'attendanceContext'])->latest()->paginate($perPage);
    }

    public function revoke(int $id): bool
    {
        $invite = $this->findById($id);
        if (!$invite) {
            return false;
        }
        return $invite->update(['is_revoked' => true]);
    }
}

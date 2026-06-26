<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class QRInviteResource extends JsonResource
{
    private static ?array $liveUsersCache = null;
    private static ?int $resourceChurchId = null;

    public static function resetCache(): void
    {
        self::$liveUsersCache = null;
        self::$resourceChurchId = null;
    }

    public static function loadUsedByUsersBatch(iterable $invites): void
    {
        $allIds = [];
        foreach ($invites as $invite) {
            $users = $invite->used_by_users ?? [];
            foreach ($users as $entry) {
                if (!empty($entry['id'])) {
                    $allIds[] = (int) $entry['id'];
                }
            }
        }

        $allIds = array_unique($allIds);

        if (empty($allIds)) {
            self::$liveUsersCache = [];
            return;
        }

        $churchId = auth()->user()?->church_id;

        $query = User::with('classe.stage');
        if ($churchId) {
            $query->where('church_id', $churchId);
        }

        self::$liveUsersCache = $query
            ->whereIn('id', $allIds)
            ->get()
            ->keyBy('id')
            ->all();
    }

    public function toArray(Request $request): array
    {
        $status = 'unused';
        if ($this->is_revoked) {
            $status = 'revoked';
        } elseif ($this->isExpired()) {
            $status = 'expired';
        } elseif ($this->isUsed() || ($this->max_uses && $this->use_count >= $this->max_uses)) {
            $status = 'used';
        } elseif ($this->use_count > 0) {
            $status = 'partial';
        }

        $remaining = null;
        if ($this->max_uses) {
            $remaining = max(0, $this->max_uses - $this->use_count);
        }

        $enrichedUsedByUsers = $this->enrichUsedByUsers($this->used_by_users);

        return [
            'id' => $this->id,
            'token' => $this->when($this->token, fn() => $this->token),
            'type' => $this->type?->value,
            'type_label' => $this->type?->label(),
            'status' => $status,
            'creator' => $this->when($this->creator, fn() => [
                'id' => $this->creator->id,
                'name' => $this->creator->name,
                'role' => $this->creator->role?->value,
                'phone' => $this->creator->phone,
            ]),
            'used_by' => $this->when($this->usedBy, fn() => [
                'id' => $this->usedBy->id,
                'name' => $this->usedBy->name,
                'role' => $this->usedBy->role?->value,
                'phone' => $this->usedBy->phone,
                'member_id' => $this->usedBy->member_id,
                'class_id' => $this->usedBy->class_id,
                'class_name' => $this->usedBy->classe?->name,
                'stage_name' => $this->usedBy->classe?->stage?->name,
                'created_at' => $this->usedBy->created_at?->toISOString(),
            ]),
            'used_by_users' => $enrichedUsedByUsers,
            'expires_at' => $this->expires_at,
            'created_at' => $this->created_at,
            'used_at' => $this->used_at,
            'classe' => $this->when($this->classe, fn() => [
                'id' => $this->classe->id,
                'name' => $this->classe->name,
                'stage_id' => $this->classe->stage?->id,
                'stage_name' => $this->classe->stage?->name,
            ]),
            'attendance_context' => $this->when($this->attendanceContext, fn() => [
                'id' => $this->attendanceContext->id,
                'name' => $this->attendanceContext->name,
                'slug' => $this->attendanceContext->slug,
            ]),
            'is_revoked' => $this->is_revoked,
            'is_valid' => $this->isValid(),
            'is_expired' => $this->isExpired(),
            'is_used' => $this->isUsed(),
            'is_single_use' => $this->is_single_use,
            'use_count' => $this->use_count,
            'max_uses' => $this->max_uses,
            'remaining_uses' => $remaining,
            'usage_label' => $this->max_uses
                ? ($this->use_count . ' / ' . $this->max_uses)
                : null,
        ];
    }

    private function enrichUsedByUsers(?array $usedByUsers): ?array
    {
        if (empty($usedByUsers)) {
            return $usedByUsers;
        }

        if (self::$liveUsersCache === null) {
            self::loadUsedByUsersBatch([$this->resource]);
        }

        return collect($usedByUsers)->map(function (array $entry) {
            $liveUser = self::$liveUsersCache[$entry['id']] ?? null;
            if ($liveUser) {
                $entry['name'] = $liveUser->name;
                $entry['role'] = $liveUser->role?->value;
                $entry['class_id'] = $liveUser->class_id;
                $entry['class_name'] = $liveUser->classe?->name;
                $entry['stage_name'] = $liveUser->classe?->stage?->name;
            }
            return $entry;
        })->values()->toArray();
    }
}

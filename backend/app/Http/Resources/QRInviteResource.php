<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\QRInvite */
class QRInviteResource extends JsonResource
{
    private static ?array $liveUsersCache = null;
    private static ?int $resourceChurchId = null;

    /** @return array<string, mixed> */
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
            'url' => config('app.frontend_url') . '/invite/' . urlencode($this->token),
            'type' => $this->type?->value,
            'type_label' => $this->type?->label(),
            'status' => $status,
            'creator' => $this->when($this->creator !== null, fn() => [
                'id' => $this->creator->id,
                'name' => $this->creator->name,
                'role' => $this->creator->role?->value,
                'phone' => $this->creator->phone,
            ]),
            'used_by' => $this->when($this->usedBy !== null, fn() => [
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
            'classe' => $this->when($this->classe !== null, fn() => [
                'id' => $this->classe->id,
                'name' => $this->classe->name,
                'stage_id' => $this->classe->stage?->id,
                'stage_name' => $this->classe->stage?->name,
            ]),
            'attendance_context' => $this->when($this->attendanceContext !== null, fn() => [
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
            /** @var \App\Models\User|null $liveUser */
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

    public static function loadUsedByUsersBatch(\Illuminate\Support\Collection|array $invites): void
    {
        $userIds = collect($invites)
            ->flatMap(fn($invite) => $invite->used_by_users ?? [])
            ->pluck('id')
            ->unique()
            ->toArray();

        if (empty($userIds)) {
            self::$liveUsersCache = [];
            return;
        }

        $users = User::whereIn('id', $userIds)->with('classe.stage')->get()->keyBy('id');
        self::$liveUsersCache = $users->all();

        $firstInvite = $invites instanceof \Illuminate\Support\Collection ? $invites->first() : ($invites[array_key_first($invites)] ?? null);
        self::$resourceChurchId = $firstInvite?->church_id;
    }
}

<?php

namespace App\Http\Resources;

use App\Models\AttendanceContext;
use App\Models\Classe;
use App\Models\QRInvite;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/** @mixin QRInvite */
class QRInviteResource extends JsonResource
{
    /** @var array<int, User>|null */
    private static ?array $liveUsersCache = null;

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
            'url' => (function () {
                /** @var string $frontendUrl */
                $frontendUrl = config('app.frontend_url');

                return $frontendUrl;
            })().'/invite/'.urlencode($this->token),
            'type' => $this->type?->value,
            'type_label' => $this->type?->label(),
            'status' => $status,
            'creator' => $this->when($this->creator !== null, function () {
                /** @var User $creator */
                $creator = $this->creator;

                return [
                    'id' => $creator->id,
                    'name' => $creator->name,
                    'role' => $creator->role?->value,
                    'phone' => $creator->phone,
                ];
            }),
            'used_by' => $this->when($this->usedBy !== null, function () {
                /** @var User $usedBy */
                $usedBy = $this->usedBy;

                return [
                    'id' => $usedBy->id,
                    'name' => $usedBy->name,
                    'role' => $usedBy->role?->value,
                    'phone' => $usedBy->phone,
                    'member_id' => $usedBy->member_id,
                    'class_id' => $usedBy->class_id,
                    'class_name' => $usedBy->classe?->name,
                    'stage_name' => $usedBy->classe?->stage?->name,
                    'created_at' => $usedBy->created_at?->toISOString(),
                ];
            }),
            'used_by_users' => $enrichedUsedByUsers,
            'expires_at' => $this->expires_at,
            'created_at' => $this->created_at,
            'used_at' => $this->used_at,
            'classe' => $this->when($this->classe !== null, function () {
                /** @var Classe $classe */
                $classe = $this->classe;

                return [
                    'id' => $classe->id,
                    'name' => $classe->name,
                    'stage_id' => $classe->stage?->id,
                    'stage_name' => $classe->stage?->name,
                ];
            }),
            'attendance_context' => $this->when($this->attendanceContext !== null, function () {
                /** @var AttendanceContext $attendanceContext */
                $attendanceContext = $this->attendanceContext;

                return [
                    'id' => $attendanceContext->id,
                    'name' => $attendanceContext->name,
                    'slug' => $attendanceContext->slug,
                ];
            }),
            'is_revoked' => $this->is_revoked,
            'is_valid' => $this->isValid(),
            'is_expired' => $this->isExpired(),
            'is_used' => $this->isUsed(),
            'is_single_use' => $this->is_single_use,
            'use_count' => $this->use_count,
            'max_uses' => $this->max_uses,
            'remaining_uses' => $remaining,
            'usage_label' => $this->max_uses
                ? ($this->use_count.' / '.$this->max_uses)
                : null,
        ];
    }

    /**
     * @param  array<int, array{id: int, name: string, role: string|null, phone: string|null, member_id: string|null, class_id: int|null, class_name: string|null, stage_name: string|null, used_at: string}>|null  $usedByUsers
     * @return array<int, array{id: int, name: string, role: string|null, phone: string|null, member_id: string|null, class_id: int|null, class_name: string|null, stage_name: string|null, used_at: string}>|null
     */
    private function enrichUsedByUsers(?array $usedByUsers): ?array
    {
        if (empty($usedByUsers)) {
            return $usedByUsers;
        }

        if (self::$liveUsersCache === null) {
            /** @var QRInvite $invite */
            $invite = $this->resource;
            self::loadUsedByUsersBatch([$invite]);
        }

        /** @var array<int, array{id: int, name: string, role: string|null, phone: string|null, member_id: string|null, class_id: int|null, class_name: string|null, stage_name: string|null, used_at: string}> $enriched */
        $enriched = collect($usedByUsers)->map(function (array $entry) {
            /** @var int $entryId */
            $entryId = $entry['id'];
            /** @var User|null $liveUser */
            $liveUser = self::$liveUsersCache[$entryId] ?? null;
            if ($liveUser) {
                $entry['name'] = $liveUser->name;
                $entry['role'] = $liveUser->role?->value;
                $entry['class_id'] = $liveUser->class_id;
                $entry['class_name'] = $liveUser->classe?->name;
                $entry['stage_name'] = $liveUser->classe?->stage?->name;
            }

            return $entry;
        })->values()->toArray();

        return $enriched;
    }

    /**
     * @param  Collection<int, QRInvite>|array<int, QRInvite>  $invites
     */
    public static function loadUsedByUsersBatch(Collection|array $invites): void
    {
        /** @var Collection<int, QRInvite> $collection */
        $collection = collect($invites);
        $userIds = $collection
            ->flatMap(function (QRInvite $invite): array {
                return $invite->used_by_users ?? [];
            })
            ->pluck('id')
            ->unique()
            ->toArray();

        if (empty($userIds)) {
            self::$liveUsersCache = [];

            return;
        }

        $users = User::whereIn('id', $userIds)->with('classe.stage')->get()->keyBy('id');
        /** @var array<int, User> $allUsers */
        $allUsers = $users->all();
        self::$liveUsersCache = $allUsers;

        $firstInvite = $invites instanceof Collection ? $invites->first() : ($invites[array_key_first($invites)] ?? null);
    }
}

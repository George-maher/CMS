<?php

namespace App\Http\Controllers\Api;

use App\Contracts\ScopeResolverInterface;
use App\Enums\UserRole;
use App\Enums\UserScope;
use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Point;
use App\Models\User;
use App\Services\CacheService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(
        private readonly CacheService $cacheService,
        private readonly ScopeResolverInterface $scopeResolver,
    ) {}

    public function stats(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $churchId = $user->church_id;

        // Fail closed (audit AUD-0005): an authenticated non-platform
        // principal without a church must not receive global aggregates —
        // without this guard the `if ($churchId)` filters below were skipped
        // entirely and every count became a cross-tenant read. Mirrors
        // ChurchScope / AttendanceContextService. Zeros are returned
        // directly so no shared cache key is written or read for such a
        // principal. The platform admin keeps its intentional cross-church
        // view.
        if ($churchId === null && ! $user->isPlatformAdmin()) {
            return response()->json([
                'data' => [
                    'total_members' => 0,
                    'active_members' => 0,
                    'total_servants' => 0,
                    'total_attendances' => 0,
                    'total_points' => 0,
                    'total_members_managed' => 0,
                ],
            ]);
        }

        // Resolve the caller's authorization scope from trusted context only
        // (role + persisted stage/class assignment — never a client input).
        //
        // - platform_admin: cross-church view (churchId === null → global).
        // - admin / assistant_admin: the whole Church (requirement: the
        //   General Secretary is never narrowed to a single Stage).
        // - stage_admin: exactly the assigned Stage.
        // - servant: exactly the assigned Class(es).
        // - member: their own Class (explicitly authorized scope).
        $scope = $this->scopeResolver->getScope($user);
        $stageId = null;
        $classIds = null;

        if (! $user->isPlatformAdmin()) {
            if ($scope === UserScope::Church) {
                // Church-wide on purpose: the General Secretary sees everything.
                $stageId = null;
                $classIds = null;
            } else {
                $classIds = $this->scopeResolver->allowedClassIds($user) ?? [];
                if ($scope === UserScope::Stage) {
                    $stageId = $user->stage_id !== null ? (int) $user->stage_id : null;
                }
            }
        }

        $scopeKey = $this->scopeKey($user, $scope, $stageId, $classIds);

        $data = $this->cacheService->rememberDashboardStats($churchId ?: 0, $scopeKey, function () use ($churchId, $stageId, $classIds) {
            $query = User::query();
            if ($churchId) {
                $query->where('church_id', $churchId);
            }
            // Scope every user aggregate (members, servants, managed members)
            // to the caller's stage/classes — the same narrowing that the
            // attendance/point aggregates apply below.
            $this->applyScope($query, $stageId, $classIds);

            $scoped = function (Builder $q) use ($stageId, $classIds) {
                $this->applyScope($q, $stageId, $classIds);
            };

            $memberQuery = (clone $query)->where('role', UserRole::Member);
            $totalMembers = (clone $memberQuery)->count();
            $activeMembers = (clone $memberQuery)->where('is_active', true)->count();
            $totalMembersManaged = (clone $memberQuery)->whereNotNull('servant_id')->count();

            $totalServants = (clone $query)->where('role', UserRole::Servant)->count();

            $totalAttendances = Attendance::whereHas('user', function ($q) use ($churchId, $scoped) {
                if ($churchId) {
                    $q->where('church_id', $churchId);
                }
                $scoped($q);
            })->count();

            $totalPoints = Point::whereHas('user', function ($q) use ($churchId, $scoped) {
                if ($churchId) {
                    $q->where('church_id', $churchId);
                }
                $scoped($q);
            })->sum('points');

            return [
                'total_members' => $totalMembers,
                'active_members' => $activeMembers,
                'total_servants' => $totalServants,
                'total_attendances' => $totalAttendances,
                'total_points' => $totalPoints,
                'total_members_managed' => $totalMembersManaged,
            ];
        });

        return response()->json([
            'data' => $data,
        ]);
    }

    /**
     * Cache discriminator for the resolved scope. Two principals of different
     * scopes must never share a dashboard cache entry.
     *
     * @param  array<int, int>|null  $classIds
     */
    private function scopeKey(User $user, UserScope $scope, ?int $stageId, ?array $classIds): string
    {
        if ($user->isPlatformAdmin()) {
            return 'global';
        }

        return match ($scope) {
            UserScope::Church => 'church',
            UserScope::Stage => 'stage:'.($stageId ?? 0).':'.implode(',', $classIds ?? []),
            default => 'class:'.implode(',', $classIds ?? []),
        };
    }

    /**
     * Narrow a users query to the caller's stage/class scope.
     *
     * @param  array<int, int>|null  $classIds
     */
    private function applyScope(Builder $query, ?int $stageId, ?array $classIds): void
    {
        if ($stageId === null && $classIds === null) {
            return; // Church scope: the church_id filter already bounds it.
        }

        $query->where(function (Builder $q) use ($stageId, $classIds) {
            if ($stageId !== null) {
                $q->orWhere('stage_id', $stageId);
            }
            if (! empty($classIds)) {
                // `users.class_year_id` is intentionally excluded: it is still
                // foreign-keyed to the deprecated `class_years` table while
                // `$classIds` holds `classes.id` values (see
                // EventPolicy::eventTargetsServantsClass()). Comparing the two
                // compares unrelated id spaces and would widen these
                // aggregates to users outside the caller's class/stage.
                $q->orWhereIn('class_id', $classIds);
            }
            if (($stageId === null || $stageId === 0) && empty($classIds)) {
                $q->whereRaw('1 = 0');
            }
        });
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Contracts\ScopeResolverInterface;
use App\Enums\UserRole;
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

        // Platform-wide reporting lives behind its dedicated platform endpoint.
        // This endpoint is for church admins and assigned Stage Admins only.
        if ($user->isPlatformAdmin()) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        if ($user->church_id === null) {
            if ($user->isAdmin()) {
                return response()->json(['data' => [
                    'total_members' => 0,
                    'active_members' => 0,
                    'total_servants' => 0,
                    'total_attendances' => 0,
                    'total_points' => 0,
                    'total_members_managed' => 0,
                ]]);
            }

            return response()->json(['message' => 'Forbidden.'], 403);
        }

        if ($user->isAdmin()) {
            $churchId = (int) $user->church_id;
            $data = $this->cacheService->rememberDashboardStats($churchId, function () use ($churchId) {
                $members = User::query()
                    ->where('church_id', $churchId)
                    ->where('role', UserRole::Member);

                return [
                    'total_members' => (clone $members)->count(),
                    'active_members' => (clone $members)->where('is_active', true)->count(),
                    'total_servants' => User::query()
                        ->where('church_id', $churchId)
                        ->where('role', UserRole::Servant)
                        ->count(),
                    'total_attendances' => Attendance::query()
                        ->where('church_id', $churchId)
                        ->whereHas('user', fn (Builder $query) => $query->where('church_id', $churchId))
                        ->count(),
                    'total_points' => Point::query()
                        ->where('church_id', $churchId)
                        ->whereHas('user', fn (Builder $query) => $query->where('church_id', $churchId))
                        ->sum('points'),
                    'total_members_managed' => (clone $members)->whereNotNull('servant_id')->count(),
                ];
            });

            return response()->json(['data' => $data]);
        }

        if (! $user->isStageAdmin()) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $stageIds = $this->scopeResolver->allowedStageIds($user);
        $classIds = $this->scopeResolver->allowedClassIds($user);
        if (count($stageIds) !== 1 || $classIds === null) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $stageId = $stageIds[0];
        $classIds = array_values(array_unique(array_map('intval', $classIds)));
        $churchId = (int) $user->church_id;
        $data = $this->cacheService->rememberDashboardStats(
            $churchId,
            fn () => $this->stageStats($churchId, $classIds),
            "stage:{$stageId}",
        );

        return response()->json(['data' => $data]);
    }

    /** @param array<int, int> $classIds
     *  @return array<string, int>
     */
    private function stageStats(int $churchId, array $classIds): array
    {
        $members = User::query()
            ->where('church_id', $churchId)
            ->where('role', UserRole::Member)
            ->whereIn('class_id', $classIds);

        $servants = User::query()
            ->where('church_id', $churchId)
            ->where('role', UserRole::Servant)
            ->where(function (Builder $query) use ($classIds): void {
                $query->whereIn('class_id', $classIds)
                    ->orWhereHas('classes', fn (Builder $classes) => $classes->whereIn('classes.id', $classIds));
            });

        $scopedMembers = User::query()
            ->where('church_id', $churchId)
            ->where('role', UserRole::Member)
            ->whereIn('class_id', $classIds);

        return [
            'total_members' => (clone $members)->count(),
            'active_members' => (clone $members)->where('is_active', true)->count(),
            'total_servants' => $servants->count(),
            'total_attendances' => $classIds === [] ? 0 : Attendance::query()
                ->where('church_id', $churchId)
                ->whereIn('class_year_id', $classIds)
                ->whereHas('classe', fn (Builder $query) => $query->where('church_id', $churchId))
                ->whereHas('user', fn (Builder $query) => $query->where('church_id', $churchId))
                ->count(),
            'total_points' => Point::query()
                ->where('church_id', $churchId)
                ->whereIn('user_id', (clone $scopedMembers)->select('id'))
                ->sum('points'),
            'total_members_managed' => (clone $members)->whereNotNull('servant_id')->count(),
        ];
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Contracts\EventRepositoryInterface;
use App\Contracts\EventServiceInterface;
use App\Contracts\ScopeResolverInterface;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EventAnalyticsController extends Controller
{
    public function __construct(
        private readonly EventServiceInterface $eventService,
        private readonly EventRepositoryInterface $eventRepository,
        private readonly ScopeResolverInterface $scopeResolver,
    ) {}

    /**
     * Class scope for the caller: null = church-wide (admin tier), otherwise
     * the ids the caller is authorized for (servant classes / stage classes).
     *
     * @return array<int, int>|null
     */
    private function classScope(User $user): ?array
    {
        if ($user->isAdmin()) {
            return null;
        }

        if ($user->isServant() || $user->isStageAdmin()) {
            return $this->scopeResolver->allowedClassIds($user) ?? [];
        }

        return null;
    }

    public function summary(Request $request, int $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $classScope = $this->classScope($user);
        $summary = $this->eventService->viewSummary($id, $classScope);

        return response()->json(['data' => $summary]);
    }

    public function viewed(Request $request, int $id): JsonResponse
    {
        /** @var array<string, mixed> $filters */
        $filters = $request->only(['class_id', 'search']);
        /** @var User $user */
        $user = $request->user();
        $classScope = $this->classScope($user);
        if ($classScope !== null) {
            // A client-supplied class filter can only narrow within the scope,
            // never widen beyond it.
            if (isset($filters['class_id']) && ! in_array($request->integer('class_id'), $classScope, true)) {
                unset($filters['class_id']);
            }
            if ($classScope === []) {
                return response()->json(['data' => []]);
            }
        }
        /** @var Collection<int, User> $users */
        $users = $this->eventService->viewedUsers($id, $filters, $classScope);

        return response()->json([
            'data' => $users->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'member_id' => $u->member_id,
                'class_year' => $u->classe ? [
                    'id' => $u->classe->id,
                    'name' => $u->classe->name,
                ] : null,
                'viewed_at' => $u->viewed_at ?? null,
            ]),
        ]);
    }

    public function notViewed(Request $request, int $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var array<string, mixed> $filters */
        $filters = $request->only(['class_id', 'search']);
        $classScope = $this->classScope($user);
        if ($classScope !== null) {
            if (isset($filters['class_id']) && ! in_array($request->integer('class_id'), $classScope, true)) {
                unset($filters['class_id']);
            }
            if ($classScope === []) {
                return response()->json(['data' => []]);
            }
        }

        /** @var Collection<int, User> $users */
        $users = $this->eventService->notViewedUsers($id, $user->church_id, $filters, $classScope);

        return response()->json([
            'data' => $users->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'member_id' => $u->member_id,
                'class_year' => $u->classe ? [
                    'id' => $u->classe->id,
                    'name' => $u->classe->name,
                ] : null,
            ]),
        ]);
    }

    public function track(Request $request, int $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->role !== UserRole::Member) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $event = $this->eventRepository->findById($id);
        if (! $event) {
            return response()->json(['message' => 'Event not found.'], 404);
        }

        /** @var int $userId */
        $userId = $user->id;
        $this->eventService->trackView($event->id, $userId, $request->ip(), $request->userAgent());

        return response()->json(['message' => 'View tracked successfully.']);
    }
}

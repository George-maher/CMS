<?php

namespace App\Services;

use App\Contracts\ScopeResolverInterface;
use App\Models\Event;
use App\Models\User;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class EventAuthorizationService
{
    public function __construct(
        private readonly ScopeResolverInterface $scopeResolver,
    ) {}

    public function assertCanAccess(User $user, Event $event): void
    {
        if (! $user->isPlatformAdmin() && $event->church_id !== $user->church_id) {
            throw new AccessDeniedHttpException('Forbidden.');
        }

        if ($user->isStageAdmin() && $this->stageAdminCannotAccess($user, $event)) {
            throw new AccessDeniedHttpException('Forbidden.');
        }

        if ($user->isServant() && $this->servantCannotAccess($user, $event)) {
            throw new AccessDeniedHttpException('Forbidden.');
        }
    }

    private function stageAdminCannotAccess(User $user, Event $event): bool
    {
        $hasAllClasses = $event->is_all_classes
            || $event->targets()->where('is_all_classes', true)->exists();

        if ($hasAllClasses) {
            return false;
        }

        /** @var array<int, int> $allowedClassIds */
        $allowedClassIds = $this->scopeResolver->allowedClassIds($user) ?? [];
        /** @var array<int, int> $targetClassIds */
        $targetClassIds = $event->targets()
            ->where('is_all_classes', false)
            ->pluck('class_id')
            ->filter()
            ->values()
            ->toArray();

        if (! empty(array_intersect($allowedClassIds, $targetClassIds))) {
            return false;
        }

        return $event->class_year_id !== null
            && ! in_array((int) $event->class_year_id, $allowedClassIds, true);
    }

    private function servantCannotAccess(User $user, Event $event): bool
    {
        $hasAllClasses = $event->is_all_classes
            || $event->targets()->where('is_all_classes', true)->exists();
        /** @var array<int, int> $servantClassIds */
        $servantClassIds = $user->classes()->pluck('classes.id')->toArray();
        /** @var array<int, int> $targetClassIds */
        $targetClassIds = $event->targets()
            ->where('is_all_classes', false)
            ->pluck('class_id')
            ->filter()
            ->toArray();

        $overlap = ! empty($targetClassIds)
            && ! empty(array_intersect($servantClassIds, $targetClassIds));

        return ! $hasAllClasses
            && ! $overlap
            && $event->class_year_id !== null
            && $event->class_year_id !== $user->class_year_id;
    }
}

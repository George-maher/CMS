<?php

namespace App\Services;

use App\Contracts\ClasseRepositoryInterface;
use App\Contracts\ClasseServiceInterface;
use App\Contracts\ScopeResolverInterface;
use App\Enums\UserRole;
use App\Enums\UserScope;
use App\Http\Resources\ClasseDetailResource;
use App\Http\Resources\ClasseResource;
use App\Http\Resources\UserResource;
use App\Models\Classe;
use App\Models\Stage;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ClasseService implements ClasseServiceInterface
{
    public function __construct(
        private readonly ClasseRepositoryInterface $classeRepository,
        private readonly ScopeResolverInterface $scopeResolver,
    ) {}

    /** @return array<string, mixed> */
    public function all(?string $search = null): array
    {
        $classes = $this->classeRepository->all($search);

        return [
            'data' => ClasseResource::collection($classes),
        ];
    }

    /** @return ?array<string, mixed> */
    public function findById(int $id): ?array
    {
        $classe = $this->classeRepository->findById($id);

        if (! $classe) {
            return null;
        }

        return [
            'data' => new ClasseResource($classe),
        ];
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): array
    {
        /** @var User $user */
        $user = auth()->user();

        // Defense in depth for the stage ownership boundary. The owning Stage
        // is resolved server-side inside the actor's tenant and the client
        // value is replaced by the resolved id, so a class can never be
        // planted under a foreign stage even if routing, the FormRequest or
        // ClassePolicy::create were bypassed. Mirrors createBulk().
        /** @var int|null $stageId */
        $stageId = isset($data['stage_id']) && is_numeric($data['stage_id']) ? (int) $data['stage_id'] : null;
        $stage = $stageId !== null ? Stage::byChurch()->find($stageId) : null;

        if (! $stage) {
            throw ValidationException::withMessages([
                'stage_id' => ['Stage not found.'],
            ]);
        }

        if (! $this->scopeResolver->canAccessStage($user, $stage)) {
            throw ValidationException::withMessages([
                'stage_id' => ['Forbidden.'],
            ]);
        }

        $data['stage_id'] = $stage->id;
        $data['church_id'] = $user->church_id;

        /** @var int $maxOrder */
        $maxOrder = Classe::byChurch()
            ->where('stage_id', $stage->id)
            ->max('display_order') ?? 0;
        $data['display_order'] = $maxOrder + 1;

        $classe = $this->classeRepository->create($data);

        return [
            'data' => new ClasseResource($classe),
        ];
    }

    /** @return array<string, mixed> */
    public function createBulk(int $stageId, int $count): array
    {
        /** @var User $user */
        $user = auth()->user();
        /** @var int $churchId */
        $churchId = $user->church_id;

        // Stage is resolved server-side and must belong to the actor's
        // church. Never trust a client-supplied church_id/stage_id pairing:
        // cross-stage writes are rejected here even if routing/policy is
        // bypassed (defense-in-depth alongside ClassePolicy::create).
        $stage = Stage::byChurch()->find($stageId);
        if (! $stage) {
            throw ValidationException::withMessages([
                'stage' => ['Stage not found.'],
            ]);
        }

        if (! $this->scopeResolver->canAccessStage($user, $stage)) {
            throw ValidationException::withMessages([
                'stage' => ['Forbidden.'],
            ]);
        }

        // Atomic: classes have a UNIQUE(church_id, stage_id, name)
        // constraint, so any collision rolls back the whole batch instead
        // of leaving partially created data.
        return DB::transaction(function () use ($churchId, $stage, $count): array {
            /** @var int $maxOrder */
            $maxOrder = Classe::byChurch()
                ->where('stage_id', $stage->id)
                ->max('display_order') ?? 0;
            /** @var array<string, bool> $taken */
            $taken = Classe::byChurch()
                ->where('stage_id', $stage->id)
                ->pluck('name')
                ->flip()
                ->toArray();
            $classes = [];
            $suffix = 1;

            for ($i = 0; $i < $count; $i++) {
                do {
                    $name = "Class {$suffix}";
                    $suffix++;
                } while (isset($taken[$name]));
                $taken[$name] = true;

                $classes[] = $this->classeRepository->create([
                    'church_id' => $churchId,
                    'stage_id' => $stage->id,
                    'name' => $name,
                    'display_order' => $maxOrder + $i + 1,
                ]);
            }

            return [
                'data' => ClasseResource::collection(collect($classes)),
            ];
        });
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): bool
    {
        $classe = $this->classeRepository->findById($id);
        if (! $classe) {
            throw ValidationException::withMessages([
                'class' => ['Class not found.'],
            ]);
        }

        return $this->classeRepository->update($id, $data);
    }

    public function delete(int $id): bool
    {
        $classe = $this->classeRepository->findById($id);
        if (! $classe) {
            throw ValidationException::withMessages([
                'class' => ['Class not found.'],
            ]);
        }

        return $this->classeRepository->delete($id);
    }

    /** @return array<string, mixed> */
    public function getDetail(int $id): array
    {
        $classe = $this->classeRepository->findById($id);

        if (! $classe) {
            throw ValidationException::withMessages([
                'class' => ['Class not found.'],
            ]);
        }

        $members = User::byChurch()
            ->where('class_id', $id)
            ->where('role', UserRole::Member)
            ->with(['servant'])
            ->get();

        $servants = $classe->servants()
            ->withCount('assignedMembers')
            ->get();

        return [
            'class' => new ClasseDetailResource($classe),
            'member_count' => $members->count(),
            'servant_count' => $servants->count(),
            'members' => UserResource::collection($members),
            'servants' => UserResource::collection($servants),
        ];
    }

    /** @return array<string, mixed> */
    public function assignServant(int $classeId, int $servantId): array
    {
        $classe = $this->classeRepository->findById($classeId);
        if (! $classe) {
            throw ValidationException::withMessages([
                'class' => ['Class not found.'],
            ]);
        }

        $servant = User::byChurch()->find($servantId);
        if (! $servant || $servant->role !== UserRole::Servant) {
            throw ValidationException::withMessages([
                'servant' => ['Invalid servant.'],
            ]);
        }

        $this->assertActorCanManageUser($servant, 'servant');

        DB::transaction(function () use ($servant, $classe, $servantId): void {
            $this->syncUserStageWithClass($servant, $classe, UserScope::ClassScope);
            $classe->servants()->syncWithoutDetaching([$servantId]);
        });

        return [
            'data' => new UserResource($servant->fresh()),
        ];
    }

    /** @return array<string, mixed> */
    public function assignMember(int $classeId, int $memberId): array
    {
        $classe = $this->classeRepository->findById($classeId);
        if (! $classe) {
            throw ValidationException::withMessages([
                'class' => ['Class not found.'],
            ]);
        }

        $member = User::byChurch()->find($memberId);
        if (! $member) {
            throw ValidationException::withMessages([
                'member' => ['User not found.'],
            ]);
        }

        $this->assertActorCanManageUser($member, 'member');

        DB::transaction(function () use ($member, $classe): void {
            $this->syncUserStageWithClass($member, $classe, UserScope::Self);
            $member->class_id = $classe->id;
            $member->save();
        });

        return [
            'data' => new UserResource($member->fresh()),
        ];
    }

    /** @return array<string, mixed> */
    public function removeServant(int $classeId, int $servantId): array
    {
        $classe = $this->classeRepository->findById($classeId);
        if (! $classe) {
            throw ValidationException::withMessages([
                'class' => ['Class not found.'],
            ]);
        }

        $servant = User::byChurch()->find($servantId);
        if ($servant === null) {
            throw ValidationException::withMessages([
                'user_id' => ['User not found.'],
            ]);
        }

        // Defense in depth: removing a servant must not become a way to detach
        // an arbitrary user id that the actor cannot otherwise manage.
        $this->assertActorCanManageUser($servant, 'user_id');

        $classe->servants()->detach($servant->id);

        return ['message' => 'Servant removed from class.'];
    }

    /**
     * Assign (or replace) the single class secretary.
     *
     * Replacement is an overwrite of the same column, so a class can never end
     * up with two active secretaries. The class row is locked for the whole
     * read-validate-write sequence.
     *
     * @return array<string, mixed>
     */
    public function assignSecretary(int $classeId, int $secretaryId): array
    {
        /** @var User $actor */
        $actor = auth()->user();

        return DB::transaction(function () use ($classeId, $secretaryId, $actor): array {
            $classe = $this->classeRepository->findByIdForUpdate($classeId);
            if ($classe === null) {
                throw ValidationException::withMessages([
                    'class' => ['Class not found.'],
                ]);
            }

            if (! $this->scopeResolver->canAccessClass($actor, $classe)) {
                throw ValidationException::withMessages([
                    'class' => ['Forbidden.'],
                ]);
            }

            $secretary = User::byChurch()->find($secretaryId);
            if ($secretary === null || $secretary->role !== UserRole::Servant) {
                throw ValidationException::withMessages([
                    'user_id' => ['Invalid class secretary.'],
                ]);
            }

            if (! $this->scopeResolver->canAccessUser($actor, $secretary)) {
                throw ValidationException::withMessages([
                    'user_id' => ['The user is outside your scope.'],
                ]);
            }

            // The secretary must belong to the class's stage, otherwise a
            // Stage Admin could grant a class to a servant from another stage
            // simply by knowing their id.
            if ($classe->stage_id !== null
                && $this->scopeResolver->userStageId($secretary) !== (int) $classe->stage_id) {
                throw ValidationException::withMessages([
                    'user_id' => ["The user does not belong to this class's stage."],
                ]);
            }

            // One user may lead only one class at a time. Rejecting is safer
            // than silently stealing the other class's secretary.
            $alreadySecretaryElsewhere = Classe::byChurch()
                ->where('class_secretary_id', $secretary->id)
                ->where('id', '!=', $classe->id)
                ->lockForUpdate()
                ->exists();

            if ($alreadySecretaryElsewhere) {
                throw ValidationException::withMessages([
                    'user_id' => ['The user is already the secretary of another class.'],
                ]);
            }

            $classe->class_secretary_id = $secretary->id;
            $classe->save();

            return ['data' => new UserResource($secretary)];
        });
    }

    /**
     * Remove the class secretary without touching any other assignment.
     *
     * @return array<string, mixed>
     */
    public function removeSecretary(int $classeId): array
    {
        /** @var User $actor */
        $actor = auth()->user();

        DB::transaction(function () use ($classeId, $actor): void {
            $classe = $this->classeRepository->findByIdForUpdate($classeId);
            if ($classe === null) {
                throw ValidationException::withMessages([
                    'class' => ['Class not found.'],
                ]);
            }

            if (! $this->scopeResolver->canAccessClass($actor, $classe)) {
                throw ValidationException::withMessages([
                    'class' => ['Forbidden.'],
                ]);
            }

            $classe->class_secretary_id = null;
            $classe->save();
        });

        return ['message' => 'Class secretary removed.'];
    }

    /**
     * Servants in the class's stage that may be appointed as its secretary.
     *
     * The current secretary is always included (flagged by id on the client),
     * so "replace" can render the existing selection.
     *
     * @return array<string, mixed>
     */
    public function getEligibleSecretaries(int $classeId): array
    {
        $classe = $this->classeRepository->findById($classeId);
        if ($classe === null) {
            throw ValidationException::withMessages([
                'class' => ['Class not found.'],
            ]);
        }

        $stageId = $classe->stage_id !== null ? (int) $classe->stage_id : null;

        $servants = User::byChurch()
            ->where('role', UserRole::Servant)
            ->where(function (Builder $query) use ($stageId): void {
                if ($stageId === null) {
                    $query->whereRaw('1 = 0');

                    return;
                }

                // stage_id is the canonical assignment, but a legacy servant
                // may only have class_id; resolve the stage through the class
                // exactly like ScopeResolver::userStageId() does.
                $query->where('stage_id', $stageId)
                    ->orWhereHas('classe', function (Builder $classQuery) use ($stageId): void {
                        $classQuery->where('stage_id', $stageId);
                    });
            })
            ->where(function (Builder $query) use ($classe): void {
                $query->whereDoesntHave('secretaryClasses')
                    ->orWhere('id', $classe->class_secretary_id);
            })
            ->orderBy('name')
            ->get();

        return ['data' => UserResource::collection($servants)];
    }

    /**
     * Move a servant from one class to another inside the same stage.
     *
     * Only the source assignment is detached; every other class the servant
     * legitimately serves is preserved.
     *
     * @return array<string, mixed>
     */
    public function moveServant(int $fromClasseId, int $toClasseId, int $servantId): array
    {
        /** @var User $actor */
        $actor = auth()->user();

        return DB::transaction(function () use ($fromClasseId, $toClasseId, $servantId, $actor): array {
            $from = $this->classeRepository->findByIdForUpdate($fromClasseId);
            $to = $this->classeRepository->findByIdForUpdate($toClasseId);

            if ($from === null || $to === null) {
                throw ValidationException::withMessages([
                    'class' => ['Class not found.'],
                ]);
            }

            if ($from->id === $to->id) {
                throw ValidationException::withMessages([
                    'to_class_id' => ['The source and target class must be different.'],
                ]);
            }

            if (! $this->scopeResolver->canAccessClass($actor, $from)
                || ! $this->scopeResolver->canAccessClass($actor, $to)) {
                throw ValidationException::withMessages([
                    'class' => ['Forbidden.'],
                ]);
            }

            // Cross-stage moves are rejected: a Stage Admin may only rearrange
            // servants inside their own stage.
            if ($from->stage_id !== $to->stage_id) {
                throw ValidationException::withMessages([
                    'to_class_id' => ['A servant can only be moved between classes of the same stage.'],
                ]);
            }

            $servant = User::byChurch()->find($servantId);
            if ($servant === null || $servant->role !== UserRole::Servant) {
                throw ValidationException::withMessages([
                    'user_id' => ['Invalid servant.'],
                ]);
            }

            if (! $this->scopeResolver->canAccessUser($actor, $servant)) {
                throw ValidationException::withMessages([
                    'user_id' => ['The user is outside your scope.'],
                ]);
            }

            $from->servants()->detach($servant->id);
            $to->servants()->syncWithoutDetaching([$servant->id]);

            $this->syncUserStageWithClass($servant, $to, UserScope::ClassScope);

            return ['data' => new UserResource($servant->refresh())];
        });
    }

    /** @param array<int, int> $orderedIds */
    public function updateOrder(array $orderedIds, User $actor): bool
    {
        $classes = Classe::query()->whereIn('id', $orderedIds)->get();
        if ($classes->count() !== count(array_unique($orderedIds))) {
            throw ValidationException::withMessages([
                'ordered_ids' => ['One or more classes were not found.'],
            ]);
        }

        foreach ($classes as $classe) {
            if (! $this->scopeResolver->canAccessClass($actor, $classe)) {
                throw ValidationException::withMessages([
                    'ordered_ids' => ['One or more classes are outside your authorized scope.'],
                ]);
            }
        }

        return $this->classeRepository->updateOrder($orderedIds);
    }

    /** @return array<string, mixed> */
    public function getMembers(int $classeId, int $perPage = 15): array
    {
        $classe = $this->classeRepository->findById($classeId);
        if (! $classe) {
            throw ValidationException::withMessages([
                'class' => ['Class not found.'],
            ]);
        }

        $paginator = User::byChurch()
            ->where('class_id', $classeId)
            ->where('role', UserRole::Member)
            ->with(['servant'])
            ->paginate($perPage);

        return [
            'data' => UserResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function getServants(int $classeId, int $perPage = 15): array
    {
        $classe = $this->classeRepository->findById($classeId);
        if (! $classe) {
            throw ValidationException::withMessages([
                'class' => ['Class not found.'],
            ]);
        }

        $paginator = $classe->servants()
            ->withCount('assignedMembers')
            ->paginate($perPage);

        return [
            'data' => UserResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }

    /**
     * Align a user's stage_id and scope with the target class.
     *
     * When a class has a stage, the user's stage and scope are derived from
     * it. If the class has no stage (edge case) the user's stage is left
     * untouched.
     */
    private function syncUserStageWithClass(User $user, Classe $classe, UserScope $scope): void
    {
        $classStageId = $classe->stage_id !== null ? (int) $classe->stage_id : null;

        if ($classStageId !== null) {
            $user->stage_id = $classStageId;
        }

        $user->scope = $scope;
        $user->save();
    }

    /**
     * Defense-in-depth: when a user is authenticated, the actor must be
     * permitted to manage the target user (the controller already returns 403;
     * this guards direct service access).
     */
    private function assertActorCanManageUser(User $target, string $field): void
    {
        /** @var User|null $actor */
        $actor = auth()->user();
        if ($actor === null || $actor->isPlatformAdmin()) {
            return;
        }

        if (! $this->scopeResolver->canAccessUser($actor, $target)) {
            throw ValidationException::withMessages([
                $field => ['The user is outside your scope.'],
            ]);
        }
    }
}

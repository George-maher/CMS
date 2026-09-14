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
use App\Models\User;
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
        $data['church_id'] = $user->church_id;
        /** @var int $maxOrder */
        $maxOrder = Classe::byChurch()
            ->where('stage_id', $data['stage_id'])
            ->max('display_order') ?? 0;
        $data['display_order'] = $maxOrder + 1;

        $classe = $this->classeRepository->create($data);

        return [
            'data' => new ClasseResource($classe),
        ];
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

        $this->syncUserStageWithClass($servant, $classe, UserScope::ClassScope);
        $classe->servants()->syncWithoutDetaching([$servantId]);

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

        $this->syncUserStageWithClass($member, $classe, UserScope::Self);
        $member->class_id = $classe->id;
        $member->save();

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

        $classe->servants()->detach($servantId);

        return ['message' => 'Servant removed from class.'];
    }

    /** @param array<int, int> $orderedIds */
    public function updateOrder(array $orderedIds): bool
    {
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

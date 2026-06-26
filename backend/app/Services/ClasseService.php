<?php

namespace App\Services;

use App\Contracts\ClasseRepositoryInterface;
use App\Contracts\ClasseServiceInterface;
use App\Enums\UserRole;
use App\Http\Resources\ClasseDetailResource;
use App\Http\Resources\ClasseResource;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class ClasseService implements ClasseServiceInterface
{
    public function __construct(
        private readonly ClasseRepositoryInterface $classeRepository,
    ) {}

    public function all(?string $search = null): array
    {
        $classes = $this->classeRepository->all($search);

        return [
            'data' => ClasseResource::collection($classes),
        ];
    }

    public function findById(int $id): ?array
    {
        $classe = $this->classeRepository->findById($id);

        if (!$classe) return null;

        return [
            'data' => new ClasseResource($classe),
        ];
    }

    public function create(array $data): array
    {
        $data['church_id'] = auth()->user()->church_id;
        $maxOrder = \App\Models\Classe::byChurch()
            ->where('stage_id', $data['stage_id'])
            ->max('display_order') ?? 0;
        $data['display_order'] = $maxOrder + 1;

        $classe = $this->classeRepository->create($data);

        return [
            'data' => new ClasseResource($classe),
        ];
    }

    public function update(int $id, array $data): bool
    {
        $classe = $this->classeRepository->findById($id);
        if (!$classe) {
            throw ValidationException::withMessages([
                'class' => ['Class not found.'],
            ]);
        }

        return $this->classeRepository->update($id, $data);
    }

    public function delete(int $id): bool
    {
        $classe = $this->classeRepository->findById($id);
        if (!$classe) {
            throw ValidationException::withMessages([
                'class' => ['Class not found.'],
            ]);
        }

        return $this->classeRepository->delete($id);
    }

    public function getDetail(int $id): array
    {
        $classe = $this->classeRepository->findById($id);

        if (!$classe) {
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

    public function assignServant(int $classeId, int $servantId): array
    {
        $classe = $this->classeRepository->findById($classeId);
        if (!$classe) {
            throw ValidationException::withMessages([
                'class' => ['Class not found.'],
            ]);
        }

        $servant = User::byChurch()->find($servantId);
        if (!$servant || $servant->role !== UserRole::Servant) {
            throw ValidationException::withMessages([
                'servant' => ['Invalid servant.'],
            ]);
        }

        $classe->servants()->syncWithoutDetaching([$servantId]);

        return [
            'data' => new UserResource($servant->fresh()),
        ];
    }

    public function removeServant(int $classeId, int $servantId): array
    {
        $classe = $this->classeRepository->findById($classeId);
        if (!$classe) {
            throw ValidationException::withMessages([
                'class' => ['Class not found.'],
            ]);
        }

        $classe->servants()->detach($servantId);

        return ['message' => 'Servant removed from class.'];
    }

    public function updateOrder(array $orderedIds): bool
    {
        return $this->classeRepository->updateOrder($orderedIds);
    }

    public function getMembers(int $classeId, int $perPage = 15): array
    {
        $classe = $this->classeRepository->findById($classeId);
        if (!$classe) {
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

    public function getServants(int $classeId, int $perPage = 15): array
    {
        $classe = $this->classeRepository->findById($classeId);
        if (!$classe) {
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
}

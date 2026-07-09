<?php

namespace App\Services;

use App\Contracts\StageRepositoryInterface;
use App\Contracts\StageServiceInterface;
use App\Enums\UserRole;
use App\Http\Resources\ClasseResource;
use App\Http\Resources\StageResource;
use App\Models\Classe;
use App\Models\Stage;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class StageService implements StageServiceInterface
{
    public function __construct(
        private readonly StageRepositoryInterface $stageRepository,
    ) {}

    /** @return array<string, mixed> */
    public function all(?string $search = null): array
    {
        $stages = $this->stageRepository->all($search);

        return [
            'data' => StageResource::collection($stages),
        ];
    }

    /** @return array<string, mixed> */
    public function structure(?string $search = null): array
    {
        $stages = $this->stageRepository->structure($search);

        return [
            'data' => $stages->map(fn (Stage $stage) => [
                'id' => $stage->id,
                'name' => $stage->name,
                'display_order' => $stage->display_order,
                'classes_count' => $stage->classes_count,
                'classes' => ClasseResource::collection($stage->classes),
            ])->values()->all(),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function stagesWithClasses(?string $search = null): array
    {
        $stages = $this->stageRepository->structure($search);

        return $stages->map(fn (Stage $stage) => [
            'stage_id' => $stage->id,
            'stage_name' => $stage->name,
            'classes' => $stage->classes->map(fn (Classe $classe) => [
                'id' => $classe->id,
                'name' => $classe->name,
            ])->values()->all(),
        ])->values()->all();
    }

    /** @return ?array<string, mixed> */
    public function findById(int $id): ?array
    {
        $stage = $this->stageRepository->findById($id);

        if (! $stage) {
            return null;
        }

        return [
            'data' => new StageResource($stage),
        ];
    }

    /** @param array<string, mixed> $data */
    /** @return array<string, mixed> */
    public function create(array $data): array
    {
        /** @var array<string, mixed> $data */
        /** @var User $user */
        $user = auth()->user();
        $data['church_id'] = $user->church_id;
        /** @var int $maxOrder */
        $maxOrder = Stage::byChurch()->max('display_order') ?? 0;
        $data['display_order'] = $maxOrder + 1;

        $stage = $this->stageRepository->create($data);

        return [
            'data' => new StageResource($stage),
        ];
    }

    /** @return array<string, mixed> */
    public function createBulk(int $churchId, int $count): array
    {
        /** @var int $maxOrder */
        $maxOrder = Stage::byChurch($churchId)->max('display_order') ?? 0;
        $stages = [];

        for ($i = 1; $i <= $count; $i++) {
            $stage = $this->stageRepository->create([
                'church_id' => $churchId,
                'name' => "Stage $i",
                'display_order' => $maxOrder + $i,
            ]);
            $stages[] = $stage;
        }

        return [
            'data' => StageResource::collection(collect($stages)),
        ];
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): bool
    {
        $stage = $this->stageRepository->findById($id);
        if (! $stage) {
            throw ValidationException::withMessages([
                'stage' => ['Stage not found.'],
            ]);
        }

        return $this->stageRepository->update($id, $data);
    }

    public function delete(int $id): bool
    {
        $stage = $this->stageRepository->findById($id);
        if (! $stage) {
            throw ValidationException::withMessages([
                'stage' => ['Stage not found.'],
            ]);
        }

        return $this->stageRepository->delete($id);
    }

    /** @return array<string, mixed> */
    public function getClasses(int $stageId, ?string $search = null): array
    {
        $stage = $this->stageRepository->findById($stageId);
        if (! $stage) {
            throw ValidationException::withMessages([
                'stage' => ['Stage not found.'],
            ]);
        }

        /** @var HasMany<Classe, Stage> $classesQuery */
        $classesQuery = $stage->classes();
        $classesQuery = $classesQuery->withCount([
            'allUsers as member_count' => fn (Builder $q) => $q->where('role', UserRole::Member),
            'servants as servant_count',
        ]);

        if ($search) {
            $classesQuery = $classesQuery->where('name', 'like', "%{$search}%");
        }

        $classes = $classesQuery->orderBy('display_order')->get();

        return [
            'data' => ClasseResource::collection($classes),
        ];
    }
}

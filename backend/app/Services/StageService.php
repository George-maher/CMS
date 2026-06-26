<?php

namespace App\Services;

use App\Contracts\StageRepositoryInterface;
use App\Contracts\StageServiceInterface;
use App\Http\Resources\ClasseResource;
use App\Http\Resources\StageResource;
use App\Models\Stage;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class StageService implements StageServiceInterface
{
    public function __construct(
        private readonly StageRepositoryInterface $stageRepository,
    ) {}

    public function all(?string $search = null): array
    {
        $stages = $this->stageRepository->all($search);

        return [
            'data' => StageResource::collection($stages),
        ];
    }

    public function structure(?string $search = null): array
    {
        $stages = $this->stageRepository->structure($search);

        return [
            'data' => $stages->map(fn(Stage $stage) => [
                'id' => $stage->id,
                'name' => $stage->name,
                'display_order' => $stage->display_order,
                'classes_count' => $stage->classes_count,
                'classes' => ClasseResource::collection($stage->classes),
            ])->values()->all(),
        ];
    }

    public function stagesWithClasses(?string $search = null): array
    {
        $stages = $this->stageRepository->structure($search);

        return $stages->map(fn(Stage $stage) => [
            'stage_id' => $stage->id,
            'stage_name' => $stage->name,
            'classes' => $stage->classes->map(fn(Classe $classe) => [
                'id' => $classe->id,
                'name' => $classe->name,
            ])->values()->all(),
        ])->values()->all();
    }

    public function findById(int $id): ?array
    {
        $stage = $this->stageRepository->findById($id);

        if (!$stage) return null;

        return [
            'data' => new StageResource($stage),
        ];
    }

    public function create(array $data): array
    {
        $data['church_id'] = auth()->user()->church_id;
        $maxOrder = Stage::byChurch()->max('display_order') ?? 0;
        $data['display_order'] = $maxOrder + 1;

        $stage = $this->stageRepository->create($data);

        return [
            'data' => new StageResource($stage),
        ];
    }

    public function createBulk(int $churchId, int $count): array
    {
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

    public function update(int $id, array $data): bool
    {
        $stage = $this->stageRepository->findById($id);
        if (!$stage) {
            throw ValidationException::withMessages([
                'stage' => ['Stage not found.'],
            ]);
        }

        return $this->stageRepository->update($id, $data);
    }

    public function delete(int $id): bool
    {
        $stage = $this->stageRepository->findById($id);
        if (!$stage) {
            throw ValidationException::withMessages([
                'stage' => ['Stage not found.'],
            ]);
        }

        return $this->stageRepository->delete($id);
    }

    public function getClasses(int $stageId, ?string $search = null): array
    {
        $stage = $this->stageRepository->findById($stageId);
        if (!$stage) {
            throw ValidationException::withMessages([
                'stage' => ['Stage not found.'],
            ]);
        }

        $classes = $stage->classes()
            ->withCount([
                'allUsers as member_count' => fn($q) => $q->where('role', \App\Enums\UserRole::Member),
                'servants as servant_count',
            ])
            ->when($search, fn($q) => $q->where('name', 'like', "%{$search}%"))
            ->orderBy('display_order')
            ->get();

        return [
            'data' => ClasseResource::collection($classes),
        ];
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Contracts\ScopeResolverInterface;
use App\Contracts\StageServiceInterface;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Classe;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StructureController extends Controller
{
    public function __construct(
        private readonly StageServiceInterface $stageService,
        private readonly ScopeResolverInterface $scopeResolver,
    ) {}

    public function classes(Request $request): JsonResponse
    {
        /** @var string|null $search */
        $search = $request->input('search');

        /** @var User|null $user */
        $user = $request->user();
        if ($user !== null && ! $user->isAdmin() && ! $user->isPlatformAdmin()) {
            $allowedStages = $this->scopeResolver->allowedStageIds($user);
            $allowedClasses = $this->scopeResolver->allowedClassIds($user) ?? [];

            /** @var array<int, array<string, mixed>> $stages */
            $stages = $this->stageService->structure($search)['data'];
            $stages = array_values(array_filter(
                $stages,
                function (array $stage) use ($allowedStages): bool {
                    $stageId = $stage['id'] ?? null;

                    return is_numeric($stageId) && in_array((int) $stageId, $allowedStages, true);
                },
            ));

            return response()->json([
                'data' => $this->filterStructureClasses($stages, $allowedClasses),
            ]);
        }

        return response()->json($this->stageService->structure($search));
    }

    public function stagesWithClasses(Request $request): JsonResponse
    {
        /** @var string|null $search */
        $search = $request->input('search');

        /** @var User|null $user */
        $user = $request->user();
        if ($user !== null && ! $user->isAdmin() && ! $user->isPlatformAdmin()) {
            $allowedStages = $this->scopeResolver->allowedStageIds($user);
            $allowedClasses = $this->scopeResolver->allowedClassIds($user) ?? [];

            /** @var array<int, array<string, mixed>> $structure */
            $structure = $this->stageService->stagesWithClasses($search);
            $structure = array_values(array_filter(
                $structure,
                function (array $stage) use ($allowedStages): bool {
                    $stageId = $stage['stage_id'] ?? null;

                    return is_numeric($stageId) && in_array((int) $stageId, $allowedStages, true);
                },
            ));

            return response()->json([
                'data' => $this->filterStructureClasses($structure, $allowedClasses, 'stage_id'),
            ]);
        }

        return response()->json($this->stageService->stagesWithClasses($search));
    }

    public function myClasses(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->isStageAdmin()) {
            $classIds = $this->scopeResolver->allowedClassIds($user) ?? [];
            $classes = $classIds === []
                ? collect()
                : Classe::byChurch()
                    ->whereIn('id', $classIds)
                    ->orderBy('display_order')
                    ->get(['id', 'name']);

            return response()->json(['data' => $classes]);
        }

        $classes = $user->classes()->get(['classes.id', 'classes.name']);

        if ($classes->isEmpty() && $user->class_id) {
            $classes = collect([(object) ['id' => $user->class_id, 'name' => $user->classe?->name]]);
        }

        return response()->json(['data' => $classes->values()]);
    }

    public function myClassServants(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->isStageAdmin()) {
            $classIds = $this->scopeResolver->allowedClassIds($user) ?? [];
            $servants = $classIds === []
                ? collect()
                : User::byChurch()
                    ->whereIn('id', function (Builder $q) use ($classIds) {
                        $q->select('user_id')
                            ->from('class_servant')
                            ->whereIn('class_id', $classIds);
                    })
                    ->where('role', UserRole::Servant)
                    ->get(['id', 'name', 'email', 'phone']);

            return response()->json(['data' => $servants]);
        }

        $classId = $user->class_id;

        if (! $classId) {
            return response()->json(['data' => []]);
        }

        $servants = User::byChurch()
            ->whereIn('id', function (Builder $q) use ($classId) {
                $q->select('user_id')
                    ->from('class_servant')
                    ->where('class_id', $classId);
            })
            ->where('role', UserRole::Servant)
            ->get(['id', 'name', 'email', 'phone']);

        return response()->json(['data' => $servants]);
    }

    /**
     * Remove unauthorized nested classes from structure payloads. Stage visibility
     * alone must never imply access to every class in that stage.
     *
     * @param  array<int, array<string, mixed>>  $stages
     * @param  array<int, int>  $allowedClassIds
     * @return array<int, array<string, mixed>>
     */
    private function filterStructureClasses(array $stages, array $allowedClassIds, string $stageIdKey = 'id'): array
    {
        foreach ($stages as &$stage) {
            $classes = collect($stage['classes'] ?? [])->filter(function ($classe) use ($allowedClassIds): bool {
                $classId = is_array($classe) ? ($classe['id'] ?? null) : ($classe->id ?? null);

                return is_numeric($classId) && in_array((int) $classId, $allowedClassIds, true);
            })->values();

            $stage['classes'] = $classes;
            if ($stageIdKey === 'id') {
                $stage['classes_count'] = $classes->count();
            }
        }
        unset($stage);

        return $stages;
    }
}

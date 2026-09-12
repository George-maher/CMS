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
        if ($user !== null && ! $user->isAdmin()) {
            $allowed = $this->scopeResolver->allowedStageIds($user);

            /** @var array<int, array<string, mixed>> $stages */
            $stages = $this->stageService->structure($search)['data'];

            return response()->json([
                'data' => array_values(array_filter(
                    $stages,
                    function (array $stage) use ($allowed): bool {
                        $stageId = $stage['id'] ?? null;

                        return is_numeric($stageId) && in_array((int) $stageId, $allowed, true);
                    },
                )),
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
        if ($user !== null && ! $user->isAdmin()) {
            $allowed = $this->scopeResolver->allowedStageIds($user);

            /** @var array<int, array<string, mixed>> $structure */
            $structure = $this->stageService->stagesWithClasses($search);

            return response()->json([
                'data' => array_values(array_filter(
                    $structure,
                    function (array $stage) use ($allowed): bool {
                        $stageId = $stage['stage_id'] ?? null;

                        return is_numeric($stageId) && in_array((int) $stageId, $allowed, true);
                    },
                )),
            ]);
        }

        return response()->json($this->stageService->stagesWithClasses($search));
    }

    public function myClasses(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->isStageAdmin() && $user->stage_id) {
            $classes = Classe::byChurch()
                ->where('stage_id', $user->stage_id)
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

        if ($user->isStageAdmin() && $user->stage_id) {
            $servants = User::byChurch()
                ->whereIn('id', function (Builder $q) use ($user) {
                    $q->select('user_id')
                        ->from('class_servant')
                        ->whereIn('class_id', function (Builder $q2) use ($user) {
                            $q2->select('id')
                                ->from('classes')
                                ->where('stage_id', $user->stage_id);
                        });
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
}

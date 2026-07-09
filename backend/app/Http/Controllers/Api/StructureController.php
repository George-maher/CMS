<?php

namespace App\Http\Controllers\Api;

use App\Contracts\StageServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StructureController extends Controller
{
    public function __construct(
        private readonly StageServiceInterface $stageService,
    ) {}

    public function classes(Request $request): JsonResponse
    {
        /** @var string|null $search */
        $search = $request->input('search');
        return response()->json($this->stageService->structure($search));
    }

    public function stagesWithClasses(Request $request): JsonResponse
    {
        /** @var string|null $search */
        $search = $request->input('search');
        return response()->json(
            $this->stageService->stagesWithClasses($search)
        );
    }

    public function myClasses(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();
        $classes = $user->classes()->get(['classes.id', 'classes.name']);

        if ($classes->isEmpty() && $user->class_id) {
            $classes = collect([(object) ['id' => $user->class_id, 'name' => $user->classe?->name]]);
        }

        return response()->json(['data' => $classes->values()]);
    }

    public function myClassServants(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();
        $classId = $user->class_id;

        if (!$classId) {
            return response()->json(['data' => []]);
        }

        $servants = User::byChurch()
            ->whereIn('id', function (\Illuminate\Database\Eloquent\Builder $q) use ($classId) {
                $q->select('user_id')
                    ->from('class_servant')
                    ->where('class_id', $classId);
            })
            ->where('role', \App\Enums\UserRole::Servant)
            ->get(['id', 'name', 'email', 'phone']);

        return response()->json(['data' => $servants]);
    }
}

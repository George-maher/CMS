<?php

namespace App\Http\Controllers\Api;

use App\Contracts\StageServiceInterface;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StructureController extends Controller
{
    public function __construct(
        private readonly StageServiceInterface $stageService,
    ) {}

    public function classes(Request $request): JsonResponse
    {
        return response()->json($this->stageService->structure($request->input('search')));
    }

    public function stagesWithClasses(Request $request): JsonResponse
    {
        return response()->json(
            $this->stageService->stagesWithClasses($request->input('search'))
        );
    }

    public function myClasses(Request $request): JsonResponse
    {
        $user = $request->user();
        $classes = $user->classes()->get(['classes.id', 'classes.name']);

        if ($classes->isEmpty() && $user->class_id) {
            $classes = collect([(object) ['id' => $user->class_id, 'name' => $user->classe?->name]]);
        }

        return response()->json(['data' => $classes->values()]);
    }

    public function myClassServants(Request $request): JsonResponse
    {
        return app(\App\Modules\User\Controllers\UserController::class)->myClassServants($request);
    }
}

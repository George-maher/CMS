<?php

namespace App\Http\Controllers\Api;

use App\Contracts\StageServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\BulkCreateStagesRequest;
use App\Http\Requests\StoreStageRequest;
use App\Http\Requests\UpdateStageRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StageController extends Controller
{
    public function __construct(
        private readonly StageServiceInterface $stageService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->stageService->all($request->input('search')));
    }

    public function store(StoreStageRequest $request): JsonResponse
    {
        $result = $this->stageService->create($request->validated());

        return response()->json([
            'message' => 'Stage created successfully.',
            'data' => $result['data'],
        ], 201);
    }

    public function bulkCreate(BulkCreateStagesRequest $request): JsonResponse
    {
        $churchId = $request->user()->church_id;
        $result = $this->stageService->createBulk($churchId, $request->input('count'));

        return response()->json([
            'message' => 'Stages created successfully.',
            'data' => $result['data'],
        ], 201);
    }

    public function show(int $id): JsonResponse
    {
        $result = $this->stageService->findById($id);

        if (!$result) {
            return response()->json(['message' => 'Stage not found.'], 404);
        }

        return response()->json($result);
    }

    public function update(UpdateStageRequest $request, int $id): JsonResponse
    {
        $this->stageService->update($id, $request->validated());

        $updated = $this->stageService->findById($id);

        return response()->json([
            'message' => 'Stage updated successfully.',
            'data' => $updated['data'],
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $this->stageService->delete($id);

        return response()->json([
            'message' => 'Stage deleted successfully.',
        ]);
    }

    public function classes(Request $request, int $id): JsonResponse
    {
        return response()->json($this->stageService->getClasses($id, $request->input('search')));
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Contracts\ScopeResolverInterface;
use App\Contracts\StageServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\BulkCreateStagesRequest;
use App\Http\Requests\StoreStageRequest;
use App\Http\Requests\UpdateStageRequest;
use App\Http\Resources\ClasseResource;
use App\Http\Resources\StageResource;
use App\Models\Classe;
use App\Models\Stage;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class StageController extends Controller
{
    public function __construct(
        private readonly StageServiceInterface $stageService,
        private readonly ScopeResolverInterface $scopeResolver,
    ) {}

    public function index(Request $request): JsonResponse
    {
        /** @var string|null $search */
        $search = $request->input('search');
        $result = $this->stageService->all($search);

        /** @var User|null $user */
        $user = $request->user();

        if ($user !== null && ! $user->isAdmin()) {
            $result['data'] = $this->filterStages($result['data'] ?? collect(), $user);
        }

        return response()->json($result);
    }

    public function store(StoreStageRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->authorize('create', Stage::class);

        $result = $this->stageService->create($request->validated());

        return response()->json([
            'message' => 'Stage created successfully.',
            'data' => $result['data'],
        ], 201);
    }

    public function bulkCreate(BulkCreateStagesRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->authorize('create', Stage::class);
        /** @var int $churchId */
        $churchId = $user->church_id;
        $count = $request->integer('count');
        $result = $this->stageService->createBulk($churchId, $count);

        return response()->json([
            'message' => 'Stages created successfully.',
            'data' => $result['data'],
        ], 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user();

        if ($user !== null && ! $user->isPlatformAdmin()) {
            $stage = Stage::query()->find($id);
            if ($stage === null) {
                return response()->json(['message' => 'Stage not found.'], 404);
            }
            if (! $this->scopeResolver->canAccessStage($user, $stage)) {
                return response()->json(['message' => 'Forbidden.'], 403);
            }
        }

        $result = $this->stageService->findById($id);

        if (! $result) {
            return response()->json(['message' => 'Stage not found.'], 404);
        }

        return response()->json($result);
    }

    public function update(Request $request, UpdateStageRequest $validation, int $id): JsonResponse
    {
        $stage = Stage::query()->find($id);
        if ($stage === null) {
            return response()->json(['message' => 'Stage not found.'], 404);
        }
        $this->authorize('update', $stage);

        $this->stageService->update($id, $validation->validated());

        /** @var array<string, mixed>|null $updated */
        $updated = $this->stageService->findById($id);

        return response()->json([
            'message' => 'Stage updated successfully.',
            'data' => $updated !== null ? $updated['data'] : null,
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $stage = Stage::query()->find($id);
        if ($stage === null) {
            return response()->json(['message' => 'Stage not found.'], 404);
        }
        $this->authorize('delete', $stage);

        $this->stageService->delete($id);

        return response()->json([
            'message' => 'Stage deleted successfully.',
        ]);
    }

    public function classes(Request $request, int $id): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user();

        if ($user !== null && ! $user->isPlatformAdmin()) {
            $stage = Stage::query()->find($id);
            if ($stage === null) {
                return response()->json(['message' => 'Stage not found.'], 404);
            }
            if (! $this->scopeResolver->canAccessStage($user, $stage)) {
                return response()->json(['message' => 'Forbidden.'], 403);
            }
        }

        /** @var string|null $search */
        $search = $request->input('search');
        $result = $this->stageService->getClasses($id, $search);

        if ($user !== null && ! $user->isAdmin()) {
            $result['data'] = $this->filterClasses($result['data'] ?? collect(), $user);
        }

        return response()->json($result);
    }

    /**
     * @param  AnonymousResourceCollection<int, StageResource>|mixed  $data
     * @return AnonymousResourceCollection|mixed
     */
    private function filterStages($data, User $user)
    {
        $allowed = $this->scopeResolver->allowedStageIds($user);
        if ($data instanceof AnonymousResourceCollection && $data->collection !== null) {
            $data->collection = $data->collection->filter(function (mixed $resource) use ($allowed): bool {
                if (! $resource instanceof StageResource) {
                    return false;
                }
                /** @var Stage $stage */
                $stage = $resource->resource;

                return in_array($stage->id, $allowed, true);
            })->values();
        }

        return $data;
    }

    /**
     * @param  AnonymousResourceCollection<int, ClasseResource>|mixed  $data
     * @return AnonymousResourceCollection|mixed
     */
    private function filterClasses($data, User $user)
    {
        $allowedClasses = $this->scopeResolver->allowedClassIds($user);
        if ($data instanceof AnonymousResourceCollection && $allowedClasses !== null && $data->collection !== null) {
            $data->collection = $data->collection->filter(function (mixed $resource) use ($allowedClasses): bool {
                if (! $resource instanceof ClasseResource) {
                    return false;
                }
                /** @var Classe $classe */
                $classe = $resource->resource;

                return $classe !== null && in_array($classe->id, $allowedClasses, true);
            })->values();
        }

        return $data;
    }
}

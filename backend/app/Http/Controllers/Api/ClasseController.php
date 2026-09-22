<?php

namespace App\Http\Controllers\Api;

use App\Contracts\ClasseServiceInterface;
use App\Contracts\ScopeResolverInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\BulkCreateClassesRequest;
use App\Http\Requests\StoreClasseRequest;
use App\Http\Requests\UpdateClasseRequest;
use App\Http\Resources\ClasseResource;
use App\Models\Classe;
use App\Models\Stage;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ClasseController extends Controller
{
    public function __construct(
        private readonly ClasseServiceInterface $classeService,
        private readonly ScopeResolverInterface $scopeResolver,
    ) {}

    public function index(Request $request): JsonResponse
    {
        /** @var string|null $search */
        $search = $request->input('search');
        $result = $this->classeService->all($search);

        /** @var User|null $user */
        $user = $request->user();
        if ($user !== null && ! $user->isAdmin()) {
            $allowed = $this->scopeResolver->allowedClassIds($user);
            if ($allowed !== null && ($result['data'] ?? null) instanceof AnonymousResourceCollection && $result['data']->collection !== null) {
                $result['data']->collection = $result['data']->collection->filter(function (mixed $resource) use ($allowed): bool {
                    if (! $resource instanceof ClasseResource) {
                        return false;
                    }
                    /** @var Classe $classe */
                    $classe = $resource->resource;

                    return in_array($classe->id, $allowed, true);
                })->values();
            }
        }

        return response()->json($result);
    }

    public function store(StoreClasseRequest $request): JsonResponse
    {
        /** @var array<string, mixed> $data */
        $data = $request->validated();
        /** @var int|null $stageId */
        $stageId = isset($data['stage_id']) && is_numeric($data['stage_id']) ? (int) $data['stage_id'] : null;
        $stage = Stage::query()->find($stageId ?? 0);
        $this->authorize('create', $stage ?? Stage::class);

        $result = $this->classeService->create($data);

        return response()->json([
            'message' => 'Class created successfully.',
            'data' => $result['data'],
        ], 201);
    }

    public function bulkCreate(BulkCreateClassesRequest $request, int $id): JsonResponse
    {
        // Stage comes from the URL, never from the payload. Authorization
        // is checked against the resolved Stage model so a Stage Admin
        // cannot create classes in another stage by tampering with input.
        $stage = Stage::query()->find($id);
        if ($stage === null) {
            return response()->json(['message' => 'Stage not found.'], 404);
        }
        $this->authorize('create', $stage);

        $result = $this->classeService->createBulk($stage->id, $request->integer('count'));

        return response()->json([
            'message' => 'Classes created successfully.',
            'data' => $result['data'],
        ], 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user();
        if ($user !== null && ! $user->isPlatformAdmin()) {
            $classe = Classe::query()->find($id);
            if ($classe === null) {
                return response()->json(['message' => 'Class not found.'], 404);
            }
            if (! $this->scopeResolver->canAccessClass($user, $classe)) {
                return response()->json(['message' => 'Forbidden.'], 403);
            }
        }

        $result = $this->classeService->findById($id);

        if (! $result) {
            return response()->json(['message' => 'Class not found.'], 404);
        }

        return response()->json($result);
    }

    public function update(UpdateClasseRequest $request, int $id): JsonResponse
    {
        $classe = Classe::query()->find($id);
        if ($classe === null) {
            return response()->json(['message' => 'Class not found.'], 404);
        }
        $this->authorize('update', $classe);

        $this->classeService->update($id, $request->validated());

        /** @var array{data: ClasseResource}|null $updated */
        $updated = $this->classeService->findById($id);

        return response()->json([
            'message' => 'Class updated successfully.',
            'data' => $updated['data'] ?? null,
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $classe = Classe::query()->find($id);
        if ($classe === null) {
            return response()->json(['message' => 'Class not found.'], 404);
        }
        $this->authorize('delete', $classe);

        $this->classeService->delete($id);

        return response()->json([
            'message' => 'Class deleted successfully.',
        ]);
    }

    public function detail(Request $request, int $id): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user();
        if ($user !== null && ! $user->isPlatformAdmin()) {
            $classe = Classe::query()->find($id);
            if ($classe === null) {
                return response()->json(['message' => 'Class not found.'], 404);
            }
            if (! $this->scopeResolver->canAccessClass($user, $classe)) {
                return response()->json(['message' => 'Forbidden.'], 403);
            }
        }

        $result = $this->classeService->getDetail($id);

        return response()->json(['data' => $result]);
    }

    public function assignServant(Request $request, int $id): JsonResponse
    {
        $classe = Classe::query()->find($id);
        if ($classe === null) {
            return response()->json(['message' => 'Class not found.'], 404);
        }
        $this->authorize('manageServants', $classe);

        $request->validate(['user_id' => 'required|integer|exists:users,id']);

        /** @var int $servantId */
        $servantId = $request->input('user_id');
        $servant = User::byChurch()->find($servantId);
        if (! $servant) {
            return response()->json(['message' => 'Servant not found.'], 404);
        }

        /** @var User|null $actor */
        $actor = $request->user();
        if ($actor === null || ! $this->scopeResolver->canAccessUser($actor, $servant)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $result = $this->classeService->assignServant(
            classeId: $id,
            servantId: $servantId,
        );

        return response()->json([
            'message' => 'Servant assigned to class successfully.',
            'data' => $result['data'],
        ]);
    }

    public function removeServant(Request $request, int $id): JsonResponse
    {
        $classe = Classe::query()->find($id);
        if ($classe === null) {
            return response()->json(['message' => 'Class not found.'], 404);
        }
        $this->authorize('manageServants', $classe);

        $request->validate(['user_id' => 'required|integer|exists:users,id']);

        /** @var int $servantId */
        $servantId = $request->input('user_id');
        $result = $this->classeService->removeServant(
            classeId: $id,
            servantId: $servantId,
        );

        return response()->json($result);
    }

    public function updateOrder(Request $request): JsonResponse
    {
        $this->authorize('reorder', Classe::class);

        $request->validate([
            'ordered_ids' => 'required|array',
            'ordered_ids.*' => 'integer|exists:classes,id',
        ]);

        /** @var array<int, int> $orderedIds */
        $orderedIds = [];
        foreach ((array) $request->input('ordered_ids') as $rawId) {
            if (is_int($rawId)) {
                $orderedIds[] = $rawId;
            }
        }
        $this->classeService->updateOrder($orderedIds);

        return response()->json(['message' => 'Class order updated successfully.']);
    }

    public function members(Request $request, int $id): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user();
        if ($user !== null && ! $user->isPlatformAdmin()) {
            $classe = Classe::query()->find($id);
            if ($classe === null) {
                return response()->json(['message' => 'Class not found.'], 404);
            }
            if (! $this->scopeResolver->canAccessClass($user, $classe)) {
                return response()->json(['message' => 'Forbidden.'], 403);
            }
        }

        $result = $this->classeService->getMembers(
            classeId: $id,
            perPage: $request->integer('per_page', 15),
        );

        return response()->json($result);
    }

    public function servants(Request $request, int $id): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user();
        if ($user !== null && ! $user->isPlatformAdmin()) {
            $classe = Classe::query()->find($id);
            if ($classe === null) {
                return response()->json(['message' => 'Class not found.'], 404);
            }
            if (! $this->scopeResolver->canAccessClass($user, $classe)) {
                return response()->json(['message' => 'Forbidden.'], 403);
            }
        }

        $result = $this->classeService->getServants(
            classeId: $id,
            perPage: $request->integer('per_page', 15),
        );

        return response()->json($result);
    }

    public function assignMember(Request $request, int $id): JsonResponse
    {
        $classe = Classe::query()->find($id);
        if ($classe === null) {
            return response()->json(['message' => 'Class not found.'], 404);
        }
        $this->authorize('manageMembers', $classe);

        $request->validate(['user_id' => 'required|integer|exists:users,id']);

        /** @var int $memberId */
        $memberId = $request->input('user_id');
        $user = User::byChurch()->find($memberId);
        if (! $user) {
            return response()->json(['message' => 'User not found.'], 404);
        }
        /** @var User|null $actor */
        $actor = $request->user();
        if ($actor === null || ! $this->scopeResolver->canAccessUser($actor, $user)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $result = $this->classeService->assignMember(
            classeId: $id,
            memberId: $memberId,
        );

        return response()->json([
            'message' => 'Member assigned to class successfully.',
            'data' => $result['data'],
        ]);
    }
}

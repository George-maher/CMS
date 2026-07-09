<?php

namespace App\Http\Controllers\Api;

use App\Contracts\ClasseServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreClasseRequest;
use App\Http\Requests\UpdateClasseRequest;
use App\Http\Resources\ClasseResource;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClasseController extends Controller
{
    public function __construct(
        private readonly ClasseServiceInterface $classeService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        /** @var string|null $search */
        $search = $request->input('search');

        return response()->json($this->classeService->all($search));
    }

    public function store(StoreClasseRequest $request): JsonResponse
    {
        $result = $this->classeService->create($request->validated());

        return response()->json([
            'message' => 'Class created successfully.',
            'data' => $result['data'],
        ], 201);
    }

    public function show(int $id): JsonResponse
    {
        $result = $this->classeService->findById($id);

        if (! $result) {
            return response()->json(['message' => 'Class not found.'], 404);
        }

        return response()->json($result);
    }

    public function update(UpdateClasseRequest $request, int $id): JsonResponse
    {
        $this->classeService->update($id, $request->validated());

        /** @var array{data: ClasseResource}|null $updated */
        $updated = $this->classeService->findById($id);

        return response()->json([
            'message' => 'Class updated successfully.',
            'data' => $updated['data'] ?? null,
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $this->classeService->delete($id);

        return response()->json([
            'message' => 'Class deleted successfully.',
        ]);
    }

    public function detail(int $id): JsonResponse
    {
        $result = $this->classeService->getDetail($id);

        return response()->json(['data' => $result]);
    }

    public function assignServant(Request $request, int $id): JsonResponse
    {
        $request->validate(['user_id' => 'required|integer|exists:users,id']);

        /** @var int $servantId */
        $servantId = $request->input('user_id');
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
        $request->validate([
            'ordered_ids' => 'required|array',
            'ordered_ids.*' => 'integer|exists:classes,id',
        ]);

        /** @var array<int, int> $orderedIds */
        $orderedIds = $request->input('ordered_ids');
        $this->classeService->updateOrder($orderedIds);

        return response()->json(['message' => 'Class order updated successfully.']);
    }

    public function members(Request $request, int $id): JsonResponse
    {
        $result = $this->classeService->getMembers(
            classeId: $id,
            perPage: $request->integer('per_page', 15),
        );

        return response()->json($result);
    }

    public function servants(Request $request, int $id): JsonResponse
    {
        $result = $this->classeService->getServants(
            classeId: $id,
            perPage: $request->integer('per_page', 15),
        );

        return response()->json($result);
    }

    public function assignMember(Request $request, int $id): JsonResponse
    {
        $request->validate(['user_id' => 'required|integer|exists:users,id']);

        /** @var int $memberId */
        $memberId = $request->input('user_id');
        $user = User::byChurch()->find($memberId);
        if (! $user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        $user->update(['class_id' => $id]);

        return response()->json([
            'message' => 'Member assigned to class successfully.',
            'data' => new UserResource($user->fresh()),
        ]);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Contracts\ClasseServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreClasseRequest;
use App\Http\Requests\UpdateClasseRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClasseController extends Controller
{
    public function __construct(
        private readonly ClasseServiceInterface $classeService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->classeService->all($request->input('search')));
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

        if (!$result) {
            return response()->json(['message' => 'Class not found.'], 404);
        }

        return response()->json($result);
    }

    public function update(UpdateClasseRequest $request, int $id): JsonResponse
    {
        $this->classeService->update($id, $request->validated());

        $updated = $this->classeService->findById($id);

        return response()->json([
            'message' => 'Class updated successfully.',
            'data' => $updated['data'],
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

        $result = $this->classeService->assignServant(
            classeId: $id,
            servantId: $request->input('user_id')
        );

        return response()->json([
            'message' => 'Servant assigned to class successfully.',
            'data' => $result['data'],
        ]);
    }

    public function removeServant(Request $request, int $id): JsonResponse
    {
        $request->validate(['user_id' => 'required|integer|exists:users,id']);

        $result = $this->classeService->removeServant(
            classeId: $id,
            servantId: $request->input('user_id')
        );

        return response()->json($result);
    }

    public function updateOrder(Request $request): JsonResponse
    {
        $request->validate([
            'ordered_ids' => 'required|array',
            'ordered_ids.*' => 'integer|exists:classes,id',
        ]);

        $this->classeService->updateOrder($request->input('ordered_ids'));

        return response()->json(['message' => 'Class order updated successfully.']);
    }

    public function members(Request $request, int $id): JsonResponse
    {
        $result = $this->classeService->getMembers(
            classeId: $id,
            perPage: (int) ($request->input('per_page', 15)),
        );

        return response()->json($result);
    }

    public function servants(Request $request, int $id): JsonResponse
    {
        $result = $this->classeService->getServants(
            classeId: $id,
            perPage: (int) ($request->input('per_page', 15)),
        );

        return response()->json($result);
    }

    public function assignMember(Request $request, int $id): JsonResponse
    {
        $request->validate(['user_id' => 'required|integer|exists:users,id']);

        $user = \App\Models\User::byChurch()->find($request->input('user_id'));
        if (!$user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        $user->update(['class_id' => $id]);

        return response()->json([
            'message' => 'Member assigned to class successfully.',
            'data' => new \App\Http\Resources\UserResource($user->fresh()),
        ]);
    }
}

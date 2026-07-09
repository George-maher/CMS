<?php

namespace App\Http\Controllers\Api;

use App\Contracts\VerseServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreVerseRequest;
use App\Http\Requests\UpdateVerseRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DailyVerseController extends Controller
{
    public function __construct(
        private readonly VerseServiceInterface $verseService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        /** @var int $perPage */
        $perPage = $request->input('per_page', 15);
        $result = $this->verseService->list(
            perPage: $perPage,
        );

        return response()->json([
            'data' => $result['data'],
            'meta' => $result['meta'],
        ]);
    }

    public function store(StoreVerseRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var int $creatorId */
        $creatorId = $user->id;
        $result = $this->verseService->create(
            data: $request->validated(),
            creatorId: $creatorId,
        );

        return response()->json([
            'message' => 'Verse created successfully.',
            'data' => $result['data'],
        ], 201);
    }

    public function show(int $id): JsonResponse
    {
        $result = $this->verseService->findById($id);

        if (! $result) {
            return response()->json(['message' => 'Verse not found.'], 404);
        }

        return response()->json($result);
    }

    public function update(UpdateVerseRequest $request, int $id): JsonResponse
    {
        $result = $this->verseService->update($id, $request->validated());

        return response()->json([
            'message' => 'Verse updated successfully.',
            'data' => $result['data'],
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $this->verseService->delete($id);

        return response()->json([
            'message' => 'Verse deleted successfully.',
        ]);
    }

    public function activate(int $id): JsonResponse
    {
        $result = $this->verseService->activate($id);

        return response()->json([
            'message' => 'Verse activated successfully.',
            'data' => $result['data'],
        ]);
    }

    public function getActive(): JsonResponse
    {
        $result = $this->verseService->getActive();

        if (! $result) {
            return response()->json(['data' => null]);
        }

        return response()->json($result);
    }
}

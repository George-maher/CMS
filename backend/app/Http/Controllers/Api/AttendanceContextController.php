<?php

namespace App\Http\Controllers\Api;

use App\Contracts\AttendanceContextServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAttendanceContextRequest;
use App\Models\AttendanceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AttendanceContextController extends Controller
{
    public function __construct(
        private readonly AttendanceContextServiceInterface $contextService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', AttendanceContext::class);

        $result = $this->contextService->list(
            perPage: $request->input('per_page', 15),
        );

        return response()->json([
            'data' => $result['data'],
            'meta' => $result['meta'],
        ]);
    }

    public function active(): JsonResponse
    {
        return response()->json($this->contextService->listActive());
    }

    public function store(StoreAttendanceContextRequest $request): JsonResponse
    {
        $this->authorize('create', AttendanceContext::class);

        $result = $this->contextService->create(
            data: $request->validated(),
            creatorId: $request->user()->id,
        );

        return response()->json([
            'message' => 'Attendance context created successfully.',
            'data' => $result['data'],
        ], 201);
    }

    public function show(int $id): JsonResponse
    {
        $context = AttendanceContext::withoutGlobalScope(\App\Models\Scopes\ChurchScope::class)->findOrFail($id);
        $this->authorize('view', $context);

        $result = $this->contextService->findById($id);

        if (!$result) {
            return response()->json(['message' => 'Attendance context not found.'], 404);
        }

        return response()->json($result);
    }

    public function update(StoreAttendanceContextRequest $request, int $id): JsonResponse
    {
        $context = AttendanceContext::withoutGlobalScope(\App\Models\Scopes\ChurchScope::class)->findOrFail($id);
        $this->authorize('update', $context);

        $result = $this->contextService->update(
            id: $id,
            data: $request->validated(),
            updaterId: $request->user()->id,
        );

        return response()->json([
            'message' => 'Attendance context updated successfully.',
            'data' => $result['data'],
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $context = AttendanceContext::withoutGlobalScope(\App\Models\Scopes\ChurchScope::class)->findOrFail($id);
        $this->authorize('delete', $context);

        $this->contextService->delete($id);

        return response()->json([
            'message' => 'Attendance context deleted successfully.',
        ]);
    }

    public function toggleActive(int $id): JsonResponse
    {
        $context = AttendanceContext::withoutGlobalScope(\App\Models\Scopes\ChurchScope::class)->findOrFail($id);
        $this->authorize('toggleActive', $context);

        $result = $this->contextService->update(
            id: $id,
            data: ['is_active' => !$context->is_active],
            updaterId: request()->user()->id,
        );

        $status = $context->fresh()->is_active ? 'activated' : 'archived';

        return response()->json([
            'message' => "Attendance context {$status} successfully.",
            'data' => $result['data'],
        ]);
    }
}

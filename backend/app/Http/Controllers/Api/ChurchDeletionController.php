<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\DeleteChurchRequest;
use App\Http\Resources\ChurchResource;
use App\Models\Church;
use App\Services\ChurchDeletionService;
use Illuminate\Http\JsonResponse;

class ChurchDeletionController extends Controller
{
    public function __construct(
        private readonly ChurchDeletionService $churchDeletionService,
    ) {}

    public function summary(int $id): JsonResponse
    {
        $church = Church::withTrashed()->findOrFail($id);

        if (!$church->trashed()) {
            $summary = $this->churchDeletionService->getDeletionSummary($church);
            return response()->json(['data' => $summary]);
        }

        return response()->json([
            'data' => [
                'church_id' => $church->id,
                'church_name' => $church->name,
                'deleted_at' => $church->deleted_at?->toISOString(),
                'deleted_by' => $church->deletedBy?->name,
                'deletion_type' => $church->deletion_type,
                'recoverable_until' => $church->recoverable_until?->toISOString(),
                'is_recoverable' => $church->isRecoverable(),
                'days_until_purge' => $church->daysUntilPurge(),
                'already_deleted' => true,
            ],
        ]);
    }

    public function softDelete(DeleteChurchRequest $request, int $id): JsonResponse
    {
        $church = Church::findOrFail($id);

        $this->churchDeletionService->softDelete($church, $request->user());

        return response()->json([
            'message' => __('church_deletion.soft_deleted'),
            'data' => new ChurchResource($church->fresh() ?? $church),
        ]);
    }

    public function restore(DeleteChurchRequest $request, int $id): JsonResponse
    {
        $church = Church::onlyTrashed()->findOrFail($id);

        $restored = $this->churchDeletionService->restore($church, $request->user());

        return response()->json([
            'message' => __('church_deletion.restored'),
            'data' => new ChurchResource($restored),
        ]);
    }

    public function hardDelete(DeleteChurchRequest $request, int $id): JsonResponse
    {
        $church = Church::withTrashed()->findOrFail($id);

        $this->churchDeletionService->hardDelete($church, $request->user());

        return response()->json([
            'message' => __('church_deletion.hard_deleted'),
        ]);
    }
}

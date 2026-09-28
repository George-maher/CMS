<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\DeleteChurchRequest;
use App\Http\Resources\ChurchResource;
use App\Models\Church;
use App\Services\ChurchDeletionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChurchDeletionController extends Controller
{
    public function __construct(
        private readonly ChurchDeletionService $churchDeletionService,
    ) {}

    public function deletedHistory(Request $request): JsonResponse
    {
        $churches = $this->churchDeletionService->getDeletedChurches($request);

        return response()->json([
            'data' => $churches->items(),
            'meta' => [
                'current_page' => $churches->currentPage(),
                'last_page' => $churches->lastPage(),
                'per_page' => $churches->perPage(),
                'total' => $churches->total(),
            ],
        ]);
    }

    public function deletedDetail(int $id): JsonResponse
    {
        $church = $this->churchDeletionService->getDeletedChurchDetail($id);

        return response()->json([
            'data' => [
                'id' => $church->id,
                'name' => $church->name,
                'slug' => $church->slug,
                'service_name' => $church->service_name,
                'priest_name' => $church->priest_name,
                'main_servant_name' => $church->main_servant_name,
                'priest_phone' => $church->priest_phone,
                'phone' => $church->phone,
                'address' => $church->address,
                'contact_email' => $church->contact_email,
                'description' => $church->description,
                'is_active' => $church->is_active,
                'is_suspended' => $church->is_suspended,
                'deleted_at' => $church->deleted_at?->toISOString(),
                'deleted_by' => $church->deletedBy ? [
                    'id' => $church->deletedBy->id,
                    'name' => $church->deletedBy->name,
                    'email' => $church->deletedBy->email,
                ] : null,
                'deletion_type' => $church->deletion_type,
                'recoverable_until' => $church->recoverable_until?->toISOString(),
                'member_count' => $church->users_count ?? $church->users()->count(),
                'created_at' => $church->created_at?->toISOString(),
                'updated_at' => $church->updated_at?->toISOString(),
            ],
        ]);
    }

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

        /** @var \App\Models\User $admin */
        $admin = $request->user();
        $this->churchDeletionService->softDelete($church, $admin);

        return response()->json([
            'message' => __('church_deletion.soft_deleted'),
            'data' => new ChurchResource($church->fresh() ?? $church),
        ]);
    }

    public function restore(DeleteChurchRequest $request, int $id): JsonResponse
    {
        $church = Church::onlyTrashed()->findOrFail($id);

        /** @var \App\Models\User $admin */
        $admin = $request->user();
        $restored = $this->churchDeletionService->restore($church, $admin);

        return response()->json([
            'message' => __('church_deletion.restored'),
            'data' => new ChurchResource($restored),
        ]);
    }

    public function hardDelete(DeleteChurchRequest $request, int $id): JsonResponse
    {
        $church = Church::withTrashed()->findOrFail($id);

        /** @var \App\Models\User $admin */
        $admin = $request->user();
        $this->churchDeletionService->hardDelete($church, $admin);

        return response()->json([
            'message' => __('church_deletion.hard_deleted'),
        ]);
    }
}

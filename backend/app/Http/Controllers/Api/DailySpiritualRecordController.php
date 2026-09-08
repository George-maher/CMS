<?php

namespace App\Http\Controllers\Api;

use App\Contracts\DailySpiritualRecordServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDailySpiritualRecordRequest;
use App\Http\Resources\DailySpiritualRecordResource;
use App\Models\DailySpiritualRecord;
use App\Models\User;
use App\Traits\ApiResponseTrait;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DailySpiritualRecordController extends Controller
{
    use ApiResponseTrait;

    public function __construct(
        private readonly DailySpiritualRecordServiceInterface $spiritualRecordService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $memberId = (int) $user->id;

        // Admin/servant can view other members' records
        if ($user->isAdmin() || $user->isServant()) {
            $requestedMemberId = $request->integer('member_id');
            if ($requestedMemberId > 0) {
                $targetMember = User::where('id', $requestedMemberId)
                    ->where('church_id', $user->church_id)
                    ->first();

                if (! $targetMember) {
                    return $this->notFound('Member not found.');
                }

                // Servants can only view their assigned members
                if ($user->isServant()) {
                    $servantClassIds = $user->getServantClassIds();
                    if ($servantClassIds === null || $targetMember->class_id === null || ! in_array($targetMember->class_id, $servantClassIds)) {
                        return $this->forbidden('You are not authorized to view this member\'s records.');
                    }
                }

                $memberId = $requestedMemberId;
            }
        }

        /** @var string|null $dateFrom */
        $dateFrom = $request->input('date_from');
        /** @var string|null $dateTo */
        $dateTo = $request->input('date_to');

        $result = $this->spiritualRecordService->listForMember(
            memberId: $memberId,
            dateFrom: $dateFrom,
            dateTo: $dateTo,
        );

        /** @var Collection<int, DailySpiritualRecord> $records */
        $records = $result['records'];

        return $this->respondWithCollection(
            DailySpiritualRecordResource::collection($records),
            [],
            'Success.',
        );
    }

    public function show(Request $request, string $activityDate): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $memberId = (int) $user->id;

        // Admin/servant can view other members' records
        if ($user->isAdmin() || $user->isServant()) {
            $requestedMemberId = $request->integer('member_id');
            if ($requestedMemberId > 0) {
                $targetMember = User::where('id', $requestedMemberId)
                    ->where('church_id', $user->church_id)
                    ->first();

                if (! $targetMember) {
                    return $this->notFound('Member not found.');
                }

                if ($user->isServant()) {
                    $servantClassIds = $user->getServantClassIds();
                    if ($servantClassIds === null || $targetMember->class_id === null || ! in_array($targetMember->class_id, $servantClassIds)) {
                        return $this->forbidden('You are not authorized to view this member\'s records.');
                    }
                }

                $memberId = $requestedMemberId;
            }
        }

        $record = $this->spiritualRecordService->getByDate($memberId, $activityDate);

        if ($record === null) {
            return $this->notFound('No record found for this date.');
        }

        return $this->respondWithResource(
            new DailySpiritualRecordResource($record),
        );
    }

    public function store(StoreDailySpiritualRecordRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        /** @var array<string, mixed> $validated */
        $validated = $request->validated();

        $result = $this->spiritualRecordService->storeOrUpdate(
            memberId: (int) $user->id,
            data: $validated,
        );

        /** @var bool $wasCreated */
        $wasCreated = $result['was_created'];

        return $this->success(
            new DailySpiritualRecordResource($result['record']),
            $wasCreated ? 'Record created successfully.' : 'Record updated successfully.',
            $wasCreated ? 201 : 200,
        );
    }

    public function destroy(Request $request, string $activityDate): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $deleted = $this->spiritualRecordService->delete((int) $user->id, $activityDate);

        if (! $deleted) {
            return $this->notFound('No record found for this date.');
        }

        return $this->success(null, 'Record deleted successfully.');
    }
}

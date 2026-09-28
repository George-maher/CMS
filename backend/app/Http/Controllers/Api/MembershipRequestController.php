<?php

namespace App\Http\Controllers\Api;

use App\Contracts\MembershipRequestServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\MembershipRequestReviewRequest;
use App\Http\Requests\MembershipRequestSubmitRequest;
use App\Http\Resources\MembershipRequestResource;
use App\Models\Church;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

class MembershipRequestController extends Controller
{
    public function __construct(
        private readonly MembershipRequestServiceInterface $membershipRequestService,
    ) {}

    /**
     * Public join request.
     *
     * The response is a fixed acknowledgement with no row identity, so a caller
     * cannot tell an accepted submission from one that was suppressed because
     * the address already exists. `membership-requests` is reachable
     * unauthenticated and `/churches/active` is public, so any distinguishable
     * outcome here would be a cross-church member enumeration oracle.
     */
    public function store(MembershipRequestSubmitRequest $request): JsonResponse
    {
        /** @var int $churchId */
        $churchId = $request->integer('church_id');
        $church = Church::find($churchId);

        if ($church === null) {
            return response()->json([
                'success' => false,
                'message' => __('membership_requests.church_not_found'),
                'code' => 'CHURCH_NOT_FOUND',
            ], 404);
        }

        $data = $request->validated();

        if ($request->hasFile('file')) {
            /** @var UploadedFile $uploadedFile */
            $uploadedFile = $request->file('file');
            $data['file'] = $uploadedFile;
        }

        $result = $this->membershipRequestService->submit($data, $churchId);

        return response()->json([
            'success' => true,
            'message' => $result['message'],
            'code' => 'REQUEST_RECEIVED',
        ], 201);
    }

    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var int $churchId */
        $churchId = $user->church_id;

        /** @var array<string, mixed> $filters */
        $filters = $request->only(['status']);

        $result = $this->membershipRequestService->listRequests(
            churchId: $churchId,
            perPage: $request->integer('per_page', 15),
            filters: $filters,
        );

        return response()->json([
            'data' => MembershipRequestResource::collection($result['data']),
            'meta' => $result['meta'],
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        /** @var int $churchId */
        $churchId = $user->church_id;
        $membershipRequest = $this->membershipRequestService->findById(
            id: $id,
            churchId: $churchId,
        );

        if (! $membershipRequest) {
            return response()->json(['message' => 'Request not found.'], 404);
        }

        return response()->json([
            'data' => new MembershipRequestResource($membershipRequest),
        ]);
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->user();
        /** @var int $adminId */
        $adminId = $admin->id;
        $result = $this->membershipRequestService->approve(
            id: $id,
            adminId: $adminId,
        );

        return response()->json([
            'message' => $result['message'],
        ]);
    }

    public function reject(MembershipRequestReviewRequest $request, int $id): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->user();
        /** @var int $adminId */
        $adminId = $admin->id;
        /** @var string $reason */
        $reason = $request->input('rejection_reason');
        $result = $this->membershipRequestService->reject(
            id: $id,
            adminId: $adminId,
            reason: $reason,
        );

        return response()->json([
            'message' => $result['message'],
        ]);
    }
}

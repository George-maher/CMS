<?php

namespace App\Http\Controllers\Api;

use App\Contracts\MembershipRequestServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\MembershipRequestReviewRequest;
use App\Http\Requests\MembershipRequestSubmitRequest;
use App\Http\Resources\MembershipRequestResource;
use App\Models\Church;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MembershipRequestController extends Controller
{
    public function __construct(
        private readonly MembershipRequestServiceInterface $membershipRequestService,
    ) {}

    public function store(MembershipRequestSubmitRequest $request): JsonResponse
    {
        $churchId = $request->input('church_id');
        $church = Church::find($churchId);

        if (!$church) {
            return response()->json(['message' => 'Church not found.'], 404);
        }

        $data = $request->validated();

        if ($request->hasFile('file')) {
            $data['file'] = $request->file('file');
        }

        $result = $this->membershipRequestService->submit(
            $data,
            $churchId,
        );

        return response()->json([
            'message' => $result['message'],
            'data' => new MembershipRequestResource($result['request']),
        ], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $filters = $request->only(['status']);

        $result = $this->membershipRequestService->listRequests(
            churchId: $user->church_id,
            perPage: $request->input('per_page', 15),
            filters: $filters,
        );

        return response()->json([
            'data' => MembershipRequestResource::collection($result['data']),
            'meta' => $result['meta'],
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        $membershipRequest = $this->membershipRequestService->findById(
            id: $id,
            churchId: $user->church_id,
        );

        if (!$membershipRequest) {
            return response()->json(['message' => 'Request not found.'], 404);
        }

        return response()->json([
            'data' => new MembershipRequestResource($membershipRequest),
        ]);
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        $result = $this->membershipRequestService->approve(
            id: $id,
            adminId: $request->user()->id,
        );

        return response()->json([
            'message' => $result['message'],
        ]);
    }

    public function reject(MembershipRequestReviewRequest $request, int $id): JsonResponse
    {
        $result = $this->membershipRequestService->reject(
            id: $id,
            adminId: $request->user()->id,
            reason: $request->input('rejection_reason'),
        );

        return response()->json([
            'message' => $result['message'],
        ]);
    }
}

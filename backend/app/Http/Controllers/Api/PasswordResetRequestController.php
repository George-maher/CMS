<?php

namespace App\Http\Controllers\Api;

use App\Contracts\PasswordResetRequestServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\ApprovePasswordResetRequest;
use App\Http\Requests\RejectPasswordResetRequest;
use App\Http\Requests\SubmitPasswordResetRequest;
use App\Http\Resources\PasswordResetRequestResource;
use App\Models\PasswordResetRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class PasswordResetRequestController extends Controller
{
    public function __construct(
        private readonly PasswordResetRequestServiceInterface $passwordResetRequestService,
    ) {}

    public function submit(SubmitPasswordResetRequest $request): JsonResponse
    {
        $result = $this->passwordResetRequestService->submitRequest($request->validated());

        return response()->json([
            'message' => $result['message'],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $perPage = min((int) $request->input('per_page', 15), 100);
        $filters = array_filter($request->only('status'));

        $result = $this->passwordResetRequestService->listRequests(
            $user->church_id,
            $perPage,
            $filters,
        );

        return response()->json([
            'data' => PasswordResetRequestResource::collection($result['data']),
            'meta' => $result['meta'],
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $user = request()->user();
        $request = $this->passwordResetRequestService->findById($id, $user->church_id);

        if (!$request) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        Gate::authorize('view', $request);

        return response()->json([
            'data' => new PasswordResetRequestResource($request),
        ]);
    }

    public function approve(int $id, ApprovePasswordResetRequest $request): JsonResponse
    {
        $user = $request->user();
        $resetRequest = PasswordResetRequest::find($id);

        if (!$resetRequest) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        Gate::authorize('approve', $resetRequest);

        $result = $this->passwordResetRequestService->approve($id, $user->id);

        return response()->json([
            'message' => $result['message'],
        ]);
    }

    public function reject(int $id, RejectPasswordResetRequest $request): JsonResponse
    {
        $user = $request->user();
        $resetRequest = PasswordResetRequest::find($id);

        if (!$resetRequest) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        Gate::authorize('reject', $resetRequest);

        $result = $this->passwordResetRequestService->reject(
            $id,
            $user->id,
            $request->input('reason'),
        );

        return response()->json([
            'message' => $result['message'],
        ]);
    }

    public function completeReset(Request $request): JsonResponse
    {
        $request->validate([
            'token' => ['required', 'string', 'size:64'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $result = $this->passwordResetRequestService->completeReset(
            $request->input('token'),
            $request->input('password'),
        );

        return response()->json([
            'message' => $result['message'],
        ]);
    }
}

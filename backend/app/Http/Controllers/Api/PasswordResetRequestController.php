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
        /** @var \App\Models\User $user */
        $user = $request->user();
        /** @var int $churchId */
        $churchId = $user->church_id;

        $perPage = min($request->integer('per_page', 15), 100);
        $filters = array_filter($request->only('status'));

        $result = $this->passwordResetRequestService->listRequests(
            $churchId,
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
        /** @var \App\Models\User $user */
        $user = request()->user();
        /** @var int $churchId */
        $churchId = $user->church_id;
        $request = $this->passwordResetRequestService->findById($id, $churchId);

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
        /** @var \App\Models\User $user */
        $user = $request->user();
        $resetRequest = PasswordResetRequest::find($id);

        if (!$resetRequest) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        Gate::authorize('approve', $resetRequest);

        /** @var int $adminId */
        $adminId = $user->id;
        $result = $this->passwordResetRequestService->approve($id, $adminId);

        return response()->json([
            'message' => $result['message'],
        ]);
    }

    public function reject(int $id, RejectPasswordResetRequest $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();
        $resetRequest = PasswordResetRequest::find($id);

        if (!$resetRequest) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        Gate::authorize('reject', $resetRequest);

        /** @var string $reason */
        $reason = $request->input('reason');
        /** @var int $adminId */
        $adminId = $user->id;
        $result = $this->passwordResetRequestService->reject(
            $id,
            $adminId,
            $reason,
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

        /** @var string $token */
        $token = $request->input('token');
        /** @var string $password */
        $password = $request->input('password');
        $result = $this->passwordResetRequestService->completeReset(
            $token,
            $password,
        );

        return response()->json([
            'message' => $result['message'],
        ]);
    }
}

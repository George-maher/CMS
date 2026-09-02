<?php

namespace App\Http\Controllers\Api;

use App\Contracts\AuditServiceInterface;
use App\Contracts\ProfileUpdateRequestServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\ApproveProfileUpdateRequest;
use App\Http\Requests\RejectProfileUpdateRequest;
use App\Http\Requests\StoreProfileUpdateRequest;
use App\Http\Requests\UpdateOwnProfileRequest;
use App\Http\Resources\ProfileUpdateRequestResource;
use App\Models\ProfileUpdateRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class ProfileUpdateRequestController extends Controller
{
    public function __construct(
        private readonly ProfileUpdateRequestServiceInterface $profileUpdateRequestService,
        private readonly AuditServiceInterface $auditService,
    ) {}

    /**
     * Admin/Servant: Update own profile directly.
     */
    public function updateOwnProfile(UpdateOwnProfileRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $validated = $request->validated();

        $oldValues = [];
        $newValues = [];

        // Capture changes
        foreach ($validated as $field => $value) {
            $oldValues[$field] = $user->{$field};
            $newValues[$field] = $value;
        }

        // Validate email uniqueness
        if (isset($validated['email']) && $validated['email'] !== $user->email) {
            $emailExists = User::where('email', $validated['email'])
                ->where('id', '!=', $user->id)
                ->exists();
            if ($emailExists) {
                throw ValidationException::withMessages([
                    'email' => [__('profile_update_requests.email_taken')],
                ]);
            }
        }

        // Validate phone uniqueness
        if (isset($validated['phone']) && $validated['phone'] !== ($user->phone ?? '')) {
            $phoneExists = User::where('phone', $validated['phone'])
                ->where('id', '!=', $user->id)
                ->exists();
            if ($phoneExists) {
                throw ValidationException::withMessages([
                    'phone' => [__('profile_update_requests.phone_taken')],
                ]);
            }
        }

        $user->forceFill($validated)->save();

        $this->auditService->log(
            action: 'profile_updated',
            resourceType: User::class,
            resourceId: $user->id,
            oldValues: $oldValues,
            newValues: $newValues,
            userId: $user->id,
            churchId: $user->church_id,
        );

        Log::info('Profile updated directly', [
            'user_id' => $user->id,
            'fields' => array_keys($newValues),
        ]);

        return response()->json([
            'message' => __('profile_update_requests.updated'),
        ]);
    }

    /**
     * Member: Submit a profile update request.
     */
    public function store(StoreProfileUpdateRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var array<string, mixed> $validated */
        $validated = $request->validated();

        /** @var int $userId */
        $userId = $user->id;
        $result = $this->profileUpdateRequestService->submitRequest($userId, $validated);

        return response()->json([
            'message' => $result['message'],
            'data' => new ProfileUpdateRequestResource($result['request']),
        ]);
    }

    /**
     * Member: List their own requests.
     */
    public function myRequests(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $perPage = min($request->integer('per_page', 15), 100);
        /** @var array<string, mixed> $filters */
        $filters = array_filter($request->only('status'));

        /** @var int $userId */
        $userId = $user->id;
        $result = $this->profileUpdateRequestService->listRequestsForMember(
            $userId,
            $perPage,
            $filters,
        );

        return response()->json([
            'data' => ProfileUpdateRequestResource::collection($result['data']),
            'meta' => $result['meta'],
        ]);
    }

    /**
     * Admin/Servant: List pending profile update requests.
     */
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var int $churchId */
        $churchId = $user->church_id;
        $perPage = min($request->integer('per_page', 15), 100);
        /** @var array<string, mixed> $filters */
        $filters = array_filter($request->only('status'));

        if ($user->isServant()) {
            /** @var int $servantId */
            $servantId = $user->id;
            $result = $this->profileUpdateRequestService->listRequestsForServant(
                $servantId,
                $perPage,
                $filters,
            );
        } else {
            $result = $this->profileUpdateRequestService->listRequestsForAdmin(
                $churchId,
                $perPage,
                $filters,
            );
        }

        return response()->json([
            'data' => ProfileUpdateRequestResource::collection($result['data']),
            'meta' => $result['meta'],
        ]);
    }

    /**
     * Admin/Servant: View a single request.
     */
    public function show(int $id): JsonResponse
    {
        /** @var User $user */
        $user = request()->user();
        /** @var int $churchId */
        $churchId = $user->church_id;

        $requestModel = $this->profileUpdateRequestService->findById($id, $churchId);

        if (! $requestModel) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        if (! $user->can('view', $requestModel)) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        return response()->json([
            'data' => new ProfileUpdateRequestResource($requestModel),
        ]);
    }

    /**
     * Admin/Servant: Approve a profile update request.
     */
    public function approve(int $id, ApproveProfileUpdateRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $requestModel = ProfileUpdateRequest::find($id);

        if (! $requestModel) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        if (! $user->can('approve', $requestModel)) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        /** @var int $reviewerId */
        $reviewerId = $user->id;
        $result = $this->profileUpdateRequestService->approve($id, $reviewerId);

        return response()->json([
            'message' => $result['message'],
            'data' => new ProfileUpdateRequestResource($result['request']),
        ]);
    }

    /**
     * Admin/Servant: Reject a profile update request.
     */
    public function reject(int $id, RejectProfileUpdateRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $requestModel = ProfileUpdateRequest::find($id);

        if (! $requestModel) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        if (! $user->can('reject', $requestModel)) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        /** @var string $reason */
        $reason = $request->input('reason');
        /** @var int $reviewerId */
        $reviewerId = $user->id;
        $result = $this->profileUpdateRequestService->reject($id, $reviewerId, $reason);

        return response()->json([
            'message' => $result['message'],
            'data' => new ProfileUpdateRequestResource($result['request']),
        ]);
    }
}

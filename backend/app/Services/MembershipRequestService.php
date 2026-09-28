<?php

namespace App\Services;

use App\Contracts\FileUploadServiceInterface;
use App\Contracts\MembershipRequestRepositoryInterface;
use App\Contracts\MembershipRequestServiceInterface;
use App\Contracts\NotificationServiceInterface;
use App\Enums\ProvisioningChannel;
use App\Enums\UserRole;
use App\Models\MembershipRequest;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MembershipRequestService implements MembershipRequestServiceInterface
{
    public function __construct(
        private readonly MembershipRequestRepositoryInterface $repository,
        private readonly FileUploadServiceInterface $fileUploadService,
        private readonly NotificationServiceInterface $notificationService,
        private readonly UserProvisioningService $userProvisioning,
    ) {}

    /**
     * Accept a public join request.
     *
     * Enumeration-safe by construction: this endpoint is unauthenticated, so the
     * response must never reveal whether the submitted address already has an
     * account, or a pending request, in this church. A submission that would be
     * refused internally is therefore *silently suppressed* — nothing is written
     * and nothing is revealed — and the caller receives exactly the same
     * acknowledgement it would have received had the request been accepted.
     *
     * @param  array<string, mixed>  $data
     * @return array{accepted: bool, message: string}
     */
    public function submit(array $data, int $churchId): array
    {
        /** @var string $email */
        $email = $data['email'];

        $existing = $this->repository->findByEmailChurch($email, $churchId);

        if ($existing !== null && $existing->isPending()) {
            // Metadata only. The submitted address is PII and is not logged.
            Log::info('membership_request', [
                'event' => 'duplicate_pending_suppressed',
                'church_id' => $churchId,
                'existing_request_id' => $existing->id,
            ]);

            return $this->acknowledgement();
        }

        $existingUser = User::where('email', $email)
            ->where('church_id', $churchId)
            ->first();

        if ($existingUser !== null) {
            Log::info('membership_request', [
                'event' => 'existing_account_suppressed',
                'church_id' => $churchId,
                'user_id' => $existingUser->id,
            ]);

            return $this->acknowledgement();
        }

        $fileUrl = null;
        $file = $data['file'] ?? null;
        if ($file instanceof UploadedFile) {
            $path = $this->fileUploadService->upload($file, 'uploads/join-requests');
            $fileUrl = $this->fileUploadService->url($path);
        }

        $this->repository->create([
            'church_id' => $churchId,
            'name' => $data['name'],
            'email' => $email,
            'phone' => $data['phone'] ?? null,
            'birthday' => $data['birthday'] ?? null,
            'address' => $data['address'] ?? null,
            'preferred_role' => $data['preferred_role'] ?? 'member',
            'file_url' => $fileUrl,
            'status' => 'pending',
        ]);

        return $this->acknowledgement();
    }

    /**
     * The single acknowledgement returned for both accepted and suppressed
     * submissions.
     *
     * It deliberately carries no row identity, so there is nothing for a caller
     * to correlate, and it is byte-identical in every suppressed case.
     *
     * @return array{accepted: bool, message: string}
     */
    private function acknowledgement(): array
    {
        return [
            'accepted' => true,
            'message' => __('membership_requests.received'),
        ];
    }

    /** @return array<string, mixed> */
    public function approve(int $id, int $adminId): array
    {
        $admin = User::find($adminId);
        if (! $admin) {
            throw ValidationException::withMessages([
                'admin' => ['Admin not found.'],
            ]);
        }

        $request = $this->repository->findById($id);

        if (! $request || ! $request->isPending()) {
            throw ValidationException::withMessages([
                'request' => ['This request is not pending.'],
            ]);
        }

        if ($request->church_id !== $admin->church_id) {
            throw ValidationException::withMessages([
                'request' => ['This request does not belong to your church.'],
            ]);
        }

        $user = DB::transaction(function () use ($request, $admin) {
            $request->update([
                'status' => 'approved',
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
            ]);

            $tmpPassword = Str::random(40);

            // The approving admin is the trusted party for this tenant, so the
            // account is created verified and can authenticate as soon as the
            // admin sets a password through the in-app reset workflow.
            return $this->userProvisioning->create([
                'church_id' => $request->church_id,
                'name' => $request->name,
                'email' => $request->email,
                'password' => bcrypt($tmpPassword),
                'role' => $request->preferred_role === 'servant' ? UserRole::Servant : UserRole::Member,
                'application_status' => 'approved',
                'is_active' => true,
                'phone' => $request->phone,
                'birthday' => $request->birthday,
                'address' => $request->address,
                'created_by' => $admin->id,
            ], ProvisioningChannel::MembershipRequestApproved);
        });

        /** @var int $memUserId */
        $memUserId = $user->id;
        $this->notificationService->create(
            userId: $memUserId,
            churchId: $request->church_id,
            title: 'Request Approved',
            body: 'Your request to join has been approved. You can now log in to the system.',
            type: 'membership_approved',
        );

        return [
            'user' => $user,
            'message' => 'Request approved. The new user can now log in.',
        ];
    }

    /** @return array<string, mixed> */
    public function reject(int $id, int $adminId, string $reason): array
    {
        $admin = User::find($adminId);
        if (! $admin) {
            throw ValidationException::withMessages([
                'admin' => ['Admin not found.'],
            ]);
        }

        $request = $this->repository->findById($id);

        if (! $request || ! $request->isPending()) {
            throw ValidationException::withMessages([
                'request' => ['This request is not pending.'],
            ]);
        }

        if ($request->church_id !== $admin->church_id) {
            throw ValidationException::withMessages([
                'request' => ['This request does not belong to your church.'],
            ]);
        }

        $request->reject($admin, $reason);

        return [
            'message' => 'Request rejected.',
        ];
    }

    /** @param array<string, mixed> $filters */
    /** @return array<string, mixed> */
    public function listRequests(int $churchId, int $perPage = 15, array $filters = []): array
    {
        $filters['church_id'] = $churchId;
        $paginator = $this->repository->paginate($perPage, $filters);

        return [
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }

    public function findById(int $id, int $churchId): ?MembershipRequest
    {
        $request = $this->repository->findById($id);

        if (! $request || $request->church_id !== $churchId) {
            return null;
        }

        return $request;
    }
}

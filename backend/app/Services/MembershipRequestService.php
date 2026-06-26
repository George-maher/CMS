<?php

namespace App\Services;

use App\Contracts\FileUploadServiceInterface;
use App\Contracts\MembershipRequestRepositoryInterface;
use App\Contracts\MembershipRequestServiceInterface;
use App\Contracts\NotificationServiceInterface;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MembershipRequestService implements MembershipRequestServiceInterface
{
    public function __construct(
        private readonly MembershipRequestRepositoryInterface $repository,
        private readonly FileUploadServiceInterface $fileUploadService,
        private readonly NotificationServiceInterface $notificationService,
    ) {}

    public function submit(array $data, int $churchId): array
    {
        $existing = $this->repository->findByEmailChurch($data['email'], $churchId);

        if ($existing && $existing->isPending()) {
            throw ValidationException::withMessages([
                'email' => ['A pending request already exists for this email.'],
            ]);
        }

        $existingUser = User::where('email', $data['email'])
            ->where('church_id', $churchId)
            ->first();

        if ($existingUser) {
            throw ValidationException::withMessages([
                'email' => ['This email is already registered in your church.'],
            ]);
        }

        $fileUrl = null;
        if (!empty($data['file']) && $data['file'] instanceof \Illuminate\Http\UploadedFile) {
            $path = $this->fileUploadService->upload($data['file'], 'uploads/join-requests');
            $fileUrl = $this->fileUploadService->url($path);
        }

        $request = $this->repository->create([
            'church_id' => $churchId,
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'birthday' => $data['birthday'] ?? null,
            'address' => $data['address'] ?? null,
            'preferred_role' => $data['preferred_role'] ?? 'member',
            'file_url' => $fileUrl,
            'status' => 'pending',
        ]);

        return [
            'request' => $request,
            'message' => 'Your request has been submitted. Once approved, you will be able to log in.',
        ];
    }

    public function approve(int $id, int $adminId): array
    {
        $admin = User::find($adminId);
        if (!$admin) {
            throw ValidationException::withMessages([
                'admin' => ['Admin not found.'],
            ]);
        }

        $request = $this->repository->findById($id);

        if (!$request || !$request->isPending()) {
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

            return User::create([
                'church_id' => $request->church_id,
                'name' => $request->name,
                'email' => $request->email,
                'password' => bcrypt($tmpPassword),
                'role' => $request->preferred_role === 'servant' ? \App\Enums\UserRole::Servant : \App\Enums\UserRole::Member,
                'application_status' => 'approved',
                'is_active' => true,
                'phone' => $request->phone,
                'birthday' => $request->birthday,
                'address' => $request->address,
            ]);
        });

        $this->notificationService->create(
            userId: $user->id,
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

    public function reject(int $id, int $adminId, string $reason): array
    {
        $admin = User::find($adminId);
        if (!$admin) {
            throw ValidationException::withMessages([
                'admin' => ['Admin not found.'],
            ]);
        }

        $request = $this->repository->findById($id);

        if (!$request || !$request->isPending()) {
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

    public function findById(int $id, int $churchId): ?\App\Models\MembershipRequest
    {
        $request = $this->repository->findById($id);

        if (!$request || $request->church_id !== $churchId) {
            return null;
        }

        return $request;
    }
}

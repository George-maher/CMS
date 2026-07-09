<?php

namespace App\Services;

use App\Contracts\AttendanceContextRepositoryInterface;
use App\Contracts\AttendanceContextServiceInterface;
use App\Http\Resources\AttendanceContextResource;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class AttendanceContextService implements AttendanceContextServiceInterface
{
    public function __construct(
        private readonly AttendanceContextRepositoryInterface $contextRepository,
    ) {}

    /** @return array<string, mixed> */
    public function list(int $perPage = 15): array
    {
        /** @var User|null $user */
        $user = auth()->user();
        $churchId = $user?->church_id;

        $filters = [];
        if ($churchId) {
            $filters['church_id'] = $churchId;
        }

        $paginator = $this->contextRepository->paginate($perPage, $filters);

        return [
            'data' => AttendanceContextResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function listActive(): array
    {
        /** @var User|null $user */
        $user = auth()->user();
        $churchId = $user?->church_id;

        $contexts = $churchId
            ? $this->contextRepository->getActiveForChurch($churchId)
            : $this->contextRepository->getActive();

        $count = $contexts->count();

        /** @var int|null $authId */
        $authId = auth()->id();

        Log::debug('[AttendanceContext] listActive', [
            'church_id' => $churchId,
            'contexts_count' => $count,
            'user_id' => $authId,
        ]);

        return [
            'data' => AttendanceContextResource::collection($contexts),
        ];
    }

    /** @return array<string, mixed> */
    public function listActiveForChurch(int $churchId): array
    {
        $contexts = $this->contextRepository->getActiveForChurch($churchId);

        return [
            'data' => AttendanceContextResource::collection($contexts),
        ];
    }

    /** @return ?array<string, mixed> */
    public function findById(int $id): ?array
    {
        $context = $this->contextRepository->findById($id);
        if (! $context) {
            return null;
        }

        return [
            'data' => new AttendanceContextResource($context),
        ];
    }

    /** @param array<string, mixed> $data */
    public function create(array $data, int $creatorId): array
    {
        /** @var User|null $authUser */
        $authUser = auth()->user();
        $churchId = $authUser?->church_id;

        $context = $this->contextRepository->create([
            'name' => $data['name'],
            'name_ar' => $data['name_ar'] ?? null,
            'description' => $data['description'] ?? null,
            'is_active' => $data['is_active'] ?? true,
            'church_id' => $churchId,
            'created_by' => $creatorId,
        ]);

        return [
            'data' => new AttendanceContextResource($context),
        ];
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data, ?int $updaterId = null): array
    {
        $context = $this->contextRepository->findById($id);
        if (! $context) {
            throw ValidationException::withMessages([
                'id' => ['Attendance context not found.'],
            ]);
        }

        $updateData = [];
        if (isset($data['name'])) {
            $updateData['name'] = $data['name'];
        }
        if (array_key_exists('name_ar', $data)) {
            $updateData['name_ar'] = $data['name_ar'];
        }
        if (array_key_exists('description', $data)) {
            $updateData['description'] = $data['description'];
        }
        if (array_key_exists('is_active', $data)) {
            $updateData['is_active'] = $data['is_active'];
        }
        if ($updaterId) {
            $updateData['updated_by'] = $updaterId;
        }

        $this->contextRepository->update($id, $updateData);

        return [
            'data' => new AttendanceContextResource($context->fresh()),
        ];
    }

    public function delete(int $id): void
    {
        $context = $this->contextRepository->findById($id);
        if (! $context) {
            throw ValidationException::withMessages([
                'id' => ['Attendance context not found.'],
            ]);
        }

        $this->contextRepository->delete($id);
    }

    public function getDefaultId(): ?int
    {
        return null;
    }
}

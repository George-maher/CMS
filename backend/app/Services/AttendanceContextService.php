<?php

namespace App\Services;

use App\Contracts\AttendanceContextRepositoryInterface;
use App\Contracts\AttendanceContextServiceInterface;
use App\Http\Resources\AttendanceContextResource;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class AttendanceContextService implements AttendanceContextServiceInterface
{
    public function __construct(
        private readonly AttendanceContextRepositoryInterface $contextRepository,
    ) {}

    public function list(int $perPage = 15): array
    {
        $filters = [];
        $churchId = auth()->user()?->church_id;
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

    public function listActive(): array
    {
        $churchId = auth()->user()?->church_id;
        $contexts = $churchId
            ? $this->contextRepository->getActiveForChurch($churchId)
            : $this->contextRepository->getActive();

        $count = $contexts->count();
        Log::debug('[AttendanceContext] listActive', [
            'church_id' => $churchId,
            'contexts_count' => $count,
            'user_id' => auth()->id(),
        ]);

        return [
            'data' => AttendanceContextResource::collection($contexts),
        ];
    }

    public function listActiveForChurch(int $churchId): array
    {
        $contexts = $this->contextRepository->getActiveForChurch($churchId);

        return [
            'data' => AttendanceContextResource::collection($contexts),
        ];
    }

    public function findById(int $id): ?array
    {
        $context = $this->contextRepository->findById($id);
        if (!$context) return null;

        return [
            'data' => new AttendanceContextResource($context),
        ];
    }

    public function create(array $data, int $creatorId): array
    {
        $churchId = auth()->user()?->church_id;

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

    public function update(int $id, array $data, ?int $updaterId = null): array
    {
        $context = $this->contextRepository->findById($id);
        if (!$context) {
            throw ValidationException::withMessages([
                'id' => ['Attendance context not found.'],
            ]);
        }

        $updateData = [];
        if (isset($data['name'])) $updateData['name'] = $data['name'];
        if (array_key_exists('name_ar', $data)) $updateData['name_ar'] = $data['name_ar'];
        if (array_key_exists('description', $data)) $updateData['description'] = $data['description'];
        if (array_key_exists('is_active', $data)) $updateData['is_active'] = $data['is_active'];
        if ($updaterId) $updateData['updated_by'] = $updaterId;

        $this->contextRepository->update($id, $updateData);

        return [
            'data' => new AttendanceContextResource($context->fresh()),
        ];
    }

    public function delete(int $id): void
    {
        $context = $this->contextRepository->findById($id);
        if (!$context) {
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

<?php

namespace App\Repositories;

use App\Contracts\EventRepositoryInterface;
use App\Models\Event;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class EventRepository implements EventRepositoryInterface
{
    public function findById(int $id): ?Event
    {
        return Event::with(['creator', 'classe', 'targets.classe'])->find($id);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Event
    {
        return DB::transaction(function () use ($data) {
            $targetClassIds = $data['target_class_ids'] ?? null;
            $isAllClasses = $data['is_all_classes'] ?? false;
            unset($data['target_class_ids']);

            $event = Event::create($data);

            if ($isAllClasses) {
                $event->targets()->create([
                    'is_all_classes' => true,
                    'church_id' => $event->church_id,
                ]);
            } elseif (! empty($targetClassIds) && is_array($targetClassIds)) {
                foreach ($targetClassIds as $classId) {
                    $event->targets()->create([
                        'class_id' => $classId,
                        'church_id' => $event->church_id,
                    ]);
                }
            }

            if (! empty($data['class_year_id'])) {
                $existing = $event->targets()->where('class_id', $data['class_year_id'])->exists();
                if (! $existing && ! $isAllClasses) {
                    $event->targets()->create([
                        'class_id' => $data['class_year_id'],
                        'church_id' => $event->church_id,
                    ]);
                }
            }

            /** @var Event $freshEvent */
            $freshEvent = $event->fresh(['creator', 'classe', 'targets.classe']);

            return $freshEvent;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(int $id, array $data): bool
    {
        $event = Event::find($id);
        if (! $event) {
            return false;
        }

        return DB::transaction(function () use ($event, $data) {
            $targetClassIds = $data['target_class_ids'] ?? null;
            $isAllClasses = $data['is_all_classes'] ?? null;
            unset($data['target_class_ids']);

            $updated = $event->update($data);

            if ($isAllClasses !== null || $targetClassIds !== null) {
                $event->targets()->delete();

                if ($isAllClasses) {
                    $event->targets()->create([
                        'is_all_classes' => true,
                        'church_id' => $event->church_id,
                    ]);
                } elseif (! empty($targetClassIds) && is_array($targetClassIds)) {
                    foreach ($targetClassIds as $classId) {
                        $event->targets()->create([
                            'class_id' => $classId,
                            'church_id' => $event->church_id,
                        ]);
                    }
                }
            }

            return $updated;
        });
    }

    public function delete(int $id): bool
    {
        $event = Event::find($id);
        if (! $event) {
            return false;
        }

        return (bool) $event->delete();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Event>
     */
    public function paginate(int $perPage = 15, array $filters = []): LengthAwarePaginator
    {
        $query = Event::with(['creator', 'classe', 'targets.classe']);

        if (! empty($filters['upcoming']) && filter_var($filters['upcoming'], FILTER_VALIDATE_BOOLEAN)) {
            $query->where(function (Builder $q) {
                $q->where('event_date', '>=', now())
                    ->orWhereNull('event_date');
            });
        }

        if (! empty($filters['active_only']) && filter_var($filters['active_only'], FILTER_VALIDATE_BOOLEAN)) {
            $query->where('is_active', true);
        }

        /** @var int|string|null $classId */
        $classId = $filters['member_class_id'] ?? $filters['class_year_id'] ?? $filters['member_class_year_id'] ?? null;
        $classIds = $filters['class_year_ids'] ?? null;

        if ($classIds !== null && is_array($classIds)) {
            $classIdsInt = array_map(function (mixed $v): int {
                /** @var int|string $v */
                return (int) $v;
            }, $classIds);
            $query->where(function (Builder $q) use ($classIdsInt) {
                $q->whereHas('targets', function (Builder $t) use ($classIdsInt) {
                    $t->where('is_all_classes', true)
                        ->orWhereIn('class_id', $classIdsInt);
                })
                    ->orWhereIn('events.class_year_id', $classIdsInt);
            });
        } elseif ($classId !== null) {
            $classId = (int) $classId;
            if ($classId === 0) {
                $query->where(function (Builder $q) {
                    $q->whereNull('class_year_id')
                        ->whereDoesntHave('targets');
                });
            } else {
                $query->where(function (Builder $q) use ($classId) {
                    $q->whereHas('targets', function (Builder $t) use ($classId) {
                        $t->where('is_all_classes', true)
                            ->orWhere('class_id', $classId);
                    })
                        ->orWhere('events.class_year_id', $classId)
                        ->orWhere(function (Builder $q2) {
                            $q2->whereNull('events.class_year_id')
                                ->whereDoesntHave('targets');
                        });
                });
            }
        }

        if (! empty($filters['search'])) {
            /** @var string $search */
            $search = $filters['search'];
            $query->where(function (Builder $q) use ($search) {
                $q->where('name', 'like', '%'.$search.'%')
                    ->orWhere('description', 'like', '%'.$search.'%');
            });
        }

        return $query->latest('event_date')->paginate($perPage);
    }
}

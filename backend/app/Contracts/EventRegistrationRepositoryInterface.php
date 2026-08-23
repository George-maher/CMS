<?php

namespace App\Contracts;

use App\Models\Event;
use App\Models\EventRegistration;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface EventRegistrationRepositoryInterface
{
    /** @param array<string, mixed> $filters */
    public function paginateForEvent(Event $event, int $perPage = 20, array $filters = []): LengthAwarePaginator;

    public function findByToken(string $token): ?EventRegistration;

    /** @param array<string, mixed> $data */
    public function create(array $data): EventRegistration;

    /** @param array<string, mixed> $data */
    public function update(EventRegistration $registration, array $data): EventRegistration;

    public function delete(EventRegistration $registration): bool;

    public function promoteFirstWaitlisted(Event $event): ?EventRegistration;
}

<?php

namespace App\Contracts;

use App\Models\Event;

interface EventLifecycleServiceInterface
{
    public function publish(Event $event): Event;

    public function closeRegistration(Event $event): Event;

    public function reopenRegistration(Event $event): Event;

    public function cancel(Event $event): Event;

    public function complete(Event $event): Event;

    public function duplicate(Event $event, int $userId): array;
}

<?php

namespace App\Contracts;

use App\Models\Event;
use App\Models\EventBus;
use App\Models\EventRegistration;
use App\Models\EventSession;
use App\Models\EventSpeaker;

interface EventScheduleServiceInterface
{
    /** @param array<string, mixed> $data */
    public function createSession(Event $event, array $data): EventSession;

    /** @param array<string, mixed> $data */
    public function updateSession(Event $event, int $sessionId, array $data): EventSession;

    public function deleteSession(Event $event, int $sessionId): void;

    /** @param array<string, mixed> $data */
    public function createSpeaker(Event $event, array $data): EventSpeaker;

    /** @param array<string, mixed> $data */
    public function updateSpeaker(Event $event, int $speakerId, array $data): EventSpeaker;

    public function deleteSpeaker(Event $event, int $speakerId): void;

    /** @param array<string, mixed> $data */
    public function createBus(Event $event, array $data): EventBus;

    /** @param array<string, mixed> $data */
    public function updateBus(Event $event, int $busId, array $data): EventBus;

    public function deleteBus(Event $event, int $busId): void;

    public function assignBus(Event $event, int $registrationId, ?int $busId): EventRegistration;
}

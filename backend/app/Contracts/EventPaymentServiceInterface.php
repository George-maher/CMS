<?php

namespace App\Contracts;

use App\Models\Event;
use App\Models\EventPayment;
use App\Models\EventRegistration;

interface EventPaymentServiceInterface
{
    /** @param array<string, mixed> $data */
    public function recordPayment(Event $event, EventRegistration $registration, array $data): EventPayment;

    public function markRefunded(int $paymentId, int $recordedBy): EventPayment;

    /** @return array<string, mixed> */
    public function financialSummary(Event $event): array;
}

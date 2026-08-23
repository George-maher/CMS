<?php

namespace App\Services;

use App\Contracts\EventPaymentServiceInterface;
use App\Contracts\NotificationServiceInterface;
use App\Enums\RegistrationStatus;
use App\Models\Event;
use App\Models\EventPayment;
use App\Models\EventRegistration;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EventPaymentService implements EventPaymentServiceInterface
{
    public function __construct(
        private readonly NotificationServiceInterface $notificationService,
    ) {}

    /** @param array<string, mixed> $data */
    public function recordPayment(Event $event, EventRegistration $registration, array $data): EventPayment
    {
        return DB::transaction(function () use ($event, $registration, $data): EventPayment {
            /** @var EventRegistration|null $locked */
            $locked = EventRegistration::query()
                ->whereKey($registration->id)
                ->lockForUpdate()
                ->first();

            if (! $locked) {
                throw ValidationException::withMessages([
                    'registration' => ['Registration not found.'],
                ]);
            }

            if ($locked->status === RegistrationStatus::Cancelled) {
                throw ValidationException::withMessages([
                    'registration' => ['Payments cannot be recorded for cancelled registrations.'],
                ]);
            }

            $price = (float) $event->price_per_participant;

            if ($price <= 0) {
                throw ValidationException::withMessages([
                    'amount' => ['This event has no participation fee.'],
                ]);
            }

            $amountRaw = $data['amount'];

            if (! is_numeric($amountRaw)) {
                throw ValidationException::withMessages([
                    'amount' => ['The amount must be a number.'],
                ]);
            }

            $amount = round((float) $amountRaw, 2);
            $remaining = max(0, $price - (float) $locked->amount_paid);

            if ($remaining <= 0) {
                throw ValidationException::withMessages([
                    'amount' => ['This registration is already fully paid.'],
                ]);
            }

            if ($amount > $remaining + 0.001) {
                throw ValidationException::withMessages([
                    'amount' => sprintf('Amount exceeds the remaining balance (%.2f).', $remaining),
                ]);
            }

            $payment = EventPayment::create([
                'registration_id' => $locked->id,
                'amount' => $amount,
                'method' => $data['method'],
                'paid_at' => $data['paid_at'] ?? now(),
                'note' => $data['note'] ?? null,
                'recorded_by' => $data['recorded_by'] ?? null,
            ]);

            $locked->addPaidAmount($amount, $price);

            $this->notificationService->create(
                $locked->user_id,
                $event->church_id ?? 0,
                'Payment Received',
                sprintf('A payment of %.2f was recorded for %s.', $amount, $event->name),
                'event_payment',
            );

            return $payment->fresh(['registration.user']) ?? $payment;
        });
    }

    public function markRefunded(int $paymentId, int $recordedBy): EventPayment
    {
        return DB::transaction(function () use ($paymentId): EventPayment {
            /** @var EventPayment|null $payment */
            $payment = EventPayment::query()->with('registration.event')->lockForUpdate()->find($paymentId);

            if (! $payment) {
                throw ValidationException::withMessages([
                    'payment' => ['Payment not found.'],
                ]);
            }

            if ($payment->refunded) {
                throw ValidationException::withMessages([
                    'payment' => ['This payment is already refunded.'],
                ]);
            }

            $payment->refunded = true;
            $payment->save();

            $registration = $payment->registration;
            $newAmount = max(0, round((float) $registration->amount_paid - (float) $payment->amount, 2));
            $registration->amount_paid = number_format($newAmount, 2, '.', '');
            $registration->save();

            $registration->refresh();
            $registration->refreshPaymentStatus((float) ($registration->event->price_per_participant ?? 0));

            return $payment->fresh(['registration.user', 'registration.event']) ?? $payment;
        });
    }

    /** @return array<string, mixed> */
    public function financialSummary(Event $event): array
    {
        $price = (float) $event->price_per_participant;

        $activeRegistrations = $event->activeRegistrations()->count();

        $collected = (float) EventPayment::query()
            ->whereHas('registration', function ($q) use ($event) {
                $q->where('event_id', $event->id)
                    ->whereIn('status', [
                        RegistrationStatus::Pending->value,
                        RegistrationStatus::Confirmed->value,
                        RegistrationStatus::Waitlisted->value,
                    ]);
            })
            ->where('refunded', false)
            ->sum('amount');

        $refunded = (float) EventPayment::query()
            ->whereHas('registration', function ($q) use ($event) {
                $q->where('event_id', $event->id);
            })
            ->where('refunded', true)
            ->sum('amount');

        $expected = $activeRegistrations * $price;
        $remaining = max(0, $expected - $collected);

        $paidCount = $event->registrations()
            ->where('payment_status', 'paid')
            ->whereIn('status', [
                RegistrationStatus::Pending->value,
                RegistrationStatus::Confirmed->value,
                RegistrationStatus::Waitlisted->value,
            ])
            ->count();

        $unpaidCount = $event->registrations()
            ->where('payment_status', 'unpaid')
            ->whereIn('status', [
                RegistrationStatus::Pending->value,
                RegistrationStatus::Confirmed->value,
                RegistrationStatus::Waitlisted->value,
            ])
            ->count();

        return [
            'price_per_participant' => $price,
            'active_registrations' => $activeRegistrations,
            'expected_revenue' => round($expected, 2),
            'collected' => round($collected, 2),
            'refunded' => round($refunded, 2),
            'remaining' => round($remaining, 2),
            'paid_participants' => $paidCount,
            'unpaid_participants' => $unpaidCount,
            'net_result' => round($collected - $refunded, 2),
        ];
    }
}

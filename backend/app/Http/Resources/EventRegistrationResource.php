<?php

namespace App\Http\Resources;

use App\Models\EventBus;
use App\Models\EventRegistration;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin EventRegistration */
class EventRegistrationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $currentUser = $request->user();

        return [
            'id' => $this->id,
            'event_id' => $this->event_id,
            'user' => $this->when($this->relationLoaded('user'), fn (): array => [
                'id' => $this->user->id,
                'name' => strval($this->user->name),
                'phone' => $this->user->phone ?? null,
                'avatar' => $this->user->avatar ?? null,
                'class_name' => $this->user->classe->name ?? null,
            ]),
            'registrar' => $this->when($this->registrar !== null, function (): array {
                /** @var User $registrar */
                $registrar = $this->registrar;

                return [
                    'id' => $registrar->id,
                    'name' => strval($registrar->name),
                ];
            }),
            'bus' => $this->when($this->bus !== null, function (): array {
                /** @var EventBus $bus */
                $bus = $this->bus;

                return [
                    'id' => $bus->id,
                    'bus_number' => strval($bus->bus_number),
                ];
            }),
            'bus_id' => $this->bus_id,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'payment_status' => $this->payment_status->value,
            'payment_status_label' => $this->payment_status->label(),
            'amount_paid' => number_format((float) $this->amount_paid, 2, '.', ''),
            'attendance_status' => $this->attendance_status->value,
            'attendance_status_label' => $this->attendance_status->label(),
            'checked_in_at' => $this->checked_in_at?->toISOString(),
            // QR token is only exposed to the participant themselves.
            'qr_token' => $this->when(
                $currentUser !== null && $currentUser->getAuthIdentifier() === $this->user_id,
                fn () => $this->qr_token,
            ),
            'notes' => $this->notes,
            'registered_at' => $this->created_at?->toISOString(),
        ];
    }
}

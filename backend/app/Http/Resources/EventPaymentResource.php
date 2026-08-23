<?php

namespace App\Http\Resources;

use App\Models\EventPayment;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin EventPayment */
class EventPaymentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'registration_id' => $this->registration_id,
            'member' => $this->when(
                $this->relationLoaded('registration') && $this->registration !== null,
                fn (): array => [
                    'id' => $this->registration->user->id,
                    'name' => strval($this->registration->user->name),
                ],
            ),
            'amount' => number_format((float) $this->amount, 2, '.', ''),
            'method' => $this->method->value,
            'method_label' => $this->method->label(),
            'paid_at' => $this->paid_at?->toISOString(),
            'note' => $this->note,
            'refunded' => (bool) $this->refunded,
            'recorded_by_name' => $this->when($this->recorder !== null, function (): string {
                /** @var User $recorder */
                $recorder = $this->recorder;

                return strval($recorder->name);
            }),
        ];
    }
}

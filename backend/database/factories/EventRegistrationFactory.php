<?php

namespace Database\Factories;

use App\Enums\EventAttendanceStatus;
use App\Enums\EventPaymentStatus;
use App\Enums\RegistrationStatus;
use App\Models\EventRegistration;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EventRegistration>
 */
class EventRegistrationFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'status' => RegistrationStatus::Confirmed->value,
            'payment_status' => EventPaymentStatus::Unpaid->value,
            'amount_paid' => 0,
            'attendance_status' => EventAttendanceStatus::NotCheckedIn->value,
            'qr_token' => str_repeat('a', 40).fake()->unique()->numberBetween(1000, 9999),
            'notes' => null,
        ];
    }

    public function checkedIn(): static
    {
        return $this->state(fn () => [
            'attendance_status' => EventAttendanceStatus::CheckedIn->value,
            'checked_in_at' => now(),
        ]);
    }

    public function waitlisted(): static
    {
        return $this->state(fn () => [
            'status' => RegistrationStatus::Waitlisted->value,
        ]);
    }
}

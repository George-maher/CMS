<?php

namespace Database\Factories;

use App\Enums\EventStatus;
use App\Enums\EventType;
use App\Models\Event;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Event>
 */
class EventFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $creator = User::factory()->create();

        return [
            'name' => fake()->sentence(3),
            'type' => EventType::Service->value,
            'status' => EventStatus::Open->value,
            'description' => fake()->paragraph(),
            'event_date' => now()->addDays(7),
            'location' => fake()->city(),
            'is_active' => true,
            'created_by' => $creator->id,
        ];
    }

    public function trip(): static
    {
        return $this->state(fn () => [
            'type' => EventType::Trip->value,
            'destination' => 'Alexandria',
            'departure_location' => 'Church Hall',
            'transportation_type' => 'bus',
            'price_per_participant' => 250,
        ]);
    }

    public function conference(): static
    {
        return $this->state(fn () => [
            'type' => EventType::Conference->value,
            'theme' => 'Faith and Youth',
        ]);
    }
}

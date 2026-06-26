<?php

namespace Database\Factories;

use App\Models\ChurchApplication;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChurchApplication>
 */
class ChurchApplicationFactory extends Factory
{
    protected $model = ChurchApplication::class;

    public function definition(): array
    {
        return [
            'church_name' => fake()->company() . ' Church',
            'priest_name' => fake()->name(),
            'priest_phone' => fake()->phoneNumber(),
            'contact_email' => fake()->safeEmail(),
            'status' => 'pending',
        ];
    }

    public function pending(): static
    {
        return $this->state(['status' => 'pending']);
    }

    public function rejected(?string $reason = null): static
    {
        return $this->state([
            'status' => 'rejected',
            'rejection_reason' => $reason ?? fake()->sentence(),
            'reviewed_at' => now(),
        ]);
    }

    public function approved(): static
    {
        return $this->state(['status' => 'approved', 'reviewed_at' => now()]);
    }
}

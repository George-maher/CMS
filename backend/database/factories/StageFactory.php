<?php

namespace Database\Factories;

use App\Models\Church;
use App\Models\Stage;
use Illuminate\Database\Eloquent\Factories\Factory;

class StageFactory extends Factory
{
    protected $model = Stage::class;

    public function definition(): array
    {
        return [
            'church_id' => Church::factory(),
            'name' => fake()->unique()->word() . ' Stage',
            'display_order' => fake()->numberBetween(1, 10),
        ];
    }

    public function forChurch(Church $church): static
    {
        return $this->state(['church_id' => $church->id]);
    }
}

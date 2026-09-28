<?php

namespace Database\Factories;

use App\Models\Church;
use App\Models\Classe;
use App\Models\Stage;
use Illuminate\Database\Eloquent\Factories\Factory;

class ClasseFactory extends Factory
{
    protected $model = Classe::class;

    public function definition(): array
    {
        return [
            'church_id' => Church::factory(),
            'stage_id' => fn (array $attrs) => Stage::factory()->state(['church_id' => $attrs['church_id']]),
            'name' => fn (array $attrs) => fake()->unique()->word() . ' Class',
            'description' => fake()->sentence(),
            'display_order' => fake()->numberBetween(1, 20),
        ];
    }

    public function forChurch(Church $church): static
    {
        return $this->state(fn () => [
            'church_id' => $church->id,
            'stage_id' => Stage::factory()->state(['church_id' => $church->id]),
        ]);
    }
}

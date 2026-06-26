<?php

namespace Database\Factories;

use App\Models\Church;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Church>
 */
class ChurchFactory extends Factory
{
    protected $model = Church::class;

    public function definition(): array
    {
        $name = fake()->unique()->company() . ' Church';
        return [
            'name' => $name,
            'slug' => Str::slug($name) . '-' . Str::random(6),
            'priest_name' => fake()->name(),
            'priest_phone' => fake()->phoneNumber(),
            'is_active' => true,
            'is_suspended' => false,
        ];
    }
}

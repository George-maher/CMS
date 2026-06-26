<?php

namespace Database\Factories;

use App\Models\AttendanceContext;
use App\Models\Church;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class AttendanceContextFactory extends Factory
{
    protected $model = AttendanceContext::class;

    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);
        return [
            'church_id' => Church::factory(),
            'name' => $name,
            'name_ar' => null,
            'slug' => Str::slug($name),
            'description' => fake()->sentence(),
            'is_active' => true,
            'created_by' => User::factory(),
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}

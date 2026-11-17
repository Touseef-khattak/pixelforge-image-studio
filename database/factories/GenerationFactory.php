<?php

namespace Database\Factories;

use App\Models\Generation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Generation> */
class GenerationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'prompt' => fake()->sentence(),
            'size' => '1024x1024',
            'quality' => 'medium',
            'reference_images' => [],
            'status' => Generation::QUEUED,
            'estimated_min_nanos' => 50_000_000,
            'estimated_max_nanos' => 100_000_000,
        ];
    }

    public function succeeded(): static
    {
        return $this->state(fn () => [
            'status' => Generation::SUCCEEDED,
            'completed_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn () => [
            'status' => Generation::FAILED,
            'completed_at' => now(),
            'error_code' => 'generation_failed',
        ]);
    }
}

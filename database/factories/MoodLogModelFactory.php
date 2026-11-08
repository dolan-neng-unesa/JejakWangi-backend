<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\MoodLogModel>
 */
class MoodLogModelFactory extends Factory
{
    protected $model = \App\Models\MoodLogModel::class;

    public function definition(): array
    {
        return [
            'green_space_id' => 1,
            'mood_score' => fake()->numberBetween(1, 5),
            'activity' => fake()->randomElement(['belajar', 'bekerja', 'olahraga', 'lainnya']),
            'anonymous_session_id' => 'factory-' . fake()->uuid(),
            'logged_at' => now(),
        ];
    }
}

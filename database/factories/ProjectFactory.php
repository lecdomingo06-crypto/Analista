<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Project> */
class ProjectFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'title' => fake()->unique()->sentence(5),
            'summary' => fake()->paragraph(),
            'school' => 'Example Community College',
            'course' => 'BS Information Technology',
            'category' => 'Web',
            'technologies' => ['Laravel', 'MySQL'],
            'feedback_needed' => 'Please review our methodology and database design.',
            'status' => 'pending',
            'deadline' => now()->addWeeks(3)->toDateString(),
            'is_public' => false,
        ];
    }

    public function approved(): static
    {
        return $this->state(fn (array $attributes) => ['status' => 'approved']);
    }

    public function publicListing(): static
    {
        return $this->approved()->state(fn (array $attributes) => ['is_public' => true]);
    }
}

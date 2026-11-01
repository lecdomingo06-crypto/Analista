<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'role' => 'student',
            'is_suspended' => false,
            'evaluator_status' => 'none',
            'capacity' => 3,
            'accepting_requests' => true,
            'experience_years' => 0,
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    public function admin(): static
    {
        return $this->state(fn (array $attributes) => ['role' => 'admin']);
    }

    public function evaluator(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => 'evaluator',
            'evaluator_status' => 'approved',
            'headline' => 'Software engineer and volunteer thesis reviewer',
            'bio' => 'I help student teams review system architecture, database design, and practical evaluation plans.',
            'expertise' => ['Web', 'Database'],
            'skills' => ['Laravel', 'MySQL'],
            'review_modes' => ['online'],
            'experience_years' => 5,
        ]);
    }

    public function pendingEvaluator(): static
    {
        return $this->evaluator()->state(fn (array $attributes) => ['evaluator_status' => 'pending']);
    }
}

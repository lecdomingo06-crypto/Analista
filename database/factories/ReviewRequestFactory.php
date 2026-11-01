<?php

namespace Database\Factories;

use App\Models\DocumentVersion;
use App\Models\Project;
use App\Models\ReviewRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ReviewRequest> */
class ReviewRequestFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory()->approved(),
            'evaluator_id' => User::factory()->evaluator(),
            'document_version_id' => fn (array $attributes) => DocumentVersion::factory()->create(['project_id' => $attributes['project_id']])->id,
            'requested_by' => fn (array $attributes) => Project::findOrFail($attributes['project_id'])->user_id,
            'status' => 'pending',
            'message' => 'Please review our methodology and suggest improvements to the study design.',
            'preferred_date' => now()->addWeeks(2)->toDateString(),
        ];
    }

    public function accepted(): static
    {
        return $this->state(fn (array $attributes) => ['status' => 'accepted', 'accepted_at' => now()]);
    }

    public function completed(): static
    {
        return $this->accepted()->state(fn (array $attributes) => ['status' => 'completed', 'completed_at' => now()]);
    }
}

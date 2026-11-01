<?php

namespace Database\Factories;

use App\Models\Rating;
use App\Models\ReviewRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Rating> */
class RatingFactory extends Factory
{
    public function definition(): array
    {
        return [
            'review_request_id' => ReviewRequest::factory()->completed(),
            'user_id' => fn (array $attributes) => ReviewRequest::findOrFail($attributes['review_request_id'])->requested_by,
            'evaluator_id' => fn (array $attributes) => ReviewRequest::findOrFail($attributes['review_request_id'])->evaluator_id,
            'rating' => 5,
            'comment' => 'The recommendations were specific and helped us plan our revision.',
        ];
    }
}

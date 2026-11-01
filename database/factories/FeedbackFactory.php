<?php

namespace Database\Factories;

use App\Models\Feedback;
use App\Models\ReviewRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Feedback> */
class FeedbackFactory extends Factory
{
    public function definition(): array
    {
        return [
            'review_request_id' => ReviewRequest::factory()->accepted(),
            'user_id' => fn (array $attributes) => ReviewRequest::findOrFail($attributes['review_request_id'])->evaluator_id,
            'section' => 'Methodology',
            'page' => 12,
            'type' => 'recommendation',
            'body' => 'Explain how participants will be selected and connect the selection criteria to the research objectives.',
            'priority' => 'major',
            'addressed' => false,
        ];
    }
}

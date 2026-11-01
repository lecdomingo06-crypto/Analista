<?php

namespace Database\Factories;

use App\Models\Tutorial;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Tutorial> */
class TutorialFactory extends Factory
{
    public function definition(): array
    {
        return [
            'title' => fake()->sentence(5),
            'slug' => fake()->unique()->slug(),
            'category' => 'Research writing',
            'excerpt' => 'Practical steps to make your research objectives easier to understand and review.',
            'body' => "Start by stating the problem in one sentence. Identify who experiences it and what evidence shows it matters.\n\nWrite one general objective and a small set of specific objectives. Each objective should describe an outcome you can evaluate with evidence.",
            'reading_minutes' => 5,
            'youtube_video_id' => 'zIYC6zG265E',
            'video_creator' => 'Scribbr',
            'is_published' => true,
            'featured' => false,
        ];
    }
}

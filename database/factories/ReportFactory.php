<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\Report;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Report> */
class ReportFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'reportable_type' => 'project',
            'reportable_id' => Project::factory()->publicListing(),
            'reason' => 'Spam',
            'details' => 'Please check whether this project belongs in the community.',
            'status' => 'pending',
        ];
    }
}

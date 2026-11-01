<?php

namespace Database\Factories;

use App\Models\DocumentVersion;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<DocumentVersion> */
class DocumentVersionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'user_id' => fn (array $attributes) => Project::findOrFail($attributes['project_id'])->user_id,
            'original_name' => 'thesis.pdf',
            'path' => 'theses/'.Str::uuid().'.pdf',
            'mime_type' => 'application/pdf',
            'size' => 1024,
            'version' => fn (array $attributes) => (DocumentVersion::where('project_id', $attributes['project_id'])->max('version') ?? 0) + 1,
        ];
    }
}

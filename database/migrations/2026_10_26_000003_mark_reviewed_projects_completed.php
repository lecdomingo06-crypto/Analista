<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('projects')->where('status', 'approved')
            ->whereExists(function ($query) {
                $query->selectRaw('1')->from('review_requests')
                    ->whereColumn('review_requests.project_id', 'projects.id')
                    ->where('review_requests.status', 'completed');
            })->update(['status' => 'completed']);
    }

    public function down(): void
    {
        // Completion is a real lifecycle event; rolling back must not reopen finished projects.
    }
};

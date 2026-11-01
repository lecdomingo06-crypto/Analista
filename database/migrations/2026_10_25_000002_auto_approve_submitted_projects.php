<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Only release submitted projects with an existing document. Keep their
        // visibility choice, documents, and review histories unchanged.
        DB::table('projects')->where('status', 'pending')
            ->whereExists(function ($query) {
                $query->selectRaw('1')->from('document_versions')
                    ->whereColumn('document_versions.project_id', 'projects.id');
            })->update(['status' => 'approved', 'moderation_note' => null]);
    }

    public function down(): void
    {
        // Do not revoke approvals or interrupt reviews if the code is rolled back.
    }
};

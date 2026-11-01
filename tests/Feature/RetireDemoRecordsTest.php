<?php

namespace Tests\Feature;

use App\Models\DocumentVersion;
use App\Models\Project;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RetireDemoRecordsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoSeeder::class);
    }

    public function test_dry_run_preserves_all_accounts_projects_and_activities(): void
    {
        $users = User::orderBy('id')->get(['id', 'name', 'email', 'role', 'evaluator_status', 'is_suspended', 'accepting_requests'])->toArray();
        $projects = Project::orderBy('id')->get(['id', 'title', 'status', 'is_public'])->toArray();
        $activities = DB::table('activities')->orderBy('id')->get()->toArray();

        $this->artisan('analista:retire-demo-records', ['--dry-run' => true, '--hide-sample-projects' => true])
            ->expectsOutput('7 legacy sample evaluator accounts; 7 untouched sample projects.')
            ->assertSuccessful();

        $this->assertSame($users, User::orderBy('id')->get(['id', 'name', 'email', 'role', 'evaluator_status', 'is_suspended', 'accepting_requests'])->toArray());
        $this->assertSame($projects, Project::orderBy('id')->get(['id', 'title', 'status', 'is_public'])->toArray());
        $this->assertEquals($activities, DB::table('activities')->orderBy('id')->get()->toArray());
    }

    public function test_cleanup_removes_exact_sample_evaluators_and_preserves_students_admin_and_actual_applicants(): void
    {
        $sampleIds = User::where('role', 'evaluator')->pluck('id')->all();
        $retainedIds = User::whereIn('role', ['student', 'admin'])->pluck('id')->all();
        $approved = User::factory()->evaluator()->create(['name' => 'Actual Approved Professional']);
        $pending = User::factory()->pendingEvaluator()->create(['name' => 'Actual Pending Applicant']);

        $this->artisan('analista:retire-demo-records')
            ->expectsOutput('Removed 7 sample evaluators; archived 0 with existing records; retained 0 sample projects as private drafts.')
            ->assertSuccessful();

        foreach ($sampleIds as $id) {
            $this->assertDatabaseMissing('users', ['id' => $id]);
        }
        foreach ($retainedIds as $id) {
            $this->assertDatabaseHas('users', ['id' => $id]);
        }
        $this->assertSame('approved', $approved->fresh()->evaluator_status);
        $this->assertSame('pending', $pending->fresh()->evaluator_status);
        $this->assertFalse($approved->fresh()->is_suspended);
        $this->assertFalse($pending->fresh()->is_suspended);
        $this->assertDatabaseCount('users', 5);
        $this->assertDatabaseCount('projects', 7);
        $this->assertDatabaseCount('tutorials', 10);
        $this->assertSame(6, Project::where('status', 'approved')->where('is_public', true)->count());
    }

    public function test_reserved_email_accounts_with_modified_names_or_biographies_are_preserved(): void
    {
        $changedName = User::where('email', 'evaluator@analista.test')->sole();
        $changedName->update(['name' => 'Actual Name Chosen By Applicant']);
        $changedBio = User::where('email', 'miguel@analista.test')->sole();
        $changedBio->update(['bio' => 'I work on mobile systems and would like to volunteer to review student research.']);

        $this->artisan('analista:retire-demo-records')
            ->expectsOutput('Removed 5 sample evaluators; archived 0 with existing records; retained 0 sample projects as private drafts.')
            ->assertSuccessful();

        $this->assertDatabaseHas('users', ['id' => $changedName->id, 'name' => 'Actual Name Chosen By Applicant', 'is_suspended' => false]);
        $this->assertDatabaseHas('users', ['id' => $changedBio->id, 'bio' => $changedBio->bio, 'is_suspended' => false]);
        $this->assertSame('approved', $changedName->fresh()->evaluator_status);
        $this->assertSame('approved', $changedBio->fresh()->evaluator_status);
        $this->assertDatabaseCount('users', 5);
    }

    public function test_sample_accounts_with_related_records_are_archived_without_losing_those_records(): void
    {
        $sample = User::where('email', 'evaluator@analista.test')->sole();
        $activity = $sample->notifyActivity('Existing legitimate update', 'Preserve this account history during retirement.', '/account');

        $this->artisan('analista:retire-demo-records')
            ->expectsOutput('Removed 6 sample evaluators; archived 1 with existing records; retained 0 sample projects as private drafts.')
            ->assertSuccessful();

        $sample->refresh();
        $this->assertTrue($sample->is_suspended);
        $this->assertFalse($sample->accepting_requests);
        $this->assertSame('rejected', $sample->evaluator_status);
        $this->assertFalse($sample->isEvaluator());
        $this->assertDatabaseHas('activities', ['id' => $activity->id, 'user_id' => $sample->id,
            'title' => 'Existing legitimate update', 'body' => 'Preserve this account history during retirement.']);
        $this->assertDatabaseCount('users', 4);
    }

    public function test_hiding_samples_only_converts_untouched_document_free_sample_projects_to_private_drafts(): void
    {
        $samples = Project::orderBy('id')->get();
        $edited = $samples[0];
        $edited->forceFill(['feedback_needed' => 'Actual revised research questions require a different review scope.',
            'updated_at' => $edited->created_at->copy()->addMinute()])->save();

        $withDocument = $samples[1];
        $document = DocumentVersion::factory()->create(['project_id' => $withDocument->id]);
        $actualOwner = User::factory()->create();
        $reassigned = $samples[2];
        $reassigned->update(['user_id' => $actualOwner->id]);
        $custom = Project::factory()->publicListing()->create(['title' => 'An actual community thesis project']);
        $untouchedIds = $samples->whereNotIn('id', [$edited->id, $withDocument->id, $reassigned->id])->pluck('id')->all();

        $this->artisan('analista:retire-demo-records', ['--hide-sample-projects' => true])
            ->expectsOutput('Removed 7 sample evaluators; archived 0 with existing records; retained 4 sample projects as private drafts.')
            ->assertSuccessful();

        foreach ($untouchedIds as $id) {
            $this->assertDatabaseHas('projects', ['id' => $id, 'status' => 'draft', 'is_public' => false]);
        }
        foreach ([$edited, $withDocument, $reassigned, $custom] as $project) {
            $this->assertSame('approved', $project->fresh()->status);
            $this->assertTrue($project->fresh()->is_public);
        }
        $this->assertDatabaseHas('document_versions', ['id' => $document->id, 'project_id' => $withDocument->id]);
        $this->assertDatabaseCount('projects', 8);
    }
}

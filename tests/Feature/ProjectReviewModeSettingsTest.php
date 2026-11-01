<?php

namespace Tests\Feature;

use App\Models\DocumentVersion;
use App\Models\Project;
use App\Models\ReviewRequest;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProjectReviewModeSettingsTest extends TestCase
{
    use RefreshDatabase;

    private string $ownedViews;

    private string $ownedDisk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $token = 'project-review-settings-'.bin2hex(random_bytes(8));
        $this->ownedViews = storage_path('framework/testing/'.$token);
        (new Filesystem)->ensureDirectoryExists($this->ownedViews);
        config(['view.compiled' => $this->ownedViews]);
        $this->app->forgetInstance('blade.compiler');
        $this->app['view.engine.resolver']->forget('blade');
        $this->ownedDisk = storage_path('framework/testing/disks/'.$token);
        Storage::set('local', Storage::fake($token));
    }

    protected function tearDown(): void
    {
        $paths = array_filter([$this->ownedViews ?? null, $this->ownedDisk ?? null]);
        $ownedRoot = realpath(storage_path('framework/testing'));
        try {
            parent::tearDown();
        } finally {
            foreach ($paths as $path) {
                $resolved = realpath($path);
                if ($ownedRoot !== false && $resolved !== false
                    && str_starts_with(strtolower($resolved), strtolower(rtrim($ownedRoot, '\\/').DIRECTORY_SEPARATOR))) {
                    (new Filesystem)->deleteDirectory($resolved);
                }
            }
        }
    }

    public function test_model_and_database_defaults_preserve_the_existing_request_workflow(): void
    {
        $this->assertSame(Project::REVIEW_MODE_REQUEST_ONLY, (new Project)->review_mode);
        $this->assertSame(1, (new Project)->reviewer_limit);
        $project = Project::factory()->create();
        $attributes = $project->getAttributes();
        unset($attributes['id'], $attributes['review_mode'], $attributes['reviewer_limit']);
        $id = DB::table('projects')->insertGetId($attributes);
        $inserted = Project::findOrFail($id);
        $this->assertSame('request_only', $inserted->review_mode);
        $this->assertSame(1, $inserted->reviewer_limit);
        $this->assertFalse($inserted->usesVolunteerReviews());
        $this->assertSame(Project::PDF_ACCESS_ASSIGNED, $inserted->pdf_access);
    }

    public function test_the_additive_migration_preserves_existing_data_and_backfills_safe_defaults(): void
    {
        config(['database.connections.review_settings_upgrade' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]]);
        $connection = DB::connection('review_settings_upgrade');
        $schema = $connection->getSchemaBuilder();
        $schema->create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('status');
            $table->string('pdf_access');
        });
        $original = ['title' => 'Existing completed thesis', 'status' => 'completed', 'pdf_access' => 'assigned'];
        $connection->table('projects')->insert($original);
        $originalSchema = Schema::getFacadeRoot();
        try {
            Schema::swap($schema);
            $migration = require database_path('migrations/2026_10_28_000003_add_review_mode_to_projects.php');
            $migration->up();
            $row = (array) $connection->table('projects')->sole();
            $this->assertSame($original, array_intersect_key($row, $original));
            $this->assertSame('request_only', $row['review_mode']);
            $this->assertSame(1, $row['reviewer_limit']);
            $connection->table('projects')->insert(['title' => 'Future thesis', 'status' => 'approved', 'pdf_access' => 'assigned']);
            $this->assertSame(['request_only', 'request_only'], $connection->table('projects')->pluck('review_mode')->all());
            $this->assertSame([1, 1], $connection->table('projects')->pluck('reviewer_limit')->all());
            $columns = collect($connection->select('PRAGMA table_info(projects)'));
            $this->assertSame(1, (int) $columns->firstWhere('name', 'review_mode')->notnull);
            $this->assertSame(1, (int) $columns->firstWhere('name', 'reviewer_limit')->notnull);
            $migration->down();
            $this->assertFalse($schema->hasColumn('projects', 'review_mode'));
            $this->assertFalse($schema->hasColumn('projects', 'reviewer_limit'));
            $this->assertSame(['Existing completed thesis', 'Future thesis'], $connection->table('projects')->pluck('title')->all());
        } finally {
            Schema::swap($originalSchema);
            DB::purge('review_settings_upgrade');
        }
    }

    public function test_create_saves_both_modes_and_keeps_draft_summary_sharing_and_pdf_access_independent(): void
    {
        $owner = User::factory()->create();
        $cases = [
            [[], 'request_only', 1, 'approved', true],
            [['review_mode' => 'request_only', 'reviewer_limit' => 2], 'request_only', 2, 'approved', true],
            [['review_mode' => 'volunteer', 'reviewer_limit' => 3, 'is_public' => false], 'volunteer', 3, 'approved', false],
            [['review_mode' => 'volunteer', 'reviewer_limit' => 5, 'save_draft' => true], 'volunteer', 5, 'draft', true],
        ];
        foreach ($cases as $index => [$settings, $mode, $limit, $status, $isPublic]) {
            $data = $this->payload($settings + ['title' => 'Review setting thesis '.$index, 'document' => $this->pdfUpload()]);
            $this->actingAs($owner)->postJson(route('projects.store'), $data)->assertCreated()->assertSessionHasNoErrors();
            $project = Project::where('title', $data['title'])->sole();
            $this->assertSame($mode, $project->review_mode);
            $this->assertSame($limit, $project->reviewer_limit);
            $this->assertSame($status, $project->status);
            $this->assertSame($isPublic, $project->is_public);
            $this->assertSame(Project::PDF_ACCESS_ASSIGNED, $project->pdf_access);
            Storage::disk('local')->assertExists($project->documents()->sole()->path);
        }
        $this->assertDatabaseCount('review_requests', 0);
    }

    public function test_selected_evaluator_navigation_only_opens_the_invitation_for_request_only_projects(): void
    {
        $owner = User::factory()->create();
        $evaluator = User::factory()->evaluator()->create();
        $this->actingAs($owner);

        foreach (['request_only', 'volunteer'] as $mode) {
            $response = $this->postJson(route('projects.store'), $this->payload([
                'review_mode' => $mode, 'evaluator_id' => $evaluator->id, 'document' => $this->pdfUpload(),
            ]))->assertCreated()->assertSessionHasNoErrors();
            $project = Project::latest('id')->firstOrFail();
            $destination = $mode === 'request_only'
                ? route('account.projects.show', ['project' => $project, 'evaluator' => $evaluator->id]).'#request-review'
                : route('account.projects.show', $project);
            $response->assertJsonPath('redirect', $destination);

            $this->put(route('projects.update', $project), $this->payload([
                'review_mode' => $mode, 'evaluator_id' => $evaluator->id,
            ]))->assertRedirect($destination)->assertSessionHasNoErrors();
        }
        $this->assertDatabaseCount('review_requests', 0);
    }

    public function test_only_owner_and_members_can_edit_review_settings_and_omitted_updates_preserve_them(): void
    {
        $project = Project::factory()->approved()->create(['is_public' => false]);
        $member = User::factory()->create();
        $project->members()->attach($member);
        $document = DocumentVersion::factory()->create(['project_id' => $project->id]);
        $beforeDocument = $document->fresh()->getAttributes();
        foreach ([$project->owner, $member] as $manager) {
            $this->actingAs($manager)->putJson(route('projects.update', $project), $this->payload([
                'review_mode' => 'volunteer', 'reviewer_limit' => 4,
            ]))->assertOk()->assertSessionHasNoErrors();
            $this->assertSame('volunteer', $project->fresh()->review_mode);
            $this->assertSame(4, $project->fresh()->reviewer_limit);
            $this->putJson(route('projects.update', $project), $this->payload())->assertOk()->assertSessionHasNoErrors();
            $this->assertSame('volunteer', $project->fresh()->review_mode);
            $this->assertSame(4, $project->fresh()->reviewer_limit);
        }
        $beforeAdminAttempt = $project->fresh()->getAttributes();
        $this->actingAs(User::factory()->admin()->create())->get(route('projects.edit', $project))->assertForbidden();
        $this->putJson(route('projects.update', $project), $this->payload([
            'review_mode' => 'request_only', 'reviewer_limit' => 1,
        ]))->assertForbidden();
        $this->assertSame($beforeAdminAttempt, $project->fresh()->getAttributes());
        $this->assertFalse($project->fresh()->is_public);
        $this->assertSame($beforeDocument, $document->fresh()->getAttributes());
        $this->assertTrue($project->members()->whereKey($member->id)->exists());
    }

    public function test_guests_unrelated_students_and_unassigned_evaluators_cannot_change_review_settings(): void
    {
        $project = Project::factory()->publicListing()->create(['pdf_access' => Project::PDF_ACCESS_APPROVED_EVALUATORS]);
        $data = $this->payload(['review_mode' => 'volunteer', 'reviewer_limit' => 5]);
        $this->put(route('projects.update', $project), $data)->assertRedirect(route('login'));
        foreach ([User::factory()->create(), User::factory()->evaluator()->create()] as $outsider) {
            $this->actingAs($outsider)->get(route('projects.edit', $project))->assertForbidden();
            $this->putJson(route('projects.update', $project), $data)->assertForbidden();
        }
        $project->owner->update(['is_suspended' => true]);
        $this->actingAs($project->owner)->put(route('projects.update', $project), $data)->assertRedirect(route('login'));
        $this->assertSame('request_only', $project->fresh()->review_mode);
        $this->assertSame(1, $project->fresh()->reviewer_limit);
    }

    public function test_invalid_explicit_modes_and_limits_fail_before_project_or_document_writes(): void
    {
        $owner = User::factory()->create();
        $project = Project::factory()->approved()->create(['user_id' => $owner->id, 'review_mode' => 'volunteer', 'reviewer_limit' => 3]);
        $this->actingAs($owner);
        foreach (['everyone', '', null, ['volunteer']] as $mode) {
            $this->postJson(route('projects.store'), $this->payload(['review_mode' => $mode, 'document' => $this->pdfUpload()]))
                ->assertUnprocessable()->assertJsonValidationErrors('review_mode');
            $this->putJson(route('projects.update', $project), $this->payload(['review_mode' => $mode]))
                ->assertUnprocessable()->assertJsonValidationErrors('review_mode');
        }
        foreach ([0, 6, -1, 1.5, '', null, [2], 'many'] as $limit) {
            $this->postJson(route('projects.store'), $this->payload(['reviewer_limit' => $limit, 'document' => $this->pdfUpload()]))
                ->assertUnprocessable()->assertJsonValidationErrors('reviewer_limit');
            $this->putJson(route('projects.update', $project), $this->payload(['reviewer_limit' => $limit]))
                ->assertUnprocessable()->assertJsonValidationErrors('reviewer_limit');
        }
        $this->assertDatabaseCount('projects', 1);
        $this->assertDatabaseCount('document_versions', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame('volunteer', $project->fresh()->review_mode);
        $this->assertSame(3, $project->fresh()->reviewer_limit);
    }

    public function test_create_edit_and_validation_errors_retain_the_selected_mode_and_limit(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);
        $this->assertSettings($this->get(route('projects.create'))->assertOk()->getContent(), 'request_only', 1);
        $project = Project::factory()->approved()->create(['user_id' => $owner->id, 'review_mode' => 'volunteer', 'reviewer_limit' => 4]);
        $this->assertSettings($this->get(route('projects.edit', $project))->assertOk()->getContent(), 'volunteer', 4);
        $this->from(route('projects.create'))->post(route('projects.store'), $this->payload([
            'title' => '', 'review_mode' => 'volunteer', 'reviewer_limit' => 2, 'document' => $this->pdfUpload(),
        ]))->assertRedirect(route('projects.create'))->assertSessionHasErrors('title');
        $this->assertSettings($this->get(route('projects.create'))->assertOk()->getContent(), 'volunteer', 2);
        $this->from(route('projects.edit', $project))->put(route('projects.update', $project), $this->payload([
            'title' => '', 'review_mode' => 'request_only', 'reviewer_limit' => 1,
        ]))->assertRedirect(route('projects.edit', $project))->assertSessionHasErrors('title');
        $this->assertSettings($this->get(route('projects.edit', $project))->assertOk()->getContent(), 'request_only', 1);
        $this->assertSame('volunteer', $project->fresh()->review_mode);
        $this->assertSame(4, $project->fresh()->reviewer_limit);
    }

    public function test_volunteer_limit_counts_distinct_occupied_evaluators_and_cannot_shrink_below_them(): void
    {
        $project = Project::factory()->approved()->create(['review_mode' => 'volunteer', 'reviewer_limit' => 3]);
        $first = ReviewRequest::factory()->accepted()->create(['project_id' => $project->id]);
        ReviewRequest::factory()->completed()->create(['project_id' => $project->id, 'evaluator_id' => $first->evaluator_id]);
        ReviewRequest::factory()->accepted()->create(['project_id' => $project->id]);
        ReviewRequest::factory()->create(['project_id' => $project->id]);
        ReviewRequest::factory()->create(['project_id' => $project->id, 'status' => 'declined']);
        $this->assertSame(2, $project->occupiedReviewerCount());
        $this->assertSame(1, $project->completedReviewerCount());
        $before = $project->reviewRequests()->orderBy('id')->get()->toArray();
        $this->actingAs($project->owner)->putJson(route('projects.update', $project), $this->payload(['reviewer_limit' => 1]))
            ->assertUnprocessable()->assertJsonValidationErrors('reviewer_limit');
        $this->assertSame(3, $project->fresh()->reviewer_limit);
        $this->assertSame($before, $project->reviewRequests()->orderBy('id')->get()->toArray());
        $this->putJson(route('projects.update', $project), $this->payload(['reviewer_limit' => 2]))->assertOk();
        $this->assertSame(2, $project->fresh()->reviewer_limit);
        $this->putJson(route('projects.update', $project), $this->payload(['review_mode' => 'request_only', 'reviewer_limit' => 1]))->assertOk();
        $this->assertSame('request_only', $project->fresh()->review_mode);
        $this->putJson(route('projects.update', $project), $this->payload(['review_mode' => 'volunteer']))
            ->assertUnprocessable()->assertJsonValidationErrors('reviewer_limit');
        $this->assertSame('request_only', $project->fresh()->review_mode);
    }

    public function test_changing_mode_or_expanding_the_target_recalculates_completion_without_replacing_review_data(): void
    {
        $project = Project::factory()->approved()->create(['status' => 'completed']);
        $review = ReviewRequest::factory()->completed()->create(['project_id' => $project->id,
            'final_summary' => 'Preserve the existing completed review and its original thesis PDF assignment.']);
        $beforeReview = $review->fresh()->getAttributes();
        $beforeDocument = $review->document->fresh()->getAttributes();
        $this->actingAs($project->owner)->putJson(route('projects.update', $project), $this->payload([
            'review_mode' => 'volunteer', 'reviewer_limit' => 2,
        ]))->assertOk();
        $this->assertSame('approved', $project->fresh()->status);
        $this->putJson(route('projects.update', $project), $this->payload(['reviewer_limit' => 1]))->assertOk();
        $this->assertSame('completed', $project->fresh()->status);
        $this->putJson(route('projects.update', $project), $this->payload(['reviewer_limit' => 3]))->assertOk();
        $this->assertSame('approved', $project->fresh()->status);
        $this->putJson(route('projects.update', $project), $this->payload(['review_mode' => 'request_only']))->assertOk();
        $this->assertSame('completed', $project->fresh()->status);
        $this->assertSame($beforeReview, $review->fresh()->getAttributes());
        $this->assertSame($beforeDocument, $review->document->fresh()->getAttributes());
        $this->assertDatabaseCount('review_requests', 1);
        $this->assertDatabaseCount('document_versions', 1);
    }

    public function test_completion_counts_distinct_evaluators_and_waits_for_legacy_accepted_version_reviews(): void
    {
        $project = Project::factory()->approved()->create(['review_mode' => 'volunteer', 'reviewer_limit' => 2]);
        $first = ReviewRequest::factory()->completed()->create(['project_id' => $project->id]);
        ReviewRequest::factory()->completed()->create(['project_id' => $project->id, 'evaluator_id' => $first->evaluator_id]);
        $project->syncReviewCompletion();
        $this->assertSame('approved', $project->fresh()->status);
        ReviewRequest::factory()->completed()->create(['project_id' => $project->id]);
        $outstanding = ReviewRequest::factory()->accepted()->create(['project_id' => $project->id, 'evaluator_id' => $first->evaluator_id]);
        $project->loadReviewProgress();
        $project->syncReviewCompletion();
        $this->assertSame('approved', $project->fresh()->status);
        $outstanding->update(['status' => 'completed']);
        $project->syncReviewCompletion();
        $this->assertSame('completed', $project->fresh()->status);
        $this->assertSame(2, $project->fresh()->completedReviewerCount());
        foreach (['draft', 'pending', 'hidden', 'needs_changes', 'rejected'] as $status) {
            $project->update(['status' => $status]);
            $project->syncReviewCompletion();
            $this->assertSame($status, $project->fresh()->status);
        }
    }

    public function test_a_draft_setting_stays_inactive_until_normal_submission_and_does_not_force_summary_sharing(): void
    {
        $project = Project::factory()->create(['status' => 'draft', 'is_public' => false]);
        DocumentVersion::factory()->create(['project_id' => $project->id]);
        $this->actingAs($project->owner)->putJson(route('projects.update', $project), $this->payload([
            'review_mode' => 'volunteer', 'reviewer_limit' => 3, 'save_draft' => true,
        ]))->assertOk();
        $this->assertSame('draft', $project->fresh()->status);
        $this->assertFalse($project->fresh()->is_public);
        $this->putJson(route('projects.update', $project), $this->payload())->assertOk();
        $this->assertSame('approved', $project->fresh()->status);
        $this->assertSame('volunteer', $project->fresh()->review_mode);
        $this->assertSame(3, $project->fresh()->reviewer_limit);
        $this->assertFalse($project->fresh()->is_public);
    }

    public function test_legacy_request_only_completion_is_preserved_without_modern_review_records(): void
    {
        $project = Project::factory()->approved()->create(['status' => 'completed']);
        $this->actingAs($project->owner)->putJson(route('projects.update', $project), $this->payload(['reviewer_limit' => 3]))
            ->assertOk()->assertSessionHasNoErrors();
        $this->assertSame('completed', $project->fresh()->status);
        $this->assertSame('request_only', $project->fresh()->review_mode);
        $this->assertDatabaseCount('review_requests', 0);
    }

    public function test_progress_scope_counts_distinct_evaluators_without_per_card_queries(): void
    {
        $project = Project::factory()->approved()->create(['review_mode' => 'volunteer', 'reviewer_limit' => 3]);
        $first = ReviewRequest::factory()->completed()->create(['project_id' => $project->id]);
        ReviewRequest::factory()->completed()->create(['project_id' => $project->id, 'evaluator_id' => $first->evaluator_id]);
        ReviewRequest::factory()->accepted()->create(['project_id' => $project->id]);
        ReviewRequest::factory()->create(['project_id' => $project->id]);
        DB::enableQueryLog();
        try {
            DB::flushQueryLog();
            $loaded = Project::query()->withReviewProgress()->findOrFail($project->id);
            $this->assertSame(2, $loaded->occupiedReviewerCount());
            $this->assertSame(1, $loaded->completedReviewerCount());
            $this->assertSame(1, (int) $loaded->accepted_reviews_count);
            $this->assertCount(1, DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
        }
    }

    public function test_admin_restoration_respects_volunteer_targets_and_preserves_request_only_completion(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);
        foreach ([['request_only', 3, 1, 'completed'], ['volunteer', 2, 1, 'approved'], ['volunteer', 2, 2, 'completed']] as [$mode, $limit, $completed, $expected]) {
            $project = Project::factory()->create(['status' => 'hidden', 'review_mode' => $mode, 'reviewer_limit' => $limit]);
            DocumentVersion::factory()->create(['project_id' => $project->id]);
            ReviewRequest::factory()->count($completed)->completed()->create(['project_id' => $project->id]);
            $this->post(route('admin.projects.moderate', $project), ['decision' => 'approve'])
                ->assertSessionHasNoErrors()->assertRedirect();
            $this->assertSame($expected, $project->fresh()->status);
        }
    }

    private function payload(array $overrides = []): array
    {
        return array_replace([
            'title' => 'Campus parking research with multiple evaluator perspectives',
            'summary' => 'A thesis project evaluating campus parking availability and reliable license plate recognition.',
            'school' => 'Community University', 'course' => 'BS Information Technology', 'category' => 'Web',
            'technologies' => 'Laravel, Python', 'feedback_needed' => 'Please review our methodology and evaluation plan.',
        ], $overrides);
    }

    private function pdfUpload(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('thesis.pdf', "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF");
    }

    private function assertSettings(string $html, string $mode, int $limit): void
    {
        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        $xpath = new \DOMXPath($document);
        $this->assertSame(1, $xpath->query('//input[@name="review_mode" and @checked]')->length);
        $this->assertSame(1, $xpath->query('//input[@name="review_mode" and @value="'.$mode.'" and @checked]')->length);
        $this->assertSame(1, $xpath->query('//select[@name="reviewer_limit"]/option[@value="'.$limit.'" and @selected]')->length);
    }
}

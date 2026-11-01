<?php

namespace Tests\Feature;

use App\Models\DocumentVersion;
use App\Models\Project;
use App\Models\ReviewRequest;
use App\Models\User;
use App\Support\ProjectAccess;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProjectPdfAccessTest extends TestCase
{
    use RefreshDatabase;

    private string $ownedViews;

    private string $ownedDisk;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $token = 'project-pdf-access-'.bin2hex(random_bytes(8));
        $this->ownedViews = storage_path('framework/testing/'.$token);
        (new Filesystem)->ensureDirectoryExists($this->ownedViews);
        config(['view.compiled' => $this->ownedViews]);
        $this->app->forgetInstance('blade.compiler');
        $this->app['view.engine.resolver']->forget('blade');

        // A unique fake disk avoids cleaning another test process's local fake.
        $this->ownedDisk = storage_path('framework/testing/disks/'.$token);
        Storage::set('local', Storage::fake($token));
    }

    protected function tearDown(): void
    {
        $paths = array_filter([$this->ownedViews ?? null, $this->ownedDisk ?? null]);
        try {
            parent::tearDown();
        } finally {
            foreach ($paths as $path) {
                (new Filesystem)->deleteDirectory($path);
            }
        }
    }

    public function test_model_and_database_default_to_assigned_access_without_exposing_legacy_pdfs(): void
    {
        $this->assertSame(Project::PDF_ACCESS_ASSIGNED, (new Project)->pdf_access);
        $project = Project::factory()->publicListing()->create();
        $attributes = $project->getAttributes();
        unset($attributes['id'], $attributes['pdf_access']);
        $attributes['title'] = 'Inserted without an Eloquent audience default';
        $id = DB::table('projects')->insertGetId($attributes);
        $this->assertSame(Project::PDF_ACCESS_ASSIGNED, Project::findOrFail($id)->pdf_access);
        $this->assertSame(Project::PDF_ACCESS_ASSIGNED, $project->fresh()->pdf_access);

        $document = $this->document($project, 'legacy-thesis.pdf');
        $viewer = User::factory()->evaluator()->create();
        $this->actingAs($viewer)->get(route('projects.show', $project))->assertOk()
            ->assertViewHas('canViewDocuments', false)->assertDontSee($document->original_name);
        $this->get(route('documents.download', $document))->assertForbidden();
    }

    public function test_the_additive_migration_preserves_existing_rows_and_defaults_future_rows(): void
    {
        config(['database.connections.pdf_access_upgrade' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]]);
        $connection = DB::connection('pdf_access_upgrade');
        $schema = $connection->getSchemaBuilder();
        $schema->create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('title');
        });
        $connection->table('projects')->insert(['title' => 'Existing thesis']);
        $originalSchema = Schema::getFacadeRoot();
        try {
            Schema::swap($schema);
            $migration = require database_path('migrations/2026_10_28_000002_add_pdf_access_to_projects.php');
            $migration->up();
            $this->assertSame('assigned', $connection->table('projects')->where('title', 'Existing thesis')->value('pdf_access'));
            $connection->table('projects')->insert(['title' => 'Future thesis']);
            $this->assertSame(['assigned', 'assigned'], $connection->table('projects')->pluck('pdf_access')->all());
            $column = collect($connection->select('PRAGMA table_info(projects)'))->firstWhere('name', 'pdf_access');
            $this->assertSame(1, (int) $column->notnull);
            $migration->down();
            $this->assertFalse($schema->hasColumn('projects', 'pdf_access'));
            $this->assertSame(['Existing thesis', 'Future thesis'], $connection->table('projects')->pluck('title')->all());
        } finally {
            Schema::swap($originalSchema);
            DB::purge('pdf_access_upgrade');
        }
    }

    public function test_create_saves_each_supported_audience_and_omitted_audience_is_assigned(): void
    {
        $owner = User::factory()->create();
        foreach ([null, Project::PDF_ACCESS_ASSIGNED, Project::PDF_ACCESS_APPROVED_EVALUATORS] as $index => $audience) {
            $payload = $this->payload(['title' => 'Thesis audience '.$index, 'document' => $this->pdfUpload()]);
            if ($audience !== null) {
                $payload['pdf_access'] = $audience;
            }
            $response = $this->actingAs($owner)->post(route('projects.store'), $payload)->assertSessionHasNoErrors();
            $project = Project::where('title', $payload['title'])->sole();
            $response->assertRedirect(route('account.projects.show', $project));
            $this->assertSame($audience ?? Project::PDF_ACCESS_ASSIGNED, $project->pdf_access);
            $this->assertTrue($project->is_public);
            $this->assertSame('approved', $project->status);
            Storage::disk('local')->assertExists($project->documents()->sole()->path);
        }
        $this->assertDatabaseCount('projects', 3);
        $this->assertDatabaseCount('document_versions', 3);
    }

    public function test_create_and_update_reject_invalid_or_empty_audiences_without_saving_them(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);
        foreach (['everyone', '', null, ['approved_evaluators']] as $audience) {
            $this->postJson(route('projects.store'), $this->payload([
                'pdf_access' => $audience, 'document' => $this->pdfUpload(),
            ]))->assertUnprocessable()->assertJsonValidationErrors('pdf_access');
        }
        $this->assertDatabaseCount('projects', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
        $project = Project::factory()->publicListing()->create([
            'user_id' => $owner->id, 'pdf_access' => Project::PDF_ACCESS_APPROVED_EVALUATORS,
        ]);
        foreach (['everyone', '', null, ['assigned']] as $audience) {
            $this->putJson(route('projects.update', $project), $this->payload(['pdf_access' => $audience]))
                ->assertUnprocessable()->assertJsonValidationErrors('pdf_access');
            $this->assertSame(Project::PDF_ACCESS_APPROVED_EVALUATORS, $project->fresh()->pdf_access);
        }
    }

    public function test_forms_select_the_default_saved_and_old_input_audience(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);
        $this->assertAudienceRadio($this->get(route('projects.create'))->assertOk()->getContent(), 'assigned');
        foreach (['assigned', 'approved_evaluators'] as $audience) {
            $project = Project::factory()->publicListing()->create(['user_id' => $owner->id, 'pdf_access' => $audience]);
            $this->assertAudienceRadio($this->get(route('projects.edit', $project))->assertOk()->getContent(), $audience);
        }

        $this->from(route('projects.create'))->post(route('projects.store'), $this->payload([
            'title' => '', 'pdf_access' => 'approved_evaluators', 'document' => $this->pdfUpload(),
        ]))->assertRedirect(route('projects.create'))->assertSessionHasErrors('title');
        $this->assertAudienceRadio($this->get(route('projects.create'))->assertOk()->getContent(), 'approved_evaluators');

        $this->from(route('projects.edit', $project))->put(route('projects.update', $project), $this->payload([
            'title' => '', 'pdf_access' => 'assigned',
        ]))->assertRedirect(route('projects.edit', $project))->assertSessionHasErrors('title');
        $this->assertAudienceRadio($this->get(route('projects.edit', $project))->assertOk()->getContent(), 'assigned');
        $this->assertSame('approved_evaluators', $project->fresh()->pdf_access);
    }

    public function test_only_owner_and_members_can_change_audience_and_omitted_updates_preserve_it(): void
    {
        $project = Project::factory()->publicListing()->create();
        $member = User::factory()->create();
        $project->members()->attach($member);
        foreach ([$project->owner, $member] as $viewer) {
            foreach (['approved_evaluators', 'assigned'] as $audience) {
                $this->actingAs($viewer)->put(route('projects.update', $project), $this->payload(['pdf_access' => $audience]))
                    ->assertSessionHasNoErrors()->assertRedirect(route('account.projects.show', $project));
                $this->assertSame($audience, $project->fresh()->pdf_access);
                $this->put(route('projects.update', $project), $this->payload())->assertSessionHasNoErrors();
                $this->assertSame($audience, $project->fresh()->pdf_access);
            }
        }
        $beforeAdminAttempt = $project->fresh()->getAttributes();
        $this->actingAs(User::factory()->admin()->create())->get(route('projects.edit', $project))->assertForbidden();
        $this->putJson(route('projects.update', $project), $this->payload(['pdf_access' => 'approved_evaluators']))
            ->assertForbidden();
        $this->assertSame($beforeAdminAttempt, $project->fresh()->getAttributes());
        $this->assertTrue($project->members()->whereKey($member->id)->exists());
    }

    public function test_pdf_browsing_does_not_grant_project_management_or_review_permissions(): void
    {
        [$project, $documents, $review] = $this->sharedProject();
        foreach ([User::factory()->create(), User::factory()->evaluator()->create()] as $viewer) {
            $this->actingAs($viewer)->get(route('projects.edit', $project))->assertForbidden();
            $this->get(route('account.projects.show', $project))->assertNotFound();
            $this->put(route('projects.update', $project), $this->payload(['pdf_access' => 'assigned']))->assertForbidden();
            $this->post(route('projects.upload', $project), ['document' => $this->pdfUpload()])->assertForbidden();
            $this->get(route('reviews.show', $review))->assertForbidden();
            $this->get(route('reviews.output', $review))->assertForbidden();
            $this->post(route('reviews.complete', $review), ['final_summary' => str_repeat('Private review ', 5)])->assertForbidden();
            $this->post(route('reviews.feedback', $review), ['body' => 'Unauthorized comment'])->assertForbidden();
            $this->post(route('reviews.store', $project), [
                'evaluator_id' => $review->evaluator_id, 'document_version_id' => $documents[0]->id,
                'message' => 'An unauthorized request for a review.',
            ])->assertForbidden();
        }
        $this->assertSame('approved_evaluators', $project->fresh()->pdf_access);
        $this->assertDatabaseCount('document_versions', 3);
        $this->assertDatabaseCount('review_requests', 1);
        $this->assertDatabaseCount('feedback', 0);
    }

    public function test_public_opt_in_allows_active_approved_evaluators_all_original_versions_but_no_private_review_material(): void
    {
        [$project, $documents, $review] = $this->sharedProject();
        $viewer = User::factory()->evaluator()->create();
        foreach (['approved', 'completed'] as $status) {
            $project->update(['status' => $status]);
            $response = $this->actingAs($viewer)->get(route('projects.show', $project))->assertOk()
                ->assertViewHas('canViewDocuments', true)->assertViewHas('canBrowseDocuments', true)
                ->assertViewHas('canViewPrivate', false)->assertViewHas('canManage', false)
                ->assertViewHas('documents', fn ($items) => $items->modelKeys() === array_reverse(array_column($documents, 'id')))
                ->assertViewHas('reviewRequests', fn ($items) => $items->isEmpty())
                ->assertViewHas('evaluators', fn ($items) => $items->isEmpty())
                ->assertSee('Thesis documents')->assertSee('Review feedback stays private.')
                ->assertDontSee('Upload a revision')->assertDontSee('Private review summary')
                ->assertDontSee($review->final_summary)->assertDontSee($review->output_original_name)
                ->assertDontSee(route('reviews.output', $review));
            $this->assertFalse($response->viewData('project')->relationLoaded('members'));
            $this->assertFalse($project->canAccess($viewer));
            $this->assertFalse(ProjectAccess::canViewPrivate($project, $viewer));
            foreach ($documents as $document) {
                $response->assertSee($document->original_name);
                $this->get(route('documents.download', $document))->assertOk()->assertDownload($document->original_name);
            }
        }
    }

    public function test_students_including_owner_and_member_still_use_the_private_workspace_and_guests_cannot_download(): void
    {
        [$project, $documents, $review] = $this->sharedProject();
        $member = User::factory()->create();
        $project->members()->attach($member);
        $this->get(route('projects.show', $project))->assertOk()->assertViewHas('canViewDocuments', false)
            ->assertDontSee($documents[0]->original_name)->assertDontSee($review->final_summary);
        $this->get(route('documents.download', $documents[0]))->assertRedirect(route('login'));
        foreach ([User::factory()->create(), $project->owner, $member] as $viewer) {
            $response = $this->actingAs($viewer)->get(route('projects.show', $project))->assertOk()
                ->assertViewHas('canViewDocuments', false)->assertViewHas('canViewPrivate', false)
                ->assertViewHas('documents', fn ($items) => $items->isEmpty())
                ->assertDontSee($documents[0]->original_name)->assertDontSee($review->final_summary);
            $this->assertFalse($response->viewData('project')->relationLoaded('members'));
            if ($viewer->id === $project->user_id || $viewer->id === $member->id) {
                $response->assertSee(route('account.projects.show', $project));
                $this->get(route('account.projects.show', $project))->assertOk()
                    ->assertViewHas('canViewDocuments', true)->assertSee($documents[0]->original_name)
                    ->assertDontSee($review->final_summary)->assertDontSee($review->output_original_name)
                    ->assertDontSee(route('reviews.output', $review))->assertSee(route('reviews.show', $review));
                $this->get(route('reviews.show', $review))->assertOk()
                    ->assertSee($review->final_summary)->assertSee($review->output_original_name)
                    ->assertSee(route('reviews.output', $review));
                $this->get(route('documents.download', $documents[0]))->assertOk();
            } else {
                $this->get(route('documents.download', $documents[0]))->assertForbidden();
            }
        }
    }

    public function test_unapproved_or_suspended_evaluators_cannot_use_the_broader_pdf_audience(): void
    {
        [$project, $documents] = $this->sharedProject();
        foreach (['pending', 'rejected', 'needs_info', 'none'] as $status) {
            $viewer = User::factory()->evaluator()->create(['evaluator_status' => $status]);
            $this->assertFalse(ProjectAccess::canBrowseThesisDocuments($project, $viewer));
            $this->actingAs($viewer)->get(route('projects.show', $project))->assertOk()->assertViewHas('canViewDocuments', false);
            $this->get(route('documents.download', $documents[0]))->assertForbidden();
        }
        $suspended = User::factory()->evaluator()->create(['is_suspended' => true]);
        $this->assertFalse(ProjectAccess::canBrowseThesisDocuments($project, $suspended));
        $this->assertFalse(ProjectAccess::canDownload($documents[0], $suspended));
        $this->actingAs($suspended)->get(route('documents.download', $documents[0]))->assertRedirect(route('login'));
    }

    public function test_opt_in_never_exposes_unlisted_or_unready_project_documents(): void
    {
        [$project, $documents] = $this->sharedProject();
        $viewer = User::factory()->evaluator()->create();
        foreach (['draft', 'pending', 'needs_changes', 'rejected', 'hidden', 'approved', 'completed'] as $status) {
            foreach ([false, true] as $public) {
                if ($public && in_array($status, ['approved', 'completed'], true)) {
                    continue;
                }
                $project->update(['status' => $status, 'is_public' => $public]);
                $this->assertFalse(ProjectAccess::canBrowseThesisDocuments($project, $viewer), $status.' public='.(int) $public);
                $this->actingAs($viewer)->get(route('projects.show', $project))->assertNotFound();
                $this->get(route('documents.download', $documents[0]))->assertForbidden();
            }
        }
    }

    public function test_pending_or_declined_review_is_not_private_access_even_when_original_pdfs_are_shared(): void
    {
        [$project, $documents, $privateReview] = $this->sharedProject();
        foreach (['pending', 'declined'] as $status) {
            $review = ReviewRequest::factory()->create([
                'project_id' => $project->id, 'document_version_id' => $documents[0]->id, 'status' => $status,
            ]);
            $this->actingAs($review->evaluator)->get(route('projects.show', $project))->assertOk()
                ->assertViewHas('canViewDocuments', true)->assertViewHas('canViewPrivate', false)
                ->assertViewHas('reviewRequests', fn ($items) => $items->isEmpty())->assertDontSee($privateReview->final_summary);
            $this->get(route('documents.download', $documents[2]))->assertOk();
            $this->get(route('reviews.show', $review))->assertOk()->assertViewHas('canViewDocument', false);
            $this->get(route('reviews.output', $privateReview))->assertForbidden();
        }
    }

    public function test_revoking_the_audience_immediately_restores_exact_assigned_version_access_without_changing_reviews_or_files(): void
    {
        [$project, $documents, $review] = $this->sharedProject();
        $browser = User::factory()->evaluator()->create();
        $originalReview = $review->fresh()->getAttributes();
        $originalDocuments = $project->documents()->orderBy('id')->get()->map->getAttributes()->all();
        $this->actingAs($browser)->get(route('documents.download', $documents[2]))->assertOk();
        $this->actingAs($review->evaluator)->get(route('documents.download', $documents[2]))->assertOk();

        $this->actingAs($project->owner)->put(route('projects.update', $project), $this->payload(['pdf_access' => 'assigned']))
            ->assertSessionHasNoErrors()->assertRedirect(route('account.projects.show', $project));
        $this->actingAs($browser)->get(route('projects.show', $project))->assertOk()->assertViewHas('canViewDocuments', false);
        $this->get(route('documents.download', $documents[0]))->assertForbidden();
        $this->get(route('documents.download', $documents[2]))->assertForbidden();
        $this->actingAs($review->evaluator)->get(route('projects.show', $project))->assertOk()
            ->assertSee('Private documents')->assertViewHas('canBrowseDocuments', false)
            ->assertViewHas('documents', fn ($items) => $items->modelKeys() === [$documents[0]->id])
            ->assertDontSee($review->final_summary)->assertDontSee($review->output_original_name)
            ->assertDontSee(route('reviews.output', $review))->assertSee(route('reviews.show', $review))
            ->assertDontSee($documents[2]->original_name);
        $this->get(route('reviews.show', $review))->assertOk()
            ->assertSee($review->final_summary)->assertSee($review->output_original_name)
            ->assertSee(route('reviews.output', $review));
        $this->get(route('documents.download', $documents[0]))->assertOk();
        $this->get(route('documents.download', $documents[2]))->assertForbidden();
        $this->get(route('reviews.output', $review))->assertOk()->assertDownload($review->output_original_name);
        $this->assertSame($originalReview, $review->fresh()->getAttributes());
        $this->assertSame($originalDocuments, $project->documents()->orderBy('id')->get()->map->getAttributes()->all());
        foreach ($documents as $document) {
            $this->assertSame($this->pdf(), Storage::disk('local')->get($document->path));
        }
    }

    public function test_unlisting_revokes_browsing_but_keeps_assigned_private_access_and_administrative_access(): void
    {
        [$project, $documents, $review] = $this->sharedProject();
        $browser = User::factory()->evaluator()->create();
        $this->actingAs($project->owner)->put(route('projects.update', $project), $this->payload(['is_public' => false]))
            ->assertSessionHasNoErrors();
        $this->assertSame('approved_evaluators', $project->fresh()->pdf_access);
        $this->actingAs($browser)->get(route('projects.show', $project))->assertNotFound();
        $this->get(route('documents.download', $documents[0]))->assertForbidden();
        $this->actingAs($review->evaluator)->get(route('projects.show', $project))->assertOk()
            ->assertViewHas('canBrowseDocuments', false)->assertViewHas('canViewPrivate', true)
            ->assertViewHas('documents', fn ($items) => $items->modelKeys() === [$documents[0]->id]);
        $this->get(route('documents.download', $documents[0]))->assertOk();
        $this->get(route('documents.download', $documents[1]))->assertForbidden();
        $this->actingAs(User::factory()->admin()->create())->get(route('projects.show', $project))->assertOk()
            ->assertViewHas('canManage', true)->assertViewHas('documents', fn ($items) => $items->count() === 3);
        $this->get(route('documents.download', $documents[2]))->assertOk();
    }

    public function test_assigned_evaluators_can_browse_all_originals_without_receiving_another_evaluators_results(): void
    {
        [$project, $documents, $review] = $this->sharedProject();
        $other = ReviewRequest::factory()->completed()->create([
            'project_id' => $project->id, 'document_version_id' => $documents[1]->id,
            'final_summary' => 'Foreign confidential review findings that must never be shared with another evaluator.',
            'output_original_name' => 'foreign-private-output.pdf',
        ]);
        $other->update(['output_path' => 'review-outputs/'.$other->id.'/'.str_repeat('b', 40).'.pdf']);
        Storage::disk('local')->put($other->output_path, $this->pdf());
        $this->actingAs($review->evaluator)->get(route('projects.show', $project))->assertOk()
            ->assertViewHas('canBrowseDocuments', true)->assertViewHas('documents', fn ($items) => $items->count() === 3)
            ->assertViewHas('reviewRequests', fn ($items) => $items->modelKeys() === [$review->id])
            ->assertDontSee($review->final_summary)->assertDontSee($review->output_original_name)
            ->assertDontSee(route('reviews.output', $review))->assertSee(route('reviews.show', $review))
            ->assertSee($documents[1]->original_name)
            ->assertDontSee($other->final_summary)->assertDontSee($other->output_original_name)
            ->assertDontSee(route('reviews.output', $other))->assertDontSee('Upload a revision');
        $this->get(route('reviews.show', $review))->assertOk()
            ->assertSee($review->final_summary)->assertSee($review->output_original_name)
            ->assertSee(route('reviews.output', $review))->assertDontSee($other->final_summary);
        $this->get(route('reviews.show', $other))->assertForbidden();
        $this->get(route('reviews.output', $other))->assertForbidden();
        $this->get(route('reviews.output', $review))->assertOk();
    }

    private function sharedProject(): array
    {
        $project = Project::factory()->publicListing()->create([
            'status' => 'completed', 'pdf_access' => Project::PDF_ACCESS_APPROVED_EVALUATORS,
        ]);
        $documents = [];
        foreach (['original-thesis.pdf', 'second-thesis-version.pdf', 'latest-thesis-version.pdf'] as $name) {
            $documents[] = $this->document($project, $name);
        }
        $review = ReviewRequest::factory()->completed()->create([
            'project_id' => $project->id, 'document_version_id' => $documents[0]->id,
            'final_summary' => 'Confidential evaluator findings and the final review summary reserved for the team and assigned reviewer.',
            'output_original_name' => 'confidential-annotated-review.pdf',
        ]);
        $review->update(['output_path' => 'review-outputs/'.$review->id.'/'.str_repeat('a', 40).'.pdf']);
        Storage::disk('local')->put($review->output_path, $this->pdf());

        return [$project, $documents, $review];
    }

    private function document(Project $project, string $name): DocumentVersion
    {
        $document = DocumentVersion::factory()->create(['project_id' => $project->id, 'original_name' => $name]);
        Storage::disk('local')->put($document->path, $this->pdf());

        return $document;
    }

    private function assertAudienceRadio(string $html, string $selected): void
    {
        $document = new \DOMDocument;
        @$document->loadHTML($html);
        $radios = (new \DOMXPath($document))->query('//input[@type="radio" and @name="pdf_access"]');
        $this->assertCount(2, $radios);
        foreach ($radios as $radio) {
            $this->assertSame($selected === $radio->getAttribute('value'), $radio->hasAttribute('checked'));
            $this->assertTrue($radio->hasAttribute('required'));
        }
    }

    private function payload(array $overrides = []): array
    {
        return array_replace([
            'title' => 'Campus request tracking thesis',
            'summary' => 'A thesis examining how a campus can track requests and improve response times for its students.',
            'school' => 'Example University', 'course' => 'BS Information Technology', 'category' => 'Web',
            'technologies' => 'Laravel, SQLite', 'feedback_needed' => 'Please review our research methodology.',
            'is_public' => true,
        ], $overrides);
    }

    private function pdfUpload(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('thesis.pdf', $this->pdf());
    }

    private function pdf(): string
    {
        return "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF";
    }
}

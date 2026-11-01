<?php

namespace Tests\Feature;

use App\Models\DocumentVersion;
use App\Models\Project;
use App\Models\ReviewRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProjectAutoApprovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_new_project_form_shares_the_summary_by_default(): void
    {
        $response = $this->actingAs(User::factory()->create())
            ->get(route('projects.create'))->assertOk();

        $this->assertPublicSummaryCheckbox($response->getContent(), true);
    }

    public function test_an_explicit_private_choice_remains_unchecked_after_a_validation_error(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create())->from(route('projects.create'))
            ->post(route('projects.store'), $this->payload([
                'title' => '', 'is_public' => '0', 'document' => $this->pdfUpload(),
            ]))->assertRedirect(route('projects.create'))->assertSessionHasErrors('title');

        $response = $this->get(route('projects.create'))->assertOk();
        $this->assertPublicSummaryCheckbox($response->getContent(), false);
        $this->assertDatabaseCount('projects', 0);
        $this->assertDatabaseCount('document_versions', 0);
    }

    public function test_a_failed_create_explains_that_nothing_was_saved_and_a_corrected_retry_creates_one_listed_project(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $title = 'Automated Campus Parking Management System with License Plate Recognition and Real-Time Slot Availability';
        $payload = $this->payload([
            'title' => $title, 'is_public' => '1', 'document' => $this->pdfUpload(),
            'member_emails' => 'unregistered-group-member@example.test',
        ]);
        $this->actingAs($owner)->from(route('projects.create'))->post(route('projects.store'), $payload)
            ->assertRedirect(route('projects.create'))->assertSessionHasErrors('member_emails');

        $response = $this->get(route('projects.create'))->assertOk()
            ->assertSee('Your project has not been created yet.')
            ->assertSee('No active registered student account was found for')
            ->assertSee('unregistered-group-member@example.test')
            ->assertSee('Correct or remove this optional group member email.')
            ->assertSee('select your thesis PDF again')
            ->assertSee('data-project-errors', false);
        $this->assertPublicSummaryCheckbox($response->getContent(), true);
        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        $this->assertSame($title, $xpath->query('//input[@id="title"]')->item(0)->getAttribute('value'));
        $this->assertSame(now()->toDateString(), $xpath->query('//input[@id="deadline"]')->item(0)->getAttribute('min'));
        $this->assertDatabaseCount('projects', 0);
        $this->assertDatabaseCount('document_versions', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());

        $payload['member_emails'] = '';
        $payload['document'] = $this->pdfUpload();
        $retry = $this->post(route('projects.store'), $payload)->assertSessionHasNoErrors();
        $project = Project::sole();
        $retry->assertRedirect(route('account.projects.show', $project));
        $this->assertSame($title, $project->title);
        $this->assertSame('approved', $project->status);
        $this->assertTrue($project->is_public);
        $this->assertDatabaseCount('projects', 1);
        $this->assertDatabaseCount('document_versions', 1);
        Storage::disk('local')->assertExists($project->documents()->sole()->path);
        $this->get(route('projects.index'))->assertOk()->assertSee($title);
        $this->get(route('account.projects'))->assertOk()->assertSee($title);
    }

    public function test_existing_project_forms_keep_their_saved_public_or_private_choice(): void
    {
        $owner = User::factory()->create();
        foreach ([false, true] as $public) {
            $project = Project::factory()->approved()->create([
                'user_id' => $owner->id, 'is_public' => $public,
            ]);
            $response = $this->actingAs($owner)->get(route('projects.edit', $project))->assertOk();

            $this->assertPublicSummaryCheckbox($response->getContent(), $public);
        }
    }

    public function test_a_new_submission_without_a_visibility_field_is_listed_but_its_pdf_remains_private(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $payload = $this->payload(['document' => $this->pdfUpload()]);
        unset($payload['is_public']);
        $response = $this->actingAs($owner)->post(route('projects.store'), $payload)
            ->assertSessionHasNoErrors();
        $project = Project::sole();
        $document = $project->documents()->sole();

        $response->assertRedirect(route('account.projects.show', $project));
        $this->assertSame('approved', $project->status);
        $this->assertTrue($project->is_public);
        $this->assertSame($owner->id, $project->user_id);
        Storage::disk('local')->assertExists($document->path);
        $this->get(route('account.projects'))->assertOk()->assertSee($project->title);
        $this->get(route('projects.index'))->assertOk()->assertSee($project->title);

        $this->post(route('logout'))->assertRedirect();
        $this->get(route('projects.index'))->assertOk()->assertSee($project->title);
        $this->get(route('projects.show', $project))->assertOk()->assertDontSee('PRIVATE DOCUMENTS');
        $this->get(route('documents.download', $document))->assertRedirect(route('login'));
    }

    public function test_metadata_updates_without_a_visibility_field_preserve_both_public_and_private_summaries(): void
    {
        $owner = User::factory()->create();
        foreach ([false, true] as $public) {
            $project = Project::factory()->approved()->create([
                'user_id' => $owner->id, 'is_public' => $public,
            ]);
            $document = DocumentVersion::factory()->create(['project_id' => $project->id]);
            $payload = $this->payload(['title' => 'Updated thesis with existing visibility']);
            unset($payload['is_public']);

            $this->actingAs($owner)->put(route('projects.update', $project), $payload)
                ->assertSessionHasNoErrors()->assertRedirect(route('account.projects.show', $project));

            $this->assertSame($public, $project->fresh()->is_public);
            $this->assertSame('approved', $project->fresh()->status);
            $this->assertSame($payload['title'], $project->fresh()->title);
            $this->assertSame($document->id, $project->documents()->sole()->id);
        }
    }

    public function test_valid_submissions_are_immediately_ready_for_review_and_only_opted_in_projects_are_public(): void
    {
        Storage::fake('local');
        $student = User::factory()->create();
        $admin = User::factory()->admin()->create();

        foreach ([false, true] as $public) {
            $title = $public ? 'Public auto approved thesis' : 'Private auto approved thesis';
            $this->actingAs($student)->post(route('projects.store'), $this->payload([
                'title' => $title, 'is_public' => $public,
                'document' => $this->pdfUpload(), 'status' => 'hidden', 'user_id' => $admin->id,
            ]))->assertSessionHasNoErrors()->assertRedirect();
            $project = Project::where('title', $title)->sole();
            $this->assertSame('approved', $project->status);
            $this->assertSame($student->id, $project->user_id);
            $this->assertSame($public, $project->is_public);
            $document = $project->documents()->sole();
            $this->assertSame(1, $document->version);
            Storage::disk('local')->assertExists($document->path);
            $this->assertStringStartsWith('theses/'.$project->id.'/', $document->path);
        }

        $this->assertSame(0, $admin->activities()->count());
        $private = Project::where('is_public', false)->sole();
        $public = Project::where('is_public', true)->sole();
        $this->post(route('logout'))->assertRedirect();
        $this->get(route('projects.index'))->assertOk()->assertSee($public->title)->assertDontSee($private->title);
        $this->get(route('projects.show', $private))->assertNotFound();
        $this->get(route('projects.show', $public))->assertOk();
        $this->get(route('documents.download', $public->documents()->sole()))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->evaluator()->create())
            ->get(route('documents.download', $public->documents()->sole()))->assertForbidden();
    }

    public function test_a_saved_draft_stays_private_until_its_owner_submits_it_with_its_existing_pdf(): void
    {
        Storage::fake('local');
        $student = User::factory()->create();
        $admin = User::factory()->admin()->create();
        $this->actingAs($student)->post(route('projects.store'), $this->payload([
            'save_draft' => true, 'is_public' => false, 'document' => $this->pdfUpload(),
        ]))->assertSessionHasNoErrors()->assertRedirect();
        $project = Project::sole();
        $document = $project->documents()->sole();
        $this->assertSame('draft', $project->status);
        $this->assertFalse($project->is_public);
        $this->put(route('projects.update', $project), $this->payload(['save_draft' => true]))
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('draft', $project->fresh()->status);

        $this->put(route('projects.update', $project), $this->payload())
            ->assertSessionHasNoErrors()->assertRedirect(route('account.projects.show', $project));
        $this->assertSame('approved', $project->fresh()->status);
        $this->assertFalse($project->fresh()->is_public);
        $this->assertSame($document->id, $project->documents()->sole()->id);
        Storage::disk('local')->assertExists($document->path);
        $this->assertSame(0, $admin->activities()->count());
    }

    public function test_resubmitting_a_valid_project_clears_its_old_moderation_note_and_approves_it_without_a_queue_notification(): void
    {
        $owner = User::factory()->create();
        $admin = User::factory()->admin()->create();
        foreach (['pending', 'needs_changes', 'rejected'] as $status) {
            $project = Project::factory()->create([
                'user_id' => $owner->id, 'status' => $status,
                'moderation_note' => 'Please clarify the scope before resubmitting.', 'is_public' => false,
            ]);
            $document = DocumentVersion::factory()->create(['project_id' => $project->id]);
            $this->actingAs($owner)->put(route('projects.update', $project), $this->payload())
                ->assertSessionHasNoErrors()->assertRedirect();
            $this->assertSame('approved', $project->fresh()->status);
            $this->assertNull($project->fresh()->moderation_note);
            $this->assertFalse($project->fresh()->is_public);
            $this->assertSame($document->id, $project->documents()->sole()->id);
        }
        $this->assertSame(0, $admin->activities()->count());
    }

    public function test_metadata_edits_keep_approval_pending_requests_accepted_reviews_and_original_documents_intact(): void
    {
        Storage::fake('local');
        $project = Project::factory()->publicListing()->create();
        $admin = User::factory()->admin()->create();
        $document = DocumentVersion::factory()->create(['project_id' => $project->id]);
        Storage::disk('local')->put($document->path, $this->pdf());
        $pending = ReviewRequest::factory()->create([
            'project_id' => $project->id, 'document_version_id' => $document->id,
        ]);
        $accepted = ReviewRequest::factory()->accepted()->create([
            'project_id' => $project->id, 'document_version_id' => $document->id,
        ]);
        $pendingAttributes = $pending->fresh()->getAttributes();
        $acceptedAttributes = $accepted->fresh()->getAttributes();

        $this->actingAs($project->owner)->put(route('projects.update', $project), $this->payload([
            'title' => 'Revised scope for an ongoing thesis review', 'is_public' => true,
        ]))->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame('approved', $project->fresh()->status);
        $this->assertSame('Revised scope for an ongoing thesis review', $project->fresh()->title);
        $this->assertTrue($project->fresh()->is_public);
        $this->assertSame($pendingAttributes, $pending->fresh()->getAttributes());
        $this->assertSame($acceptedAttributes, $accepted->fresh()->getAttributes());
        $this->assertSame($document->id, $project->documents()->sole()->id);
        Storage::disk('local')->assertExists($document->path);
        foreach ([$admin, $pending->evaluator, $accepted->evaluator] as $user) {
            $this->assertSame(0, $user->activities()->count());
        }
        $this->actingAs($accepted->evaluator)->get(route('documents.download', $document))->assertOk()->assertDownload('thesis.pdf');
        $this->post(route('reviews.feedback', $accepted), [
            'type' => 'recommendation',
            'body' => 'Connect each participant task to the research objective it is intended to evaluate.',
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->actingAs($pending->evaluator)->post(route('reviews.respond', $pending), ['decision' => 'accept'])
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('accepted', $pending->fresh()->status);
    }

    public function test_missing_or_invalid_pdf_submissions_cannot_create_or_publish_a_project(): void
    {
        Storage::fake('local');
        $student = User::factory()->create();
        $this->actingAs($student)->postJson(route('projects.store'), $this->payload())
            ->assertUnprocessable()->assertJsonValidationErrors('document');
        $this->postJson(route('projects.store'), $this->payload([
            'document' => UploadedFile::fake()->createWithContent('thesis.pdf', '<html>Not a thesis PDF</html>'),
        ]))->assertUnprocessable()->assertJsonValidationErrors('document');
        $this->assertDatabaseCount('projects', 0);
        $this->assertDatabaseCount('document_versions', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());

        foreach (['draft', 'pending', 'rejected', 'needs_changes'] as $status) {
            $project = Project::factory()->create(['user_id' => $student->id, 'status' => $status]);
            $this->putJson(route('projects.update', $project), $this->payload())
                ->assertUnprocessable()->assertJsonValidationErrors('document');
            $this->assertSame($status, $project->fresh()->status);
        }
    }

    public function test_auto_approval_does_not_bypass_student_role_ownership_suspension_or_group_membership_rules(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $project = Project::factory()->approved()->create(['user_id' => $owner->id]);
        $member = User::factory()->create();
        $project->members()->attach($member);

        $this->post(route('projects.store'), $this->payload(['document' => $this->pdfUpload()]))
            ->assertRedirect(route('login'));
        $this->actingAs(User::factory()->evaluator()->create())
            ->post(route('projects.store'), $this->payload(['document' => $this->pdfUpload()]))->assertForbidden();
        $this->actingAs(User::factory()->create())
            ->put(route('projects.update', $project), $this->payload())->assertForbidden();
        $this->actingAs($owner)->putJson(route('projects.update', $project), $this->payload(['member_emails' => '']))
            ->assertUnprocessable()->assertJsonValidationErrors('member_emails');
        $this->assertTrue($project->members()->whereKey($member->id)->exists());

        $owner->update(['is_suspended' => true]);
        $this->actingAs($owner)->post(route('projects.store'), $this->payload(['document' => $this->pdfUpload()]))
            ->assertRedirect(route('login'))->assertSessionHasErrors('email');
        $this->actingAs($owner)->put(route('projects.update', $project), $this->payload())
            ->assertRedirect(route('login'))->assertSessionHasErrors('email');
        $this->assertSame('approved', $project->fresh()->status);
        $this->assertDatabaseCount('projects', 1);
    }

    public function test_owner_edits_cannot_restore_a_project_hidden_by_an_administrator(): void
    {
        $project = Project::factory()->create(['status' => 'hidden', 'moderation_note' => 'This listing was hidden after review.']);
        DocumentVersion::factory()->create(['project_id' => $project->id]);
        $this->actingAs($project->owner)->put(route('projects.update', $project), $this->payload([
            'status' => 'approved', 'is_public' => true,
        ]))->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('hidden', $project->fresh()->status);
        $this->assertSame('This listing was hidden after review.', $project->fresh()->moderation_note);
    }

    public function test_migration_releases_only_legacy_pending_projects_with_documents_and_preserves_visibility_and_reviews(): void
    {
        $pending = [];
        foreach ([false, true] as $public) {
            $project = Project::factory()->create(['status' => 'pending', 'is_public' => $public, 'moderation_note' => 'Old queue note']);
            $document = DocumentVersion::factory()->create(['project_id' => $project->id]);
            $review = ReviewRequest::factory()->accepted()->create([
                'project_id' => $project->id, 'document_version_id' => $document->id,
            ]);
            $pending[] = [$project, $public, $document->fresh()->getAttributes(), $review->fresh()->getAttributes()];
        }
        $unchanged = [];
        foreach (['draft', 'needs_changes', 'rejected', 'hidden', 'approved'] as $status) {
            $project = Project::factory()->create(['status' => $status, 'moderation_note' => 'Keep this moderation decision.']);
            DocumentVersion::factory()->create(['project_id' => $project->id]);
            $unchanged[] = [$project, $project->fresh()->getAttributes()];
        }
        $withoutPdf = Project::factory()->create(['status' => 'pending']);
        $unchanged[] = [$withoutPdf, $withoutPdf->fresh()->getAttributes()];
        $migration = require database_path('migrations/2026_10_25_000002_auto_approve_submitted_projects.php');
        $migration->up();

        foreach ($pending as [$project, $public, $documentAttributes, $reviewAttributes]) {
            $this->assertSame('approved', $project->fresh()->status);
            $this->assertSame($public, $project->fresh()->is_public);
            $this->assertNull($project->fresh()->moderation_note);
            $this->assertSame($documentAttributes, $project->documents()->sole()->getAttributes());
            $this->assertSame($reviewAttributes, $project->reviewRequests()->sole()->getAttributes());
        }
        foreach ($unchanged as [$project, $attributes]) {
            $this->assertSame($attributes, $project->fresh()->getAttributes());
        }
        $migration->down();
        $this->assertSame('approved', $pending[0][0]->fresh()->status);
        $this->assertDatabaseCount('activities', 0);
    }

    private function assertPublicSummaryCheckbox(string $html, bool $checked): void
    {
        $document = new \DOMDocument;
        @$document->loadHTML($html);
        $checkboxes = (new \DOMXPath($document))->query('//input[@type="checkbox" and @name="is_public"]');
        $this->assertCount(1, $checkboxes);
        $this->assertSame($checked, $checkboxes->item(0)->hasAttribute('checked'));
    }

    private function payload(array $overrides = []): array
    {
        return array_replace([
            'title' => 'Community service request tracking',
            'summary' => 'A thesis examining how residents submit service requests and track the progress of each request.',
            'school' => 'Example Community College', 'course' => 'BS Information Technology',
            'category' => 'Web', 'technologies' => 'Laravel, MySQL',
            'feedback_needed' => 'Please review our objectives and methodology.', 'is_public' => false,
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

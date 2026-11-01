<?php

namespace Tests\Feature;

use App\Models\DocumentVersion;
use App\Models\Project;
use App\Models\Report;
use App\Models\ReviewRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsEvaluatorVerification;
use Tests\TestCase;

class AnalistaWorkflowTest extends TestCase
{
    use RefreshDatabase, BuildsEvaluatorVerification;

    public function test_student_can_upload_an_auto_approved_private_thesis_without_overriding_ownership(): void
    {
        Storage::fake('local');
        $student = User::factory()->create();

        $this->actingAs($student)->post(route('projects.store'), [
            'title' => 'Community service request tracking',
            'summary' => 'A thesis examining how residents submit service requests and track the progress of each request.',
            'school' => 'Example Community College',
            'course' => 'BS Information Technology',
            'category' => 'Web',
            'technologies' => 'Laravel, MySQL',
            'feedback_needed' => 'Please review our objectives and methodology.',
            'is_public' => false,
            'document' => UploadedFile::fake()->createWithContent('thesis.pdf', $this->pdf()),
            'status' => 'approved',
            'user_id' => User::factory()->admin()->create()->id,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $project = Project::sole();
        $document = $project->documents()->sole();
        $this->assertSame('approved', $project->status);
        $this->assertSame($student->id, $project->user_id);
        $this->assertFalse($project->is_public);
        $this->assertSame(1, $document->version);
        $this->assertSame(['Laravel', 'MySQL'], $project->technologies);
        Storage::disk('local')->assertExists($document->path);
        $this->assertStringStartsNotWith('public/', $document->path);
    }

    public function test_registration_cannot_create_an_admin_or_preapprove_an_evaluator(): void
    {
        $data = ['name' => 'Community Member', 'email' => 'new-member@example.test',
            'password' => 'A-long-test-password!2026', 'password_confirmation' => 'A-long-test-password!2026', 'terms' => '1'];

        $this->postJson(route('register'), $data + ['role' => 'admin'])
            ->assertUnprocessable()->assertJsonValidationErrors('role');
        $this->assertDatabaseMissing('users', ['email' => $data['email']]);

        $this->post(route('register'), $data + ['role' => 'evaluator', 'evaluator_status' => 'approved', 'is_suspended' => false])
            ->assertSessionHasNoErrors()->assertRedirect(route('account.evaluator-verification.show'));

        $evaluator = User::where('email', $data['email'])->sole();
        $this->assertSame('evaluator', $evaluator->role);
        $this->assertSame('pending', $evaluator->evaluator_status);
        $this->assertFalse($evaluator->isEvaluator());
    }

    public function test_only_admins_can_approve_evaluators_and_projects(): void
    {
        $student = User::factory()->create();
        $evaluator = User::factory()->pendingEvaluator()->create();
        $project = Project::factory()->create(['user_id' => $student->id]);
        DocumentVersion::factory()->create(['project_id' => $project->id]);

        $this->actingAs($student)->get(route('admin.index'))->assertForbidden();
        $this->post(route('admin.evaluators.moderate', $evaluator), ['decision' => 'approve'])->assertForbidden();
        $this->post(route('admin.projects.moderate', $project), ['decision' => 'approve'])->assertForbidden();
        $this->assertSame('pending', $evaluator->fresh()->evaluator_status);
        $this->assertSame('pending', $project->fresh()->status);

        $admin = User::factory()->admin()->create();
        $verification = $this->createVerification($evaluator);
        $this->actingAs($admin)->post(route('admin.evaluators.moderate', $evaluator), $this->approvalData($verification))
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->post(route('admin.projects.moderate', $project), ['decision' => 'approve'])
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->assertTrue($evaluator->fresh()->isEvaluator());
        $this->assertSame('approved', $project->fresh()->status);
    }

    public function test_review_requests_require_both_an_approved_project_and_an_approved_evaluator(): void
    {
        $project = Project::factory()->create();
        $document = DocumentVersion::factory()->create(['project_id' => $project->id]);
        $evaluator = User::factory()->evaluator()->create();
        $data = ['evaluator_id' => $evaluator->id, 'document_version_id' => $document->id,
            'message' => 'Please check the research methodology and database design.'];

        $this->actingAs($project->owner)->postJson(route('reviews.store', $project), $data)
            ->assertUnprocessable()->assertJsonValidationErrors('project');

        $project->update(['status' => 'approved']);
        $evaluator->update(['evaluator_status' => 'pending']);
        $this->postJson(route('reviews.store', $project), $data)
            ->assertUnprocessable()->assertJsonValidationErrors('evaluator_id');
        $this->assertDatabaseCount('review_requests', 0);

        $evaluator->update(['evaluator_status' => 'approved']);
        $this->post(route('reviews.store', $project), $data)->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseHas('review_requests', ['project_id' => $project->id, 'evaluator_id' => $evaluator->id, 'status' => 'pending']);
    }

    public function test_pdf_access_requires_acceptance_and_is_limited_to_the_reviewed_document_version(): void
    {
        Storage::fake('local');
        $review = ReviewRequest::factory()->create();
        $document = $review->document;
        Storage::disk('local')->put($document->path, $this->pdf());
        $revision = DocumentVersion::factory()->create(['project_id' => $review->project_id]);
        Storage::disk('local')->put($revision->path, $this->pdf());

        $this->actingAs(User::factory()->create())->get(route('documents.download', $document))->assertForbidden();
        $this->actingAs($review->evaluator)->get(route('documents.download', $document))->assertForbidden();
        $this->post(route('reviews.respond', $review), ['decision' => 'accept'])
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->get(route('documents.download', $document))->assertOk()->assertDownload('thesis.pdf');
        $this->get(route('documents.download', $revision))->assertForbidden();

        $review->project->update(['status' => 'hidden']);
        $this->get(route('documents.download', $document))->assertForbidden();
    }

    public function test_an_evaluator_cannot_download_or_accept_another_evaluators_request(): void
    {
        Storage::fake('local');
        $review = ReviewRequest::factory()->create();
        Storage::disk('local')->put($review->document->path, $this->pdf());

        $this->actingAs(User::factory()->evaluator()->create())
            ->post(route('reviews.respond', $review), ['decision' => 'accept'])->assertForbidden();
        $this->get(route('documents.download', $review->document))->assertForbidden();
        $this->assertSame('pending', $review->fresh()->status);
    }

    public function test_the_review_workflow_keeps_feedback_on_its_original_version_and_requires_a_final_summary(): void
    {
        $review = ReviewRequest::factory()->create();
        $this->actingAs($review->evaluator)->post(route('reviews.respond', $review), ['decision' => 'accept'])
            ->assertSessionHasNoErrors()->assertRedirect();

        $this->postJson(route('reviews.complete', $review))->assertUnprocessable()->assertJsonValidationErrors('final_summary');
        $this->assertSame('accepted', $review->fresh()->status);

        $this->post(route('reviews.feedback', $review), [
            'type' => 'recommendation', 'section' => 'Chapter 3: Methodology', 'page' => 12,
            'body' => 'Explain the participant selection criteria and connect each evaluation task to a specific research objective.',
            'priority' => 'major',
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->post(route('reviews.complete', $review), ['final_summary' => 'Clarify participant selection and connect each evaluation task to its research objective before conducting the final assessment.'])
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('completed', $review->fresh()->status);
        $this->assertNotNull($review->fresh()->completed_at);

        $feedback = $review->feedback()->sole();
        $this->actingAs($review->requester)->post(route('feedback.addressed', $feedback), ['addressed' => true])
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->post(route('reviews.feedback', $review), ['type' => 'question',
            'body' => 'Would a task-based observation guide help us justify our usability findings?'])
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->assertTrue($feedback->fresh()->addressed);

        DocumentVersion::factory()->create(['project_id' => $review->project_id]);
        $this->assertSame($review->document_version_id, $feedback->reviewRequest->document_version_id);
        $this->assertDatabaseCount('feedback', 2);
        $this->post(route('reviews.rate', $review), ['rating' => 5, 'comment' => 'Specific and useful recommendations.'])
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseHas('ratings', ['review_request_id' => $review->id, 'user_id' => $review->requested_by,
            'evaluator_id' => $review->evaluator_id, 'rating' => 5]);
    }

    public function test_members_cannot_submit_evaluator_recommendations_or_review_their_own_project(): void
    {
        $review = ReviewRequest::factory()->accepted()->create();
        $this->actingAs($review->requester)->postJson(route('reviews.feedback', $review), [
            'type' => 'recommendation', 'body' => 'This recommendation is being submitted by the student, not an evaluator.',
        ])->assertUnprocessable()->assertJsonValidationErrors('type');
        $this->assertDatabaseCount('feedback', 0);

        $review->project->members()->attach($review->evaluator);
        $this->actingAs($review->evaluator)->postJson(route('reviews.feedback', $review), [
            'type' => 'recommendation', 'body' => 'An evaluator must be independent of the thesis group they review.',
        ])->assertForbidden();
    }

    public function test_legacy_admin_questions_refresh_activity_without_restoring_the_follow_up_form(): void
    {
        $this->freezeTime();
        $review = ReviewRequest::factory()->accepted()->create(['updated_at' => now()->subDay()]);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('reviews.show', $review))
            ->assertOk()->assertDontSee('action="'.route('reviews.feedback', $review).'"', false)
            ->assertSee(route('messages.compose', $review->evaluator), false);
        $this->post(route('reviews.feedback', $review), [
            'type' => 'question',
            'body' => 'Could the group clarify the specific section that needs this recommendation?',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame(now()->toDateTimeString(), $review->fresh()->updated_at->toDateTimeString());
        $this->assertDatabaseHas('feedback', ['review_request_id' => $review->id, 'user_id' => $admin->id, 'type' => 'question']);
        $this->postJson(route('reviews.feedback', $review), [
            'type' => 'recommendation', 'body' => 'An administrator is moderating rather than acting as the assigned evaluator.',
        ])->assertUnprocessable()->assertJsonValidationErrors('type');
    }

    public function test_public_project_listing_hides_pending_and_private_projects(): void
    {
        $public = Project::factory()->publicListing()->create(['title' => 'Visible community project']);
        $pending = Project::factory()->create(['title' => 'Pending confidential project', 'is_public' => true]);
        $private = Project::factory()->approved()->create(['title' => 'Approved private project']);

        $this->get(route('projects.index'))->assertOk()->assertSee($public->title)
            ->assertDontSee($pending->title)->assertDontSee($private->title);
        $this->get(route('projects.show', $pending))->assertNotFound();
        $this->get(route('projects.show', $private))->assertNotFound();
        $this->actingAs($private->owner)->get(route('projects.show', $private))->assertRedirect(route('account.projects.show', $private));
        $this->get(route('account.projects.show', $private))->assertOk();
    }

    public function test_reporter_identity_is_private_and_report_resolution_notifies_only_the_reporter(): void
    {
        $project = Project::factory()->publicListing()->create();
        $reporter = User::factory()->create(['name' => 'Private Reporter Identity', 'email' => 'private-reporter@example.test']);
        $admin = User::factory()->admin()->create();

        $this->actingAs($reporter)->post(route('reports.store'), [
            'reportable_type' => 'project', 'reportable_id' => $project->id,
            'reason' => 'fake_information', 'details' => 'Please verify that the information is accurate.',
        ])->assertSessionHasNoErrors()->assertRedirect();
        $report = Report::sole();
        $this->assertSame($reporter->id, $report->user_id);

        $this->actingAs($project->owner)->get(route('projects.show', $project))->assertOk()
            ->assertDontSee($reporter->name)->assertDontSee($reporter->email);
        $this->get(route('admin.index', ['section' => 'reports']))->assertForbidden();
        $this->assertSame(0, $project->owner->activities()->count());

        $this->actingAs($admin)->post(route('admin.reports.moderate', $report), [
            'status' => 'resolved', 'action' => 'dismiss',
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('resolved', $report->fresh()->status);
        $this->assertNotNull($report->fresh()->resolved_at);
        $this->assertSame(0, $project->owner->activities()->count());
        $this->assertSame(1, $reporter->activities()->count());
    }

    private function pdf(): string
    {
        return "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF";
    }
}

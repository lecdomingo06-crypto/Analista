<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\DirectMessage;
use App\Models\DocumentVersion;
use App\Models\Project;
use App\Models\ReviewRequest;
use App\Models\User;
use App\Support\ProjectAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProjectReviewCompletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_completed_projects_remain_in_shared_listings_and_have_a_scoped_my_projects_filter(): void
    {
        $owner = User::factory()->create();
        $project = Project::factory()->publicListing()->create(['user_id' => $owner->id, 'title' => 'Completed campus thesis']);
        $review = ReviewRequest::factory()->accepted()->create(['project_id' => $project->id]);
        $private = Project::factory()->create(['user_id' => $owner->id, 'status' => 'completed', 'title' => 'Private completed thesis']);
        $ready = Project::factory()->publicListing()->create(['user_id' => $owner->id, 'title' => 'Still ready for review']);
        $other = Project::factory()->create(['status' => 'completed', 'title' => 'Unrelated completed thesis']);
        Project::factory()->publicListing()->create(['status' => 'hidden', 'title' => 'Hidden thesis']);

        $this->actingAs($review->evaluator)->post(route('reviews.complete', $review), ['final_summary' => $this->summary()])
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('completed', $project->fresh()->status);
        $this->actingAs($owner)->get(route('account.projects', ['status' => 'completed']))->assertOk()
            ->assertViewHas('projects', fn ($items) => $items->pluck('id')->sort()->values()->all() === [$project->id, $private->id])
            ->assertSee($project->title)->assertSee($private->title)->assertDontSee($ready->title)->assertDontSee($other->title);
        $this->get(route('evaluators.show', $review->evaluator))->assertOk()
            ->assertViewHas('projects', fn ($items) => $items->modelKeys() === [$ready->id]);

        auth()->logout();
        $this->get(route('projects.index'))->assertOk()->assertSee($project->title)->assertSee($ready->title)
            ->assertDontSee($private->title)->assertDontSee('Hidden thesis');
        $this->get(route('projects.show', $project))->assertOk()->assertDontSee($this->summary());
        $this->get(route('projects.show', $private))->assertNotFound();
        $this->get(route('home'))->assertOk()->assertViewHas('stats', fn ($stats) => $stats['projects'] === 2);
    }

    public function test_completion_closes_new_requests_and_pending_acceptance_but_allows_an_existing_accepted_evaluator_to_finish(): void
    {
        Storage::fake('local');
        $first = ReviewRequest::factory()->accepted()->create();
        $project = $first->project;
        $second = ReviewRequest::factory()->accepted()->create(['project_id' => $project->id]);
        $pending = ReviewRequest::factory()->create(['project_id' => $project->id]);
        foreach ([$first->document, $second->document, $pending->document] as $document) {
            Storage::disk('local')->put($document->path, $this->pdf());
        }
        $this->actingAs($first->evaluator)->post(route('reviews.complete', $first), ['final_summary' => $this->summary()])
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->actingAs($second->evaluator)->get(route('documents.download', $second->document))->assertOk();
        $this->get(route('documents.download', $first->document))->assertForbidden();
        $this->post(route('reviews.complete', $second), ['final_summary' => $this->summary()])
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('completed', $second->fresh()->status);
        $this->assertSame('completed', $project->fresh()->status);
        $this->assertTrue($project->fresh()->canAccess($second->evaluator));
        $this->assertTrue(ProjectAccess::canViewPrivate($project->fresh(), $second->evaluator));
        $this->actingAs($pending->evaluator)->postJson(route('reviews.respond', $pending), ['decision' => 'accept'])
            ->assertUnprocessable()->assertJsonValidationErrors('decision');
        $this->assertSame('pending', $pending->fresh()->status);
        $this->get(route('documents.download', $pending->document))->assertForbidden();
        $this->post(route('reviews.respond', $pending), ['decision' => 'decline'])
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('declined', $pending->fresh()->status);

        $this->actingAs($project->owner)->postJson(route('reviews.store', $project), [
            'evaluator_id' => $pending->evaluator_id, 'document_version_id' => $first->document_version_id,
            'message' => 'Please review this already completed thesis project.',
        ])->assertUnprocessable()->assertJsonValidationErrors('project');
        $this->get(route('reviews.prepare', ['project_id' => $project->id, 'evaluator_id' => $pending->evaluator_id]))
            ->assertForbidden();
        $this->assertSame(3, $project->reviewRequests()->count());
    }

    public function test_completion_keeps_private_downloads_message_cards_ratings_and_chat_available(): void
    {
        Storage::fake('local');
        $review = ReviewRequest::factory()->accepted()->create();
        Storage::disk('local')->put($review->document->path, $this->pdf());
        $this->actingAs($review->evaluator)->post(route('reviews.complete', $review), [
            'final_summary' => $this->summary(), 'annotated_pdf' => UploadedFile::fake()->createWithContent('review.pdf', $this->pdf()),
        ])->assertSessionHasNoErrors()->assertRedirect();
        $review->refresh();
        $this->get(route('documents.download', $review->document))->assertOk();
        $this->get(route('reviews.output', $review))->assertOk();
        $this->get(route('reviews.show', $review))->assertOk()->assertViewHas('canViewDocument', true)->assertViewHas('canViewOutput', true);
        $card = DirectMessage::where('review_request_id', $review->id)->where('review_event', 'completed')->sole();
        $this->assertTrue($card->reviewCardFor($review->evaluator)['available']);
        $this->assertTrue($card->reviewCardFor($review->requester)['available']);
        $this->actingAs($review->requester)->get(route('account.projects.show', $review->project))->assertOk()->assertSee($this->summary());
        $this->get(route('reviews.output', $review))->assertOk();
        $this->post(route('reviews.rate', $review), ['rating' => 5])->assertSessionHasNoErrors()->assertRedirect();
        $this->post(route('messages.store', $card->direct_conversation_id), ['body' => 'Thank you. Can you clarify the methodology recommendation?'])
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(2, $card->conversation->messages()->count());
        $review->project->update(['status' => 'hidden']);
        $this->assertFalse($card->fresh()->reviewCardFor($review->evaluator)['available']);
        $this->actingAs($review->evaluator)->get(route('documents.download', $review->document))->assertForbidden();
        $this->get(route('reviews.output', $review))->assertForbidden();
        $this->actingAs(User::factory()->create())->get(route('projects.show', $review->project))->assertNotFound();
    }

    public function test_editing_sharing_and_uploading_do_not_reopen_completed_or_hidden_projects(): void
    {
        Storage::fake('local');
        $review = ReviewRequest::factory()->completed()->create();
        $project = $review->project;
        $project->update(['status' => 'completed']);
        $this->actingAs($project->owner)->put(route('projects.update', $project), $this->projectData() + ['is_public' => true, 'status' => 'approved'])
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('completed', $project->fresh()->status);
        $this->assertTrue($project->fresh()->is_public);
        $this->post(route('projects.upload', $project), ['document' => UploadedFile::fake()->createWithContent('revision.pdf', $this->pdf())])
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('completed', $project->fresh()->status);
        $this->assertSame(2, $project->documents()->count());
        $project->update(['status' => 'needs_changes']);
        $this->put(route('projects.update', $project), $this->projectData())->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('completed', $project->fresh()->status);
        $project->update(['status' => 'hidden']);
        $this->put(route('projects.update', $project), $this->projectData() + ['status' => 'approved'])
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('hidden', $project->fresh()->status);
    }

    public function test_admin_restoration_preserves_completed_lifecycle_and_public_completed_projects_can_be_reported(): void
    {
        $review = ReviewRequest::factory()->completed()->create();
        $project = $review->project;
        $project->update(['status' => 'completed', 'is_public' => true]);
        $reporter = User::factory()->create();
        $this->actingAs($reporter)->post(route('reports.store'), [
            'reportable_type' => 'project', 'reportable_id' => $project->id, 'reason' => 'fake_information',
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseHas('reports', ['reportable_id' => $project->id, 'user_id' => $reporter->id]);
        $this->actingAs(User::factory()->admin()->create())->post(route('admin.projects.moderate', $project), [
            'decision' => 'hide', 'reason' => 'Temporarily reviewing the reported project summary.',
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('hidden', $project->fresh()->status);
        $this->post(route('admin.projects.moderate', $project), ['decision' => 'approve'])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('completed', $project->fresh()->status);
        $this->assertSame('completed', $review->fresh()->status);
        $this->assertFalse($project->owner->activities()->where('body', 'like', '%You can now request a volunteer evaluator%')->exists());
    }

    public function test_migration_marks_only_approved_projects_with_completed_reviews_and_preserves_all_review_data(): void
    {
        $eligible = [];
        foreach ([false, true] as $public) {
            $review = ReviewRequest::factory()->completed()->create(['final_summary' => $this->summary()]);
            $project = $review->project;
            $project->update(['is_public' => $public]);
            $eligible[] = [$project, $project->fresh()->getAttributes(), $review->fresh()->getAttributes()];
        }
        $untouched = [];
        foreach (['draft', 'hidden', 'pending', 'rejected', 'needs_changes', 'completed'] as $status) {
            $review = ReviewRequest::factory()->completed()->create();
            $review->project->update(['status' => $status]);
            $untouched[] = [$review->project, $status];
        }
        $open = ReviewRequest::factory()->accepted()->create();
        $untouched[] = [$open->project, 'approved'];
        $migration = require database_path('migrations/2026_10_26_000003_mark_reviewed_projects_completed.php');
        $migration->up();
        $migration->up();
        $migration->down();
        foreach ($eligible as [$project, $attributes, $reviewAttributes]) {
            $this->assertSame([...$attributes, 'status' => 'completed'], $project->fresh()->getAttributes());
            $this->assertSame($reviewAttributes, $project->reviewRequests()->sole()->getAttributes());
        }
        foreach ($untouched as [$project, $status]) {
            $this->assertSame($status, $project->fresh()->status);
        }
        $this->assertSame(0, Activity::count());
        $this->assertSame(0, DirectMessage::count());
    }

    private function projectData(): array
    {
        return ['title' => 'Completed campus parking research', 'summary' => 'A thesis evaluation of a parking system using plate recognition and available spaces.',
            'school' => 'Campus University', 'course' => 'BS Information Technology', 'category' => 'Web',
            'feedback_needed' => 'Please review the evaluation methodology and limitations.'];
    }

    private function summary(): string
    {
        return 'Clarify the evaluation methodology, describe the participant selection criteria, and explain the limitations of the completed prototype.';
    }

    private function pdf(): string
    {
        return "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF";
    }
}

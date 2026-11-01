<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\DirectMessage;
use App\Models\DocumentVersion;
use App\Models\Project;
use App\Models\ReviewRequest;
use App\Models\User;
use App\Services\ReviewDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class ReviewWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_request_acceptance_and_completion_deliver_once_and_the_conversation_remains_open(): void
    {
        $project = Project::factory()->approved()->create();
        $document = DocumentVersion::factory()->create(['project_id' => $project->id]);
        $evaluator = User::factory()->evaluator()->create();
        $member = User::factory()->create();
        $project->members()->attach($member);

        $this->actingAs($project->owner)->post(route('reviews.store', $project), [
            'evaluator_id' => $evaluator->id, 'document_version_id' => $document->id,
            'message' => 'Please review our study design and system evaluation approach.',
        ])->assertSessionHasNoErrors()->assertRedirect();
        $review = ReviewRequest::sole();
        $this->assertSame(1, DirectMessage::where('review_event', 'requested')->count());
        $this->assertSame(1, $evaluator->activities()->count());
        $this->actingAs($evaluator)->post(route('reviews.respond', $review), [
            'decision' => 'accept', 'agreed_date' => now()->addWeek()->toDateString(),
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('accepted', $review->fresh()->status);
        $this->assertSame(2, DirectMessage::where('review_event', 'accepted')->count());
        $this->assertSame(1, $project->owner->activities()->count());
        $this->assertSame(1, $member->activities()->count());

        $messageCount = DirectMessage::count();
        $activityCount = Activity::count();
        $this->postJson(route('reviews.respond', $review), ['decision' => 'accept'])
            ->assertUnprocessable()->assertJsonValidationErrors('decision');
        $this->assertSame($messageCount, DirectMessage::count());
        $this->assertSame($activityCount, Activity::count());

        $this->post(route('reviews.complete', $review), ['final_summary' => '  '.$this->summary().'  '])
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('completed', $review->fresh()->status);
        $this->assertSame('completed', $project->fresh()->status);
        $this->assertSame($this->summary(), $review->fresh()->final_summary);
        $this->assertNotNull($review->fresh()->completed_at);
        $this->assertSame(2, DirectMessage::where('review_event', 'completed')->count());
        $this->assertSame(2, $project->owner->activities()->count());
        $this->assertSame(2, $member->activities()->count());
        $messageCount = DirectMessage::count();
        $activityCount = Activity::count();
        $this->postJson(route('reviews.complete', $review), ['final_summary' => $this->summary()])
            ->assertUnprocessable()->assertJsonValidationErrors('review');
        $this->assertSame($messageCount, DirectMessage::count());
        $this->assertSame($activityCount, Activity::count());

        $card = DirectMessage::where('review_event', 'completed')->where('recipient_id', $project->user_id)->sole();
        $this->actingAs($project->owner)->post(route('messages.store', $card->direct_conversation_id), [
            'body' => 'Thank you. Could we discuss the recommended evaluation tasks?',
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseHas('direct_messages', ['direct_conversation_id' => $card->direct_conversation_id,
            'sender_id' => $project->user_id, 'body' => 'Thank you. Could we discuss the recommended evaluation tasks?']);
        $this->assertSame($document->id, $review->fresh()->document_version_id);
    }

    public function test_declining_delivers_a_card_and_notification_without_granting_document_access(): void
    {
        $review = ReviewRequest::factory()->create();
        $this->actingAs($review->evaluator)->post(route('reviews.respond', $review), ['decision' => 'decline'])
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('declined', $review->fresh()->status);
        $this->assertDatabaseHas('direct_messages', ['review_request_id' => $review->id, 'review_event' => 'declined', 'recipient_id' => $review->requested_by]);
        $this->assertSame(1, $review->requester->activities()->count());
        $this->get(route('documents.download', $review->document))->assertForbidden();
    }

    public function test_completion_can_supply_the_written_review_without_a_separate_feedback_post(): void
    {
        $review = ReviewRequest::factory()->accepted()->create();
        $this->assertSame(0, $review->feedback()->count());
        $this->actingAs($review->evaluator)->post(route('reviews.complete', $review), ['final_summary' => $this->summary()])
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame($this->summary(), $review->fresh()->final_summary);
        $this->assertNull($review->fresh()->output_path);
    }

    public function test_final_summary_and_annotated_pdf_are_validated_before_completion(): void
    {
        Storage::fake('local');
        $review = ReviewRequest::factory()->accepted()->create();
        $this->actingAs($review->evaluator);
        foreach ([null, '   ', str_repeat('x', 29), str_repeat('x', 10001)] as $summary) {
            $this->postJson(route('reviews.complete', $review), ['final_summary' => $summary])
                ->assertUnprocessable()->assertJsonValidationErrors('final_summary');
        }
        $this->postJson(route('reviews.complete', $review), [
            'final_summary' => $this->summary(),
            'annotated_pdf' => UploadedFile::fake()->createWithContent('disguised.pdf', 'This is not a PDF.')->mimeType('application/pdf'),
        ])->assertUnprocessable()->assertJsonValidationErrors('annotated_pdf');
        $this->postJson(route('reviews.complete', $review), [
            'final_summary' => $this->summary(), 'annotated_pdf' => UploadedFile::fake()->create('large.pdf', 20481, 'application/pdf'),
        ])->assertUnprocessable()->assertJsonValidationErrors('annotated_pdf');
        $this->assertSame('accepted', $review->fresh()->status);
        $this->assertNull($review->fresh()->final_summary);
        $this->assertSame([], Storage::disk('local')->allFiles('review-outputs'));
        $this->assertDatabaseCount('direct_messages', 0);
        $this->assertDatabaseCount('activities', 0);
    }

    public function test_annotated_pdf_is_private_version_bound_and_never_replaces_the_original_thesis(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $review = ReviewRequest::factory()->accepted()->create();
        $this->get(route('reviews.output', $review))->assertRedirect(route('login'));
        $original = $review->document;
        Storage::disk('local')->put($original->path, $this->pdf('Original thesis'));
        $otherVersion = DocumentVersion::factory()->create(['project_id' => $review->project_id]);
        Storage::disk('local')->put($otherVersion->path, $this->pdf('Later revision'));
        $this->actingAs($review->evaluator)->post(route('reviews.complete', $review), [
            'final_summary' => $this->summary(),
            'annotated_pdf' => UploadedFile::fake()->createWithContent('annotated-thesis.pdf', $this->pdf('Evaluator annotations')),
            'output_path' => $original->path, 'document_version_id' => $otherVersion->id,
        ])->assertSessionHasNoErrors()->assertRedirect();
        $completed = $review->fresh();
        $this->assertSame($original->id, $completed->document_version_id);
        $this->assertSame($this->pdf('Original thesis'), Storage::disk('local')->get($original->path));
        $this->assertSame($this->pdf('Later revision'), Storage::disk('local')->get($otherVersion->path));
        $this->assertSame($this->pdf('Evaluator annotations'), Storage::disk('local')->get($completed->safeOutputPath()));
        $this->assertSame(strlen($this->pdf('Evaluator annotations')), $completed->output_size);
        Storage::disk('public')->assertMissing($completed->output_path);
        $this->get(route('reviews.output', $review))->assertOk()->assertDownload('annotated-thesis.pdf')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->actingAs($review->requester)->get(route('reviews.output', $review))
            ->assertOk()->assertDownload('annotated-thesis.pdf');
        $member = User::factory()->create();
        $review->project->members()->attach($member);
        $this->actingAs($member)->get(route('reviews.output', $review))->assertOk();
        $this->actingAs(User::factory()->admin()->create())->get(route('reviews.output', $review))->assertOk();
        $this->actingAs(User::factory()->create())->get(route('reviews.output', $review))->assertForbidden();
        $this->actingAs(User::factory()->evaluator()->create())->get(route('reviews.output', $review))->assertForbidden();
        $review->project->update(['status' => 'hidden']);
        $this->actingAs($review->evaluator)->get(route('reviews.output', $review))->assertForbidden();
        $this->actingAs($review->requester)->get(route('reviews.output', $review))->assertOk();
        $review->requester->update(['is_suspended' => true]);
        $this->actingAs($review->requester)->get(route('reviews.output', $review))->assertRedirect(route('login'));
    }

    public function test_completed_output_cannot_be_replaced_and_unsafe_paths_cannot_be_downloaded(): void
    {
        Storage::fake('local');
        $review = ReviewRequest::factory()->accepted()->create();
        $this->actingAs($review->evaluator)->post(route('reviews.complete', $review), [
            'final_summary' => $this->summary(), 'annotated_pdf' => UploadedFile::fake()->createWithContent('first.pdf', $this->pdf('First output')),
        ])->assertSessionHasNoErrors()->assertRedirect();
        $path = $review->fresh()->output_path;
        $this->postJson(route('reviews.complete', $review), [
            'final_summary' => 'This is a different final summary that must not replace the sent review.',
            'annotated_pdf' => UploadedFile::fake()->createWithContent('replacement.pdf', $this->pdf('Replacement')),
        ])->assertUnprocessable()->assertJsonValidationErrors('review');
        $this->assertSame($path, $review->fresh()->output_path);
        $this->assertSame($this->summary(), $review->fresh()->final_summary);
        $this->assertCount(1, Storage::disk('local')->allFiles('review-outputs'));
        Storage::disk('local')->put('unrelated/private.pdf', $this->pdf('Unrelated private file'));
        foreach (['unrelated/private.pdf', '../unrelated/private.pdf', 'review-outputs/'.($review->id + 1).'/'.str_repeat('x', 40).'.pdf'] as $unsafePath) {
            $review->update(['output_path' => $unsafePath]);
            $this->actingAs($review->requester)->get(route('reviews.output', $review))->assertNotFound();
        }
        Storage::disk('local')->assertExists('unrelated/private.pdf');
    }

    public function test_completion_requires_the_assigned_active_independent_evaluator_and_accepted_request(): void
    {
        $review = ReviewRequest::factory()->create();
        $data = ['final_summary' => $this->summary()];
        $this->actingAs($review->requester)->postJson(route('reviews.complete', $review), $data)->assertForbidden();
        $this->actingAs(User::factory()->evaluator()->create())->postJson(route('reviews.complete', $review), $data)->assertForbidden();
        $this->actingAs($review->evaluator)->postJson(route('reviews.complete', $review), $data)
            ->assertUnprocessable()->assertJsonValidationErrors('review');
        $review->update(['status' => 'accepted', 'accepted_at' => now()]);
        $review->project->members()->attach($review->evaluator);
        $this->postJson(route('reviews.complete', $review), $data)->assertForbidden();
        $review->project->members()->detach($review->evaluator);
        $review->project->update(['status' => 'hidden']);
        $this->postJson(route('reviews.complete', $review), $data)->assertUnprocessable()->assertJsonValidationErrors('review');
        $review->project->update(['status' => 'approved']);
        $review->evaluator->update(['evaluator_status' => 'pending']);
        $this->actingAs($review->evaluator->fresh())->postJson(route('reviews.complete', $review), $data)->assertForbidden();
        $review->evaluator->update(['evaluator_status' => 'approved', 'is_suspended' => true]);
        $this->actingAs($review->evaluator->fresh())->post(route('reviews.complete', $review), $data)->assertRedirect(route('login'));
        $this->assertSame('accepted', $review->fresh()->status);
        $this->assertNull($review->fresh()->final_summary);
    }

    public function test_failed_delivery_rolls_back_completion_notifications_and_the_new_output_file(): void
    {
        Storage::fake('local');
        $review = ReviewRequest::factory()->accepted()->create();
        Storage::disk('local')->put($review->document->path, $this->pdf('Original thesis'));
        $delivery = Mockery::mock(ReviewDelivery::class);
        $delivery->shouldReceive('deliver')->once()->withArgs(fn ($locked, $event) => $locked->id === $review->id && $event === 'completed')
            ->andReturnUsing(function () use ($review) {
                $review->requester->notifyActivity('Partial delivery', 'This must roll back.', '/messages');
                throw new RuntimeException('Simulated delivery failure.');
            });
        $this->app->instance(ReviewDelivery::class, $delivery);
        $this->withoutExceptionHandling();
        try {
            $this->actingAs($review->evaluator)->post(route('reviews.complete', $review), [
                'final_summary' => $this->summary(), 'annotated_pdf' => UploadedFile::fake()->createWithContent('annotations.pdf', $this->pdf('Annotations')),
            ]);
            $this->fail('A failing delivery must not report completion.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated delivery failure.', $exception->getMessage());
        }
        $this->assertSame('accepted', $review->fresh()->status);
        $this->assertNull($review->fresh()->completed_at);
        $this->assertSame('approved', $review->project->fresh()->status);
        $this->assertNull($review->fresh()->final_summary);
        $this->assertNull($review->fresh()->output_path);
        $this->assertDatabaseCount('activities', 0);
        $this->assertDatabaseCount('direct_messages', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('review-outputs'));
        $this->assertSame($this->pdf('Original thesis'), Storage::disk('local')->get($review->document->path));
    }

    public function test_only_student_project_members_can_rate_a_completed_review_and_edit_their_own_rating(): void
    {
        $review = ReviewRequest::factory()->accepted()->create();
        $member = User::factory()->create();
        $review->project->members()->attach($member);
        $this->actingAs($review->requester)->postJson(route('reviews.rate', $review), ['rating' => 5])->assertUnprocessable();
        $review->update(['status' => 'completed', 'completed_at' => now(), 'final_summary' => $this->summary()]);
        $this->post(route('reviews.rate', $review), ['rating' => 5, 'comment' => 'Clear and helpful recommendations.'])
            ->assertSessionHasNoErrors()->assertRedirect(route('reviews.show', $review).'#rating');
        $this->post(route('reviews.rate', $review), ['rating' => 4, 'comment' => 'Updated after discussing the review.'])
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->actingAs($member)->post(route('reviews.rate', $review), ['rating' => 5])
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(2, $review->ratings()->count());
        $this->assertDatabaseHas('ratings', ['review_request_id' => $review->id, 'user_id' => $review->requested_by,
            'evaluator_id' => $review->evaluator_id, 'rating' => 4, 'comment' => 'Updated after discussing the review.']);
        $this->actingAs(User::factory()->create())->postJson(route('reviews.rate', $review), ['rating' => 1])->assertForbidden();
        $review->project->members()->attach($review->evaluator);
        $this->actingAs($review->evaluator)->postJson(route('reviews.rate', $review), ['rating' => 5])->assertForbidden();
        $admin = User::factory()->admin()->create();
        $review->project->members()->attach($admin);
        $this->actingAs($admin)->postJson(route('reviews.rate', $review), ['rating' => 5])->assertForbidden();
        $member->update(['is_suspended' => true]);
        $this->actingAs($member)->post(route('reviews.rate', $review), ['rating' => 1])->assertRedirect(route('login'));
        $this->assertSame(2, $review->ratings()->count());
    }

    public function test_ratings_validate_stars_and_comment_without_overwriting_a_valid_rating(): void
    {
        $review = ReviewRequest::factory()->completed()->create();
        $this->actingAs($review->requester)->post(route('reviews.rate', $review), ['rating' => 4])
            ->assertSessionHasNoErrors()->assertRedirect();
        foreach ([0, 6, 1.5, 'five'] as $rating) {
            $this->postJson(route('reviews.rate', $review), ['rating' => $rating])
                ->assertUnprocessable()->assertJsonValidationErrors('rating');
        }
        $this->postJson(route('reviews.rate', $review), ['rating' => 5, 'comment' => str_repeat('x', 1501)])
            ->assertUnprocessable()->assertJsonValidationErrors('comment');
        $this->assertSame(4, $review->ratings()->sole()->rating);
    }

    private function summary(): string
    {
        return 'The proposed workflow is clear. Connect each evaluation task to a research objective, define participant selection, and document the limitations of the prototype.';
    }

    private function pdf(string $label): string
    {
        return "%PDF-1.4\n% ".$label."\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF";
    }
}

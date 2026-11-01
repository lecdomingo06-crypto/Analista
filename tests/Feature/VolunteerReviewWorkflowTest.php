<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\DirectMessage;
use App\Models\DocumentVersion;
use App\Models\Project;
use App\Models\ReviewRequest;
use App\Models\User;
use App\Services\ReviewDelivery;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class VolunteerReviewWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private string $ownedViews;
    private string $ownedDisk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $token = 'volunteer-review-workflow-'.bin2hex(random_bytes(8));
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
        try {
            parent::tearDown();
        } finally {
            foreach ($paths as $path) {
                (new Filesystem)->deleteDirectory($path);
            }
        }
    }

    public function test_volunteering_assigns_the_current_pdf_and_server_owned_fields_and_notifies_the_group_once(): void
    {
        $project = $this->project(2);
        $older = $project->documents()->sole();
        $latest = $this->document($project);
        $member = User::factory()->create();
        $project->members()->attach($member);
        $actor = User::factory()->evaluator()->create();
        $other = User::factory()->evaluator()->create();
        $foreign = $this->document(Project::factory()->approved()->create());

        $this->actingAs($actor)->post(route('reviews.volunteer', $project), $this->payload() + [
            'message' => '  I can review the system evaluation and methodology.  ',
            'evaluator_id' => $other->id, 'requested_by' => $actor->id,
            'document_version_id' => $foreign->id, 'status' => 'completed',
            'preferred_date' => now()->addYear()->toDateString(),
        ])->assertSessionHasNoErrors()->assertRedirect();
        $review = ReviewRequest::sole();
        $this->assertSame($actor->id, $review->evaluator_id);
        $this->assertSame($project->user_id, $review->requested_by);
        $this->assertSame($latest->id, $review->document_version_id);
        $this->assertSame('accepted', $review->status);
        $this->assertNotNull($review->accepted_at);
        $this->assertNull($review->completed_at);
        $this->assertSame($project->deadline->toDateString(), $review->preferred_date->toDateString());
        $this->assertSame($this->payload()['agreed_date'], $review->agreed_date->toDateString());
        $this->assertSame('I can review the system evaluation and methodology.', $review->message);
        $this->assertSame(2, DirectMessage::where('review_event', 'volunteered')->count());
        $this->assertSame(2, Activity::count());
        $this->assertSame([$project->user_id, $member->id], Activity::orderBy('user_id')->pluck('user_id')->all());
        $this->assertSame([$actor->id], Activity::distinct()->pluck('actor_id')->all());
        $event = DirectMessage::where('recipient_id', $project->user_id)->sole();
        $this->assertSame('Volunteer review started', $event->reviewCardFor($project->owner)['label']);
        $this->assertSame('volunteered', $event->reviewCardFor($actor)['event']);
        $this->get(route('documents.download', $latest))->assertOk();
        $this->get(route('documents.download', $older))->assertForbidden();
        $this->actingAs($other)->get(route('documents.download', $latest))->assertForbidden();
        $this->get(route('reviews.show', $review))->assertForbidden();
        $this->actingAs($member)->get(route('account.projects.show', $project))->assertOk()->assertSee($latest->original_name);
    }

    public function test_pdf_audience_is_separate_from_volunteering_and_never_grants_other_reviews(): void
    {
        $project = $this->project(2, ['pdf_access' => Project::PDF_ACCESS_APPROVED_EVALUATORS]);
        $existing = ReviewRequest::factory()->accepted()->create(['project_id' => $project->id]);
        Storage::disk('local')->put($existing->document->path, $this->pdf());
        $actor = User::factory()->evaluator()->create();
        $document = $project->documents()->oldest('version')->first();
        $this->actingAs($actor)->get(route('documents.download', $document))->assertOk();
        $this->assertSame(1, ReviewRequest::count());
        $this->get(route('reviews.show', $existing))->assertForbidden();
        $this->get(route('reviews.output', $existing))->assertForbidden();
        $this->post(route('reviews.volunteer', $project), $this->payload())->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(2, ReviewRequest::count());
        $this->get(route('reviews.show', $existing))->assertForbidden();
        $this->get(route('reviews.output', $existing))->assertForbidden();
    }

    public function test_only_approved_active_evaluators_can_volunteer(): void
    {
        $project = $this->project();
        $this->post(route('reviews.volunteer', $project), $this->payload())->assertRedirect(route('login'));
        foreach ([User::factory()->create(), User::factory()->admin()->create(), User::factory()->pendingEvaluator()->create(),
            User::factory()->evaluator()->create(['evaluator_status' => 'rejected'])] as $actor) {
            $this->actingAs($actor)->postJson(route('reviews.volunteer', $project), $this->payload())->assertForbidden();
        }
        $suspended = User::factory()->evaluator()->create(['is_suspended' => true]);
        $this->actingAs($suspended)->post(route('reviews.volunteer', $project), $this->payload())->assertRedirect(route('login'));
        $this->assertDatabaseCount('review_requests', 0);
        $this->assertDatabaseCount('activities', 0);
    }

    public function test_private_unavailable_closed_and_request_only_projects_do_not_accept_volunteers(): void
    {
        $this->actingAs(User::factory()->evaluator()->create());
        foreach (['draft', 'pending', 'needs_changes', 'rejected', 'hidden'] as $status) {
            $project = $this->project(1, ['status' => $status]);
            $this->postJson(route('reviews.volunteer', $project), $this->payload())->assertNotFound();
        }
        $private = $this->project(1, ['is_public' => false]);
        $this->postJson(route('reviews.volunteer', $private), $this->payload())->assertNotFound();
        foreach ([['status' => 'completed'], ['review_mode' => Project::REVIEW_MODE_REQUEST_ONLY]] as $attributes) {
            $project = $this->project(1, $attributes);
            $this->postJson(route('reviews.volunteer', $project), $this->payload())
                ->assertUnprocessable()->assertJsonValidationErrors('volunteer');
        }
        $this->assertDatabaseCount('review_requests', 0);
    }

    public function test_owners_members_uploaders_requesters_and_prior_contributors_cannot_review_their_own_work(): void
    {
        foreach (['owner', 'member', 'uploader', 'requester', 'feedback'] as $conflict) {
            $actor = User::factory()->evaluator()->create();
            $project = $this->project(2, $conflict === 'owner' ? ['user_id' => $actor->id] : []);
            if ($conflict === 'member') {
                $project->members()->attach($actor);
            } elseif ($conflict === 'uploader') {
                $project->documents()->update(['user_id' => $actor->id]);
            } elseif ($conflict === 'requester') {
                ReviewRequest::factory()->create(['project_id' => $project->id, 'requested_by' => $actor->id, 'status' => 'declined']);
            } elseif ($conflict === 'feedback') {
                $other = ReviewRequest::factory()->accepted()->create(['project_id' => $project->id]);
                $other->feedback()->create(['user_id' => $actor->id, 'type' => 'reply', 'body' => 'I contributed to this thesis.', 'priority' => 'suggestion', 'addressed' => false]);
            }
            $count = ReviewRequest::count();
            $this->actingAs($actor)->postJson(route('reviews.volunteer', $project), $this->payload())->assertForbidden();
            $this->assertSame($count, ReviewRequest::count());
        }
    }

    public function test_volunteers_must_be_available_and_below_their_accepted_review_capacity(): void
    {
        $project = $this->project();
        foreach ([['accepting_requests' => false], ['capacity' => 0]] as $attributes) {
            $actor = User::factory()->evaluator()->create($attributes);
            $this->actingAs($actor)->postJson(route('reviews.volunteer', $project), $this->payload())
                ->assertUnprocessable()->assertJsonValidationErrors('volunteer');
        }
        $actor = User::factory()->evaluator()->create(['capacity' => 1]);
        ReviewRequest::factory()->accepted()->create(['evaluator_id' => $actor->id]);
        $this->actingAs($actor)->postJson(route('reviews.volunteer', $project), $this->payload())
            ->assertUnprocessable()->assertJsonValidationErrors('volunteer');
        $actor = User::factory()->evaluator()->create(['capacity' => 1]);
        ReviewRequest::factory()->completed()->create(['evaluator_id' => $actor->id]);
        $this->actingAs($actor)->post(route('reviews.volunteer', $project), $this->payload())->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(1, $project->reviewRequests()->count());
    }

    public function test_agreed_date_and_optional_message_are_validated_without_creating_a_review(): void
    {
        $project = $this->project();
        $this->actingAs(User::factory()->evaluator()->create());
        foreach ([[], ['agreed_date' => 'not a date'], ['agreed_date' => now()->subDay()->toDateString()]] as $payload) {
            $this->postJson(route('reviews.volunteer', $project), $payload)->assertUnprocessable()->assertJsonValidationErrors('agreed_date');
        }
        foreach ([['message' => ['invalid']], ['message' => str_repeat('x', 3001)]] as $payload) {
            $this->postJson(route('reviews.volunteer', $project), $payload + $this->payload())->assertUnprocessable()->assertJsonValidationErrors('message');
        }
        $this->assertDatabaseCount('review_requests', 0);
        $this->post(route('reviews.volunteer', $project), ['agreed_date' => now()->toDateString(), 'message' => '  '])
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->assertNotEmpty(ReviewRequest::sole()->message);
    }

    public function test_latest_pdf_must_exist_and_be_valid_without_falling_back_to_an_old_version(): void
    {
        $this->actingAs(User::factory()->evaluator()->create());
        foreach (['none', 'missing', 'mime', 'contents', 'unsafe'] as $problem) {
            $project = $this->project();
            if ($problem === 'none') {
                $project->documents()->delete();
            } else {
                $latest = $this->document($project);
                if ($problem === 'missing') {
                    Storage::disk('local')->delete($latest->path);
                } elseif ($problem === 'mime') {
                    $latest->update(['mime_type' => 'text/plain']);
                } elseif ($problem === 'contents') {
                    Storage::disk('local')->put($latest->path, 'An invalid thesis PDF.');
                } else {
                    $latest->update(['path' => 'theses/../unrelated.pdf']);
                }
            }
            $this->postJson(route('reviews.volunteer', $project), $this->payload())
                ->assertUnprocessable()->assertJsonValidationErrors('document');
        }
        $this->assertDatabaseCount('review_requests', 0);
        $this->assertDatabaseCount('direct_messages', 0);
    }

    public function test_replayed_and_competing_last_slot_submissions_cannot_duplicate_or_oversubscribe(): void
    {
        $project = $this->project();
        $actor = User::factory()->evaluator()->create();
        $other = User::factory()->evaluator()->create();
        $this->actingAs($actor)->post(route('reviews.volunteer', $project), $this->payload())->assertSessionHasNoErrors()->assertRedirect();
        $this->postJson(route('reviews.volunteer', $project), $this->payload())->assertUnprocessable()->assertJsonValidationErrors('volunteer');
        $this->actingAs($other)->postJson(route('reviews.volunteer', $project), $this->payload())->assertUnprocessable()->assertJsonValidationErrors('volunteer');
        $this->assertSame(1, $project->occupiedReviewerCount());
        $this->assertDatabaseCount('review_requests', 1);
        $this->assertDatabaseCount('direct_messages', 1);
        $this->assertDatabaseCount('activities', 1);
    }

    public function test_completed_reviewers_still_occupy_slots_and_pending_invites_cannot_bypass_a_full_target(): void
    {
        $project = $this->project(2);
        ReviewRequest::factory()->completed()->create(['project_id' => $project->id]);
        $pending = ReviewRequest::factory()->create(['project_id' => $project->id]);
        Storage::disk('local')->put($pending->document->path, $this->pdf());
        $actor = User::factory()->evaluator()->create();
        $this->actingAs($pending->evaluator)->postJson(route('reviews.volunteer', $project), $this->payload())
            ->assertUnprocessable()->assertJsonValidationErrors('volunteer');
        $this->actingAs($actor)->post(route('reviews.volunteer', $project), $this->payload())->assertSessionHasNoErrors()->assertRedirect();
        $this->actingAs($pending->evaluator)->postJson(route('reviews.respond', $pending), ['decision' => 'accept'])
            ->assertUnprocessable()->assertJsonValidationErrors('decision');
        $this->assertSame('pending', $pending->fresh()->status);
        $this->post(route('reviews.respond', $pending), ['decision' => 'decline'])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('declined', $pending->fresh()->status);
        $this->assertSame(2, $project->occupiedReviewerCount());
    }

    public function test_manual_invites_are_blocked_in_volunteer_mode_but_existing_pending_requests_can_still_be_accepted(): void
    {
        $project = $this->project(2);
        $pending = ReviewRequest::factory()->create(['project_id' => $project->id]);
        $this->actingAs($project->owner)->postJson(route('reviews.store', $project), [
            'evaluator_id' => User::factory()->evaluator()->create()->id,
            'document_version_id' => $project->documents()->first()->id,
            'message' => 'Please review our thesis methodology and system architecture.',
        ])->assertUnprocessable()->assertJsonValidationErrors('project');
        $this->actingAs($pending->evaluator)->post(route('reviews.respond', $pending), ['decision' => 'accept', 'agreed_date' => $this->payload()['agreed_date']])
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('accepted', $pending->fresh()->status);
        $this->assertSame($pending->document_version_id, $pending->fresh()->document_version_id);
        $this->assertDatabaseCount('review_requests', 1);
    }

    public function test_targeted_completion_waits_for_all_volunteer_reviews_and_each_summary_stays_private(): void
    {
        $project = $this->project(3);
        $reviews = collect();
        for ($index = 0; $index < 3; $index++) {
            $actor = User::factory()->evaluator()->create();
            $this->actingAs($actor)->post(route('reviews.volunteer', $project), $this->payload())->assertSessionHasNoErrors()->assertRedirect();
            $reviews->push($project->reviewRequests()->where('evaluator_id', $actor->id)->sole());
        }
        foreach ($reviews as $index => $review) {
            $response = $this->actingAs($review->evaluator)->post(route('reviews.complete', $review), ['final_summary' => $this->summary()]);
            $response->assertSessionHasNoErrors()->assertRedirect();
            $this->assertSame($index === 2 ? 'completed' : 'approved', $project->fresh()->status);
            if ($index < 2) {
                $this->assertStringNotContainsString('project marked completed', session('success'));
            }
            $message = DirectMessage::where('review_request_id', $review->id)->where('review_event', 'completed')->sole();
            $this->assertStringNotContainsString('project is marked completed', $message->body);
            $this->assertStringNotContainsString('project is marked completed', $message->reviewCardFor($project->owner)['description']);
        }
        $this->actingAs($reviews[0]->evaluator)->get(route('reviews.show', $reviews[1]))->assertForbidden();
        $this->actingAs($project->owner)->get(route('reviews.show', $reviews[1]))->assertOk()->assertSee($this->summary());
        $this->actingAs(User::factory()->evaluator()->create())->get(route('projects.show', $project))->assertOk()->assertDontSee($this->summary());
        $this->assertSame(3, $project->completedReviewerCount());
        $this->assertSame(3, DirectMessage::where('review_event', 'completed')->count());
    }

    public function test_legacy_multiple_pdf_reviews_from_one_evaluator_must_all_finish_before_target_completion(): void
    {
        $project = $this->project();
        $actor = User::factory()->evaluator()->create();
        $first = ReviewRequest::factory()->accepted()->create(['project_id' => $project->id, 'evaluator_id' => $actor->id]);
        $second = ReviewRequest::factory()->accepted()->create(['project_id' => $project->id, 'evaluator_id' => $actor->id]);
        $this->actingAs($actor)->post(route('reviews.complete', $first), ['final_summary' => $this->summary()])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('approved', $project->fresh()->status);
        $this->assertSame(1, $project->completedReviewerCount());
        $this->assertSame(1, $project->occupiedReviewerCount());
        $this->post(route('reviews.complete', $second), ['final_summary' => $this->summary()])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('completed', $project->fresh()->status);
    }

    public function test_switching_back_to_request_only_preserves_existing_assigned_access_and_completion(): void
    {
        $project = $this->project(3);
        $actor = User::factory()->evaluator()->create();
        $this->actingAs($actor)->post(route('reviews.volunteer', $project), $this->payload())->assertSessionHasNoErrors()->assertRedirect();
        $review = $project->reviewRequests()->sole();
        $project->update(['review_mode' => Project::REVIEW_MODE_REQUEST_ONLY]);
        $this->get(route('documents.download', $review->document))->assertOk();
        $this->post(route('reviews.complete', $review), ['final_summary' => $this->summary()])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('completed', $project->fresh()->status);
        $this->get(route('reviews.show', $review))->assertOk()->assertSee($this->summary());
        $this->get(route('documents.download', $review->document))->assertOk();
    }

    public function test_delivery_failure_rolls_back_the_assignment_and_every_partial_notification(): void
    {
        $project = $this->project();
        $delivery = Mockery::mock(ReviewDelivery::class);
        $delivery->shouldReceive('deliver')->once()->withArgs(fn ($review, $event) => $review->project_id === $project->id && $event === 'volunteered')
            ->andReturnUsing(function () use ($project) {
                $project->owner->notifyActivity('Partial volunteer notification', 'This must roll back.', '/messages');
                throw new RuntimeException('Simulated volunteer delivery failure.');
            });
        $this->app->instance(ReviewDelivery::class, $delivery);
        $this->withoutExceptionHandling();
        try {
            $this->actingAs(User::factory()->evaluator()->create())->post(route('reviews.volunteer', $project), $this->payload());
            $this->fail('A failed delivery must roll back the volunteer assignment.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated volunteer delivery failure.', $exception->getMessage());
        }
        $this->assertDatabaseCount('review_requests', 0);
        $this->assertDatabaseCount('activities', 0);
        $this->assertDatabaseCount('direct_messages', 0);
        $this->assertSame('approved', $project->fresh()->status);
    }

    private function project(int $limit = 1, array $attributes = []): Project
    {
        $project = Project::factory()->publicListing()->create($attributes + [
            'review_mode' => Project::REVIEW_MODE_VOLUNTEER, 'reviewer_limit' => $limit,
        ]);
        $this->document($project);

        return $project;
    }

    private function document(Project $project): DocumentVersion
    {
        $document = DocumentVersion::factory()->create(['project_id' => $project->id, 'size' => strlen($this->pdf())]);
        Storage::disk('local')->put($document->path, $this->pdf());

        return $document;
    }

    private function payload(): array
    {
        return ['agreed_date' => now()->addWeek()->toDateString()];
    }

    private function summary(): string
    {
        return 'Clarify the evaluation methodology, describe participant selection, and explain the prototype limitations before the final defense.';
    }

    private function pdf(): string
    {
        return "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF";
    }
}

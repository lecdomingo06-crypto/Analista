<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\DirectConversation;
use App\Models\DirectMessage;
use App\Models\ReviewRequest;
use App\Models\User;
use App\Services\ReviewDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReviewMessageDeliveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_review_updates_reuse_pair_conversations_and_reach_each_current_project_member_once(): void
    {
        $review = ReviewRequest::factory()->create();
        $owner = $review->project->owner;
        $member = User::factory()->create();
        $removed = User::factory()->create();
        $review->project->members()->attach([$member->id, $removed->id, $owner->id]);
        $review->project->members()->detach($removed->id);
        $this->actingAs($owner)->postJson(route('messages.send', $review->evaluator), ['body' => 'A normal conversation before requesting review.'])->assertSuccessful();
        $original = DirectConversation::sole();

        $this->deliver($review, 'requested');
        $review->update(['status' => 'accepted', 'accepted_at' => now()]);
        $this->deliver($review, 'accepted');
        $this->deliver($review, 'accepted');
        $review->update(['status' => 'completed', 'completed_at' => now()]);
        $this->deliver($review, 'completed');
        $this->deliver($review, 'completed');

        $this->assertDatabaseCount('direct_conversations', 2);
        $this->assertDatabaseCount('direct_messages', 6);
        $this->assertDatabaseCount('activities', 5);
        $this->assertSame(1, DirectMessage::where('review_event', 'requested')->count());
        foreach (['accepted', 'completed'] as $event) {
            $this->assertSame([$owner->id, $member->id], DirectMessage::where('review_event', $event)->orderBy('recipient_id')->pluck('recipient_id')->all());
            $this->assertSame([$review->evaluator_id], DirectMessage::where('review_event', $event)->distinct()->pluck('sender_id')->all());
        }
        $this->assertSame(4, $original->messages()->count());
        $this->assertSame(0, Activity::where('user_id', $removed->id)->count());
        $this->assertSame(0, DirectMessage::where('recipient_id', $removed->id)->count());
        $this->assertSame(5, DirectMessage::where('kind', 'review_event')->whereNull('read_at')->count());
    }

    public function test_completed_cards_match_in_html_and_polling_without_copying_private_output_into_messages(): void
    {
        $title = 'Parking <script>alert("project")</script> & campus access';
        $review = ReviewRequest::factory()->completed()->create([
            'agreed_date' => now()->addDays(2)->toDateString(),
            'final_summary' => 'PRIVATE FINAL SUMMARY THAT MUST REMAIN ON THE REVIEW PAGE',
        ]);
        $review->project->update(['title' => $title, 'is_public' => false]);
        $this->deliver($review, 'completed');
        $message = DirectMessage::sole();
        $owner = $review->project->owner;
        $this->assertStringNotContainsString($title, $message->body);
        $this->assertStringNotContainsString('PRIVATE FINAL SUMMARY', $message->body);

        $html = $this->actingAs($owner)->get(route('messages.show', $message->conversation))->assertOk()
            ->assertSee('Review completed')->assertSee(e($title), false)->assertDontSee($title, false)
            ->assertSee('Rate evaluator')->assertSee(route('reviews.show', $review).'#rating', false)
            ->assertDontSee($review->final_summary)->assertDontSee($review->document->path);
        $this->assertStringContainsString('data-review-card', $html->getContent());
        $json = $this->getJson(route('messages.updates', ['conversation' => $message->conversation, 'after' => 0]))
            ->assertOk()->assertJsonPath('messages.0.kind', 'review_event')
            ->assertJsonPath('messages.0.review_card.project_title', $title)
            ->assertJsonPath('messages.0.review_card.status', 'Completed')
            ->assertJsonPath('messages.0.review_card.document_version', $review->document->version)
            ->assertJsonPath('messages.0.review_card.rating_url', route('reviews.show', $review).'#rating')
            ->assertJsonPath('messages.0.review_card.review_url', route('reviews.show', $review).'#review-summary');
        $this->assertStringNotContainsString($review->final_summary, $json->getContent());
        $this->assertStringNotContainsString($review->document->path, $json->getContent());
        $this->assertNotNull($message->fresh()->read_at);
        $this->assertNull(Activity::sole()->read_at);
        $this->assertNull($message->reviewCardFor($review->evaluator)['rating_url']);
    }

    public function test_removed_members_and_hidden_or_deleted_reviews_receive_only_generic_unavailable_cards(): void
    {
        $review = ReviewRequest::factory()->completed()->create();
        $review->project->update(['title' => 'PRIVATE PARKING PROJECT SENTINEL']);
        $member = User::factory()->create();
        $review->project->members()->attach($member);
        $this->deliver($review, 'completed');
        $message = DirectMessage::where('recipient_id', $member->id)->sole();
        $review->project->members()->detach($member);
        $this->actingAs($member);
        $this->assertUnavailable($message);

        $ownerMessage = DirectMessage::where('recipient_id', $review->project->user_id)->sole();
        $this->actingAs($review->project->owner);
        $review->project->update(['status' => 'hidden']);
        $this->assertUnavailable($ownerMessage);
        $review->delete();
        $this->assertNull($ownerMessage->fresh()->review_request_id);
        $this->assertUnavailable($ownerMessage);
    }

    public function test_request_cards_let_assigned_evaluators_open_the_queue_without_exposing_a_private_project_link(): void
    {
        $review = ReviewRequest::factory()->create();
        $review->project->update(['is_public' => false]);
        $this->deliver($review, 'requested');
        $message = DirectMessage::sole();
        $card = $message->reviewCardFor($review->evaluator);
        $this->assertTrue($card['available']);
        $this->assertSame('New review request', $card['label']);
        $this->assertSame('Awaiting response', $card['status']);
        $this->assertNull($card['project_url']);
        $this->assertNull($card['rating_url']);
        $this->assertSame(route('reviews.show', $review), $card['review_url']);
        $this->assertFalse($message->reviewCardFor(User::factory()->admin()->create())['available']);
        $this->actingAs($review->evaluator)->get(route('messages.show', $message->conversation))
            ->assertOk()->assertSee('View request')->assertDontSee('href="'.route('projects.show', $review->project).'"', false);
    }

    public function test_repeated_delivery_keeps_read_receipts_and_does_not_replace_a_newer_ordinary_message(): void
    {
        $review = ReviewRequest::factory()->accepted()->create();
        $this->deliver($review, 'accepted');
        $event = DirectMessage::sole();
        $event->update(['read_at' => now()]);
        $this->travel(10)->minutes();
        $this->actingAs($review->project->owner)->postJson(route('messages.store', $event->conversation), ['body' => 'Thank you, can we discuss the methodology?'])->assertSuccessful();
        $later = DirectMessage::latest('id')->firstOrFail();
        $lastAt = $event->conversation->fresh()->last_message_at;
        $this->deliver($review, 'accepted');
        $this->assertDatabaseCount('direct_messages', 2);
        $this->assertDatabaseCount('activities', 1);
        $this->assertNotNull($event->fresh()->read_at);
        $this->assertTrue($lastAt->equalTo($event->conversation->fresh()->last_message_at));
        $this->assertSame($later->id, $event->conversation->fresh()->latestMessage->id);
        $this->travelBack();
    }

    public function test_review_delivery_rolls_back_with_the_review_transition(): void
    {
        $review = ReviewRequest::factory()->create();
        try {
            DB::transaction(function () use ($review) {
                $review->update(['status' => 'accepted']);
                app(ReviewDelivery::class)->deliver($review, 'accepted');
                throw new \RuntimeException('Simulated transaction failure');
            });
            $this->fail('The simulated failure should escape the transaction.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Simulated transaction failure', $error->getMessage());
        }
        $this->assertSame('pending', $review->fresh()->status);
        $this->assertDatabaseCount('direct_conversations', 0);
        $this->assertDatabaseCount('direct_messages', 0);
        $this->assertDatabaseCount('activities', 0);
    }

    public function test_normal_chat_cannot_spoof_system_review_cards_and_remains_available_after_completion(): void
    {
        $review = ReviewRequest::factory()->completed()->create();
        $this->deliver($review, 'completed');
        $event = DirectMessage::sole();
        $owner = $review->project->owner;
        $this->actingAs($owner)->postJson(route('messages.store', $event->conversation), [
            'body' => 'A follow-up question after the review.', 'kind' => 'review_event',
            'review_request_id' => $review->id, 'review_event' => 'completed',
            'review_event_key' => 'forged-event-key',
        ])->assertSuccessful()->assertJsonPath('message.kind', 'text')->assertJsonPath('message.review_card', null);
        $normal = DirectMessage::latest('id')->firstOrFail();
        $this->assertSame('text', $normal->kind);
        $this->assertNull($normal->review_request_id);
        $this->assertNull($normal->review_event_key);
        $this->actingAs($review->evaluator)->postJson(route('messages.store', $event->conversation), ['body' => 'We can continue the discussion here.'])->assertSuccessful();
        $this->assertDatabaseCount('direct_messages', 3);
    }

    private function deliver(ReviewRequest $review, string $event): void
    {
        DB::transaction(fn () => app(ReviewDelivery::class)->deliver($review, $event));
    }

    private function assertUnavailable(DirectMessage $message): void
    {
        $this->get(route('messages.show', $message->conversation))->assertOk()
            ->assertSee('Review update unavailable')->assertDontSee('PRIVATE PARKING PROJECT SENTINEL')
            ->assertDontSee('Rate evaluator');
        $this->getJson(route('messages.updates', ['conversation' => $message->conversation, 'after' => 0]))
            ->assertOk()->assertJsonPath('messages.0.review_card', [
                'available' => false,
                'label' => 'Review update unavailable',
                'description' => 'This review is no longer available to your account.',
            ])->assertDontSee('PRIVATE PARKING PROJECT SENTINEL');
    }
}

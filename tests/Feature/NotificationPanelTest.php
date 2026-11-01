<?php

namespace Tests\Feature;

use App\Models\DirectConversation;
use App\Models\DirectMessage;
use App\Models\Project;
use App\Models\ReviewRequest;
use App\Models\User;
use App\Services\ReviewDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class NotificationPanelTest extends TestCase
{
    use RefreshDatabase;

    public function test_notification_state_and_read_actions_require_authentication_and_page_is_validated(): void
    {
        $user = User::factory()->create();
        $activity = $user->notifyActivity('Private update', 'Private body', '/account');
        $this->get(route('account.notifications.state'))->assertRedirect(route('login'));
        $this->getJson(route('account.notifications.state'))->assertUnauthorized();
        $this->postJson(route('account.notifications.read', $activity))->assertUnauthorized();
        $this->postJson(route('account.notifications.readall'))->assertUnauthorized();

        $this->actingAs($user);
        foreach ([0, -1, 'invalid', '1.5'] as $page) {
            $this->getJson(route('account.notifications.state', ['page' => $page]))
                ->assertUnprocessable()->assertJsonValidationErrors('page');
        }
        $this->getJson(route('account.notifications.state', ['page' => 1]))->assertOk();
        $this->assertNull($activity->fresh()->read_at);
    }

    public function test_state_paginates_only_owned_updates_with_stable_order_actual_unread_count_and_no_read_side_effects(): void
    {
        $this->freezeTime();
        $user = User::factory()->create();
        $other = User::factory()->create();
        $activities = collect();
        for ($index = 1; $index <= 23; $index++) {
            $activities->push($user->notifyActivity('Owned update '.$index, 'Owned body '.$index, '/account?tab=notifications'));
        }
        foreach ($activities->take(2) as $activity) {
            $activity->update(['read_at' => now()->subDay()]);
        }
        $other->notifyActivity('Another account secret title', 'Another account secret body', '/account');
        $before = $user->activities()->orderBy('id')->get()->map->getAttributes()->all();

        $first = $this->actingAs($user)->getJson(route('account.notifications.state', ['user_id' => $other->id]))
            ->assertOk()->assertJsonPath('user_id', $user->id)->assertJsonPath('unread_count', 21)
            ->assertJsonCount(20, 'activities')->assertJsonPath('conversations', [])
            ->assertJsonPath('next_page_url', route('account.notifications.state', ['page' => 2]))
            ->assertJsonMissing(['title' => 'Another account secret title'])
            ->assertJsonMissing(['body' => 'Another account secret body']);
        $this->assertPrivateJson($first);
        $this->assertSame($activities->reverse()->take(20)->pluck('id')->values()->all(), array_column($first->json('activities'), 'id'));
        $this->assertSame([
            'id', 'title', 'body', 'sender', 'url', 'created_at', 'created_label', 'read_at', 'read_url',
        ], array_keys($first->json('activities.0')));
        $this->assertSame(route('account.notifications.read', $activities->last()), $first->json('activities.0.read_url'));
        $this->assertSame($activities->last()->created_at->toIso8601String(), $first->json('activities.0.created_at'));

        $last = $this->getJson($first->json('next_page_url'))->assertOk()
            ->assertJsonCount(3, 'activities')->assertJsonPath('next_page_url', null)
            ->assertJsonPath('unread_count', 21);
        $this->assertSame($activities->take(3)->reverse()->pluck('id')->values()->all(), array_column($last->json('activities'), 'id'));
        $this->assertSame($before, $user->activities()->orderBy('id')->get()->map->getAttributes()->all());
    }

    public function test_conversations_merge_owned_group_and_assigned_reviews_without_other_or_removed_members_projects(): void
    {
        $this->freezeTime();
        $user = User::factory()->evaluator()->create();
        $owned = Project::factory()->create(['user_id' => $user->id, 'status' => 'hidden', 'title' => 'Owned hidden thesis']);
        $group = Project::factory()->approved()->create(['title' => 'Current group thesis']);
        $group->members()->attach($user);
        $assigned = Project::factory()->approved()->create(['title' => 'Assigned thesis']);
        $overlap = Project::factory()->approved()->create(['title' => 'Assigned and group thesis']);
        $overlap->members()->attach($user);
        $former = Project::factory()->approved()->create(['title' => 'Removed group secret thesis']);
        $former->members()->attach($user);
        $former->members()->detach($user);

        $ownedReview = ReviewRequest::factory()->create(['project_id' => $owned->id, 'status' => 'declined', 'updated_at' => now()->subMinutes(4)]);
        $groupReview = ReviewRequest::factory()->create(['project_id' => $group->id, 'status' => 'pending', 'updated_at' => now()->subMinutes(3)]);
        $assignedReview = ReviewRequest::factory()->accepted()->create(['project_id' => $assigned->id, 'evaluator_id' => $user->id, 'updated_at' => now()->subMinutes(2)]);
        $overlapReview = ReviewRequest::factory()->completed()->create(['project_id' => $overlap->id, 'evaluator_id' => $user->id, 'updated_at' => now()->subMinute()]);
        ReviewRequest::factory()->create(['project_id' => $former->id]);
        ReviewRequest::factory()->create();

        $response = $this->actingAs($user)->getJson(route('account.notifications.state'))->assertOk()
            ->assertJsonCount(4, 'conversations')->assertJsonPath('activities', [])
            ->assertJsonPath('conversations.0.title', $overlap->title)
            ->assertJsonPath('conversations.0.participant_name', $overlapReview->requester->name)
            ->assertJsonPath('conversations.0.status', 'completed')
            ->assertJsonPath('conversations.0.status_label', 'Completed')
            ->assertJsonPath('conversations.0.url', route('reviews.show', $overlapReview))
            ->assertJsonPath('conversations.2.participant_name', $groupReview->evaluator->name)
            ->assertJsonMissing(['title' => $former->title]);
        $this->assertSame([$overlapReview->id, $assignedReview->id, $groupReview->id, $ownedReview->id], array_column($response->json('conversations'), 'id'));
        $this->assertSame(['completed', 'accepted', 'pending', 'declined'], array_column($response->json('conversations'), 'status'));
        $this->assertSame($overlapReview->updated_at->toIso8601String(), $response->json('conversations.0.updated_at'));
        $this->assertSame([
            'id', 'title', 'participant_name', 'participant_avatar_url', 'participant_initials',
            'status', 'status_label', 'updated_at', 'updated_label', 'url',
        ], array_keys($response->json('conversations.0')));

        $this->actingAs(User::factory()->admin()->create())->getJson(route('account.notifications.state'))
            ->assertOk()->assertJsonPath('conversations', [])->assertJsonPath('activities', []);
    }

    public function test_conversations_are_limited_to_the_latest_ten_with_stable_ties(): void
    {
        $this->freezeTime();
        $user = User::factory()->create();
        $project = Project::factory()->approved()->create(['user_id' => $user->id]);
        $reviews = ReviewRequest::factory()->count(12)->create(['project_id' => $project->id]);

        $response = $this->actingAs($user)->getJson(route('account.notifications.state'))->assertOk()
            ->assertJsonCount(10, 'conversations');
        $this->assertSame($reviews->reverse()->take(10)->pluck('id')->values()->all(), array_column($response->json('conversations'), 'id'));
    }

    public function test_activity_links_remain_local_and_unsafe_or_external_targets_are_rejected(): void
    {
        config(['app.url' => 'https://analista.example/subdirectory']);
        $user = User::factory()->create();
        $links = [
            'local' => ['/projects/8?tab=review#summary', '/projects/8?tab=review#summary'],
            'relative' => ['account?tab=notifications', '/account?tab=notifications'],
            'current origin' => ['http://localhost/projects/8', '/projects/8'],
            'configured app origin' => ['https://analista.example/subdirectory/projects/8?tab=review#summary', '/subdirectory/projects/8?tab=review#summary'],
            'external' => ['https://external.example/private', null],
            'lookalike' => ['https://analista.example.external.example/private', null],
            'unsafe scheme' => ['javascript:alert(1)', null],
            'protocol relative' => ['//external.example/private', null],
            'credentials' => ['https://user:password@analista.example/private', null],
            'backslash' => ['/\\external.example/private', null],
            'control character' => ["/projects/8\n", null],
        ];
        foreach ($links as $label => [$target]) {
            $user->notifyActivity($label, null, $target);
        }

        $response = $this->actingAs($user)->getJson('http://localhost/account/notifications/state')->assertOk();
        $activities = collect($response->json('activities'))->keyBy('title');
        foreach ($links as $label => [, $expected]) {
            $this->assertSame($expected, $activities[$label]['url'], $label);
        }
    }

    public function test_explicit_senders_include_their_avatar_and_initials_while_system_events_never_guess_a_reporter(): void
    {
        $recipient = User::factory()->create();
        $sender = User::factory()->evaluator()->create(['name' => 'Marina Lopez']);
        $sender->update(['profile_photo_path' => 'profile-photos/'.$sender->id.'/'.str_repeat('m', 40).'.png']);
        $person = $recipient->notifyActivity('A new recommendation', null, '/account', $sender);
        $reporter = User::factory()->create(['name' => 'Private Reporter Identity']);
        $this->actingAs($reporter);
        $system = $recipient->notifyActivity('Report received', 'Your report is queued.', '/account');
        $project = Project::factory()->approved()->create(['user_id' => $recipient->id]);
        ReviewRequest::factory()->accepted()->create(['project_id' => $project->id, 'evaluator_id' => $sender->id]);

        $response = $this->actingAs($recipient)->getJson(route('account.notifications.state'))->assertOk()
            ->assertJsonMissing(['name' => $reporter->name])
            ->assertJsonPath('conversations.0.participant_avatar_url', $sender->profile_photo_url)
            ->assertJsonPath('conversations.0.participant_initials', 'ML');
        $activities = collect($response->json('activities'))->keyBy('id');
        $this->assertSame([
            'name' => $sender->name, 'avatar_url' => $sender->profile_photo_url, 'initials' => 'ML', 'kind' => 'person',
        ], $activities[$person->id]['sender']);
        $this->assertSame('', $activities[$person->id]['body']);
        $this->assertSame([
            'name' => 'Analista', 'avatar_url' => null, 'initials' => 'A', 'kind' => 'system',
        ], $activities[$system->id]['sender']);
        $this->assertNull($system->fresh()->actor_id);
    }

    public function test_real_review_delivery_records_the_sender_and_legacy_sender_resolution_is_exact_owned_and_read_only(): void
    {
        $review = ReviewRequest::factory()->accepted()->create();
        $recipient = $review->project->owner;
        $sender = $review->evaluator;
        DB::transaction(fn () => app(ReviewDelivery::class)->deliver($review, 'accepted'));
        $activity = $recipient->activities()->sole();
        $this->assertSame($sender->id, $activity->actor_id);
        $this->actingAs($recipient)->getJson(route('account.notifications.state'))->assertOk()
            ->assertJsonPath('activities.0.sender.name', $sender->name)
            ->assertJsonPath('activities.0.sender.kind', 'person');

        $activity->update(['actor_id' => null]);
        $unknown = $recipient->notifyActivity($sender->name.' accepted a review', null, '/account');
        $secretSender = User::factory()->evaluator()->create(['name' => 'Unrelated Private Sender']);
        $otherRecipient = User::factory()->create();
        $conversation = DirectConversation::create([
            'lower_user_id' => min($secretSender->id, $otherRecipient->id),
            'higher_user_id' => max($secretSender->id, $otherRecipient->id),
        ]);
        $foreignKey = 'review:999:accepted:'.$otherRecipient->id;
        DirectMessage::create([
            'direct_conversation_id' => $conversation->id, 'sender_id' => $secretSender->id,
            'recipient_id' => $otherRecipient->id, 'kind' => 'review_event', 'review_event' => 'accepted',
            'review_event_key' => $foreignKey, 'body' => 'An unrelated review event.',
        ]);
        $foreign = $recipient->notifyActivity('Unmatched legacy event', null, '/account');
        $foreign->update(['review_event_key' => $foreignKey]);

        $response = $this->getJson(route('account.notifications.state'))->assertOk()
            ->assertJsonMissing(['name' => $secretSender->name]);
        $activities = collect($response->json('activities'))->keyBy('id');
        $this->assertSame($sender->name, $activities[$activity->id]['sender']['name']);
        $this->assertSame('person', $activities[$activity->id]['sender']['kind']);
        foreach ([$unknown, $foreign] as $system) {
            $this->assertSame('system', $activities[$system->id]['sender']['kind']);
            $this->assertSame('Analista', $activities[$system->id]['sender']['name']);
        }
        $this->assertNull($activity->fresh()->actor_id);
        $this->assertNull($activity->fresh()->read_at);
    }

    public function test_json_marking_read_is_owned_idempotent_and_reports_the_real_remaining_count(): void
    {
        $this->freezeTime();
        $user = User::factory()->create();
        $first = $user->notifyActivity('First', null, '/account');
        $second = $user->notifyActivity('Second', null, '/account');
        $alreadyRead = $user->notifyActivity('Previously read', null, '/account');
        $alreadyRead->update(['read_at' => now()->subDay()]);
        $originalRead = $alreadyRead->fresh()->read_at->toIso8601String();
        $other = User::factory()->create()->notifyActivity('Other account', null, '/account');

        $this->actingAs($user)->postJson(route('account.notifications.read', $other))->assertForbidden();
        $this->assertNull($other->fresh()->read_at);
        $response = $this->postJson(route('account.notifications.read', $first))->assertOk()
            ->assertJsonPath('user_id', $user->id)->assertJsonPath('unread_count', 1)
            ->assertJsonPath('read_at', now()->toIso8601String());
        $this->assertPrivateJson($response);
        $firstRead = $first->fresh()->read_at->toIso8601String();
        $this->travel(5)->minutes();
        $this->postJson(route('account.notifications.read', $first))->assertOk()
            ->assertJsonPath('read_at', $firstRead)->assertJsonPath('unread_count', 1);

        $all = $this->postJson(route('account.notifications.readall'))->assertOk()
            ->assertJsonPath('user_id', $user->id)->assertJsonPath('unread_count', 0)
            ->assertJsonPath('read_at', now()->toIso8601String());
        $this->assertPrivateJson($all);
        $this->assertSame($firstRead, $first->fresh()->read_at->toIso8601String());
        $this->assertSame($originalRead, $alreadyRead->fresh()->read_at->toIso8601String());
        $this->assertNotNull($second->fresh()->read_at);
        $this->assertNull($other->fresh()->read_at);
    }

    public function test_regular_mark_read_forms_keep_the_redirect_and_success_messages(): void
    {
        $user = User::factory()->create();
        $first = $user->notifyActivity('First', null, '/account');
        $second = $user->notifyActivity('Second', null, '/account');
        $fallback = route('account.dashboard', ['tab' => 'notifications']);

        $this->actingAs($user)->from($fallback)->post(route('account.notifications.read', $first))
            ->assertRedirect($fallback)->assertSessionHas('success', 'Notification marked as read.');
        $this->assertNotNull($first->fresh()->read_at);
        $this->assertNull($second->fresh()->read_at);
        $this->from($fallback)->post(route('account.notifications.readall'))
            ->assertRedirect($fallback)->assertSessionHas('success', 'All notifications marked as read.');
        $this->assertNotNull($second->fresh()->read_at);
    }

    private function assertPrivateJson(TestResponse $response): void
    {
        $response->assertHeader('Content-Type', 'application/json');
        $this->assertTrue($response->headers->hasCacheControlDirective('private'));
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $this->assertFalse($response->headers->hasCacheControlDirective('public'));
    }
}

<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ReviewRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MergedNotificationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_students_evaluators_and_group_members_see_only_their_updates_and_project_conversations(): void
    {
        $owner = User::factory()->create();
        $evaluator = User::factory()->evaluator()->create();
        $member = User::factory()->create();
        $review = $this->reviewFor($owner, $evaluator, 'OUR TEAM THESIS CONVERSATION');
        $review->project->members()->attach($member);
        $unrelated = $this->reviewFor(User::factory()->create(), User::factory()->evaluator()->create(), 'OTHER TEAM PRIVATE THESIS CONVERSATION');
        $outsider = User::factory()->create();
        $outsider->notifyActivity('OUTSIDER PRIVATE ACTIVITY', 'OUTSIDER PRIVATE ACTIVITY BODY', route('reviews.show', $unrelated));
        $activities = [];
        foreach ([$owner, $evaluator, $member] as $user) {
            $activities[$user->id] = $user->notifyActivity('OWN ACTIVITY '.$user->id, 'OWN ACTIVITY BODY '.$user->id, route('reviews.show', $review));
        }

        foreach ([$owner, $evaluator, $member] as $user) {
            $response = $this->actingAs($user)->get($this->notificationsUrl())->assertOk()
                ->assertViewHas('tab', 'notifications')
                ->assertViewHas('conversations', fn ($items) => $items->modelKeys() === [$review->id])
                ->assertViewHas('activities', fn ($items) => $items->total() === 1 && $items->first()->id === $activities[$user->id]->id)
                ->assertViewHas('stats', fn ($stats) => $stats['unread_notifications'] === 1)
                ->assertSee('RECENT UPDATES')->assertSee('PROJECT CONVERSATIONS')
                ->assertSee($activities[$user->id]->title)->assertSee($activities[$user->id]->body)
                ->assertSee($review->project->title)->assertDontSee($unrelated->project->title)
                ->assertDontSee('OUTSIDER PRIVATE ACTIVITY');
            foreach ($activities as $ownerId => $activity) {
                if ($ownerId !== $user->id) {
                    $response->assertDontSee($activity->title)->assertDontSee($activity->body);
                }
            }
        }
    }

    public function test_overlapping_conversations_are_deduplicated_and_sorted_by_latest_activity(): void
    {
        $user = User::factory()->evaluator()->create();
        $older = $this->reviewFor(User::factory()->create(), $user, 'OLDER SHARED CONVERSATION');
        // Existing group membership can make a review match both scoped collections.
        $older->project->members()->attach($user);
        $newer = $this->reviewFor(User::factory()->create(), $user, 'RECENT CONVERSATION');
        $older->update(['updated_at' => now()->subHours(2)]);
        $newer->update(['updated_at' => now()->subHour()]);

        $response = $this->actingAs($user)->get($this->notificationsUrl())->assertOk()
            ->assertViewHas('conversations', fn ($items) => $items->modelKeys() === [$newer->id, $older->id])
            ->assertViewHas('stats', fn ($stats) => $stats['unread_notifications'] === 0)
            ->assertSeeInOrder([$newer->project->title, $older->project->title]);
        $this->assertSame(1, substr_count($response->getContent(), 'href="'.route('reviews.show', $older).'"'));
        $this->assertSame(1, substr_count($response->getContent(), 'href="'.route('reviews.show', $newer).'"'));
    }

    public function test_legacy_inbox_links_redirect_to_notifications_and_preserve_other_query_parameters(): void
    {
        $user = User::factory()->create();
        $query = ['tab' => 'inbox', 'page' => 2, 'filter' => 'unread', 'origin' => 'saved-link'];
        $this->actingAs($user)->get(route('account.dashboard', $query))
            ->assertRedirect(route('account.dashboard', [...$query, 'tab' => 'notifications']));
        $this->get(route('account.dashboard', ['tab' => 'inbox']))->assertRedirect($this->notificationsUrl());
    }

    public function test_marking_updates_read_keeps_conversations_and_preserves_other_users_activity(): void
    {
        $owner = User::factory()->create();
        $review = $this->reviewFor($owner, User::factory()->evaluator()->create(), 'CONVERSATION REMAINS AFTER READING');
        $first = $owner->notifyActivity('First owned update', 'An owned review update.', route('reviews.show', $review));
        $second = $owner->notifyActivity('Second owned update', 'Another owned review update.', route('reviews.show', $review));
        $other = User::factory()->create()->notifyActivity('Another member update', 'Private to another member.', route('account.dashboard'));

        $this->actingAs($owner)->from($this->notificationsUrl())->post(route('account.notifications.read', $other))->assertForbidden();
        $this->assertNull($other->fresh()->read_at);
        $this->from($this->notificationsUrl())->post(route('account.notifications.read', $first))->assertRedirect($this->notificationsUrl());
        $this->assertNotNull($first->fresh()->read_at);
        $this->assertNull($second->fresh()->read_at);
        $this->get($this->notificationsUrl())->assertOk()
            ->assertViewHas('stats', fn ($stats) => $stats['unread_notifications'] === 1)
            ->assertViewHas('conversations', fn ($items) => $items->modelKeys() === [$review->id])
            ->assertSee($review->project->title)->assertSee($first->title)->assertSee($second->title);

        $this->from($this->notificationsUrl())->post(route('account.notifications.readall'))->assertRedirect($this->notificationsUrl());
        $this->assertNotNull($second->fresh()->read_at);
        $this->assertNull($other->fresh()->read_at);
        $this->get($this->notificationsUrl())->assertOk()
            ->assertViewHas('stats', fn ($stats) => $stats['unread_notifications'] === 0)
            ->assertViewHas('conversations', fn ($items) => $items->modelKeys() === [$review->id])
            ->assertSee($review->project->title)->assertDontSee($other->title);
        $this->assertSame('accepted', $review->fresh()->status);
    }

    public function test_notifications_are_accessed_from_one_active_sidebar_link_without_workspace_tabs(): void
    {
        $user = User::factory()->create();
        $response = $this->actingAs($user)->get($this->notificationsUrl())->assertOk()->assertSee('<h1>Notifications', false);
        $xpath = $this->xpath($response->getContent());
        $this->assertSame(0, $xpath->query('//nav[@aria-label="Your space sections"]')->length);
        $this->assertSame(0, $xpath->query('//a[contains(@href,"tab=inbox")]')->length);
        $links = $xpath->query('//aside[@id="sidebar"]//a[@href="'.$this->notificationsUrl().'"]');
        $this->assertSame(1, $links->length);
        $this->assertStringContainsString('active', $links->item(0)->getAttribute('class'));
        $this->assertSame('page', $links->item(0)->getAttribute('aria-current'));
        $this->assertSame(0, $xpath->query('//aside[@id="sidebar"]//a[@href="'.route('account.dashboard').'"]')->length);

        foreach ([$user, User::factory()->evaluator()->create()] as $account) {
            $workspacePage = $this->actingAs($account)
                ->get(route('account.dashboard', ['tab' => 'reviews']))->assertOk();
            $xpath = $this->xpath($workspacePage->getContent());
            $this->assertSame(3, $xpath->query('//nav[@aria-label="Your space sections"]//a')->length);
            $this->assertSame(1, $xpath->query('//nav[@aria-label="Your space sections"]//a[@href="'.route('account.reviews').'" and @aria-current="page"]')->length);
            $this->assertSame(1, $xpath->query('//form[@aria-label="Filter reviews"]//select[@name="status"]')->length);
            $this->assertSame(0, $xpath->query('//nav[@aria-label="Your space sections"]//a[contains(@href,"tab=inbox") or contains(@href,"tab=notifications")]')->length);
        }
    }

    public function test_empty_notifications_show_both_updates_and_conversation_empty_states(): void
    {
        $this->actingAs(User::factory()->create())->get($this->notificationsUrl())->assertOk()
            ->assertViewHas('activities', fn ($items) => $items->isEmpty())
            ->assertViewHas('conversations', fn ($items) => $items->isEmpty())
            ->assertViewHas('stats', fn ($stats) => $stats['unread_notifications'] === 0)
            ->assertSee('RECENT UPDATES')->assertSee('PROJECT CONVERSATIONS')
            ->assertSee("You're all caught up.")->assertSee('No review conversations yet.');
    }

    public function test_activity_pagination_keeps_the_notifications_page_and_conversations(): void
    {
        $user = User::factory()->create();
        $review = $this->reviewFor($user, User::factory()->evaluator()->create(), 'PAGINATED NOTIFICATIONS CONVERSATION');
        for ($index = 0; $index < 21; $index++) {
            $user->notifyActivity('Paged notification '.$index, 'A scoped activity update.', route('account.dashboard'));
        }
        $response = $this->actingAs($user)->get(route('account.dashboard', ['tab' => 'notifications', 'filter' => 'all']))->assertOk()
            ->assertViewHas('conversations', fn ($items) => $items->modelKeys() === [$review->id]);
        $next = $response->viewData('activities')->nextPageUrl();
        parse_str(parse_url($next, PHP_URL_QUERY), $query);
        $this->assertSame('notifications', $query['tab']);
        $this->assertSame('all', $query['filter']);
        $this->assertSame('2', $query['page']);
        $this->get($next)->assertOk()->assertViewHas('tab', 'notifications')
            ->assertViewHas('activities', fn ($items) => $items->count() === 1 && $items->total() === 21)
            ->assertViewHas('conversations', fn ($items) => $items->modelKeys() === [$review->id])
            ->assertSee($review->project->title);
    }

    private function reviewFor(User $owner, User $evaluator, string $title): ReviewRequest
    {
        $project = Project::factory()->approved()->create(['user_id' => $owner->id, 'title' => $title]);

        return ReviewRequest::factory()->accepted()->create(['project_id' => $project->id, 'evaluator_id' => $evaluator->id]);
    }

    private function notificationsUrl(): string
    {
        return route('account.dashboard', ['tab' => 'notifications']);
    }

    private function xpath(string $html): \DOMXPath
    {
        $document = new \DOMDocument;
        @$document->loadHTML($html);

        return new \DOMXPath($document);
    }
}

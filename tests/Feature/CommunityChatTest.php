<?php

namespace Tests\Feature;

use App\Models\CommunityChatMessage;
use App\Models\CommunityChatPresence;
use App\Models\User;
use App\Services\CommunityChat;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

class CommunityChatTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_reads_are_public_private_cached_and_do_not_fabricate_presence_or_messages(): void
    {
        $key = (string) Str::uuid();
        $response = $this->withSession(['community_chat.guest_key' => $key])
            ->getJson(route('community-chat.state'))->assertOk()
            ->assertJsonPath('presence.count', 0)->assertJsonCount(0, 'presence.visitors')
            ->assertJsonCount(0, 'messages')->assertJsonPath('next_after_id', 0)
            ->assertJsonPath('viewer.guest', true)->assertJsonPath('viewer.needs_name', true)
            ->assertJsonPath('viewer.can_send', false);

        $this->assertStringStartsWith('g_', $response->json('viewer.id'));
        $this->assertStringNotContainsString($key, $response->getContent());
        $this->assertStringNotContainsString(session()->getId(), $response->getContent());
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertDatabaseCount('community_chat_messages', 0);
        $this->assertDatabaseCount('community_chat_presences', 0);
    }

    public function test_guests_choose_a_session_name_before_sending_and_remain_labelled_guests(): void
    {
        $this->postJson(route('community-chat.messages.store'), ['body' => 'Before choosing a name'])
            ->assertUnprocessable()->assertJsonValidationErrors('name')->assertHeader('Pragma', 'no-cache');
        $identity = $this->postJson(route('community-chat.identity'), ['name' => '  Thesis visitor  '])
            ->assertOk()->assertJsonPath('viewer.name', 'Thesis visitor')
            ->assertJsonPath('viewer.guest', true)->assertJsonPath('viewer.needs_name', false)
            ->assertJsonPath('viewer.can_send', true)->assertJsonPath('presence.count', 1);
        $id = $identity->json('viewer.id');
        $this->postJson(route('community-chat.messages.store'), [
            'body' => 'Hello community', 'user_id' => 999, 'author_key' => 'fake',
            'author_name' => 'Administrator', 'is_guest' => false,
        ])->assertCreated()->assertJsonPath('message.author.id', $id)
            ->assertJsonPath('message.author.name', 'Thesis visitor')
            ->assertJsonPath('message.author.guest', true)->assertJsonPath('message.own', true);
        $this->getJson(route('community-chat.state'))->assertOk()
            ->assertJsonPath('viewer.name', 'Thesis visitor')->assertJsonPath('viewer.id', $id)
            ->assertJsonPath('messages.0.author.name', 'Thesis visitor');
        $this->assertDatabaseHas('community_chat_messages', ['user_id' => null, 'is_guest' => true]);
    }

    public function test_guest_and_account_messages_share_one_real_feed_with_server_owned_attribution(): void
    {
        $guestKey = (string) Str::uuid();
        $this->withSession(['community_chat.guest_key' => $guestKey, 'community_chat.name' => 'Research visitor']);
        $guestMessage = $this->postJson(route('community-chat.messages.store'), ['body' => 'Guest question'])
            ->assertCreated()->json('message');
        $user = User::factory()->create(['name' => 'Actual member', 'email' => 'private@example.test']);
        $this->actingAs($user)->postJson(route('community-chat.identity'), ['name' => 'Spoofed admin'])
            ->assertOk()->assertJsonPath('viewer.name', 'Actual member')
            ->assertJsonPath('viewer.needs_name', false)->assertJsonPath('viewer.guest', false);
        $memberMessage = $this->postJson(route('community-chat.messages.store'), [
            'body' => 'Member answer', 'name' => 'Fake name', 'user_id' => 12345,
        ])->assertCreated()->assertJsonPath('message.author.name', 'Actual member')
            ->assertJsonPath('message.author.guest', false)->json('message');

        $this->app['auth']->forgetGuards();
        $feed = $this->withSession(['community_chat.guest_key' => $guestKey])->getJson(route('community-chat.state'))
            ->assertOk()->assertJsonCount(2, 'messages')->assertJsonPath('messages.0.id', $guestMessage['id'])
            ->assertJsonPath('messages.0.own', true)->assertJsonPath('messages.1.id', $memberMessage['id'])
            ->assertJsonPath('messages.1.own', false);
        $this->assertStringNotContainsString($user->email, $feed->getContent());
        $this->assertDatabaseHas('community_chat_messages', ['id' => $memberMessage['id'], 'user_id' => $user->id]);
    }

    public function test_plain_text_and_only_public_author_fields_are_returned(): void
    {
        $user = User::factory()->create([
            'name' => 'Public member', 'email' => 'secret@example.test', 'bio' => 'PRIVATE PROFILE EVIDENCE',
            'remember_token' => 'SECRET REMEMBER TOKEN',
            'profile_photo_path' => 'profile-photos/1/'.str_repeat('x', 40).'.png',
        ]);
        $body = '<script>alert("unsafe")</script> https://example.test <img src=x onerror=alert(1)>';
        $this->actingAs($user)->postJson(route('community-chat.messages.store'), ['body' => $body])->assertCreated();
        $user->update(['name' => 'Updated public member']);
        $response = $this->getJson(route('community-chat.state'))->assertOk()
            ->assertJsonPath('messages.0.body', $body)->assertJsonPath('messages.0.author.name', 'Updated public member');
        $this->assertSame(['id', 'name', 'avatar_url', 'guest'], array_keys($response->json('messages.0.author')));
        foreach ([$user->email, $user->password, $user->remember_token, $user->bio, $user->profile_photo_path] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
        $this->assertDatabaseHas('community_chat_messages', ['body' => $body]);
    }

    public function test_message_and_guest_name_validation_reject_invalid_inputs(): void
    {
        foreach (['', str_repeat('a', 41), "Hidden\nname", "Bidi\u{202E}name"] as $name) {
            $this->postJson(route('community-chat.identity'), ['name' => $name])->assertUnprocessable()
                ->assertJsonValidationErrors('name');
        }
        $this->postJson(route('community-chat.identity'), ['name' => 'Valid name'])->assertOk();
        foreach (['', '   ', str_repeat('a', 501), ['not a string']] as $body) {
            $this->postJson(route('community-chat.messages.store'), ['body' => $body])->assertUnprocessable()
                ->assertJsonValidationErrors('body');
        }
        $this->postJson(route('community-chat.messages.store'), ['body' => str_repeat('a', 500)])->assertCreated();
        $this->assertDatabaseCount('community_chat_messages', 1);
        $this->getJson(route('community-chat.state', ['after' => -1]))->assertUnprocessable();
        $this->getJson(route('community-chat.state', ['after' => 'invalid']))->assertUnprocessable();
    }

    public function test_presence_counts_distinct_live_sessions_and_expires_without_a_read_writing_rows(): void
    {
        $this->freezeTime();
        $firstKey = (string) Str::uuid();
        $this->withSession(['community_chat.guest_key' => $firstKey])->postJson(route('community-chat.presence'))
            ->assertOk()->assertJsonPath('presence.count', 1);
        $this->postJson(route('community-chat.presence'))->assertOk()->assertJsonPath('presence.count', 1);
        $this->withSession(['community_chat.guest_key' => (string) Str::uuid()])->postJson(route('community-chat.presence'))
            ->assertOk()->assertJsonPath('presence.count', 2);
        $this->assertDatabaseCount('community_chat_presences', 2);
        $this->travel(90)->seconds();
        $this->getJson(route('community-chat.state'))->assertOk()->assertJsonPath('presence.count', 0);
        $this->assertDatabaseCount('community_chat_presences', 2);
        $this->postJson(route('community-chat.presence'))->assertOk()->assertJsonPath('presence.count', 1);
        $this->assertDatabaseCount('community_chat_presences', 1);
    }

    public function test_account_presence_is_deduplicated_across_sessions_and_replaces_its_guest_entry(): void
    {
        $user = User::factory()->create(['name' => 'Actual signed in member']);
        $this->postJson(route('community-chat.presence'))->assertOk()->assertJsonPath('presence.count', 1);
        $first = $this->actingAs($user)->postJson(route('community-chat.presence'))->assertOk()
            ->assertJsonPath('presence.count', 1)->assertJsonPath('presence.visitors.0.name', $user->name)
            ->assertJsonPath('presence.visitors.0.guest', false)->json('viewer.id');
        $this->withSession(['community_chat.guest_key' => (string) Str::uuid()])
            ->postJson(route('community-chat.presence'))->assertOk()->assertJsonPath('presence.count', 1)
            ->assertJsonPath('viewer.id', $first);
        $this->assertDatabaseCount('community_chat_presences', 1);
        $this->assertStringNotContainsString((string) $user->email, $this->getJson(route('community-chat.state'))->getContent());
    }

    public function test_latest_feed_and_incremental_pages_are_bounded_ordered_and_do_not_skip_messages(): void
    {
        foreach (range(1, 115) as $number) {
            CommunityChatMessage::create([
                'author_key' => 'g_'.str_repeat('a', 64), 'author_name' => 'Earlier guest',
                'is_guest' => true, 'body' => 'Message '.$number,
            ]);
        }
        $this->getJson(route('community-chat.state'))->assertOk()->assertJsonCount(50, 'messages')
            ->assertJsonPath('messages.0.id', 66)->assertJsonPath('messages.49.id', 115)->assertJsonPath('next_after_id', 115);
        $this->getJson(route('community-chat.state', ['after' => 0]))->assertOk()->assertJsonCount(50, 'messages')
            ->assertJsonPath('messages.0.id', 1)->assertJsonPath('messages.49.id', 50)->assertJsonPath('next_after_id', 50);
        $this->getJson(route('community-chat.state', ['after' => 50]))->assertOk()->assertJsonCount(50, 'messages')
            ->assertJsonPath('messages.0.id', 51)->assertJsonPath('messages.49.id', 100)->assertJsonPath('next_after_id', 100);
        $this->getJson(route('community-chat.state', ['after' => 100]))->assertOk()->assertJsonCount(15, 'messages')
            ->assertJsonPath('next_after_id', 115);
        $this->getJson(route('community-chat.state', ['after' => 115]))->assertOk()->assertJsonCount(0, 'messages')
            ->assertJsonPath('next_after_id', 115);
    }

    public function test_suspended_accounts_can_read_but_cannot_send_or_impersonate_a_guest(): void
    {
        $user = User::factory()->create(['is_suspended' => true]);
        $this->actingAs($user)->getJson(route('community-chat.state'))->assertOk()
            ->assertJsonPath('viewer.guest', false)->assertJsonPath('viewer.can_send', false);
        $this->postJson(route('community-chat.identity'), ['name' => 'Unsuspended guest'])->assertOk()
            ->assertJsonPath('viewer.name', $user->name)->assertJsonPath('viewer.can_send', false);
        $this->postJson(route('community-chat.messages.store'), ['body' => 'Blocked message'])
            ->assertForbidden()->assertHeader('Pragma', 'no-cache');
        $this->assertDatabaseCount('community_chat_messages', 0);
        $this->assertAuthenticatedAs($user);
    }

    public function test_send_rate_limit_survives_identity_renaming_and_does_not_store_raw_ip(): void
    {
        $this->withSession(['community_chat.name' => 'Guest sender']);
        foreach (range(1, 15) as $number) {
            $this->postJson(route('community-chat.messages.store'), ['body' => 'Message '.$number])->assertCreated();
        }
        $this->postJson(route('community-chat.identity'), ['name' => 'Renamed guest'])->assertOk();
        $this->postJson(route('community-chat.messages.store'), ['body' => 'Over limit'])
            ->assertStatus(429)->assertHeader('Retry-After');
        $this->assertDatabaseCount('community_chat_messages', 15);
        $this->assertStringNotContainsString('127.0.0.1', json_encode(CommunityChatMessage::first()->getAttributes()));
        $this->assertStringNotContainsString('127.0.0.1', json_encode(CommunityChatPresence::first()->getAttributes()));
        $this->travel(61)->seconds();
        $this->postJson(route('community-chat.messages.store'), ['body' => 'Allowed after wait'])->assertCreated();
    }

    public function test_ip_limit_stops_repeated_guest_session_rotation(): void
    {
        $ipKey = 'community-chat:store:ip:'.app(CommunityChat::class)->opaque('ip:127.0.0.1');
        RateLimiter::hit($ipKey, 60);
        RateLimiter::increment($ipKey, 60, 59);
        $this->withSession(['community_chat.name' => 'New guest', 'community_chat.guest_key' => (string) Str::uuid()])
            ->postJson(route('community-chat.messages.store'), ['body' => 'Blocked from this network'])
            ->assertStatus(429)->assertHeader('Retry-After');
        $this->assertDatabaseCount('community_chat_messages', 0);
    }

    public function test_guest_mutation_endpoints_keep_web_csrf_protection(): void
    {
        $this->app->bind(ValidateCsrfToken::class, fn ($app) => new class($app, $app['encrypter']) extends ValidateCsrfToken
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        });
        foreach (['community-chat.identity', 'community-chat.presence', 'community-chat.messages.store'] as $name) {
            $this->postJson(route($name), ['name' => 'Guest', 'body' => 'No CSRF token'])->assertStatus(419);
        }
        $this->assertDatabaseCount('community_chat_messages', 0);
        $this->assertDatabaseCount('community_chat_presences', 0);
    }
}

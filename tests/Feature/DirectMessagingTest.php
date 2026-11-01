<?php

namespace Tests\Feature;

use App\Models\DirectConversation;
use App\Models\DirectMessage;
use App\Models\ReviewRequest;
use App\Models\User;
use App\Services\ReviewDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DirectMessagingTest extends TestCase
{
    use RefreshDatabase;

    public function test_messages_require_sign_in_and_only_participants_can_access_a_conversation(): void
    {
        $student = User::factory()->create();
        $evaluator = User::factory()->evaluator()->create();
        $conversation = $this->conversation($student, $evaluator);
        $message = $this->message($conversation, $evaluator, $student, 'PRIVATE PARTICIPANT MESSAGE');

        foreach ([route('messages.index'), route('messages.compose', $evaluator), route('messages.show', $conversation)] as $url) {
            $this->get($url)->assertRedirect(route('login'));
        }
        foreach ([route('messages.send', $evaluator), route('messages.store', $conversation)] as $url) {
            $this->post($url, ['body' => 'Unauthorized message'])->assertRedirect(route('login'));
        }
        $this->getJson(route('messages.updates', $conversation))->assertUnauthorized();
        $this->getJson(route('messages.inbox-updates'))->assertUnauthorized();

        foreach ([User::factory()->create(), User::factory()->admin()->create()] as $outsider) {
            $this->actingAs($outsider)->get(route('messages.show', $conversation))->assertForbidden();
            $this->getJson(route('messages.updates', $conversation))->assertForbidden();
            $this->postJson(route('messages.store', $conversation), ['body' => 'Outsider insertion'])->assertForbidden();
            $this->get(route('messages.index'))->assertOk()->assertDontSee($message->body);
        }

        $this->assertNull($message->fresh()->read_at);
        $this->assertDatabaseCount('direct_conversations', 1);
        $this->assertDatabaseCount('direct_messages', 1);
    }

    public function test_opening_a_new_evaluator_composer_selects_the_recipient_without_creating_or_sending_anything(): void
    {
        $student = User::factory()->create();
        $evaluator = User::factory()->evaluator()->create(['name' => 'Chosen volunteer reviewer', 'accepting_requests' => false]);

        $response = $this->actingAs($student)->get(route('messages.compose', $evaluator))->assertOk()
            ->assertSee($evaluator->name)->assertSee('name="body"', false)
            ->assertSee('action="'.route('messages.send', $evaluator).'"', false);
        $xpath = $this->xpath($response->getContent());
        $textarea = $xpath->query('//form[@data-message-form]//textarea[@name="body"]')->item(0);
        $this->assertNotNull($textarea);
        $this->assertSame('', trim($textarea->textContent));
        $this->get(route('messages.index'))->assertOk();
        $this->assertDatabaseCount('direct_conversations', 0);
        $this->assertDatabaseCount('direct_messages', 0);
        $this->assertDatabaseCount('activities', 0);
    }

    public function test_new_conversations_only_allow_other_active_approved_evaluators(): void
    {
        $sender = User::factory()->evaluator()->create();
        $recipients = [
            $sender,
            User::factory()->create(),
            User::factory()->admin()->create(),
            User::factory()->pendingEvaluator()->create(),
            User::factory()->evaluator()->create(['evaluator_status' => 'rejected']),
            User::factory()->evaluator()->create(['evaluator_status' => 'needs_info']),
            User::factory()->evaluator()->create(['is_suspended' => true]),
        ];
        $this->actingAs($sender);
        foreach ($recipients as $recipient) {
            $this->get(route('messages.compose', $recipient))->assertForbidden();
            $this->postJson(route('messages.send', $recipient), ['body' => 'A new conversation request'])->assertForbidden();
        }

        $this->assertDatabaseCount('direct_conversations', 0);
        $this->assertDatabaseCount('direct_messages', 0);
    }

    public function test_message_validation_trims_body_and_resolves_sender_recipient_and_read_state_on_the_server(): void
    {
        $sender = User::factory()->create();
        $recipient = User::factory()->evaluator()->create();
        $outsider = User::factory()->create();
        $this->actingAs($sender);
        foreach ([" \r\n\t ", ['not' => 'text'], str_repeat('x', 5001)] as $body) {
            $this->postJson(route('messages.send', $recipient), ['body' => $body])
                ->assertUnprocessable()->assertJsonValidationErrors('body');
        }
        $this->assertDatabaseCount('direct_conversations', 0);

        $this->postJson(route('messages.send', $recipient), [
            'body' => " \n A specific question about database normalization. \t ",
            'sender_id' => $outsider->id, 'recipient_id' => $outsider->id,
            'direct_conversation_id' => 99999, 'read_at' => now()->toISOString(),
        ])->assertSuccessful();
        $conversation = DirectConversation::sole();
        $message = DirectMessage::sole();
        $this->assertSame($sender->id, $message->sender_id);
        $this->assertSame($recipient->id, $message->recipient_id);
        $this->assertSame($conversation->id, $message->direct_conversation_id);
        $this->assertSame('A specific question about database normalization.', $message->body);
        $this->assertNull($message->read_at);

        $this->postJson(route('messages.store', $conversation), ['body' => str_repeat('x', 5000)])
            ->assertSuccessful();
        $this->assertSame(5000, mb_strlen($conversation->messages()->latest('id')->firstOrFail()->body));
        $this->postJson(route('messages.store', $conversation), ['body' => '   '])
            ->assertUnprocessable()->assertJsonValidationErrors('body');
        $this->assertDatabaseCount('direct_messages', 2);
        $this->assertDatabaseCount('activities', 0);
    }

    public function test_reversed_senders_reuse_one_canonical_conversation_and_persist_replies(): void
    {
        $student = User::factory()->create();
        $evaluator = User::factory()->evaluator()->create();
        $this->actingAs($student)->postJson(route('messages.send', $evaluator), ['body' => 'Student first message'])
            ->assertSuccessful();
        $conversation = DirectConversation::sole();
        $this->assertSame(min($student->id, $evaluator->id), $conversation->lower_user_id);
        $this->assertSame(max($student->id, $evaluator->id), $conversation->higher_user_id);

        $this->actingAs($evaluator)->get(route('messages.compose', $student))
            ->assertRedirect(route('messages.show', $conversation));
        $this->postJson(route('messages.send', $student), ['body' => 'Evaluator reply through recipient route'])
            ->assertSuccessful()->assertJsonPath('redirect', route('messages.show', $conversation));
        $this->postJson(route('messages.store', $conversation), ['body' => 'Another evaluator reply'])
            ->assertSuccessful()->assertJsonPath('message.body', 'Another evaluator reply');

        $this->assertDatabaseCount('direct_conversations', 1);
        $this->assertDatabaseCount('direct_messages', 3);
        $this->assertSame([$student->id, $evaluator->id, $evaluator->id], $conversation->messages()->orderBy('id')->pluck('sender_id')->all());
        $this->assertNotNull($conversation->fresh()->last_message_at);
        $this->actingAs($student)->get(route('messages.show', $conversation))->assertOk()
            ->assertSee('Student first message')->assertSee('Evaluator reply through recipient route')
            ->assertSee('Another evaluator reply');
    }

    public function test_existing_active_participants_can_reply_after_approval_changes_but_a_suspended_peer_is_read_only(): void
    {
        $student = User::factory()->create();
        $evaluator = User::factory()->evaluator()->create();
        $conversation = $this->conversation($student, $evaluator);
        $this->message($conversation, $student, $evaluator, 'Existing discussion before status change');
        $evaluator->update(['evaluator_status' => 'rejected', 'accepting_requests' => false]);

        $this->actingAs($student)->get(route('messages.compose', $evaluator))
            ->assertRedirect(route('messages.show', $conversation));
        $this->postJson(route('messages.store', $conversation), ['body' => 'Existing participant follow-up'])
            ->assertSuccessful();
        $this->actingAs($evaluator->fresh())->postJson(route('messages.store', $conversation), ['body' => 'Reply while application needs another review'])
            ->assertSuccessful();

        $evaluator->update(['is_suspended' => true]);
        $response = $this->actingAs($student)->get(route('messages.show', $conversation))->assertOk()
            ->assertSee('Existing discussion before status change')->assertSee('Existing participant follow-up');
        $xpath = $this->xpath($response->getContent());
        $this->assertSame(0, $xpath->query('//form[@data-message-form]//textarea[@name="body" and not(@disabled)]')->length);
        $this->postJson(route('messages.store', $conversation), ['body' => 'Must not be sent'])->assertForbidden();
        $this->postJson(route('messages.send', $evaluator), ['body' => 'Cannot bypass through recipient route'])->assertForbidden();
        $this->assertDatabaseCount('direct_messages', 3);

        $this->actingAs($evaluator->fresh())->get(route('messages.index'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_conversation_lists_and_sidebar_unread_counts_are_scoped_without_marking_messages_read(): void
    {
        $student = User::factory()->create();
        $evaluator = User::factory()->evaluator()->create(['name' => 'My messaging reviewer']);
        $conversation = $this->conversation($student, $evaluator);
        $first = $this->message($conversation, $evaluator, $student, 'MY INCOMING PRIVATE MESSAGE ONE');
        $second = $this->message($conversation, $evaluator, $student, 'MY INCOMING PRIVATE MESSAGE TWO');
        $outgoing = $this->message($conversation, $student, $evaluator, 'MY OUTGOING MESSAGE');
        $outsider = User::factory()->create();
        $otherPeer = User::factory()->evaluator()->create(['name' => 'Unrelated private messaging reviewer']);
        $otherConversation = $this->conversation($outsider, $otherPeer);
        $other = $this->message($otherConversation, $otherPeer, $outsider, 'OUTSIDER PRIVATE MESSAGE BODY');
        $activity = $student->notifyActivity('Separate unread review notification', 'Not a direct message.', route('account.dashboard'));

        $response = $this->actingAs($student)->get(route('messages.index', ['list' => 1]))->assertOk()
            ->assertViewHas('conversation', fn ($selected) => $selected === null)
            ->assertSee($evaluator->name)->assertDontSee($otherPeer->name)->assertDontSee($other->body)
            ->assertDontSee('data-message-form', false);
        $xpath = $this->xpath($response->getContent());
        $nav = $xpath->query('//aside[@id="sidebar"]//a[@data-messages-nav]')->item(0);
        $this->assertNotNull($nav);
        $this->assertSame(route('messages.index'), $nav->getAttribute('href'));
        $this->assertStringContainsString('active', $nav->getAttribute('class'));
        $this->assertSame('2', trim($xpath->query('.//*[@data-message-unread]', $nav)->item(0)->textContent));
        $this->getJson(route('messages.inbox-updates'))->assertOk()
            ->assertJsonCount(1, 'conversations')->assertJsonPath('conversations.0.id', $conversation->id)
            ->assertJsonPath('conversations.0.name', $evaluator->name)
            ->assertJsonPath('conversations.0.unread_count', 2)->assertJsonPath('unread_count', 2);
        foreach ([$first, $second, $outgoing, $other] as $message) {
            $this->assertNull($message->fresh()->read_at);
        }
        $this->assertNull($activity->fresh()->read_at);
    }

    public function test_opening_a_message_page_marks_only_fetched_incoming_messages_read(): void
    {
        $student = User::factory()->create();
        $evaluator = User::factory()->evaluator()->create();
        $conversation = $this->conversation($student, $evaluator);
        $incoming = [];
        for ($index = 1; $index <= 55; $index++) {
            $incoming[] = $this->message($conversation, $evaluator, $student, 'PAGED INCOMING ROW '.str_pad((string) $index, 3, '0', STR_PAD_LEFT));
        }
        $outgoing = $this->message($conversation, $student, $evaluator, 'OUTGOING ROW REMAINS UNREAD BY PEER');
        $otherConversation = $this->conversation($student, User::factory()->evaluator()->create());
        $other = $this->message($otherConversation, $otherConversation->otherParticipant($student), $student, 'OTHER THREAD INCOMING ROW');

        $this->actingAs($student)->get(route('messages.show', $conversation))->assertOk()
            ->assertSee('PAGED INCOMING ROW 055')->assertSee('PAGED INCOMING ROW 007');
        $this->assertSame(49, $conversation->messages()->where('recipient_id', $student->id)->whereNotNull('read_at')->count());
        $this->assertSame(6, $conversation->messages()->where('recipient_id', $student->id)->whereNull('read_at')->count());
        $this->assertNull($incoming[0]->fresh()->read_at);
        $this->assertNull($outgoing->fresh()->read_at);
        $this->assertNull($other->fresh()->read_at);
        $this->actingAs($evaluator)->getJson(route('messages.updates', ['conversation' => $conversation, 'after' => $outgoing->id]))
            ->assertOk()->assertJsonCount(0, 'messages')->assertJsonPath('peer_read_through_id', 0);

        $this->actingAs($student)->get(route('messages.show', ['conversation' => $conversation, 'message_page' => 2]))->assertOk()
            ->assertSee('PAGED INCOMING ROW 001')->assertSee('PAGED INCOMING ROW 006');
        $this->assertSame(55, $conversation->messages()->where('recipient_id', $student->id)->whereNotNull('read_at')->count());
        $this->assertNull($outgoing->fresh()->read_at);
        $this->assertNull($other->fresh()->read_at);
        $this->actingAs($evaluator)->getJson(route('messages.updates', ['conversation' => $conversation, 'after' => $outgoing->id]))
            ->assertOk()->assertJsonPath('peer_read_through_id', $incoming[54]->id);
    }

    public function test_incremental_updates_are_bounded_scoped_and_mark_only_returned_incoming_rows_read(): void
    {
        $student = User::factory()->create();
        $evaluator = User::factory()->evaluator()->create();
        $conversation = $this->conversation($student, $evaluator);
        $outgoing = $this->message($conversation, $student, $evaluator, 'OWN UPDATE ROW');
        for ($index = 1; $index <= 103; $index++) {
            $this->message($conversation, $evaluator, $student, 'UPDATE ROW '.$index);
        }
        $otherConversation = $this->conversation($student, User::factory()->evaluator()->create());
        $other = $this->message($otherConversation, $otherConversation->otherParticipant($student), $student, 'UNRELATED UPDATE ROW');
        $this->actingAs($student);
        foreach ([-1, 'invalid'] as $after) {
            $this->getJson(route('messages.updates', ['conversation' => $conversation, 'after' => $after]))
                ->assertUnprocessable()->assertJsonValidationErrors('after');
        }
        $this->assertSame(103, $conversation->messages()->where('recipient_id', $student->id)->whereNull('read_at')->count());

        $first = $this->getJson(route('messages.updates', ['conversation' => $conversation, 'after' => 0]))->assertOk()
            ->assertJsonCount(100, 'messages')->assertJsonPath('messages.0.id', $outgoing->id)
            ->assertJsonPath('messages.0.is_own', true)->assertJsonPath('unread_count', 5);
        $cursor = $first->json('latest_id');
        $this->assertSame($first->json('messages.99.id'), $cursor);
        $this->assertSame(99, $conversation->messages()->where('recipient_id', $student->id)->whereNotNull('read_at')->count());
        $this->assertNull($outgoing->fresh()->read_at);
        $this->assertNull($other->fresh()->read_at);

        $second = $this->getJson(route('messages.updates', ['conversation' => $conversation, 'after' => $cursor]))->assertOk()
            ->assertJsonCount(4, 'messages')->assertJsonPath('unread_count', 1);
        $ids = array_column($second->json('messages'), 'id');
        $this->assertSame([], array_intersect($ids, array_column($first->json('messages'), 'id')));
        $this->assertNotContains($other->id, $ids);
        $this->assertNull($other->fresh()->read_at);
        $this->getJson(route('messages.updates', ['conversation' => $conversation, 'after' => $second->json('latest_id')]))
            ->assertOk()->assertJsonCount(0, 'messages')->assertJsonPath('latest_id', $second->json('latest_id'));
    }

    public function test_evaluator_message_links_work_for_guests_hide_self_and_escape_private_message_content(): void
    {
        $evaluator = User::factory()->evaluator()->create();
        $compose = route('messages.compose', $evaluator);
        foreach ([route('evaluators.index'), route('evaluators.show', $evaluator)] as $url) {
            $this->get($url)->assertOk()->assertSee('href="'.$compose.'"', false);
        }
        $this->get($compose)->assertRedirect(route('login'));
        foreach ([route('evaluators.index'), route('evaluators.show', $evaluator)] as $url) {
            $this->actingAs($evaluator)->get($url)->assertOk()->assertDontSee('href="'.$compose.'"', false);
        }

        $student = User::factory()->create();
        $body = 'Hello <script>alert("private-message")</script> & <strong>database reviewer</strong>';
        $this->actingAs($student)->postJson(route('messages.send', $evaluator), ['body' => $body])->assertSuccessful();
        $conversation = DirectConversation::sole();
        $this->assertSame($body, DirectMessage::sole()->body);
        $this->get(route('messages.show', $conversation))->assertOk()->assertSee(e($body), false)->assertDontSee($body, false);
        $this->getJson(route('messages.updates', ['conversation' => $conversation, 'after' => 0]))
            ->assertOk()->assertJsonPath('messages.0.body', $body)->assertJsonPath('messages.0.is_own', true);
    }

    public function test_conversation_pagination_uses_recent_scoped_threads_and_keeps_its_page_parameter(): void
    {
        $student = User::factory()->create();
        $conversations = [];
        for ($index = 1; $index <= 26; $index++) {
            $peer = User::factory()->evaluator()->create(['name' => 'PAGINATED REVIEWER '.str_pad((string) $index, 2, '0', STR_PAD_LEFT)]);
            $conversation = $this->conversation($student, $peer);
            $this->message($conversation, $peer, $student, 'Scoped paginated discussion '.$index);
            $conversation->update(['last_message_at' => now()->addMinutes($index)]);
            $conversations[] = $conversation;
        }
        $response = $this->actingAs($student)->get(route('messages.index', ['q' => 'PAGINATED REVIEWER']))->assertOk()
            ->assertSee('PAGINATED REVIEWER 26')->assertDontSee('PAGINATED REVIEWER 01');
        $xpath = $this->xpath($response->getContent());
        $next = $xpath->query('//a[contains(@href,"chats_page=2")]')->item(0);
        $this->assertNotNull($next);
        parse_str(parse_url($next->getAttribute('href'), PHP_URL_QUERY), $query);
        $this->assertSame('PAGINATED REVIEWER', $query['q']);
        $this->get($next->getAttribute('href'))->assertOk()->assertSee('PAGINATED REVIEWER 01')
            ->assertDontSee('PAGINATED REVIEWER 26');
        $this->assertSame(26, DirectMessage::where('recipient_id', $student->id)->whereNull('read_at')->count());
    }

    public function test_each_message_has_its_real_sender_avatar_in_html_and_incremental_updates(): void
    {
        $student = User::factory()->create(['name' => 'Maya Santos']);
        $evaluator = User::factory()->evaluator()->create(['name' => 'Ezra Cruz']);
        $evaluator->update(['profile_photo_path' => 'profile-photos/'.$evaluator->id.'/'.str_repeat('m', 40).'.png']);
        $conversation = $this->conversation($student, $evaluator);
        $outgoing = $this->message($conversation, $student, $evaluator, 'Could you review our methodology?');
        $incoming = $this->message($conversation, $evaluator, $student, 'Yes, please share your specific question.');

        $response = $this->actingAs($student)->get(route('messages.show', $conversation))->assertOk();
        $xpath = $this->xpath($response->getContent());
        foreach ([[$outgoing, $student], [$incoming, $evaluator]] as [$message, $sender]) {
            $article = $xpath->query('//article[@data-message-id="'.$message->id.'"]')->item(0);
            $this->assertNotNull($article);
            $avatar = $xpath->query('./span[contains(@class,"chat-message-avatar")]', $article)->item(0);
            $this->assertNotNull($avatar);
            $this->assertSame('img', $avatar->getAttribute('role'));
            $this->assertSame($sender->name, $avatar->getAttribute('aria-label'));
            $this->assertSame($sender->initials(), $avatar->getAttribute('data-avatar-initials'));
            $content = $xpath->query('./div[@class="chat-message-content"]', $article)->item(0);
            $this->assertNotNull($content);
            $this->assertSame(1, $xpath->query('./p[@class="chat-bubble"]', $content)->length);
            $this->assertSame(1, $xpath->query('./div[@class="chat-message-meta"]', $content)->length);
            if ($sender->is($evaluator)) {
                $photo = $xpath->query('./img', $avatar)->item(0);
                $this->assertNotNull($photo);
                $this->assertSame($evaluator->profile_photo_url, $photo->getAttribute('src'));
                $this->assertSame('', $photo->getAttribute('alt'));
            } else {
                $this->assertSame($student->initials(), trim($avatar->textContent));
                $this->assertSame(0, $xpath->query('./img', $avatar)->length);
            }
        }

        $updates = $this->getJson(route('messages.updates', ['conversation' => $conversation, 'after' => 0]))
            ->assertOk()->assertJsonCount(2, 'messages')
            ->assertJsonPath('messages.0.sender_id', $student->id)
            ->assertJsonPath('messages.0.sender_name', 'Maya Santos')
            ->assertJsonPath('messages.0.sender_initials', 'MS')
            ->assertJsonPath('messages.0.sender_profile_photo_url', null)
            ->assertJsonPath('messages.1.sender_id', $evaluator->id)
            ->assertJsonPath('messages.1.sender_name', 'Ezra Cruz')
            ->assertJsonPath('messages.1.sender_initials', 'EC')
            ->assertJsonPath('messages.1.sender_profile_photo_url', $evaluator->profile_photo_url);
        $this->assertStringNotContainsString($evaluator->profile_photo_path, $updates->getContent());
        foreach ($updates->json('messages') as $payload) {
            foreach (['sender', 'email', 'password', 'profile_photo_path', 'remember_token'] as $privateField) {
                $this->assertArrayNotHasKey($privateField, $payload);
            }
        }
    }

    public function test_a_sent_message_uses_server_owned_sender_avatar_fields_instead_of_client_values(): void
    {
        $student = User::factory()->create(['name' => 'Maya Santos', 'profile_photo_path' => 'https://untrusted.example/portrait.png']);
        $evaluator = User::factory()->evaluator()->create();
        $conversation = $this->conversation($student, $evaluator);

        $this->actingAs($student)->postJson(route('messages.store', $conversation), [
            'body' => 'Thanks for your help.',
            'sender_id' => $evaluator->id,
            'sender_name' => 'Spoofed sender',
            'sender_initials' => 'XX',
            'sender_profile_photo_url' => 'https://untrusted.example/forged.png',
        ])->assertCreated()
            ->assertJsonPath('message.sender_id', $student->id)
            ->assertJsonPath('message.sender_name', 'Maya Santos')
            ->assertJsonPath('message.sender_initials', 'MS')
            ->assertJsonPath('message.sender_profile_photo_url', null)
            ->assertJsonPath('message.is_own', true);
        $this->assertDatabaseHas('direct_messages', ['sender_id' => $student->id, 'recipient_id' => $evaluator->id]);
        $this->assertDatabaseCount('direct_messages', 1);
    }

    public function test_review_event_cards_also_show_the_actual_senders_avatar(): void
    {
        $review = ReviewRequest::factory()->completed()->create();
        DB::transaction(fn () => app(ReviewDelivery::class)->deliver($review, 'completed'));
        $message = DirectMessage::sole();
        $owner = $review->project->owner;

        $response = $this->actingAs($owner)->get(route('messages.show', $message->conversation))->assertOk();
        $xpath = $this->xpath($response->getContent());
        $article = $xpath->query('//article[@data-message-id="'.$message->id.'"]')->item(0);
        $this->assertNotNull($article);
        $avatar = $xpath->query('./span[contains(@class,"chat-message-avatar")]', $article)->item(0);
        $this->assertNotNull($avatar);
        $this->assertSame($review->evaluator->name, $avatar->getAttribute('aria-label'));
        $this->assertSame($review->evaluator->initials(), trim($avatar->textContent));
        $this->assertSame(1, $xpath->query('./div[@class="chat-message-content"]/div[@data-review-card]', $article)->length);
        $this->getJson(route('messages.updates', ['conversation' => $message->conversation, 'after' => 0]))
            ->assertOk()->assertJsonPath('messages.0.kind', 'review_event')
            ->assertJsonPath('messages.0.sender_id', $review->evaluator_id)
            ->assertJsonPath('messages.0.sender_name', $review->evaluator->name)
            ->assertJsonPath('messages.0.sender_initials', $review->evaluator->initials())
            ->assertJsonPath('messages.0.review_card.available', true);
    }

    private function conversation(User $first, User $second): DirectConversation
    {
        return DirectConversation::create([
            'lower_user_id' => min($first->id, $second->id),
            'higher_user_id' => max($first->id, $second->id), 'last_message_at' => now(),
        ]);
    }

    private function message(DirectConversation $conversation, User $sender, User $recipient, string $body): DirectMessage
    {
        return $conversation->messages()->create([
            'sender_id' => $sender->id, 'recipient_id' => $recipient->id, 'body' => $body,
        ]);
    }

    private function xpath(string $html): \DOMXPath
    {
        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="utf-8" ?>'.$html);

        return new \DOMXPath($document);
    }
}

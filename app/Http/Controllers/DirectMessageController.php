<?php

namespace App\Http\Controllers;

use App\Models\DirectConversation;
use App\Models\DirectMessage;
use App\Models\DirectMessageAttachment;
use App\Models\User;
use App\Support\MessageAttachment;
use App\Support\ProjectAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Throwable;

class DirectMessageController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        ProjectAccess::assertActive($user);
        $q = $this->searchTerm($request);
        $conversations = $this->conversationList($user, $q);
        $conversation = $q === '' && ! $request->boolean('list') ? $conversations->first() : null;

        return $this->inboxView($request, $conversations, $conversation, null, $q);
    }

    public function compose(Request $request, User $recipient): Response|RedirectResponse
    {
        $user = $request->user();
        ProjectAccess::assertActive($user);
        abort_if($recipient->is($user), 403, 'You cannot message yourself.');
        $conversation = $this->pairQuery($user->id, $recipient->id)->first();
        if ($conversation) {
            return redirect()->route('messages.show', $conversation);
        }
        abort_unless($recipient->isEvaluator(), 403, 'New conversations can only be started with an approved, active evaluator.');
        $q = $this->searchTerm($request);

        return $this->inboxView($request, $this->conversationList($user, $q), null, $recipient, $q);
    }

    public function show(Request $request, DirectConversation $conversation): Response
    {
        $user = $request->user();
        ProjectAccess::assertActive($user);
        abort_unless($conversation->includesUser($user), 403);
        $q = $this->searchTerm($request);

        return $this->inboxView($request, $this->conversationList($user, $q), $conversation, null, $q);
    }

    public function send(Request $request, User $recipient): JsonResponse|RedirectResponse
    {
        ProjectAccess::assertActive($request->user());
        abort_if($recipient->is($request->user()), 403, 'You cannot message yourself.');
        $message = $this->persistMessage($request, $recipient->id);
        $url = route('messages.show', $message->direct_conversation_id);

        return $request->expectsJson()
            ? response()->json(['redirect' => $url], 201)->withHeaders($this->privateHeaders())
            : redirect()->to($url);
    }

    public function store(Request $request, DirectConversation $conversation): JsonResponse|RedirectResponse
    {
        $user = $request->user();
        ProjectAccess::assertActive($user);
        abort_unless($conversation->includesUser($user), 403);
        $recipientId = (int) $conversation->lower_user_id === (int) $user->id
            ? $conversation->higher_user_id : $conversation->lower_user_id;
        $message = $this->persistMessage($request, $recipientId, $conversation);

        return $request->expectsJson()
            ? response()->json(['message' => $this->messageData($message, $user), 'latest_id' => $message->id], 201)
                ->withHeaders($this->privateHeaders())
            : redirect()->route('messages.show', $message->direct_conversation_id);
    }

    public function updates(Request $request, DirectConversation $conversation): JsonResponse
    {
        $user = $request->user();
        ProjectAccess::assertActive($user);
        abort_unless($conversation->includesUser($user), 403);
        $data = $request->validate(['after' => ['sometimes', 'integer', 'min:0', 'max:'.PHP_INT_MAX]]);
        $after = (int) ($data['after'] ?? 0);
        $messages = $conversation->messages()->with(['sender', 'attachments', 'reviewRequest.project', 'reviewRequest.evaluator', 'reviewRequest.document'])
            ->where('id', '>', $after)->orderBy('id')->limit(100)->get();
        $this->markFetchedIncomingRead($conversation, $user, $messages);

        return response()->json([
            'messages' => $messages->map(fn (DirectMessage $message) => $this->messageData($message, $user))->values(),
            'latest_id' => $messages->last()?->id ?? $after,
            'peer_read_through_id' => $this->peerReadThrough($conversation, $user),
            'unread_count' => $user->receivedDirectMessages()->whereNull('read_at')->count(),
            'can_message' => ! $conversation->otherParticipant($user)->is_suspended,
        ])->withHeaders($this->privateHeaders());
    }

    public function inboxUpdates(Request $request): JsonResponse
    {
        $user = $request->user();
        ProjectAccess::assertActive($user);
        $conversations = $this->conversationQuery($user, $this->searchTerm($request))->limit(25)->get();

        return response()->json([
            'conversations' => $conversations->map(function (DirectConversation $conversation) use ($user) {
                $peer = $conversation->otherParticipant($user);
                $latest = $conversation->latestMessage;

                return [
                    'id' => $conversation->id,
                    'name' => $peer->name,
                    'initials' => $peer->initials(),
                    'profile_photo_url' => $peer->profile_photo_url,
                    'url' => route('messages.show', $conversation),
                    'preview' => Str::limit($latest?->previewText() ?? '', 120),
                    'is_own' => $latest !== null && (int) $latest->sender_id === (int) $user->id,
                    'last_message_at' => $conversation->last_message_at?->toIso8601String(),
                    'label' => $latest?->created_at?->format('M j · g:i a') ?? '',
                    'unread_count' => (int) $conversation->unread_messages_count,
                ];
            })->values(),
            'unread_count' => $user->receivedDirectMessages()->whereNull('read_at')->count(),
        ])->withHeaders($this->privateHeaders());
    }

    public function attachment(Request $request, DirectMessageAttachment $attachment): BinaryFileResponse
    {
        // Re-read both identity and membership for every file request. Administrators
        // have no special access to another person's private conversation.
        $user = User::query()->findOrFail($request->user()->getAuthIdentifier());
        ProjectAccess::assertActive($user);
        $message = $attachment->message()->with('conversation')->firstOrFail();
        abort_unless($message->conversation && $message->conversation->includesUser($user), 403);
        $file = MessageAttachment::localFile($attachment, $message);
        abort_unless($file, 404);
        $disposition = $attachment->is_image && ! $request->boolean('download')
            ? ResponseHeaderBag::DISPOSITION_INLINE : ResponseHeaderBag::DISPOSITION_ATTACHMENT;
        $response = new BinaryFileResponse($file, 200, [
            ...$this->privateHeaders(),
            'Content-Type' => $attachment->mime_type,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cross-Origin-Resource-Policy' => 'same-origin',
        ], false);

        return $response->setContentDisposition($disposition, $attachment->name, str_replace('%', '', Str::ascii($attachment->name)) ?: 'attachment');
    }

    private function persistMessage(Request $request, int $recipientId, ?DirectConversation $conversation = null): DirectMessage
    {
        if (is_string($request->input('body'))) {
            $request->merge(['body' => trim($request->input('body'))]);
        }
        $data = $request->validate([
            'body' => ['nullable', 'required_without:attachments', 'string', 'min:1', 'max:5000'],
            'attachments' => ['nullable', 'array', 'max:'.MessageAttachment::MAX_FILES],
            'attachments.*' => ['required', 'file', 'max:10240'],
        ]);
        $files = MessageAttachment::validateUploads($data['attachments'] ?? []);
        $actorId = $request->user()->id;
        $written = [];

        try {
            return DB::transaction(function () use ($actorId, $recipientId, $conversation, $data, $files, &$written) {
                // A commit failure can retry the callback after its files were written.
                MessageAttachment::cleanup($written);
                try {
                    // Every send locks the same two accounts in canonical order before the conversation.
                    $participants = User::query()->whereIn('id', [$actorId, $recipientId])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                    $actor = $participants->get($actorId);
                    $recipient = $participants->get($recipientId);
                    abort_unless($actor && $recipient, 404);
                    ProjectAccess::assertActive($actor);
                    abort_if($actor->is($recipient) || $recipient->is_suspended, 403, 'This conversation is currently read-only.');

                    if ($conversation) {
                        $thread = DirectConversation::query()->lockForUpdate()->findOrFail($conversation->id);
                        abort_unless($thread->includesUser($actor) && $thread->includesUser($recipient), 403);
                    } else {
                        $thread = $this->pairQuery($actor->id, $recipient->id)->lockForUpdate()->first();
                        if (! $thread) {
                            abort_unless($recipient->isEvaluator(), 403, 'New conversations can only be started with an approved, active evaluator.');
                            $thread = DirectConversation::firstOrCreate([
                                'lower_user_id' => min($actor->id, $recipient->id),
                                'higher_user_id' => max($actor->id, $recipient->id),
                            ]);
                        }
                    }

                    $message = $thread->messages()->create([
                        'sender_id' => $actor->id,
                        'recipient_id' => $recipient->id,
                        'body' => $data['body'] ?? '',
                    ]);
                    MessageAttachment::store($message, $files, $written);
                    $message->load('attachments');
                    $message->setRelation('sender', $actor);
                    $thread->forceFill(['last_message_at' => $message->created_at])->save();

                    return $message;
                } catch (Throwable $exception) {
                    MessageAttachment::cleanup($written);
                    throw $exception;
                }
            }, 3);
        } catch (Throwable $exception) {
            MessageAttachment::cleanup($written);
            throw $exception;
        }
    }

    private function conversationList(User $user, string $q): LengthAwarePaginator
    {
        return $this->conversationQuery($user, $q)->paginate(25, ['*'], 'chats_page')->withQueryString();
    }

    private function conversationQuery(User $user, string $q): Builder
    {
        return DirectConversation::query()->forUser($user->id)
            ->with(['lowerUser', 'higherUser', 'latestMessage.attachments'])
            ->withCount(['messages as unread_messages_count' => fn ($query) => $query->where('recipient_id', $user->id)->whereNull('read_at')])
            ->when($q !== '', fn ($query) => $query->where(function ($query) use ($user, $q) {
                $query->whereHas('lowerUser', fn ($query) => $query->where('id', '!=', $user->id)->where('name', 'like', '%'.$q.'%'))
                    ->orWhereHas('higherUser', fn ($query) => $query->where('id', '!=', $user->id)->where('name', 'like', '%'.$q.'%'));
            }))
            ->orderByDesc('last_message_at')->orderByDesc('id');
    }

    private function inboxView(Request $request, LengthAwarePaginator $conversations, ?DirectConversation $conversation, ?User $recipient, string $q): Response
    {
        $user = $request->user();
        if ($conversation) {
            abort_unless($conversation->includesUser($user), 403);
            $conversation->loadMissing(['lowerUser', 'higherUser']);
            $recipient = $conversation->otherParticipant($user);
            $messages = $conversation->messages()->with(['sender', 'attachments', 'reviewRequest.project', 'reviewRequest.evaluator', 'reviewRequest.document'])
                ->orderByDesc('id')->paginate(50, ['*'], 'message_page')->withQueryString();
            $messages->setCollection($messages->getCollection()->reverse()->values());
            $this->markFetchedIncomingRead($conversation, $user, $messages->getCollection());
            $listedConversation = $conversations->firstWhere('id', $conversation->id);
            if ($listedConversation) {
                $listedConversation->setAttribute('unread_messages_count', $conversation->messages()->where('recipient_id', $user->id)->whereNull('read_at')->count());
            }
        } else {
            $messages = new LengthAwarePaginator([], 0, 50, 1, ['path' => $request->url(), 'pageName' => 'message_page']);
        }
        $canMessage = $recipient !== null && ! $recipient->is_suspended
            && ($conversation !== null || $recipient->isEvaluator());

        return response()->view('messages.index', compact(
            'user', 'conversations', 'conversation', 'recipient', 'messages', 'canMessage', 'q'
        ))->withHeaders($this->privateHeaders());
    }

    private function markFetchedIncomingRead(DirectConversation $conversation, User $user, Collection $messages): void
    {
        $incoming = $messages->filter(fn (DirectMessage $message) => (int) $message->recipient_id === (int) $user->id && $message->read_at === null);
        if ($incoming->isEmpty()) {
            return;
        }
        $readAt = now();
        $conversation->messages()->where('recipient_id', $user->id)->whereIn('id', $incoming->modelKeys())->whereNull('read_at')->update(['read_at' => $readAt]);
        $incoming->each(fn (DirectMessage $message) => $message->read_at = $readAt);
    }

    private function peerReadThrough(DirectConversation $conversation, User $user): int
    {
        $sent = $conversation->messages()->where('sender_id', $user->id);
        $firstUnread = (clone $sent)->whereNull('read_at')->min('id');

        // A read receipt never claims an older, unfetched message was seen.
        return (int) $sent->whereNotNull('read_at')
            ->when($firstUnread !== null, fn ($query) => $query->where('id', '<', $firstUnread))->max('id');
    }

    private function messageData(DirectMessage $message, User $user): array
    {
        $sender = $message->sender;

        return [
            'id' => $message->id,
            'body' => $message->body,
            'attachments' => $message->attachments->map(fn (DirectMessageAttachment $attachment) => $attachment->toMessageData())->values()->all(),
            'kind' => $message->kind ?? 'text',
            'review_card' => $message->reviewCardFor($user),
            'sender_id' => $message->sender_id,
            'sender_name' => $sender?->name ?? 'Account unavailable',
            'sender_initials' => $sender?->initials() ?? '?',
            'sender_profile_photo_url' => $sender?->profile_photo_url,
            'is_own' => (int) $message->sender_id === (int) $user->id,
            'created_at' => $message->created_at->toIso8601String(),
            'label' => $message->created_at->format('M j, Y · g:i a'),
        ];
    }

    private function pairQuery(int $firstUserId, int $secondUserId)
    {
        return DirectConversation::query()->where('lower_user_id', min($firstUserId, $secondUserId))
            ->where('higher_user_id', max($firstUserId, $secondUserId));
    }

    private function searchTerm(Request $request): string
    {
        $data = $request->validate(['q' => ['nullable', 'string', 'max:100']]);

        return trim($data['q'] ?? '');
    }

    private function privateHeaders(): array
    {
        return ['Cache-Control' => 'private, no-store, max-age=0', 'Pragma' => 'no-cache'];
    }
}

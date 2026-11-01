@extends('layouts.app')
@section('title', 'Messages')
@section('crumb', 'messages')
@section('body-class', 'evaluators-page messages-page')
@push('styles')
<link rel="stylesheet" href="{{ asset('assets/evaluators.css') }}?v={{ filemtime(public_path('assets/evaluators.css')) }}">
<link rel="stylesheet" href="{{ asset('assets/messages.css') }}?v={{ filemtime(public_path('assets/messages.css')) }}">
@endpush
@section('content')
@include('evaluators.partials.sky')
<div class="messages-heading"><h1>Messages<span class="muted">.</span></h1></div>
<div class="messages-shell {{ $recipient ? 'has-conversation' : '' }}" data-message-room
    data-inbox-updates-url="{{ route('messages.inbox-updates', ['q' => $q]) }}"
    @if($conversation && (!$messages || $messages->currentPage() === 1)) data-updates-url="{{ route('messages.updates', $conversation) }}" @endif
    data-last-id="{{ $messages?->getCollection()->max('id') ?? 0 }}" data-user-id="{{ $user->id }}" data-conversation-id="{{ $conversation?->id }}">
    <aside class="chat-sidebar" aria-label="Conversations">
        <header class="chat-list-heading"><h2>Chats</h2><a class="icon-button" href="{{ route('evaluators.index') }}" aria-label="Start a new message" title="Start a new message"><x-icon name="edit"/></a></header>
        <form method="GET" action="{{ route('messages.index') }}" class="chat-search" role="search"><label for="chat-search" class="sr-only">Search your chats</label><x-icon name="search"/><input id="chat-search" name="q" value="{{ $q }}" placeholder="Search your chats" maxlength="100"><button type="submit" class="icon-button" aria-label="Search chats"><x-icon name="arrow-right"/></button></form>
        <div class="chat-list">
            @if($recipient && !$conversation)<div class="chat-person active"><x-avatar :user="$recipient"/><span class="chat-person-copy"><strong>{{ $recipient->name }}</strong><span>New conversation</span></span><span class="chat-draft badge">DRAFT</span></div>@endif
            @forelse($conversations as $chat)
                @php($partner = $chat->otherParticipant($user))
                <a class="chat-person {{ $conversation?->id === $chat->id ? 'active' : '' }} {{ $chat->unread_messages_count ? 'has-unread' : '' }}" href="{{ route('messages.show', $chat) }}" data-chat-id="{{ $chat->id }}" @if($conversation?->id === $chat->id) aria-current="page" @endif>
                    <x-avatar :user="$partner"/>
                    <span class="chat-person-copy"><strong>{{ $partner->name }}</strong><span data-chat-preview>@if($chat->latestMessage){{ (int) $chat->latestMessage->sender_id === (int) $user->id ? 'You: ' : '' }}{{ Str::limit($chat->latestMessage->previewText(), 56) }}@else Say hello @endif</span></span>
                    <span class="chat-person-meta">@if($chat->last_message_at)<time datetime="{{ $chat->last_message_at->toIso8601String() }}">{{ $chat->last_message_at->shortRelativeDiffForHumans() }}</time>@endif @if($chat->unread_messages_count)<span class="chat-unread" aria-label="{{ $chat->unread_messages_count }} unread messages">{{ $chat->unread_messages_count }}</span>@endif</span>
                </a>
            @empty
                @if(!$recipient || $conversation)<div class="chat-list-empty"><x-icon name="message-circle"/><p>{{ $q !== '' ? 'No chats match your search.' : 'Your conversations will appear here.' }}</p>@if($q !== '')<a class="text-link small" href="{{ route('messages.index', ['list' => 1]) }}">Clear search</a>@else<a class="text-link small" href="{{ route('evaluators.index') }}">Find an evaluator to message <x-icon name="arrow-right"/></a>@endif</div>@endif
            @endforelse
        </div>
        @if($conversations->hasPages())<div class="chat-list-pagination"><x-pagination :paginator="$conversations"/></div>@endif
    </aside>
    <section class="chat-panel" aria-label="{{ $recipient ? 'Conversation with '.$recipient->name : 'Select a conversation' }}">
        @if($recipient)
            <header class="chat-header"><a class="icon-button chat-back" href="{{ route('messages.index', ['list' => 1]) }}" aria-label="Back to all chats"><x-icon name="arrow-left"/></a><x-avatar :user="$recipient" class="avatar-small"/><div><h2>{{ $recipient->name }}</h2><span class="hint">{{ $recipient->is_suspended ? 'Account unavailable' : ($recipient->isEvaluator() ? 'Approved evaluator' : ucfirst($recipient->role)) }}</span></div>@if($recipient->isEvaluator())<a class="icon-button" href="{{ route('evaluators.show', $recipient) }}" aria-label="View {{ $recipient->name }}'s profile" title="View profile"><x-icon name="user"/></a>@endif</header>
            <div class="chat-thread" data-message-thread role="log" aria-label="Message history" aria-live="polite" aria-relevant="additions" tabindex="0">
                @if($messages && $messages->hasPages())<nav class="chat-history-nav" aria-label="Message history pages">@if($messages->hasMorePages())<a class="text-link small" href="{{ $messages->nextPageUrl() }}">↑ Earlier messages</a>@endif @if(!$messages->onFirstPage())<a class="text-link small" href="{{ route('messages.show', $conversation) }}">Back to latest messages ↓</a>@endif</nav>@endif
                <div class="chat-introduction" data-chat-introduction><x-avatar :user="$recipient" class="avatar-large"/><h3>{{ $recipient->name }}</h3><p class="small muted">Say hello, share your thesis topic, and ask how they can help.</p><p class="hint">Keep your conversation respectful and focused on constructive feedback.</p></div>
                @foreach($messages ?? [] as $message) @include('messages.partials.message', ['message' => $message]) @endforeach
            </div>
            <div class="chat-live-status hint" data-chat-status role="status"></div>
            @if($canMessage)
                <form method="POST" enctype="multipart/form-data" action="{{ $conversation ? route('messages.store', $conversation) : route('messages.send', $recipient) }}" class="chat-composer" data-message-form>
                    @csrf
                    <div class="chat-attachment-preview" data-message-attachments-preview aria-label="Selected attachments" hidden></div>
                    <div class="chat-composer-controls">
                        <div class="chat-attach-control">
                            <input type="file" id="message-attachments" name="attachments[]" multiple accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv,.zip" aria-label="Attach photos or files" data-message-files>
                            <label for="message-attachments" class="chat-attach-button" title="Attach photos or files"><x-icon name="paperclip"/><span class="sr-only">Attach photos or files</span></label>
                        </div>
                        <label for="message-body" class="sr-only">Message {{ $recipient->name }}</label><textarea id="message-body" name="body" rows="1" maxlength="5000" placeholder="Write a message…" aria-describedby="message-hint" data-message-input>{{ old('body') }}</textarea><button type="submit" class="chat-send" aria-label="Send message" title="Send message"><x-icon name="send"/></button>
                    </div>
                    <span class="hint" id="message-hint">Enter to send · Shift + Enter for a new line</span>
                </form>
                <noscript><p class="hint chat-no-js">Add a message or choose a file, then use the send button. Refresh the page to check for replies.</p></noscript>
            @else
                <div class="chat-unavailable"><x-icon name="lock"/><p class="small">You cannot send messages to this account right now. Your conversation history is still available.</p></div>
            @endif
        @else
            <div class="chat-welcome"><span class="chat-welcome-icon"><x-icon name="message-circle"/></span><h2>A conversation starts here.</h2><p>Connect with an evaluator, ask a question, and take the next step with your thesis.</p><a class="btn btn-primary" href="{{ route('evaluators.index') }}">Find an evaluator <x-icon name="arrow-right"/></a></div>
        @endif
    </section>
</div>
@endsection
@push('scripts')<script src="{{ asset('assets/messages.js') }}?v={{ filemtime(public_path('assets/messages.js')) }}" defer></script>@endpush
